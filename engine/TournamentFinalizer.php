<?php
// /engine/TournamentFinalizer.php
declare(strict_types=1);

/**
 * Finalizzazione torneo + classifica (auto-discovery colonne, nessuna migrazione richiesta).
 * - Pool: usa prize_pool (se presente) altrimenti buyin * vite_totali * (pool_pct o 100-rake_pct).
 * - Vincitore unico: 100% pool.
 * - Zero vivi: split su ultimo round (pesi = #vite con pick al round).
 * - Log su points_balance_log SOLO se colonne minime esistono.
 * - Payout su tournament_payouts SOLO se colonne minime esistono.
 */

class TournamentFinalizer {

  /* ================== Helpers comuni ================== */

  protected static function columnExists(\PDO $pdo, string $table, string $col): bool {
    static $cache = [];
    $k="$table.$col"; if(isset($cache[$k])) return $cache[$k];
    $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $q->execute([$table,$col]);
    return $cache[$k]=(bool)$q->fetchColumn();
  }

  protected static function firstCol(\PDO $pdo, string $table, array $cands, $fallback='NULL'){
    foreach($cands as $c){ if(self::columnExists($pdo,$table,$c)) return $c; }
    return $fallback;
  }

  protected static function pickColOrNull(\PDO $pdo, string $table, array $cands): ?string {
    foreach($cands as $c){ if(self::columnExists($pdo,$table,$c)) return $c; }
    return null;
  }

  protected static function colMaxLen(\PDO $pdo, string $table, string $col): ?int {
    $st = $pdo->prepare("SELECT CHARACTER_MAXIMUM_LENGTH
                         FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA=DATABASE()
                           AND TABLE_NAME=? AND COLUMN_NAME=?");
    $st->execute([$table,$col]);
    $n = $st->fetchColumn();
    return ($n!==false && $n!==null) ? (int)$n : null;
  }

  protected static function genCode(int $len=8): string {
    $hex = strtoupper(bin2hex(random_bytes(max(4, min(32,$len)))));
    return substr($hex, 0, $len);
  }

  protected static function uniqueCode(\PDO $pdo, string $table, string $col, int $len=10): string {
    $tries=0;
    do {
      $code = self::genCode($len);
      $q=$pdo->prepare("SELECT 1 FROM `$table` WHERE `$col`=? LIMIT 1");
      $q->execute([$code]);
      $exists = (bool)$q->fetchColumn();
      $tries++;
    } while ($exists && $tries < 16);
    return $code;
  }

  public static function resolveTournamentId(\PDO $pdo, ?int $id, ?string $code): int {
    $tTable='tournaments';
    $tId   = self::firstCol($pdo,$tTable,['id'],'id');
    $tCode = self::firstCol($pdo,$tTable,['code','tour_code','t_code','short_id'],'NULL');
    if ($id && $id>0) return (int)$id;
    if ($code && $code!=='' && $tCode!=='NULL'){
      $st=$pdo->prepare("SELECT $tId FROM $tTable WHERE $tCode=? LIMIT 1");
      $st->execute([$code]);
      return (int)$st->fetchColumn();
    }
    return 0;
  }

  protected static function statusLabel(?string $s, ?string $lockIso): string {
    $now=time(); $s=strtolower((string)$s); $ts=$lockIso?strtotime($lockIso):null;
    if(in_array($s,['closed','ended','finished','chiuso','terminato'],true)) return 'CHIUSO';
    if($ts!==null && $ts <= $now) return 'IN CORSO';
    return 'APERTO';
  }

  /**
   * Sceglie un valore "chiuso" compatibile con lo schema di $table.$col.
   */
  protected static function pickClosedStatusValue(\PDO $pdo, string $table, string $col): array {
    $st = $pdo->prepare(
      "SELECT DATA_TYPE, COLUMN_TYPE
         FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = ?
          AND COLUMN_NAME  = ?
        LIMIT 1"
    );
    $st->execute([$table, $col]);
    $row = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    if (!$row) return ['expr'=>'?','param'=>'closed'];

    $dataType   = strtolower((string)$row['DATA_TYPE']);
    $columnType = strtolower((string)$row['COLUMN_TYPE']);

    if ($dataType === 'enum') {
      preg_match_all("/'([^']+)'/", $columnType, $m);
      $values = $m[1] ?? [];
      if ($values) {
        $prefs = ['finished','closed','ended','chiuso','terminato','finito','chiusa'];
        foreach ($prefs as $want) {
          if (in_array($want, $values, true)) return ['expr'=>'?','param'=>$want];
        }
        foreach ($values as $v) {
          if (str_contains($v,'clos') || str_contains($v,'end') || str_contains($v,'fin') || str_contains($v,'term')) {
            return ['expr'=>'?','param'=>$v];
          }
        }
        return ['expr'=>'?','param'=>$values[0]];
      }
      return ['expr'=>'?','param'=>'closed'];
    }

    if (in_array($dataType, ['tinyint','smallint','mediumint','int','bigint','boolean','bool'], true)) {
      return ['expr'=>'1','param'=>null];
    }

    return ['expr'=>'?','param'=>'closed'];
  }

  /* ================== Mappa tabelle ================== */

  protected static function mapTables(\PDO $pdo): array {
    // tournaments
    $tT   = 'tournaments';
    $tId  = self::firstCol($pdo,$tT,['id'],'id');
    $tCode= self::firstCol($pdo,$tT,['code','tour_code','t_code','short_id'],'NULL');
    $tBuy = self::firstCol($pdo,$tT,['buyin_coins','buyin'],'0');
    $tPool= self::firstCol($pdo,$tT,['prize_pool_coins','pool_coins','prize_coins','prize_pool','montepremi'],'NULL');
    $tSt  = self::firstCol($pdo,$tT,['status','state'],'NULL');
    $tCR  = self::firstCol($pdo,$tT,['current_round','round_current','round'],'NULL');
    $tLock= self::firstCol($pdo,$tT,['lock_at','close_at','subscription_end','reg_close_at','start_time'],'NULL');
    $tRake= self::firstCol($pdo,$tT,['rake_pct','rake_percent','rake','fee_pct','commission_pct'],'NULL');
    $tPPct= self::firstCol($pdo,$tT,['pool_percent','prize_pool_percent','payout_pct','payout_percent'],'NULL');
    $tFin = self::firstCol($pdo,$tT,['finalized_at','finished_at','closed_at'],'NULL');
    $tWin = self::firstCol($pdo,$tT,['winner_user_id','winner_uid','winner'],'NULL');

    // lives
    $lT=null; foreach(['tournament_lives','tournaments_lives'] as $try){
      $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
      $q->execute([$try]); if($q->fetchColumn()){ $lT=$try; break; }
    }
    if(!$lT) $lT='tournament_lives';
    $lId  = self::firstCol($pdo,$lT,['id'],'id');
    $lUid = self::firstCol($pdo,$lT,['user_id','uid'],'user_id');
    $lTid = self::firstCol($pdo,$lT,['tournament_id','tid'],'tournament_id');
    $lRnd = self::firstCol($pdo,$lT,['round','rnd'],'NULL');
    $lSt  = self::firstCol($pdo,$lT,['status','state'],'NULL');
    $lCrAt= self::firstCol($pdo,$lT,['created_at','created'],'NULL');
    $lUpAt= self::firstCol($pdo,$lT,['updated_at','updated'],'NULL');

    // picks
    $pT=null; foreach(['tournament_picks','picks','scelte'] as $try){
      $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
      $q->execute([$try]); if($q->fetchColumn()){ $pT=$try; break; }
    }
    if(!$pT) $pT='tournament_picks';
    $pId  = self::firstCol($pdo,$pT,['id'],'id');
    $pLife= self::firstCol($pdo,$pT,['life_id'],'life_id');
    $pTid = self::firstCol($pdo,$pT,['tournament_id','tid'],'NULL');
    $pRnd = self::firstCol($pdo,$pT,['round','rnd'],'round');
    $pCAt = self::firstCol($pdo,$pT,['created_at','created'],'NULL');
    $pUAt = self::firstCol($pdo,$pT,['updated_at','updated'],'NULL');

    // users
    $uT='users';
    $uId = self::firstCol($pdo,$uT,['id'],'id');
    $uNm = self::firstCol($pdo,$uT,['username','name','fullname','display_name'],'username');
    $uAv = self::firstCol($pdo,$uT,['avatar','avatar_url','photo','photo_url','picture','image','profile_pic'],'NULL');
    $uCoins = self::firstCol($pdo,$uT,['coins','balance','credits'],'coins');

    // points log (facoltativo) — colonne minime user_id + delta
    $logT = null; $hasLogTbl=false;
    $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='points_balance_log'");
    $q->execute(); $hasLogTbl = (bool)$q->fetchColumn();
    if ($hasLogTbl) $logT='points_balance_log';

    $logTx    = $hasLogTbl ? self::pickColOrNull($pdo,$logT,['tx_code','code']) : null;
    $logAdm   = $hasLogTbl ? self::pickColOrNull($pdo,$logT,['admin_id']) : null;
    $logUID   = $hasLogTbl ? self::pickColOrNull($pdo,$logT,['user_id','uid']) : null;     // NULL se non c'è
    $logDelta = $hasLogTbl ? self::pickColOrNull($pdo,$logT,['delta','amount']) : null;     // NULL se non c'è
    $logReason= $hasLogTbl ? self::pickColOrNull($pdo,$logT,['reason','note','notes']) : null;
    $logCAt   = $hasLogTbl ? self::pickColOrNull($pdo,$logT,['created_at','created']) : null;

    // payout table (facoltativa) — richiede almeno 3 colonne base
    $payT = null;
    $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tournament_payouts'");
    $q->execute();
    if($q->fetchColumn()){ $payT='tournament_payouts'; }

    $payTid = $payT ? self::pickColOrNull($pdo,$payT,['tournament_id','tid']) : null;
    $payUid = $payT ? self::pickColOrNull($pdo,$payT,['user_id','uid']) : null;
    $payAmt = $payT ? self::pickColOrNull($pdo,$payT,['amount','coins','payout_coins']) : null;
    $payRank= $payT ? self::pickColOrNull($pdo,$payT,['rank','position']) : null;
    $payMeta= $payT ? self::pickColOrNull($pdo,$payT,['meta_json','meta']) : null;
    $payCAt = $payT ? self::pickColOrNull($pdo,$payT,['created_at','created']) : null;
    $payAdm = $payT ? self::pickColOrNull($pdo,$payT,['admin_id']) : null;

    // se mancano le 3 colonne base → disattiva payout table
    if ($payT && (!$payTid || !$payUid || !$payAmt)) {
      $payT=null; $payTid=$payUid=$payAmt=$payRank=$payMeta=$payCAt=$payAdm=null;
    }

    return compact(
      'tT','tId','tCode','tBuy','tPool','tSt','tCR','tLock','tRake','tPPct','tFin','tWin',
      'lT','lId','lUid','lTid','lRnd','lSt','lCrAt','lUpAt',
      'pT','pId','pLife','pTid','pRnd','pCAt','pUAt',
      'uT','uId','uNm','uAv','uCoins',
      'logT','logTx','logAdm','logUID','logDelta','logReason','logCAt',
      'payT','payTid','payUid','payAmt','payRank','payMeta','payCAt','payAdm'
    );
  }

  /* ================== Pool effettivo ================== */

  protected static function getEffectivePool(\PDO $pdo, int $tid, array $M): float {
    extract($M);
    // 1) usa colonna pool se presente e > 0
    if ($tPool!=='NULL') {
      $st=$pdo->prepare("SELECT COALESCE($tPool,0) FROM $tT WHERE $tId=? LIMIT 1");
      $st->execute([$tid]); $pool=(float)$st->fetchColumn();
      if ($pool>0) return round($pool,2);
    }
    // 2) fallback: buyin * vite_totali * pool%
    $st=$pdo->prepare("SELECT COALESCE($tBuy,0) AS buyin, ".
                      ($tPPct!=='NULL' ? "$tPPct AS pool_pct" : "NULL AS pool_pct").", ".
                      ($tRake!=='NULL' ? "$tRake AS rake_pct" : "NULL AS rake_pct").
                      " FROM $tT WHERE $tId=? LIMIT 1");
    $st->execute([$tid]); $row=$st->fetch(\PDO::FETCH_ASSOC) ?: ['buyin'=>0,'pool_pct'=>null,'rake_pct'=>null];
    $buyin=(float)($row['buyin'] ?? 0);
    $poolPct = isset($row['pool_pct']) ? (float)$row['pool_pct'] : null;
    $rakePct = isset($row['rake_pct']) ? (float)$row['rake_pct'] : null;

    $norm=function($v){ if($v===null) return null; $v=(float)$v; if($v<=1.0) $v*=100.0; if($v<0)$v=0; if($v>100)$v=100; return $v; };
    $poolPct = $norm($poolPct); $rakePct=$norm($rakePct);
    $effPoolPct = ($poolPct!==null)? $poolPct : (100.0 - ($rakePct ?? 0.0));

    $st=$pdo->prepare("SELECT COUNT(*) FROM $lT WHERE $lTid=?");
    $st->execute([$tid]);
    $livesTotal=(int)$st->fetchColumn();

    return round($buyin * $livesTotal * ($effPoolPct/100.0), 2);
  }

  /* ================== Alive users ================== */

  protected static function getAliveUsers(\PDO $pdo, int $tid, array $M): array {
    extract($M);
    if ($lSt==='NULL'){
      $sql="SELECT DISTINCT $lUid FROM $lT WHERE $lTid=?";
      $st=$pdo->prepare($sql); $st->execute([$tid]);
      return array_map('intval',$st->fetchAll(\PDO::FETCH_COLUMN));
    }
    $sql="SELECT DISTINCT $lUid FROM $lT WHERE $lTid=? AND LOWER($lSt)='alive'";
    $st=$pdo->prepare($sql); $st->execute([$tid]);
    return array_map('intval',$st->fetchAll(\PDO::FETCH_COLUMN));
  }

  /* ================== Ultimo round / pesi ================== */

  protected static function getLastRoundFromPicks(\PDO $pdo, int $tid, array $M): ?int {
    extract($M);
    if(!$pT) return null;
    $where = ($pTid!=='NULL') ? "WHERE p.$pTid=?" : "";
    $st=$pdo->prepare("SELECT MAX(p.$pRnd) FROM $pT p $where");
    $st->execute(($pTid!=='NULL')?[$tid]:[]);
    $r=$st->fetchColumn();
    if($r!==null && $r!=='') return (int)$r;

    if ($lRnd!=='NULL') {
      $st=$pdo->prepare("SELECT MAX($lRnd) FROM $lT WHERE $lTid=?");
      $st->execute([$tid]);
      $r=$st->fetchColumn();
      return ($r!==null && $r!=='') ? (int)$r : null;
    }
    return null;
  }

  protected static function getWeightsAtRound(\PDO $pdo, int $tid, int $round, array $M): array {
    extract($M);
    $weights = [];

    if ($pT){
      $join = "JOIN $lT l ON l.$lId = p.$pLife";
      $where = ($pTid!=='NULL') ? "p.$pTid=? AND p.$pRnd=?" : "p.$pRnd=?";
      $params = ($pTid!=='NULL') ? [$tid,$round] : [$round];
      $sql="SELECT l.$lUid AS uid, COUNT(DISTINCT p.$pLife) AS cnt
            FROM $pT p $join
            WHERE $where
            GROUP BY l.$lUid";
      $st=$pdo->prepare($sql); $st->execute($params);
      foreach($st->fetchAll(\PDO::FETCH_ASSOC) as $r){
        $weights[(int)$r['uid']] = (int)$r['cnt'];
      }
    }

    if (!$weights && $lRnd!=='NULL'){
      $st=$pdo->prepare("SELECT $lUid AS uid, COUNT(*) AS cnt
                         FROM $lT
                         WHERE $lTid=? AND $lRnd=?
                         GROUP BY $lUid");
      $st->execute([$tid,$round]);
      foreach($st->fetchAll(\PDO::FETCH_ASSOC) as $r){
        $weights[(int)$r['uid']] = (int)$r['cnt'];
      }
    }

    return $weights;
  }

  /* ================== Leaderboard (Top 10) ================== */

  public static function buildLeaderboard(\PDO $pdo, int $tid, array $winnerUserIds = []): array {
    $M = self::mapTables($pdo);
    extract($M);

    $st=$pdo->prepare("SELECT DISTINCT $lUid FROM $lT WHERE $lTid=?");
    $st->execute([$tid]);
    $userIds = array_map('intval',$st->fetchAll(\PDO::FETCH_COLUMN));
    if(!$userIds) return [];

    $aliveUsers = self::getAliveUsers($pdo,$tid,$M);
    $aliveSet = array_fill_keys($aliveUsers,true);

    $stats=[];
    foreach($userIds as $uid1){
      $bestRound = null; $livesAtBest=0; $ts=null;

      if ($pT){
        $where = ($pTid!=='NULL') ? "p.$pTid=? AND l.$lUid=?" : "l.$lUid=?";
        $params = ($pTid!=='NULL') ? [$tid,$uid1] : [$uid1];
        $sql = "SELECT p.$pRnd AS r, COUNT(DISTINCT p.$pLife) AS cnt,
                       MAX(".($pUAt!=='NULL'?"p.$pUAt":($pCAt!=='NULL'?"p.$pCAt":"NULL")).") AS ts
                FROM $pT p JOIN $lT l ON l.$lId = p.$pLife
                ".(($pTid!=='NULL')?"WHERE $where":"WHERE $where")."
                GROUP BY p.$pRnd
                ORDER BY r DESC
                LIMIT 1";
        $x=$pdo->prepare($sql); $x->execute($params);
        $r=$x->fetch(\PDO::FETCH_ASSOC);
        if($r){
          $bestRound=(int)$r['r'];
          $livesAtBest=(int)$r['cnt'];
          $ts = $r['ts'] ?? null;
        }
      }

      if ($bestRound===null && $lRnd!=='NULL'){
        $x=$pdo->prepare("SELECT MAX($lRnd) FROM $lT WHERE $lTid=? AND $lUid=?");
        $x->execute([$tid,$uid1]); $br=$x->fetchColumn();
        if($br!==null){ $bestRound=(int)$br; }
        if($bestRound!==null){
          $x=$pdo->prepare("SELECT COUNT(*) FROM $lT WHERE $lTid=? AND $lUid=? AND $lRnd=?");
          $x->execute([$tid,$uid1,$bestRound]); $livesAtBest=(int)$x->fetchColumn();
        }
      }

      $stats[$uid1]=[
        'user_id'=>$uid1,
        'best_round'=>$bestRound ?? 0,
        'lives_at_best'=>$livesAtBest,
        'alive_now'=> isset($aliveSet[$uid1]),
        'ts'=>$ts
      ];
    }

    $in = implode(',', array_fill(0,count($userIds),'?'));
    $sqlU = "SELECT $uId AS id, $uNm AS username".($uAv!=='NULL' ? ", $uAv AS avatar" : ", NULL AS avatar")." FROM $uT WHERE $uId IN ($in)";
    $st=$pdo->prepare($sqlU); $st->execute($userIds);
    $U=[]; foreach($st->fetchAll(\PDO::FETCH_ASSOC) as $r){ $U[(int)$r['id']]=$r; }

    $winnerSet = array_fill_keys(array_map('intval',$winnerUserIds), true);
    usort($stats, function($a,$b) use($winnerSet){
      $aw = isset($winnerSet[$a['user_id']]) ? 1 : 0;
      $bw = isset($winnerSet[$b['user_id']]) ? 1 : 0;
      if ($aw !== $bw) return $bw - $aw;
      if ($a['best_round'] !== $b['best_round']) return $b['best_round'] - $a['best_round'];
      if ($a['lives_at_best'] !== $b['lives_at_best']) return $b['lives_at_best'] - $a['lives_at_best'];
      $ta = $a['ts'] ? strtotime((string)$a['ts']) : PHP_INT_MAX;
      $tb = $b['ts'] ? strtotime((string)$b['ts']) : PHP_INT_MAX;
      if ($ta !== $tb) return $ta - $tb;
      return $a['user_id'] - $b['user_id'];
    });

    $out=[];
    foreach(array_slice($stats,0,10) as $row){
      $u = $U[$row['user_id']] ?? ['username'=>null,'avatar'=>null];
      $out[] = [
        'user_id'      => $row['user_id'],
        'username'     => $u['username'] ?? ('user#'.$row['user_id']),
        'avatar'       => $u['avatar'] ?? null,
        'best_round'   => $row['best_round'],
        'lives_at_best'=> $row['lives_at_best'],
        'is_winner'    => isset($winnerSet[$row['user_id']]),
      ];
    }
    return $out;
  }

  /* ================== Check fine torneo ================== */

  public static function shouldEndTournament(\PDO $pdo, int $tid): array {
    $M = self::mapTables($pdo);
    extract($M);

    $st=$pdo->prepare("SELECT ".($tCR!=='NULL'?"COALESCE($tCR,1)":"1")." AS r,
                              ".($tSt!=='NULL'?"$tSt":"NULL")." AS status,
                              ".($tLock!=='NULL'?"$tLock":"NULL")." AS lock_at
                       FROM $tT WHERE $tId=? LIMIT 1");
    $st->execute([$tid]); $T=$st->fetch(\PDO::FETCH_ASSOC) ?: ['r'=>1,'status'=>null,'lock_at'=>null];

    $state = self::statusLabel($T['status']??null, $T['lock_at']??null);
    if ($state==='CHIUSO'){
      return ['should_end'=>false,'reason'=>'already_closed','alive_users'=>0,'round'=>(int)$T['r']];
    }

    $aliveUsers = self::getAliveUsers($pdo,$tid,$M);
    $n = count($aliveUsers);
    if ($n >= 2) return ['should_end'=>false,'reason'=>'enough_players','alive_users'=>$n,'round'=>(int)$T['r']];
    if ($n === 1) return ['should_end'=>true, 'reason'=>'single_winner','alive_users'=>1,'round'=>(int)$T['r']];
    return ['should_end'=>true, 'reason'=>'split_pot','alive_users'=>0,'round'=>(int)$T['r']];
  }

  /* ================== split proporzionale ================== */

  protected static function splitWithRemainder(float $pool, array $weights): array {
    $sum = array_sum($weights);
    if ($sum <= 0) {
      $n = max(1,count($weights));
      $base = floor(($pool/$n)*100)/100;
      $res=[]; foreach($weights as $uid=>$w){ $res[$uid]=$base; }
      $used = array_sum($res);
      $rem = round($pool - $used, 2);
      foreach($res as $uid=>$_){ if($rem<=0) break; $res[$uid]=round($res[$uid]+0.01,2); $rem=round($rem-0.01,2); }
      return $res;
    }
    $raw=[]; $fract=[];
    foreach($weights as $uid=>$w){
      $share = ($pool * $w) / $sum;
      $raw[$uid] = $share;
      $base = floor($share*100)/100;
      $fract[$uid] = $share - $base;
    }
    $res=[]; $used=0.0;
    foreach($raw as $uid=>$share){
      $base = floor($share*100)/100;
      $res[$uid] = round($base,2);
      $used = round($used + $res[$uid], 2);
    }
    $rem = round($pool - $used, 2);
    if ($rem > 0){
      arsort($fract, SORT_NUMERIC);
      foreach($fract as $uid=>$f){
        if ($rem <= 0) break;
        $res[$uid] = round($res[$uid] + 0.01, 2);
        $rem = round($rem - 0.01, 2);
      }
    }
    return $res;
  }

  /* ================== Finalizzazione ================== */

  public static function finalizeTournament(\PDO $pdo, int $tournamentId, int $adminId = 0): array {
    $M = self::mapTables($pdo);
    extract($M);
    $tid = $tournamentId;

    $pdo->beginTransaction();
    try{
      $pdo->prepare("SELECT $tId FROM $tT WHERE $tId=? FOR UPDATE")->execute([$tid]);

      $st=$pdo->prepare("SELECT ".($tSt!=='NULL'?"$tSt":"NULL")." AS status, ".
                                 ($tCR!=='NULL'?"$tCR":"NULL")." AS round
                        FROM $tT WHERE $tId=? LIMIT 1");
      $st->execute([$tid]); $T=$st->fetch(\PDO::FETCH_ASSOC) ?: [];
      $aliveUsers = self::getAliveUsers($pdo,$tid,$M);
      $nAlive = count($aliveUsers);

      if ($nAlive >= 2){
        $pdo->commit();
        return ['ok'=>false,'error'=>'not_final','detail'=>'At least two users alive'];
      }

      $pool = self::getEffectivePool($pdo,$tid,$M);

      $winners=[]; $payouts=[]; $resultType='';
      if ($nAlive === 1){
        $uidW = $aliveUsers[0];
        $winners = [$uidW];
        $payouts = [$uidW => $pool];
        $resultType='winner';
      } else {
        $lastRound = self::getLastRoundFromPicks($pdo,$tid,$M) ?? 1;
        $weights = self::getWeightsAtRound($pdo,$tid,$lastRound,$M);
        if (!$weights){
          $st=$pdo->prepare("SELECT DISTINCT $lUid FROM $lT WHERE $lTid=?");
          $st->execute([$tid]);
          $ids = array_map('intval',$st->fetchAll(\PDO::FETCH_COLUMN));
          foreach($ids as $u) $weights[$u]=1;
        }
        $winners = array_keys($weights);
        $payouts = self::splitWithRemainder($pool, $weights);
        $resultType='split';
      }

      // accrediti + log opzionale + payouts opzionale
      foreach($payouts as $uidX=>$amt){
        if ($amt<=0) continue;

        // accredito saldo utente
        $pdo->prepare("UPDATE $uT SET $uCoins = COALESCE($uCoins,0) + ? WHERE $uId=?")->execute([$amt,$uidX]);

        // log points_balance_log SOLO se ci sono user_id + delta
        if ($logT && $logUID && $logDelta) {
          $cols=[]; $vals=[]; $par=[];
          if ($logTx){ $cols[]=$logTx; $vals[]='?'; $par[]=self::uniqueCode($pdo,$logT,$logTx, max(8, self::colMaxLen($pdo,$logT,$logTx) ?: 8)); }
          $cols[]=$logUID;   $vals[]='?';    $par[]=$uidX;
          $cols[]=$logDelta; $vals[]='?';    $par[]=$amt;
          if ($logReason){ $cols[]=$logReason; $vals[]='?'; $par[]='Payout torneo #'.$tid; }
          if ($logAdm){    $cols[]=$logAdm;   $vals[]='?'; $par[]=$adminId; }
          if ($logCAt){    $cols[]=$logCAt;   $vals[]='NOW()'; }
          $sql="INSERT INTO $logT(".implode(',',$cols).") VALUES(".implode(',',$vals).")";
          $pdo->prepare($sql)->execute($par);
        }

        // tournament_payouts (se presente e con colonne minime)
        if ($payT){
          $cols = [$payTid, $payUid, $payAmt];
          $vals = ['?','?','?'];
          $par  = [$tid, $uidX, $amt];

          if ($payRank){ $cols[]=$payRank; $vals[]='?'; $par[]=1; }
          if ($payMeta){ $cols[]=$payMeta; $vals[]='?'; $par[]= json_encode(['type'=>$resultType]); }
          if ($payAdm){  $cols[]=$payAdm;  $vals[]='?'; $par[]= $adminId; }
          if ($payCAt){  $cols[]=$payCAt;  $vals[]='NOW()'; }

          $sql = "INSERT INTO $payT(".implode(',', $cols).") VALUES(".implode(',', $vals).")";
          $pdo->prepare($sql)->execute($par);
        }
      }

      // chiusura torneo (status compatibile + finalized_at + winner se unico)
      $setParts = []; $params   = [];

      if ($tSt !== 'NULL') {
        $cs = self::pickClosedStatusValue($pdo, $tT, $tSt);
        $setParts[] = "$tSt = " . $cs['expr'];
        if ($cs['param'] !== null) { $params[] = $cs['param']; }
      }
      if ($tFin !== 'NULL') { $setParts[] = "$tFin = NOW()"; }
      if ($tWin !== 'NULL' && count($winners) === 1) {
        $setParts[] = "$tWin = ?"; $params[] = intval($winners[0]);
      }

      if ($setParts) {
        $params[] = $tid;
        $sql = "UPDATE $tT SET " . implode(', ', $setParts) . " WHERE $tId = ?";
        $pdo->prepare($sql)->execute($params);
      }

      $pdo->commit();

      // winners (username+avatar)
      $in=implode(',', array_fill(0,count($winners),'?'));
      $WU=[];
      if ($winners){
        $sqlWU="SELECT $uId AS user_id, $uNm AS username".($uAv!=='NULL' ? ", $uAv AS avatar" : ", NULL AS avatar")."
                FROM $uT WHERE $uId IN ($in)";
        $st=$pdo->prepare($sqlWU); $st->execute($winners);
        foreach($st->fetchAll(\PDO::FETCH_ASSOC) as $r){ $WU[(int)$r['user_id']]=$r; }
      }

      $winnersOut=[];
      foreach($winners as $uidW){
        $winnersOut[]=[
          'user_id'=>$uidW,
          'username'=>$WU[$uidW]['username'] ?? ('user#'.$uidW),
          'avatar'=>$WU[$uidW]['avatar'] ?? null,
          'amount'=>$payouts[$uidW] ?? 0
        ];
      }

      $top10 = self::buildLeaderboard($pdo,$tid,$winners);

      return [
        'ok'=>true,
        'result'=>$resultType,
        'pool'=>round($pool,2),
        'winners'=>$winnersOut,
        'leaderboard_top10'=>$top10
      ];

    }catch(\Throwable $e){
      if($pdo->inTransaction()) $pdo->rollBack();
      return ['ok'=>false,'error'=>'finalize_failed','detail'=>$e->getMessage()];
    }
  }

  /* ================== Avviso utente (pop-up) ================== */

  public static function userNotice(\PDO $pdo, int $tid, int $uid): array {
    $M = self::mapTables($pdo);
    extract($M);

    $st=$pdo->prepare("SELECT ".($tSt!=='NULL'?"$tSt":"NULL")." AS status FROM $tT WHERE $tId=?");
    $st->execute([$tid]); $status=$st->fetchColumn();
    $state = self::statusLabel($status??null,null);
    if ($state!=='CHIUSO') return ['ok'=>true,'show'=>false];

    $winners=[];
    if ($payT){
      $st=$pdo->prepare("SELECT $payUid AS user_id, $payAmt AS amount FROM $payT WHERE $payTid=? ORDER BY $payAmt DESC");
      $st->execute([$tid]); $rows=$st->fetchAll(\PDO::FETCH_ASSOC);
      foreach($rows as $r){ $winners[(int)$r['user_id']] = (float)$r['amount']; }
    }
    if (!$winners){
      $alive = self::getAliveUsers($pdo,$tid,$M);
      if (count($alive)===1){ $winners[$alive[0]]=self::getEffectivePool($pdo,$tid,$M); }
      else {
        $lastRound = self::getLastRoundFromPicks($pdo,$tid,$M) ?? 1;
        $weights = self::getWeightsAtRound($pdo,$tid,$lastRound,$M);
        $winners = self::splitWithRemainder(self::getEffectivePool($pdo,$tid,$M), $weights);
      }
    }

    if (!$winners) return ['ok'=>true,'show'=>false];

    $uids = array_keys($winners);
    $in = implode(',', array_fill(0,count($uids),'?'));
    $sqlU="SELECT $uId AS user_id, $uNm AS username".($uAv!=='NULL'? ", $uAv AS avatar":", NULL AS avatar")."
           FROM $uT WHERE $uId IN ($in)";
    $x=$pdo->prepare($sqlU); $x->execute($uids);
    $U=[]; foreach($x->fetchAll(\PDO::FETCH_ASSOC) as $r){ $U[(int)$r['user_id']]=$r; }

    $winsOut=[];
    foreach($uids as $id){
      $winsOut[] = [
        'user_id'=>$id,
        'username'=>$U[$id]['username'] ?? ('user#'.$id),
        'avatar'=>$U[$id]['avatar'] ?? null,
        'amount'=>round((float)$winners[$id],2)
      ];
    }

    $isWinner = in_array($uid, $uids, true);
    $type = (count($uids)===1 ? 'king' : 'one_of_winners');

    $top10 = self::buildLeaderboard($pdo,$tid,$uids);

    return [
      'ok'=>true,
      'show'=>$isWinner,
      'type'=>$type,
      'winners'=>$winsOut,
      'leaderboard_top10'=>$top10
    ];
  }
}
