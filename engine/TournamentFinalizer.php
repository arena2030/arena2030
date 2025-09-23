<?php
declare(strict_types=1);

/**
 * TournamentFinalizer — chiusura torneo (payout, update stato, classifica, avvisi).
 */

final class TournamentFinalizer
{
    /* ===== mapping dinamico ===== */
    protected static function firstCol(\PDO $pdo, string $table, array $cands, string $fallback='NULL'): string {
      foreach ($cands as $c) {
        $st=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
        $st->execute([$table,$c]);
        if ($st->fetchColumn()) return $c;
      }
      return $fallback;
    }

    protected static function mapTables(\PDO $pdo): array {
      // tournaments
      $tT  = 'tournaments';
      $tId = self::firstCol($pdo,$tT,['id'],'id');
      $tCR = self::firstCol($pdo,$tT,['current_round','round_current','round'],'NULL');
      $tSt = self::firstCol($pdo,$tT,['status','state'],'NULL');
      $tFin= self::firstCol($pdo,$tT,['finalized_at','ended_at'],'NULL');
      $tLock= self::firstCol($pdo,$tT,['lock_at','close_at'],'NULL');

      // events
      $eT  = 'tournament_events';
      $eId = self::firstCol($pdo,$eT,['id'],'id');
      $eTid= self::firstCol($pdo,$eT,['tournament_id','tid'],'tournament_id');
      $eRnd= self::firstCol($pdo,$eT,['round','rnd'],'round');
      $eHome=self::firstCol($pdo,$eT,['home_team_id','home_id'],'home_team_id');
      $eAway=self::firstCol($pdo,$eT,['away_team_id','away_id'],'away_team_id');
      $eRes=self::firstCol($pdo,$eT,['result','outcome','status'],'result');

      // lives
      $lT  = 'tournament_lives';
      $lId = self::firstCol($pdo,$lT,['id'],'id');
      $lUid= self::firstCol($pdo,$lT,['user_id','uid'],'user_id');
      $lTid= self::firstCol($pdo,$lT,['tournament_id','tid'],'tournament_id');
      $lRnd= self::firstCol($pdo,$lT,['round','rnd'],'NULL');
      $lSt = self::firstCol($pdo,$lT,['status','state'],'NULL');

      // picks
      $pT  = 'tournament_picks';
      $pId = self::firstCol($pdo,$pT,['id'],'id');
      $pLid= self::firstCol($pdo,$pT,['life_id','lid'],'life_id');
      $pTid= self::firstCol($pdo,$pT,['tournament_id','tid'],'NULL');
      $pEid= self::firstCol($pdo,$pT,['event_id','eid'],'event_id');
      $pRnd= self::firstCol($pdo,$pT,['round','rnd'],'round');
      $pTm = self::firstCol($pdo,$pT,['team_id','pick_team_id','choice'],'team_id');

      // users
      $uT  = 'users';
      $uId = self::firstCol($pdo,$uT,['id'],'id');
      $uNm = self::firstCol($pdo,$uT,['username','name','fullname','display_name'],'username');
      $uAv = self::firstCol($pdo,$uT,['avatar','avatar_url','img'],'NULL');
      $uCoins = self::firstCol($pdo,$uT,['coins','balance','credits'],'coins');

      // log (opzionale)
      $logT = null; $logTx='NULL'; $logAdmin='NULL'; $logNote='NULL'; $logAmt='NULL'; $logUser='NULL';
      $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='points_balance_log'");
      $q->execute(); if ($q->fetchColumn()) {
        $logT = 'points_balance_log';
        $logTx   = self::firstCol($pdo,$logT,['tx_code','code','uid'],'NULL');
        $logAdmin= self::firstCol($pdo,$logT,['admin_id','by_admin'],'NULL');
        $logNote = self::firstCol($pdo,$logT,['note','notes','description'],'NULL');
        $logAmt  = self::firstCol($pdo,$logT,['amount','delta','value'],'amount');
        $logUser = self::firstCol($pdo,$logT,['user_id','uid'],'user_id');
      }

      // payout (opzionale)
      $payT = null; $payUid='NULL'; $payAmt='NULL'; $payTid='NULL';
      $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tournament_payouts'");
      $q->execute(); if ($q->fetchColumn()) {
        $payT  = 'tournament_payouts';
        $payUid= self::firstCol($pdo,$payT,['user_id','uid'],'user_id');
        $payAmt= self::firstCol($pdo,$payT,['amount','value'],'amount');
        $payTid= self::firstCol($pdo,$payT,['tournament_id','tid'],'tournament_id');
      }

      return compact(
        'tT','tId','tCR','tSt','tFin','tLock',
        'eT','eId','eTid','eRnd','eHome','eAway','eRes',
        'lT','lId','lUid','lTid','lRnd','lSt',
        'pT','pId','pLid','pTid','pEid','pRnd','pTm',
        'uT','uId','uNm','uAv','uCoins',
        'logT','logTx','logAdmin','logNote','logAmt','logUser',
        'payT','payUid','payAmt','payTid'
      );
    }

    public static function resolveTournamentId(\PDO $pdo, int $id=0, ?string $code=null): int {
      $tT='tournaments'; $tId=self::firstCol($pdo,$tT,['id'],'id');
      $tCode=self::firstCol($pdo,$tT,['code','tour_code','t_code','short_id'],'NULL');
      if ($id>0) return $id;
      if ($code && $tCode!=='NULL') {
        $st=$pdo->prepare("SELECT {$tId} FROM {$tT} WHERE UPPER({$tCode})=UPPER(?) LIMIT 1");
        $st->execute([$code]); $x=$st->fetchColumn();
        return $x? (int)$x : 0;
      }
      return 0;
    }

    /* =========================
     *  Pre-check fine torneo
     * ========================= */
    public static function shouldEndTournament(\PDO $pdo, int $tournamentId): array {
      $M = self::mapTables($pdo);
      // utenti vivi
      $sql = "SELECT COUNT(DISTINCT {$M['lUid']}) FROM {$M['lT']} WHERE {$M['lTid']}=? ".
             ($M['lSt']!=='NULL' ? "AND LOWER({$M['lSt']})='alive' " : "").
             ($M['lRnd']!=='NULL' ? "" : "");
      $st=$pdo->prepare($sql); $st->execute([$tournamentId]); $alive=(int)$st->fetchColumn();
      $round = 1;
      if ($M['tCR']!=='NULL') {
        $z=$pdo->prepare("SELECT COALESCE({$M['tCR']},1) FROM {$M['tT']} WHERE {$M['tId']}=? LIMIT 1");
        $z->execute([$tournamentId]); $round=(int)$z->fetchColumn();
      }
      return [
        'should_end'=> ($alive<2),
        'reason'    => ($alive<2 ? 'alive_users_lt_2' : 'alive_users_ge_2'),
        'alive_users'=> $alive,
        'round'     => $round
      ];
    }

    /* =========================
     *  Finalizzazione
     * ========================= */
    public static function finalizeTournament(\PDO $pdo, int $tournamentId, int $adminId): array {
      $M=self::mapTables($pdo);

      // lock riga torneo
      $pdo->beginTransaction();
      try{
        $st=$pdo->prepare("SELECT * FROM {$M['tT']} WHERE {$M['tId']}=? FOR UPDATE");
        $st->execute([$tournamentId]); $tour=$st->fetch(\PDO::FETCH_ASSOC);
        if (!$tour) { $pdo->rollBack(); return ['ok'=>false,'error'=>'tournament_not_found']; }

        // check utenti vivi
        $chk = self::shouldEndTournament($pdo,$tournamentId);
        if (!$chk['should_end']) {
          $pdo->commit(); // non facciamo nulla
          return ['ok'=>false,'error'=>'not_final','detail'=>'alive_users_ge_2','alive_users'=>$chk['alive_users']];
        }

        // determina pool (se presente colonna nel torneo; fallback 0)
        $pool = 0.0;
        if (array_key_exists('prize_pool',$tour)) $pool = (float)$tour['prize_pool'];
        elseif (array_key_exists('pool',$tour))    $pool = (float)$tour['pool'];

        // trova vincitori (utenti vivi)
        $sqlWin = "SELECT DISTINCT {$M['lUid']} AS user_id FROM {$M['lT']} WHERE {$M['lTid']}=? ".
                  ($M['lSt']!=='NULL' ? "AND LOWER({$M['lSt']})='alive'" : "");
        $stW=$pdo->prepare($sqlWin); $stW->execute([$tournamentId]);
        $winnerIds = array_map('intval',$stW->fetchAll(\PDO::FETCH_COLUMN));
        sort($winnerIds);
        $nW = count($winnerIds);

        // calcolo payout pro‑quota se pool>0
        $winnerRows = [];
        if ($nW>0 && $pool>0) {
          $quota = $pool / $nW;
          // accrediti
          foreach ($winnerIds as $uid) {
            // update saldo
            $pdo->prepare("UPDATE {$M['uT']} SET {$M['uCoins']} = {$M['uCoins']} + ? WHERE {$M['uId']}=?")
                ->execute([$quota,$uid]);

            // log opzionale
            if (!empty($M['logT'])) {
              $cols = []; $vals = []; $ph = [];

              $cols[] = $M['logUser'];  $ph[]='?'; $vals[]=$uid;
              $cols[] = $M['logAmt'];   $ph[]='?'; $vals[]=$quota;
              if ($M['logAdmin']!=='NULL') { $cols[]=$M['logAdmin']; $ph[]='?'; $vals[]=$adminId; }
              if ($M['logNote']!=='NULL')  { $cols[]=$M['logNote'];  $ph[]='?'; $vals[]='Payout torneo #'.$tournamentId; }
              if ($M['logTx']!=='NULL')    { $cols[]=$M['logTx'];    $ph[]='?'; $vals[]=strtoupper(substr(bin2hex(random_bytes(8)),0,12)); }

              $sql = "INSERT INTO {$M['logT']} (".implode(',',$cols).") VALUES (".implode(',',$ph).")";
              $pdo->prepare($sql)->execute($vals);
            }

            // payout table opzionale
            if (!empty($M['payT'])) {
              $pdo->prepare("INSERT INTO {$M['payT']} ({$M['payTid']},{$M['payUid']},{$M['payAmt']}) VALUES (?,?,?)")
                  ->execute([$tournamentId,$uid,$quota]);
            }

            $winnerRows[] = ['user_id'=>$uid,'amount'=>$quota];
          }
        }

        // chiusura torneo
        $upd = "UPDATE {$M['tT']} SET ";
        $set = [];
        if ($M['tSt']!=='NULL')  $set[]="{$M['tSt']}='FINISHED'";
        if ($M['tFin']!=='NULL') $set[]="{$M['tFin']}=NOW()";
        if ($M['tLock']!=='NULL')$set[]="{$M['tLock']}=NULL";
        $upd .= implode(',', $set)." WHERE {$M['tId']}=?";
        $pdo->prepare($upd)->execute([$tournamentId]);

        $pdo->commit();

        // arricchisci con info winners (username/avatar)
        $winners = [];
        if ($winnerRows) {
          $in = implode(',', array_fill(0,count($winnerRows),'?'));
          $ids = array_column($winnerRows,'user_id');
          $stU=$pdo->prepare("SELECT {$M['uId']} AS user_id, {$M['uNm']} AS username".
                              ($M['uAv']!=='NULL' ? ", {$M['uAv']} AS avatar" : "").
                              " FROM {$M['uT']} WHERE {$M['uId']} IN ($in)");
          $stU->execute($ids);
          $info = [];
          foreach ($stU->fetchAll(\PDO::FETCH_ASSOC) as $r) $info[(int)$r['user_id']]=$r;

          foreach ($winnerRows as $w) {
            $uid=$w['user_id']; $row=['user_id'=>$uid,'amount'=>$w['amount']];
            if (isset($info[$uid])) {
              $row['username']=$info[$uid]['username'] ?? null;
              if ($M['uAv']!=='NULL') $row['avatar']=$info[$uid]['avatar'] ?? null;
            }
            $winners[]=$row;
          }
        }

        return [
          'ok'=>true,
          'result'=>'finished',
          'pool'=>$pool,
          'winners'=>$winners
        ];
      }catch(\Throwable $e){
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok'=>false,'error'=>'finalize_failed','detail'=>$e->getMessage()];
      }
    }

    /* =====================
     *  Leaderboard / Notice
     * ===================== */
    public static function buildLeaderboard(\PDO $pdo, int $tournamentId, array $winnerIds=[]): array {
      $M=self::mapTables($pdo);
      // base: utenti vivi, poi eventualmente out
      $sql = "SELECT l.{$M['lUid']} AS user_id, u.{$M['uNm']} AS username".
             ($M['uAv']!=='NULL' ? ", u.{$M['uAv']} AS avatar" : "").
             " FROM {$M['lT']} l JOIN {$M['uT']} u ON u.{$M['uId']}=l.{$M['lUid']}".
             " WHERE l.{$M['lTid']}=? ".
             ($M['lSt']!=='NULL' ? "ORDER BY (LOWER(l.{$M['lSt']})='alive') DESC, l.{$M['lId']} ASC" : "ORDER BY l.{$M['lId']} ASC").
             " LIMIT 100";
      $st=$pdo->prepare($sql); $st->execute([$tournamentId]);
      $rows=$st->fetchAll(\PDO::FETCH_ASSOC);

      // porta eventuali winners in testa
      if ($winnerIds) {
        $set=array_fill_keys($winnerIds,true);
        usort($rows, function($a,$b) use ($set){
          $aw = isset($set[(int)$a['user_id']]) ? 1 : 0;
          $bw = isset($set[(int)$b['user_id']]) ? 1 : 0;
          if ($aw!==$bw) return $bw - $aw;
          return strcmp((string)$a['username'], (string)$b['username']);
        });
      }

      return array_slice($rows,0,10);
    }

    public static function userNotice(\PDO $pdo, int $tournamentId, int $userId): array {
      $M=self::mapTables($pdo);
      // è winner?
      $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tournament_payouts'");
      $q->execute(); $has = (bool)$q->fetchColumn();
      if (!$has) return ['ok'=>true,'is_winner'=>false];

      $st=$pdo->prepare("SELECT {$M['payAmt']} AS amount FROM {$M['payT']} WHERE {$M['payTid']}=? AND {$M['payUid']}=? LIMIT 1");
      $st->execute([$tournamentId,$userId]);
      $amt = $st->fetchColumn();
      if ($amt===false) return ['ok'=>true,'is_winner'=>false];

      $u=$pdo->prepare("SELECT {$M['uNm']} AS username".($M['uAv']!=='NULL' ? ", {$M['uAv']} AS avatar" : "")." FROM {$M['uT']} WHERE {$M['uId']}=? LIMIT 1");
      $u->execute([$userId]); $info=$u->fetch(\PDO::FETCH_ASSOC) ?: [];

      return [
        'ok'=>true,
        'is_winner'=>true,
        'amount'=>(float)$amt,
        'username'=>$info['username'] ?? null,
        'avatar'=> $info['avatar'] ?? null
      ];
    }
}
