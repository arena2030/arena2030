<?php
declare(strict_types=1);

/**
 * TournamentCore — motore unico del torneo (sigillo, calcolo round, pubblicazione round successivo).
 *
 * Requisiti implementati:
 *  - Si calcolano SOLO le pick "sigillate" del round R (autodetect colonna di sigillo).
 *  - Se esiste anche un solo evento del round con risultato UNKNOWN → blocco calcolo (results_missing).
 *  - Se una vita "alive" non ha alcuna pick sigillata al round R → viene eliminata (status='out').
 *  - Se una pick sigillata ha team_id che non è né HOME né AWAY per l'evento → blocco calcolo con errore dettagliato.
 *  - Se esistono doppie pick sigillate per la stessa vita/round → blocco calcolo con errore dettagliato (indicando l'ultima).
 *  - Ricalcolo PRIMA della pubblicazione:
 *      * resetta gli effetti del round R per le vite coinvolte (riporta a alive + round=R),
 *      * ricalcola da zero in un'unica transazione.
 *  - Non aggiorna current_round automaticamente; verrà aggiornato solo su "publish_next_round".
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

    private static function normalizeOutcome(?string $s): string {
      $s = strtoupper(trim((string)$s));
      if ($s==='') return 'UNKNOWN';
      // sinonimi in varie lingue
      $map = [
        'HOME' => ['HOME','CASA','H','1','WIN_HOME','HOME_WIN','HOST','LOCAL'],
        'AWAY' => ['AWAY','TRASFERTA','A','2','WIN_AWAY','AWAY_WIN','GUEST','VISITOR'],
        'DRAW' => ['DRAW','PAREGGIO','PARI','X','TIE','EVEN'],
        'VOID' => ['VOID','ANNULLATA','ANNULLATO','CANCELLED','CANCELED','POSTPONED','RINVIATA','RINVIATO','SOSPESA','SOSPESO','ABANDONED'],
        'UNKNOWN' => ['UNKNOWN','PENDING']
      ];
      foreach ($map as $k=>$arr) if (in_array($s,$arr,true)) return $k;
      return $s; // eventualmente già HOME/AWAY/DRAW/VOID
    }

    /** Outcome da riga evento, con supporto a più schemi (punteggi, outcome stringa, winner_team_id). */
    private static function detectEventOutcome(\PDO $pdo, string $eTable, array $e, string $homeIdCol, string $awayIdCol): string {
      // 1) punteggi
      $homeScoreCol = self::pickColOrNull($pdo,$eTable, ['home_score','score_home','home_goals','goals_home','hs','sh']);
      $awayScoreCol = self::pickColOrNull($pdo,$eTable, ['away_score','score_away','away_goals','goals_away','as','sa']);
      if ($homeScoreCol && $awayScoreCol && isset($e[$homeScoreCol], $e[$awayScoreCol])
          && $e[$homeScoreCol]!==null && $e[$awayScoreCol]!==null && $e[$homeScoreCol]!=='' && $e[$awayScoreCol]!=='') {
        $h = (int)$e[$homeScoreCol]; $a=(int)$e[$awayScoreCol];
        if ($h>$a)  return 'HOME';
        if ($a>$h)  return 'AWAY';
        return 'DRAW';
      }

      // 2) outcome testuale
      $resCol = self::pickColOrNull($pdo,$eTable, ['result','outcome','esito','winner','win_side','status']);
      if ($resCol && isset($e[$resCol]) && $e[$resCol]!==null && $e[$resCol]!=='') {
        $n = self::normalizeOutcome((string)$e[$resCol]);
        if (in_array($n, ['HOME','AWAY','DRAW','VOID','UNKNOWN'], true)) return $n;
      }

      // 3) winner_id
      $winnerCol = self::pickColOrNull($pdo,$eTable, ['winner_team_id','team_winner_id','winner_id']);
      if ($winnerCol && isset($e[$winnerCol]) && $e[$winnerCol]!==null && $e[$winnerCol]!=='') {
        $w = (int)$e[$winnerCol];
        if ($w === (int)$e[$homeIdCol]) return 'HOME';
        if ($w === (int)$e[$awayIdCol]) return 'AWAY';
      }

      return 'UNKNOWN';
    }

    /** Risolve ID torneo da id numerico o code (case-insensitive). */
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
     *  Mappature tabelle base
     * ======================= */

    private static function map(\PDO $pdo): array {
      $tT   = 'tournaments';
      $tId  = self::firstCol($pdo,$tT,['id'],'id');
      $tCode= self::firstCol($pdo,$tT,['code','tour_code','t_code','short_id'],'NULL');
      $tCR  = self::firstCol($pdo,$tT,['current_round','round_current','round'],'NULL'); // attenzione: usato solo su publish
      $tLock= self::firstCol($pdo,$tT,['lock_at','close_at','reg_close_at','subscription_end','start_time'],'NULL');

      $eT   = 'tournament_events';
      $eId  = self::firstCol($pdo,$eT,['id'],'id');
      $eTid = self::firstCol($pdo,$eT,['tournament_id','tid'],'tournament_id');
      $eRnd = self::firstCol($pdo,$eT,['round','rnd'],'round');
      $eHome= self::firstCol($pdo,$eT,['home_team_id','home_id','team_home_id'],'home_team_id');
      $eAway= self::firstCol($pdo,$eT,['away_team_id','away_id','team_away_id'],'away_team_id');
      $eCode= self::firstCol($pdo,$eT,['event_code','code'],'NULL');

      $lT   = 'tournament_lives';
      $lId  = self::firstCol($pdo,$lT,['id'],'id');
      $lUid = self::firstCol($pdo,$lT,['user_id','uid'],'user_id');
      $lTid = self::firstCol($pdo,$lT,['tournament_id','tid'],'tournament_id');
      $lRnd = self::firstCol($pdo,$lT,['round','rnd'],'NULL'); // opzionale ma raccomandato
      $lSt  = self::firstCol($pdo,$lT,['status','state'],'status'); // 'alive'/'out'

      $pT   = 'tournament_picks';
      $pId  = self::firstCol($pdo,$pT,['id'],'id');
      $pLid = self::firstCol($pdo,$pT,['life_id','lid'],'life_id');
      $pTid = self::firstCol($pdo,$pT,['tournament_id','tid'],'NULL');
      $pEid = self::firstCol($pdo,$pT,['event_id','eid'],'event_id');
      $pRnd = self::firstCol($pdo,$pT,['round','rnd'],'round');
      $pTm  = self::firstCol($pdo,$pT,['team_id','choice','team_choice','pick_team_id','team','squadra_id','teamid','teamID'],'team_id');
      $pAt  = self::firstCol($pdo,$pT,['created_at','ts','inserted_at'],'NULL');
      $pLock= self::firstCol($pdo,$pT,['locked_at','sealed_at','confirmed_at','finalized_at','lock_at'],'NULL');
      $pCode= self::firstCol($pdo,$pT,['pick_code','code','pcode','token','uid'],'NULL');

      $uT   = 'users';
      $uId  = self::firstCol($pdo,$uT,['id'],'id');
      $uNm  = self::firstCol($pdo,$uT,['username','name','fullname','display_name'],'username');

      return compact('tT','tId','tCode','tCR','tLock',
                     'eT','eId','eTid','eRnd','eHome','eAway','eCode',
                     'lT','lId','lUid','lTid','lRnd','lSt',
                     'pT','pId','pLid','pTid','pEid','pRnd','pTm','pAt','pLock','pCode',
                     'uT','uId','uNm');
    }

    /* ========================
     *  Sigillo & ri-apertura
     * ======================== */

    /** Sigilla tutte le pick del torneo/round: set pLock=NOW() e assegna pCode (se colonna esiste). */
    public static function sealRoundPicks(\PDO $pdo, int $tournamentId, int $round): array {
      $m = self::map($pdo);
      if ($m['pLock']==='NULL') return ['ok'=>false,'error'=>'seal_column_missing'];

      // pick non sigillate per round (limitato al torneo se c'è pTid)
      $whereTid = ($m['pTid']!=='NULL') ? "p.$m[pTid]=? AND " : "";
      $params   = ($m['pTid']!=='NULL') ? [$tournamentId,$round] : [$round];

      $sql = "SELECT p.$m[pId] AS id
                FROM $m[pT] p
               WHERE $whereTid p.$m[pRnd]=? AND (p.$m[pLock] IS NULL)";
      $st=$pdo->prepare($sql); $st->execute($params);
      $ids = array_map('intval',$st->fetchAll(\PDO::FETCH_COLUMN));

      if (!$ids) return ['ok'=>true,'sealed'=>0,'skipped'=>0,'codes'=>[]];

      $codes=[]; $sealed=0;
      $pdo->beginTransaction();
      try{
        foreach ($ids as $pid) {
          $code = self::genCode(10);
          if ($m['pCode']!=='NULL') {
            $u = $pdo->prepare("UPDATE $m[pT] SET $m[pCode]=?, $m[pLock]=NOW() WHERE $m[pId]=?");
            $u->execute([$code,$pid]);
          } else {
            $u = $pdo->prepare("UPDATE $m[pT] SET $m[pLock]=NOW() WHERE $m[pId]=?");
            $u->execute([$pid]);
          }
          $codes[$pid]=$code; $sealed++;
        }
        $pdo->commit();
      }catch(\Throwable $e){
        if($pdo->inTransaction()) $pdo->rollBack();
        return ['ok'=>false,'error'=>'seal_failed','detail'=>$e->getMessage()];
      }
      return ['ok'=>true,'sealed'=>$sealed,'skipped'=>0,'codes'=>$codes];
    }

    /** Riapre (annulla sigillo) per tutte le pick del torneo/round corrente: pLock=NULL (+ svuota pCode se presente). */
    public static function reopenRoundPicks(\PDO $pdo, int $tournamentId, int $round): array {
      $m = self::map($pdo);
      if ($m['pLock']==='NULL') return ['ok'=>false,'error'=>'seal_column_missing'];

      $whereTid = ($m['pTid']!=='NULL') ? "AND $m[pTid]=?" : "";
      $params   = ($m['pTid']!=='NULL') ? [$round,$tournamentId] : [$round];

      $pdo->beginTransaction();
      try{
        if ($m['pCode']!=='NULL') {
          $sql = "UPDATE $m[pT] SET $m[pLock]=NULL, $m[pCode]=NULL WHERE $m[pRnd]=? $whereTid";
        } else {
          $sql = "UPDATE $m[pT] SET $m[pLock]=NULL WHERE $m[pRnd]=? $whereTid";
        }
        $u=$pdo->prepare($sql); $u->execute($params);
        $n = $u->rowCount();
        $pdo->commit();
        return ['ok'=>true,'reopened'=>$n];
      }catch(\Throwable $e){
        if($pdo->inTransaction()) $pdo->rollBack();
        return ['ok'=>false,'error'=>'reopen_failed','detail'=>$e->getMessage()];
      }
    }

    /* =================
     *  Calcolo del round
     * ================= */

    /**
     * Calcolo unico del round R:
     * - verifica eventi completi (no UNKNOWN),
     * - elimina vite alive senza pick sigillata al R,
     * - controlla coerenze (team scelto parte dell'evento, no doppie pick),
     * - ricalcolo idempotente (reset vite coinvolte → calcolo),
     * - ritorna contatori + needs_finalize.
     */
    public static function computeRound(\PDO $pdo, int $tournamentId, int $round): array {
      $m = self::map($pdo);

      // Se current_round è già > round, non è più consentito ricalcolare
      if ($m['tCR']!=='NULL') {
        $cur = (int)($pdo->query("SELECT COALESCE($m[tCR],1) FROM $m[tT] WHERE $m[tId]=".(int)$tournamentId." LIMIT 1")->fetchColumn() ?: 1);
        if ($cur > $round) {
          return ['ok'=>false,'error'=>'round_already_published','detail'=>"Il round $round è già stato pubblicato (current_round=$cur)."];
        }
      }

      if ($m['pLock']==='NULL') {
        return ['ok'=>false,'error'=>'seal_column_missing','detail'=>'Nessuna colonna di sigillo pick rilevata.'];
      }

      // 1) Eventi del round e verifica risultati completi
      $evSql = "SELECT * FROM $m[eT] WHERE $m[eTid]=? AND $m[eRnd]=?";
      $evq = $pdo->prepare($evSql); $evq->execute([$tournamentId,$round]);
      $events = $evq->fetchAll(\PDO::FETCH_ASSOC);
      if (!$events) return ['ok'=>false,'error'=>'no_events_for_round'];

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

      // 2) Pick sigillate del round (join con vite e utenti, per controlli e dettagli)
      $whereTid = ($m['pTid']!=='NULL') ? "p.$m[pTid]=? AND " : "";
      $params   = ($m['pTid']!=='NULL') ? [$tournamentId,$round] : [$round];

      $selCols = "p.$m[pId] AS pick_id, p.$m[pLid] AS life_id, p.$m[pEid] AS event_id, p.$m[pTm] AS pick_val, ".
                 "p.$m[pLock] AS sealed_at, ".
                 "l.$m[lUid] AS user_id, l.$m[lId] AS l_id, ".
                 "e.$m[eHome] AS home_id, e.$m[eAway] AS away_id";
      $sqlP = "SELECT $selCols
                 FROM $m[pT] p
                 JOIN $m[lT] l ON l.$m[lId]=p.$m[pLid]
                 JOIN $m[eT] e ON e.$m[eId]=p.$m[pEid]
                WHERE $whereTid p.$m[pRnd]=? AND p.$m[pLock] IS NOT NULL
                  AND LOWER(l.$m[lSt])='alive'".
               ($m['lRnd']!=='NULL' ? " AND l.$m[lRnd]=?" : "");
      if ($m['lRnd']!=='NULL') $params[] = $round;

      $pq=$pdo->prepare($sqlP); $pq->execute($params);
      $picks = $pq->fetchAll(\PDO::FETCH_ASSOC);

      // 3) Vite alive a cui avremmo chiesto la pick al round R (serve lRnd per regola "senza pick = out")
      $aliveAtR = [];
      if ($m['lRnd']!=='NULL') {
        $stAlive = $pdo->prepare("SELECT $m[lId] FROM $m[lT] WHERE $m[lTid]=? AND LOWER($m[lSt])='alive' AND $m[lRnd]=?");
        $stAlive->execute([$tournamentId,$round]);
        $aliveAtR = array_map('intval',$stAlive->fetchAll(\PDO::FETCH_COLUMN));
      } else {
        // Se manca la colonna round nelle vite, non possiamo applicare la regola "senza pick = out"
        // Segnaliamo comunque un warning non bloccante.
        $aliveAtR = [];
      }

      // 4) Controllo doppie pick per stessa vita/round
      if ($picks) {
        $byLife = [];
        foreach ($picks as $r) {
          $lid=(int)$r['life_id'];
          $byLife[$lid][] = $r;
        }
        $dups = array_filter($byLife, fn($arr)=>count($arr)>1);
        if ($dups) {
          // costruisci dettaglio errore
          $conflicts=[];
          foreach ($dups as $lid=>$arr) {
            usort($arr, function($a,$b){
              $ta = $a['sealed_at'] ? strtotime((string)$a['sealed_at']) : 0;
              $tb = $b['sealed_at'] ? strtotime((string)$b['sealed_at']) : 0;
              if ($ta!==$tb) return $tb-$ta; // più recente prima
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

      // 5) Controllo coerenza team scelto ∈ {home,away} (solo se pick_val è numerico)
      if ($picks) {
        foreach ($picks as $r) {
          $pv = $r['pick_val'];
          if (is_numeric($pv)) {
            $teamId=(int)$pv;
            $home=(int)$r['home_id']; $away=(int)$r['away_id'];
            if ($teamId!==$home && $teamId!==$away) {
              // recupera username e nome squadra (se disponibile)
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

      // 6) Preparazione insiemi: chi ha pick sigillata e chi no
      $lifeWithPick = $picks ? array_values(array_unique(array_map(fn($r)=>(int)$r['life_id'],$picks))) : [];
      $lifeNoPick   = [];
      if ($m['lRnd']!=='NULL') {
        // vite alive al round R che non compaiono in $lifeWithPick → eliminate
        $setWithPick = array_fill_keys($lifeWithPick,true);
        foreach ($aliveAtR as $lid) if (!isset($setWithPick[$lid])) $lifeNoPick[]=(int)$lid;
      }

      // 7) Reset idempotente delle vite coinvolte (solo vite del round R interessate)
      $involved = array_values(array_unique(array_merge($lifeWithPick, $lifeNoPick)));
      $pdo->beginTransaction();
      try{
        if ($involved) {
          $in = implode(',', array_fill(0,count($involved),'?'));
          // Riporta a alive e round=R (se lRnd esiste)
          if ($m['lRnd']!=='NULL') {
            $pdo->prepare("UPDATE $m[lT] SET $m[lSt]='alive', $m[lRnd]=? WHERE $m[lId] IN ($in)")
                ->execute(array_merge([$round], $involved));
          } else {
            $pdo->prepare("UPDATE $m[lT] SET $m[lSt]='alive' WHERE $m[lId] IN ($in)")
                ->execute($involved);
          }
        }

        // 8) Applica calcolo (per pick sigillate)
        $pass = []; $out = [];

        if ($picks) {
          // Precarica eventi (tutti già presi sopra ma senza indice): creiamo mappa event_id-> outcome
          $evById = [];
          foreach ($events as $e) {
            $evById[(int)$e[$m['eId']]] = $e;
          }

          foreach ($picks as $r) {
            $ev = $evById[(int)$r['event_id']] ?? null;
            if (!$ev) continue; // non dovrebbe accadere

            $outcome = self::detectEventOutcome($pdo,$m['eT'],$ev,$m['eHome'],$m['eAway']); // HOME/AWAY/DRAW/VOID
            if ($outcome==='UNKNOWN') { // sicurezza extra (non dovrebbe capitare dopo check)
              throw new \RuntimeException('Internal outcome unknown after validation');
            }

            $rawPick = $r['pick_val'];
            $homeId  = (int)$r['home_id'];
            $awayId  = (int)$r['away_id'];
            $lifeId  = (int)$r['life_id'];

            $isWin = false;
            if (is_numeric($rawPick)) {
              $teamId=(int)$rawPick;
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

        // Aggiungi vite senza pick all'insieme out
        if ($lifeNoPick) $out = array_values(array_unique(array_merge($out,$lifeNoPick)));

        // Batch update: out
        if ($out) {
          $out = array_values(array_unique($out));
          $in = implode(',', array_fill(0,count($out),'?'));
          $pdo->prepare("UPDATE $m[lT] SET $m[lSt]='out' WHERE $m[lId] IN ($in)")->execute($out);
        }

        // Batch update: pass → round+1 e status alive
        if ($pass) {
          $pass = array_values(array_unique($pass));
          $in = implode(',', array_fill(0,count($pass),'?'));
          if ($m['lRnd']!=='NULL') {
            $pdo->prepare("UPDATE $m[lT] SET $m[lRnd]=$m[lRnd]+1, $m[lSt]='alive' WHERE $m[lId] IN ($in)")->execute($pass);
          } else {
            $pdo->prepare("UPDATE $m[lT] SET $m[lSt]='alive' WHERE $m[lId] IN ($in)")->execute($pass);
          }
        }

        // Conteggio utenti vivi dopo il calcolo
        $aliveUsers = (int)$pdo->prepare("SELECT COUNT(DISTINCT $m[lUid]) FROM $m[lT] WHERE $m[lTid]=? AND LOWER($m[lSt])='alive'")
                               ->execute([$tournamentId])->fetchColumn();

        $pdo->commit();

        return [
          'ok'=>true,
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
        // se manca current_round, aggiorniamo solo lock_at
        try{
          if ($m['tLock']!=='NULL') {
            $pdo->prepare("UPDATE $m[tT] SET $m[tLock]=NULL WHERE $m[tId]=?")->execute([$tournamentId]);
          }
          return ['ok'=>true,'current_round'=>null];
        }catch(\Throwable $e){
          return ['ok'=>false,'error'=>'publish_failed','detail'=>$e->getMessage()];
        }
      }

      $pdo->beginTransaction();
      try{
        $nx = $round + 1;
        $pdo->prepare("UPDATE $m[tT] SET $m[tCR] = GREATEST(COALESCE($m[tCR],1), ?)" . ($m['tLock']!=='NULL' ? ", $m[tLock]=NULL" : "") . " WHERE $m[tId]=?")
            ->execute([$nx, $tournamentId]);
        $cur = (int)$pdo->query("SELECT $m[tCR] FROM $m[tT] WHERE $m[tId]=".(int)$tournamentId." LIMIT 1")->fetchColumn();
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
      // assumiamo tabella teams(id,name) come nel pannello admin
      try{
        $st=$pdo->prepare("SELECT name FROM teams WHERE id=? LIMIT 1");
        $st->execute([$teamId]);
        $n=$st->fetchColumn();
        return ($n!==false && $n!=='') ? (string)$n : null;
      }catch(\Throwable $e){ return null; }
    }

    private static function getUserInfo(\PDO $pdo, int $userId, array $m): array {
      try{
        $st=$pdo->prepare("SELECT $m[uId] AS id, $m[uNm] AS username FROM $m[uT] WHERE $m[uId]=? LIMIT 1");
        $st->execute([$userId]);
        $r=$st->fetch(\PDO::FETCH_ASSOC) ?: [];
        return $r;
      }catch(\Throwable $e){ return []; }
    }
}
