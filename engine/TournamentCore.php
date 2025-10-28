<?php
declare(strict_types=1);

/**
 * TournamentCore — motore unico del torneo (sigillo, riapertura, calcolo round, pubblicazione).
 *
 * Meccanismi di "sigillo" supportati (in ordine di priorità):
 *  1) Pick-level: colonna su tournament_picks (locked_at / sealed_at / confirmed_at / finalized_at / lock_at)
 *  2) Event-level: colonna su tournament_events (is_locked / locked / locked_flag)
 *  3) Tournament-level: tournaments.lock_at (countdown del round)
 *
 * Regole calcolo:
 *  - HOME  → sopravvive se pick su squadra di casa
 *  - AWAY  → sopravvive se pick su squadra in trasferta
 *  - DRAW  → la vita muore
 *  - VOID / POSTPONED / CANCELLED → la vita sopravvive (nessuna penalità)
 *  - UNKNOWN → blocco calcolo (results_missing)
 *  - Vita ALIVE senza pick SIGILLATA nel round → eliminata (out)
 *  - Doppie pick sigillate (stessa vita/round) → blocco con dettaglio ultima pick sigillata
 *  - Pick incoerente (team scelto non è né home né away) → blocco con dettaglio vita/utente/squadra/evento
 *  - Ricalcolo PRIMA della pubblicazione: reset idempotente degli effetti e ricalcolo completo in transazione
 *  - current_round si aggiorna SOLO in publishNextRound (non in computeRound)
 */

final class TournamentCore
{
    /* =======================
     *  Utilities & autodetect
     * ======================= */

    private static array $colCache = [];

    private static function columnExists(\PDO $pdo, string $table, string $col): bool {
      $k = $table.'.'.$col;
      if (array_key_exists($k, self::$colCache)) return self::$colCache[$k];
      $st=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
      $st->execute([$table,$col]);
      return self::$colCache[$k]=(bool)$st->fetchColumn();
    }

    private static function firstCol(\PDO $pdo, string $table, array $cands, string $fallback='NULL'): string {
      foreach ($cands as $c) if (self::columnExists($pdo,$table,$c)) return $c;
      return $fallback;
    }

    private static function pickColOrNull(\PDO $pdo, string $table, array $cands): ?string {
      foreach ($cands as $c) if (self::columnExists($pdo,$table,$c)) return $c;
      return null;
    }

    private static function genCode(int $len=10): string {
      $len = max(4, min(32, $len));
      return strtoupper(substr(bin2hex(random_bytes($len)), 0, $len));
    }

    /** Normalizza esito evento → HOME | AWAY | DRAW | VOID | UNKNOWN */
    private static function normalizeOutcome(?string $s): string {
      $s = strtoupper(trim((string)$s));
      if ($s==='') return 'UNKNOWN';
      $map = [
        'HOME' => ['HOME','CASA','H','1','WIN_HOME','HOME_WIN','HOST','LOCAL'],
        'AWAY' => ['AWAY','TRASFERTA','A','2','WIN_AWAY','AWAY_WIN','GUEST','VISITOR'],
        'DRAW' => ['DRAW','PAREGGIO','PARI','X','TIE','EVEN'],
        'VOID' => ['VOID','ANNULLATA','ANNULLATO','CANCELLED','CANCELED','POSTPONED','RINVIATA','RINVIATO','SOSPESA','SOSPESO','ABANDONED'],
        'UNKNOWN' => ['UNKNOWN','PENDING']
      ];
      foreach ($map as $k=>$arr) if (in_array($s,$arr,true)) return $k;
      return $s;
    }

    /** Ricava outcome evento compatibile con schemi diversi. */
    private static function detectEventOutcome(\PDO $pdo, string $eTable, array $e, string $homeIdCol, string $awayIdCol): string {
      // 1) Punteggi
      $homeScoreCol = self::pickColOrNull($pdo,$eTable, ['home_score','score_home','home_goals','goals_home','hs','sh']);
      $awayScoreCol = self::pickColOrNull($pdo,$eTable, ['away_score','score_away','away_goals','goals_away','as','sa']);
      if ($homeScoreCol && $awayScoreCol && isset($e[$homeScoreCol], $e[$awayScoreCol])
          && $e[$homeScoreCol]!==null && $e[$awayScoreCol]!==null && $e[$homeScoreCol]!=='' && $e[$awayScoreCol]!=='') {
        $h = (int)$e[$homeScoreCol]; $a=(int)$e[$awayScoreCol];
        if ($h>$a)  return 'HOME';
        if ($a>$h)  return 'AWAY';
        return 'DRAW';
      }

      // 2) Campo testuale risultato/outcome
      $resCol = self::pickColOrNull($pdo,$eTable, ['result','outcome','esito','winner','win_side','status']);
      if ($resCol && isset($e[$resCol]) && $e[$resCol]!==null && $e[$resCol]!=='') {
        $n = self::normalizeOutcome((string)$e[$resCol]);
        if (in_array($n, ['HOME','AWAY','DRAW','VOID','UNKNOWN'], true)) return $n;
      }

      // 3) winner_id = team id
      $winnerCol = self::pickColOrNull($pdo,$eTable, ['winner_team_id','team_winner_id','winner_id']);
      if ($winnerCol && !empty($e[$winnerCol])) {
        $w = (int)$e[$winnerCol];
        if ($w === (int)$e[$homeIdCol]) return 'HOME';
        if ($w === (int)$e[$awayIdCol]) return 'AWAY';
      }

      return 'UNKNOWN';
    }

    public static function resolveTournamentId(\PDO $pdo, int $id=0, ?string $code=null): int {
      $tT   = 'tournaments';
      $tId  = self::firstCol($pdo,$tT,['id'],'id');
      $tCode= self::firstCol($pdo,$tT,['code','tour_code','t_code','short_id'],'NULL');
      if ($id>0) return $id;
      if ($code && $tCode!=='NULL') {
        $st=$pdo->prepare("SELECT $tId FROM $tT WHERE UPPER($tCode)=UPPER(?) LIMIT 1");
        $st->execute([$code]);
        $x=$st->fetchColumn();
        return $x? (int)$x : 0;
      }
      return 0;
    }

    /* =======================
     *  Mappatura tabelle base
     * ======================= */

    private static function map(\PDO $pdo): array {
      $tT   = 'tournaments';
      $tId  = self::firstCol($pdo,$tT,['id'],'id');
      $tCR  = self::firstCol($pdo,$tT,['current_round','round_current','round'],'NULL');
      $tLock= self::firstCol($pdo,$tT,['lock_at','close_at','reg_close_at','subscription_end','start_time'],'NULL');

      $eT   = 'tournament_events';
      $eId  = self::firstCol($pdo,$eT,['id'],'id');
      $eTid = self::firstCol($pdo,$eT,['tournament_id','tid'],'tournament_id');
      $eRnd = self::firstCol($pdo,$eT,['round','rnd'],'round');
      $eHome= self::firstCol($pdo,$eT,['home_team_id','home_id','team_home_id'],'home_team_id');
      $eAway= self::firstCol($pdo,$eT,['away_team_id','away_id','team_away_id'],'away_team_id');
      $eCode= self::firstCol($pdo,$eT,['event_code','code'],'NULL');
      $eLock= self::firstCol($pdo,$eT,['is_locked','locked','locked_flag'],'NULL'); // lock per-evento

      $lT   = 'tournament_lives';
      $lId  = self::firstCol($pdo,$lT,['id'],'id');
      $lUid = self::firstCol($pdo,$lT,['user_id','uid'],'user_id');
      $lTid = self::firstCol($pdo,$lT,['tournament_id','tid'],'tournament_id');
      $lRnd = self::firstCol($pdo,$lT,['round','rnd'],'NULL');
      $lSt  = self::firstCol($pdo,$lT,['status','state'],'status'); // 'alive' / 'out'

      $pT   = 'tournament_picks';
      $pId  = self::firstCol($pdo,$pT,['id'],'id');
      $pLid = self::firstCol($pdo,$pT,['life_id','lid'],'life_id');
      $pTid = self::firstCol($pdo,$pT,['tournament_id','tid'],'NULL');
      $pEid = self::firstCol($pdo,$pT,['event_id','eid'],'event_id');
      $pRnd = self::firstCol($pdo,$pT,['round','rnd'],'round');
      $pTm  = self::firstCol($pdo,$pT,['team_id','choice','team_choice','pick_team_id','team','squadra_id','teamid','teamID'],'team_id');
      $pAt  = self::firstCol($pdo,$pT,['created_at','ts','inserted_at'],'NULL');
      $pLock= self::firstCol($pdo,$pT,['locked_at','sealed_at','confirmed_at','finalized_at','lock_at'],'NULL'); // pick-level lock
      $pCode= self::firstCol($pdo,$pT,['pick_code','code','pcode','token','uid'],'NULL');

      $uT   = 'users';
      $uId  = self::firstCol($pdo,$uT,['id'],'id');
      $uNm  = self::firstCol($pdo,$uT,['username','name','fullname','display_name'],'username');

      return compact(
        'tT','tId','tCR','tLock',
        'eT','eId','eTid','eRnd','eHome','eAway','eCode','eLock',
        'lT','lId','lUid','lTid','lRnd','lSt',
        'pT','pId','pLid','pTid','pEid','pRnd','pTm','pAt','pLock','pCode',
        'uT','uId','uNm'
      );
    }

    /* ========================
     *  Sigillo & riapertura
     * ======================== */

    public static function sealRoundPicks(\PDO $pdo, int $tournamentId, int $round): array {
      $m = self::map($pdo);

      // 1) pick-level lock
      if ($m['pLock']!=='NULL') {
        $whereTid = ($m['pTid']!=='NULL') ? "p.{$m['pTid']}=? AND " : "";
        $params   = ($m['pTid']!=='NULL') ? [$tournamentId,$round] : [$round];

        $sql = "SELECT p.{$m['pId']} AS id
                  FROM {$m['pT']} p
                 WHERE $whereTid p.{$m['pRnd']}=? AND (p.{$m['pLock']} IS NULL)";
        $st=$pdo->prepare($sql); $st->execute($params);
        $ids = array_map('intval',$st->fetchAll(\PDO::FETCH_COLUMN));
        if (!$ids) return ['ok'=>true,'mode'=>'pick_lock','sealed'=>0,'skipped'=>0,'codes'=>[]];

        $codes=[]; $sealed=0;
        $pdo->beginTransaction();
        try{
          foreach ($ids as $pid) {
            $code = self::genCode(10);
            if ($m['pCode']!=='NULL') {
              $u = $pdo->prepare("UPDATE {$m['pT']} SET {$m['pCode']}=?, {$m['pLock']}=NOW() WHERE {$m['pId']}=?");
              $u->execute([$code,$pid]);
            } else {
              $u = $pdo->prepare("UPDATE {$m['pT']} SET {$m['pLock']}=NOW() WHERE {$m['pId']}=?");
              $u->execute([$pid]);
            }
            $codes[$pid]=$code; $sealed++;
          }

          // --- BEGIN NormalLifeCyclePolicy (torneo normale) ---
          // Consolidamento/audit del ciclo vite sulle pick appena sigillate
          require_once __DIR__ . '/NormalLifeCyclePolicy.php';
          $__nlcp = new NormalLifeCyclePolicy($pdo);
          $__nlcp->onSealRound($tournamentId, $round);
          // --- END NormalLifeCyclePolicy ---

          $pdo->commit();
          return ['ok'=>true,'mode'=>'pick_lock','sealed'=>$sealed,'skipped'=>0,'codes'=>$codes];
        }catch(\Throwable $e){
          if($pdo->inTransaction()) $pdo->rollBack();
          return ['ok'=>false,'error'=>'seal_failed','detail'=>$e->getMessage()];
        }
      }

      // 2) event-level lock
      if ($m['eLock']!=='NULL') {
        try{
          $u=$pdo->prepare("UPDATE {$m['eT']} SET {$m['eLock']}=1 WHERE {$m['eTid']}=? AND {$m['eRnd']}=?");
          $u->execute([$tournamentId,$round]);
          return ['ok'=>true,'mode'=>'event_lock','events_locked'=>$u->rowCount()];
        }catch(\Throwable $e){
          return ['ok'=>false,'error'=>'seal_failed','detail'=>$e->getMessage()];
        }
      }

      // 3) tournament-level lock_at → chiusura immediata
      if ($m['tLock']!=='NULL') {
        try{
          $pdo->prepare("UPDATE {$m['tT']} SET {$m['tLock']}=NOW() WHERE {$m['tId']}=?")->execute([$tournamentId]);
          return ['ok'=>true,'mode'=>'tour_lock','lock_set'=>true];
        }catch(\Throwable $e){
          return ['ok'=>false,'error'=>'seal_failed','detail'=>$e->getMessage()];
        }
      }

      return ['ok'=>false,'error'=>'seal_column_missing','detail'=>'Nessuna colonna di sigillo disponibile (pick/event/tournament).'];
    }

    public static function reopenRoundPicks(\PDO $pdo, int $tournamentId, int $round): array {
      $m = self::map($pdo);

      // 1) pick-level lock
      if ($m['pLock']!=='NULL') {
        $whereTid = ($m['pTid']!=='NULL') ? "AND {$m['pTid']}=?" : "";
        $params   = ($m['pTid']!=='NULL') ? [$round,$tournamentId] : [$round];

        $pdo->beginTransaction();
        try{
          if ($m['pCode']!=='NULL') {
            $sql = "UPDATE {$m['pT']} SET {$m['pLock']}=NULL, {$m['pCode']}=NULL WHERE {$m['pRnd']}=? $whereTid";
          } else {
            $sql = "UPDATE {$m['pT']} SET {$m['pLock']}=NULL WHERE {$m['pRnd']}=? $whereTid";
          }
          $u=$pdo->prepare($sql); $u->execute($params);
          $n=$u->rowCount();
          $pdo->commit();
          return ['ok'=>true,'mode'=>'pick_lock','reopened'=>$n];
        }catch(\Throwable $e){
          if($pdo->inTransaction()) $pdo->rollBack();
          return ['ok'=>false,'error'=>'reopen_failed','detail'=>$e->getMessage()];
        }
      }

      // 2) event-level lock
      if ($m['eLock']!=='NULL') {
        try{
          $u=$pdo->prepare("UPDATE {$m['eT']} SET {$m['eLock']}=0 WHERE {$m['eTid']}=? AND {$m['eRnd']}=?");
          $u->execute([$tournamentId,$round]);
          return ['ok'=>true,'mode'=>'event_lock','events_unlocked'=>$u->rowCount()];
        }catch(\Throwable $e){
          return ['ok'=>false,'error'=>'reopen_failed','detail'=>$e->getMessage()];
        }
      }

      // 3) tournament-level lock_at → riapri = NULL
      if ($m['tLock']!=='NULL') {
        try{
          $pdo->prepare("UPDATE {$m['tT']} SET {$m['tLock']}=NULL WHERE {$m['tId']}=?")->execute([$tournamentId]);
          return ['ok'=>true,'mode'=>'tour_lock','lock_cleared'=>true];
        }catch(\Throwable $e){
          return ['ok'=>false,'error'=>'reopen_failed','detail'=>$e->getMessage()];
        }
      }

      return ['ok'=>false,'error'=>'seal_column_missing','detail'=>'Nessuna colonna di sigillo disponibile per riapertura (pick/event/tournament).'];
    }

    /* =================
     *  Calcolo del round
     * ================= */

    public static function computeRound(\PDO $pdo, int $tournamentId, int $round): array {
      $m = self::map($pdo);

      // Vietare ricalcolo se round già pubblicato (current_round > round)
      if ($m['tCR']!=='NULL') {
        $stCur=$pdo->prepare("SELECT COALESCE({$m['tCR']},1) FROM {$m['tT']} WHERE {$m['tId']}=? LIMIT 1");
        $stCur->execute([$tournamentId]);
        $cur=(int)$stCur->fetchColumn();
        if ($cur > $round) {
          return ['ok'=>false,'error'=>'round_already_published','detail'=>"Il round $round è già stato pubblicato (current_round=$cur)."];
        }
      }

      // Determina modalità di sigillo attiva
      $mode = 'tour_lock';
      if ($m['pLock']!=='NULL') $mode='pick_lock';
      elseif ($m['eLock']!=='NULL') $mode='event_lock';

      // Carica eventi del round
      $evq = $pdo->prepare("SELECT * FROM {$m['eT']} WHERE {$m['eTid']}=? AND {$m['eRnd']}=?");
      $evq->execute([$tournamentId,$round]);
      $events = $evq->fetchAll(\PDO::FETCH_ASSOC);
      if (!$events) return ['ok'=>false,'error'=>'no_events_for_round'];

      // Verifica risultati: blocca se ci sono UNKNOWN
      $unknownEvs = [];
      foreach ($events as $e) {
        $out = self::detectEventOutcome($pdo,$m['eT'],$e,$m['eHome'],$m['eAway']);
        if ($out==='UNKNOWN') {
          $code = $m['eCode']!=='NULL' ? (string)($e[$m['eCode']] ?? '') : ('#'.$e[$m['eId']]);
          $unknownEvs[] = $code ?: ('#'.$e[$m['eId']]);
        }
      }
      if ($unknownEvs) {
        return ['ok'=>false,'error'=>'results_missing','events'=>$unknownEvs];
      }

      // Se siamo in fallback "tour_lock", richiedi tournaments.lock_at presente e trascorso
      if ($mode==='tour_lock') {
        if ($m['tLock']==='NULL') {
          return ['ok'=>false,'error'=>'seal_backend_missing','detail'=>'Nessuna colonna di sigillo pick rilevata.'];
        }
        $st=$pdo->prepare("SELECT {$m['tLock']} FROM {$m['tT']} WHERE {$m['tId']}=?"); $st->execute([$tournamentId]);
        $lockIso=$st->fetchColumn();
        if (!$lockIso) return ['ok'=>false,'error'=>'lock_not_set','detail'=>'Imposta il lock o chiudi le scelte.'];
        if (time() < (int)strtotime((string)$lockIso)) {
          return ['ok'=>false,'error'=>'lock_not_reached','detail'=>'Il countdown non è ancora scaduto.'];
        }
      }

      // Query pick sigillate per il round
      $whereTid = ($m['pTid']!=='NULL') ? "p.{$m['pTid']}=? AND " : "";
      $params   = ($m['pTid']!=='NULL') ? [$tournamentId,$round] : [$round];

      if ($m['lRnd']!=='NULL') $params[] = $round;

      $sealedWhere = "1=1";
      if ($mode==='pick_lock')        $sealedWhere = "p.{$m['pLock']} IS NOT NULL";
      elseif ($mode==='event_lock')   $sealedWhere = "COALESCE(e.{$m['eLock']},0)=1";

      $selCols = "p.{$m['pId']} AS pick_id, p.{$m['pLid']} AS life_id, p.{$m['pEid']} AS event_id, p.{$m['pTm']} AS pick_val, ".
                 "l.{$m['lUid']} AS user_id, l.{$m['lId']} AS l_id, ".
                 "e.{$m['eHome']} AS home_id, e.{$m['eAway']} AS away_id".
                 ($m['pLock']!=='NULL' ? ", p.{$m['pLock']} AS sealed_at" : "");

      $sqlP = "SELECT $selCols
                 FROM {$m['pT']} p
                 JOIN {$m['lT']} l ON l.{$m['lId']}=p.{$m['pLid']}
                 JOIN {$m['eT']} e ON e.{$m['eId']}=p.{$m['pEid']}
                WHERE $whereTid p.{$m['pRnd']}=? AND $sealedWhere
                  AND LOWER(l.{$m['lSt']})='alive'".
               ($m['lRnd']!=='NULL' ? " AND l.{$m['lRnd']}=?" : "");

      $pq=$pdo->prepare($sqlP); $pq->execute($params);
      $picks = $pq->fetchAll(\PDO::FETCH_ASSOC);

      // Vite ALIVE attese al round R (solo se abbiamo colonna round sulle vite)
      $aliveAtR = [];
      if ($m['lRnd']!=='NULL') {
        $stAlive = $pdo->prepare("SELECT {$m['lId']} FROM {$m['lT']} WHERE {$m['lTid']}=? AND LOWER({$m['lSt']})='alive' AND {$m['lRnd']}=?");
        $stAlive->execute([$tournamentId,$round]);
        $aliveAtR = array_map('intval',$stAlive->fetchAll(\PDO::FETCH_COLUMN));
      }

      // Doppie pick sigillate per stessa vita
      if ($picks) {
        $byLife = [];
        foreach ($picks as $r) { $byLife[(int)$r['life_id']][] = $r; }
        $dups = array_filter($byLife, fn($arr)=>count($arr)>1);
        if ($dups) {
          $conflicts=[];
          foreach ($dups as $lid=>$arr) {
            usort($arr, function($a,$b){
              $ta = isset($a['sealed_at']) && $a['sealed_at'] ? strtotime((string)$a['sealed_at']) : 0;
              $tb = isset($b['sealed_at']) && $b['sealed_at'] ? strtotime((string)$b['sealed_at']) : 0;
              if ($ta!==$tb) return $tb-$ta;
              return ((int)$b['pick_id']) - ((int)$a['pick_id']);
            });
            $last=$arr[0];
            $conflicts[]=[
              'life_id'=>$lid,
              'user_id'=>(int)$last['user_id'],
              'last_pick_id'=>(int)$last['pick_id'],
              'total_picks'=>count($arr),
              'round'=>$round
            ];
          }
          return ['ok'=>false,'error'=>'duplicate_picks','detail'=>$conflicts];
        }
      }

      // Pick incoerenti (team non combacia con home/away)
      if ($picks) {
        foreach ($picks as $r) {
          $pv = $r['pick_val'];
          if (is_numeric($pv)) {
            $teamId=(int)$pv;
            $home=(int)$r['home_id']; $away=(int)$r['away_id'];
            if ($teamId!==$home && $teamId!==$away) {
              $uInfo = self::getUserInfo($pdo,(int)$r['user_id'],$m);
              $teamName = self::teamName($pdo,$teamId);
              return [
                'ok'=>false,
                'error'=>'invalid_pick_team',
                'detail'=>[
                  'life_id'=>(int)$r['life_id'],
                  'user_id'=>(int)$r['user_id'],
                  'username'=>$uInfo['username'] ?? null,
                  'picked_team_id'=>$teamId,
                  'picked_team_name'=>$teamName,
                  'event'=>[
                    'event_id'=>(int)$r['event_id'],
                    'home_id'=>$home,'away_id'=>$away
                  ]
                ]
              ];
            }
          }
        }
      }

      // Confronto vite con pick vs senza pick → quelle senza pick vengono OUT
      $lifeWithPick = $picks ? array_values(array_unique(array_map(fn($r)=>(int)$r['life_id'],$picks))) : [];
      $lifeNoPick   = [];
      if ($m['lRnd']!=='NULL' && $aliveAtR) {
        $setWithPick = array_fill_keys($lifeWithPick,true);
        foreach ($aliveAtR as $lid) if (!isset($setWithPick[$lid])) $lifeNoPick[]=(int)$lid;
      }

      // Reset idempotente + calcolo
      $pdo->beginTransaction();
      try{
        $involved = array_values(array_unique(array_merge($lifeWithPick, $lifeNoPick)));
        if ($involved) {
          $in = implode(',', array_fill(0,count($involved),'?'));
          if ($m['lRnd']!=='NULL') {
            $pdo->prepare("UPDATE {$m['lT']} SET {$m['lSt']}='alive', {$m['lRnd']}=? WHERE {$m['lId']} IN ($in)")
                ->execute(array_merge([$round], $involved));
          } else {
            $pdo->prepare("UPDATE {$m['lT']} SET {$m['lSt']}='alive' WHERE {$m['lId']} IN ($in)")
                ->execute($involved);
          }
        }

        $pass = []; $out = [];

        if ($picks) {
          $evById = [];
          foreach ($events as $e) { $evById[(int)$e[$m['eId']]] = $e; }

          foreach ($picks as $r) {
            $ev = $evById[(int)$r['event_id']] ?? null;
            if (!$ev) continue;

            $outcome = self::detectEventOutcome($pdo,$m['eT'],$ev,$m['eHome'],$m['eAway']); // HOME/AWAY/DRAW/VOID
            if ($outcome==='UNKNOWN') throw new \RuntimeException('Internal outcome unknown after validation');

            $rawPick = $r['pick_val'];
            $homeId  = (int)$r['home_id'];
            $awayId  = (int)$r['away_id'];
            $lifeId  = (int)$r['life_id'];

            $isWin = false;
            if (is_numeric($pv = $rawPick)) {
              $teamId=(int)$pv;
              $isWin = ($outcome==='HOME' && $teamId===$homeId) || ($outcome==='AWAY' && $teamId===$awayId);
            } else {
              $side = strtoupper(trim((string)$rawPick));
              $isWin = ($outcome==='HOME' && $side==='HOME') || ($outcome==='AWAY' && $side==='AWAY');
            }

            $isDraw = ($outcome==='DRAW');
            $isVoid = ($outcome==='VOID');

            if ($isWin || $isVoid) $pass[]=$lifeId;
            elseif ($isDraw || !$isWin) $out[]=$lifeId;
          }
        }

        if ($lifeNoPick) $out = array_values(array_unique(array_merge($out,$lifeNoPick)));

        if ($out) {
          $out = array_values(array_unique($out));
          $in = implode(',', array_fill(0,count($out),'?'));
          $pdo->prepare("UPDATE {$m['lT']} SET {$m['lSt']}='out' WHERE {$m['lId']} IN ($in)")->execute($out);
        }

        if ($pass) {
          $pass = array_values(array_unique($pass));
          $in = implode(',', array_fill(0,count($pass),'?'));
          if ($m['lRnd']!=='NULL') {
            $pdo->prepare("UPDATE {$m['lT']} SET {$m['lRnd']}={$m['lRnd']}+1, {$m['lSt']}='alive' WHERE {$m['lId']} IN ($in)")->execute($pass);
          } else {
            $pdo->prepare("UPDATE {$m['lT']} SET {$m['lSt']}='alive' WHERE {$m['lId']} IN ($in)")->execute($pass);
          }
        }

        $stAliveUsers = $pdo->prepare("SELECT COUNT(DISTINCT {$m['lUid']}) FROM {$m['lT']} WHERE {$m['lTid']}=? AND LOWER({$m['lSt']})='alive'");
        $stAliveUsers->execute([$tournamentId]);
        $aliveUsers = (int)$stAliveUsers->fetchColumn();

        $pdo->commit();

        return [
          'ok'=>true,
          'sealed_mode'=>$mode,
          'passed'=>count($pass),
          'out'=>count($out),
          'next_round'=>$round+1,
          'alive_users'=>$aliveUsers,
          'needs_finalize'=> ($aliveUsers < 2)
        ];
      }catch(\Throwable $e){
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok'=>false,'error'=>'calc_failed','detail'=>$e->getMessage()];
      }
    }

    /* ===========================
     *  Pubblicazione round R+1
     * =========================== */

    public static function publishNextRound(\PDO $pdo, int $tournamentId, int $round): array {
      $m = self::map($pdo);
      if ($m['tCR']==='NULL') {
        try{
          if ($m['tLock']!=='NULL') {
            $pdo->prepare("UPDATE {$m['tT']} SET {$m['tLock']}=NULL WHERE {$m['tId']}=?")->execute([$tournamentId]);
          }
          return ['ok'=>true,'current_round'=>null];
        }catch(\Throwable $e){
          return ['ok'=>false,'error'=>'publish_failed','detail'=>$e->getMessage()];
        }
      }

      $pdo->beginTransaction();
      try{
        $nx = $round + 1;
        $sql = "UPDATE {$m['tT']} SET {$m['tCR']} = GREATEST(COALESCE({$m['tCR']},1), ?)";
        if ($m['tLock']!=='NULL') $sql .= ", {$m['tLock']}=NULL";
        $sql .= " WHERE {$m['tId']}=?";
        $pdo->prepare($sql)->execute([$nx, $tournamentId]);

        $st = $pdo->prepare("SELECT {$m['tCR']} FROM {$m['tT']} WHERE {$m['tId']}=? LIMIT 1");
        $st->execute([$tournamentId]);
        $cur = (int)$st->fetchColumn();

        $pdo->commit();
        return ['ok'=>true,'current_round'=>$cur];
      }catch(\Throwable $e){
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok'=>false,'error'=>'publish_failed','detail'=>$e->getMessage()];
      }
    }

    /* =====================
     *  Helpers di contesto
     * ===================== */

    private static function teamName(\PDO $pdo, int $teamId): ?string {
      if ($teamId<=0) return null;
      try{
        $st=$pdo->prepare("SELECT name FROM teams WHERE id=? LIMIT 1");
        $st->execute([$teamId]);
        $n=$st->fetchColumn();
        return ($n!==false && $n!=='') ? (string)$n : null;
      }catch(\Throwable $e){ return null; }
    }

    private static function getUserInfo(\PDO $pdo, int $userId, array $m): array {
      try{
        $st=$pdo->prepare("SELECT {$m['uId']} AS id, {$m['uNm']} AS username FROM {$m['uT']} WHERE {$m['uId']}=? LIMIT 1");
        $st->execute([$userId]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: [];
      }catch(\Throwable $e){ return []; }
    }

    /* ===========================
     *  VALIDAZIONE PICK (corretta)
     * =========================== */

    public static function validatePick(\PDO $pdo, int $tournamentId, int $lifeId, int $round, int $teamId, int $eventId = 0): array
    {
      $m = self::map($pdo);

      // Vita appartenente al torneo e viva
      $cols = "{$m['lId']} AS id".($m['lSt']!=='NULL' ? ", {$m['lSt']} AS state" : "");
      $st = $pdo->prepare("SELECT $cols FROM {$m['lT']} WHERE {$m['lId']}=? AND {$m['lTid']}=? LIMIT 1");
      $st->execute([$lifeId,$tournamentId]);
      $life = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
      if (!$life) return ['ok'=>false,'reason'=>'life_not_found','msg'=>'Vita non trovata'];
      if ($m['lSt']!=='NULL' && isset($life['state']) && strtolower((string)$life['state'])!=='alive') {
        return ['ok'=>false,'reason'=>'life_not_alive','msg'=>'Vita non in gioco'];
      }

      // Evento: se specificato usa quello, altrimenti cerca (home=team OR away=team) nel round
      $ev = null;
      $colsEv = "{$m['eId']} AS id, {$m['eHome']} AS home, {$m['eAway']} AS away"
              . ($m['eLock']!=='NULL' ? ", {$m['eLock']} AS is_locked" : ", NULL AS is_locked");

      if ($eventId > 0) {
        $q = $pdo->prepare("SELECT $colsEv FROM {$m['eT']} WHERE {$m['eTid']}=? AND {$m['eRnd']}=? AND {$m['eId']}=? LIMIT 1");
        $q->execute([$tournamentId,$round,$eventId]);
        $ev = $q->fetch(\PDO::FETCH_ASSOC) ?: null;
      }
      if (!$ev) {
        $q = $pdo->prepare("SELECT $colsEv FROM {$m['eT']} WHERE {$m['eTid']}=? AND {$m['eRnd']}=? AND ({$m['eHome']}=? OR {$m['eAway']}=?) LIMIT 1");
        $q->execute([$tournamentId,$round,$teamId,$teamId]);
        $ev = $q->fetch(\PDO::FETCH_ASSOC) ?: null;
      }
      if (!$ev) return ['ok'=>false,'reason'=>'no_event','msg'=>'Nessun evento per questo round'];

      // Lock evento o lock torneo
      if ($m['eLock']!=='NULL' && (int)($ev['is_locked'] ?? 0) === 1) {
        return ['ok'=>false,'reason'=>'locked','msg'=>'Round bloccato'];
      }
      if ($m['tLock']!=='NULL') {
        $s=$pdo->prepare("SELECT {$m['tLock']} FROM {$m['tT']} WHERE {$m['tId']}=? LIMIT 1");
        $s->execute([$tournamentId]); $lockIso=$s->fetchColumn();
        if ($lockIso && $lockIso!=='0000-00-00 00:00:00' && strtotime((string)$lockIso) <= time()) {
          return ['ok'=>false,'reason'=>'locked','msg'=>'Round bloccato'];
        }
      }

      // Team deve appartenere proprio a questo evento
      $home = (int)$ev['home']; $away=(int)$ev['away'];
      if ($teamId !== $home && $teamId !== $away) {
        return ['ok'=>false,'reason'=>'team_not_in_event','msg'=>'Squadra non parte di questo evento'];
      }

      // --- BEGIN NormalLifeCyclePolicy (torneo normale) ---
      // Enforce: no-repeat nel ciclo principale + sottociclo controllato
      require_once __DIR__ . '/NormalLifeCyclePolicy.php';
      $__nlcp = new NormalLifeCyclePolicy($pdo);
      $__res = $__nlcp->validateUnique($tournamentId, $lifeId, $round, $teamId);
      if (!$__res['ok']) {
        // Mappo su chiavi esistenti ('reason', 'msg') senza toccare il resto
        return [
          'ok'    => false,
          'reason'=> (string)($__res['code'] ?? 'life_cycle_policy'),
          'msg'   => (string)($__res['message'] ?? 'Regola ciclo vite')
        ];
      }
      // --- END NormalLifeCyclePolicy ---

      return ['ok'=>true];
    }
}
