<?php
declare(strict_types=1);

/**
 * TournamentCore — motore del torneo (vite, scelte, lock, calcolo round).
 *
 * Obiettivi:
 *  - Validazione scelte con vincolo "una vita non può scegliere la stessa squadra nello stesso ciclo principale",
 *    con eccezioni quando non esistono squadre nuove disponibili in quel round (ma in quel caso NON si può ripetere
 *    la stessa squadra del round precedente).
 *  - Seal/lock: allo scadere del lock del round, le pick vengono "sigillate" e viene assegnato un codice univoco
 *    che collega vita+round+scelta.
 *  - Calcolo round: l'admin valuta i risultati evento → le vite con pick vincente o evento annullato/posticipato
 *    sopravvivono e passano al round successivo; altrimenti la vita viene persa.
 *
 * Il tutto è scritto per essere "a prova di bomba" e non richiedere migrazioni:
 *   - autodetect nomi tabelle/colonne (alias comuni),
 *   - feature-flag su colonne opzionali (se mancano, le si ignora).
 */
final class TournamentCore
{
    /* =======================
     *  Utilities & autodetect
     * ======================= */

    /** Verifica se esiste una colonna. Caches by (table.column). */
    private static array $colCache = [];

    private static function columnExists(\PDO $pdo, string $table, string $col): bool {
      $k = $table.'.'.$col;
      if (array_key_exists($k, self::$colCache)) return self::$colCache[$k];
      $st=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
      $st->execute([$table,$col]);
      return self::$colCache[$k]=(bool)$st->fetchColumn();
    }

    /** Ritorna la prima colonna esistente fra i candidati, altrimenti $fallback (default NULL literal). */
    private static function firstCol(\PDO $pdo, string $table, array $cands, string $fallback='NULL'): string {
      foreach ($cands as $c) if (self::columnExists($pdo,$table,$c)) return $c;
      return $fallback;
    }

    /** Come firstCol ma ritorna null se nessuna trovata. */
    private static function pickColOrNull(\PDO $pdo, string $table, array $cands): ?string {
      foreach ($cands as $c) if (self::columnExists($pdo,$table,$c)) return $c;
      return null;
    }

    /** Genera codice alfanumerico upper-case, lunghezza $len (4..32) */
    private static function genCode(int $len=10): string {
      $len = max(4, min(32, $len));
      return strtoupper(substr(bin2hex(random_bytes($len)), 0, $len));
    }

    /** Normalizza string outcome → 'home','away','draw','void','unknown' */
    private static function normalizeOutcome(?string $s): string {
      $s = strtolower(trim((string)$s));
      if ($s==='') return 'unknown';
      // sinonimi
      $map = [
        'home' => ['home','casa','1','h','win_home','home_win','host','local'],
        'away' => ['away','trasferta','2','a','win_away','away_win','guest','visitor'],
        'draw' => ['x','draw','pari','tie','even','pareggio'],
        'void' => ['void','annullato','annullata','cancelled','canceled','postponed','rinviato','rinviata','sospeso','sospesa','abandoned']
      ];
      foreach ($map as $k=>$arr) if (in_array($s,$arr,true)) return $k;
      return $s;
    }

    /** Outcome da riga evento, con supporto a più schemi */
    private static function detectEventOutcome(\PDO $pdo, string $eTable, array $e, string $homeIdCol, string $awayIdCol): string {
      // 1) punteggi?
      $homeScoreCol = self::pickColOrNull($pdo,$eTable, ['home_score','score_home','home_goals','goals_home','hs','sh']);
      $awayScoreCol = self::pickColOrNull($pdo,$eTable, ['away_score','score_away','away_goals','goals_away','as','sa']);
      if ($homeScoreCol && $awayScoreCol && isset($e[$homeScoreCol], $e[$awayScoreCol])
          && $e[$homeScoreCol]!==null && $e[$awayScoreCol]!==null && $e[$homeScoreCol]!=='' && $e[$awayScoreCol]!=='') {
        $h = (int)$e[$homeScoreCol]; $a=(int)$e[$awayScoreCol];
        if ($h>$a)  return 'home';
        if ($a>$h)  return 'away';
        return 'draw';
      }

      // 2) campo di outcome testuale?
      $resCol = self::pickColOrNull($pdo,$eTable, ['outcome','result','esito','winner','win_side','status']);
      if ($resCol && isset($e[$resCol]) && $e[$resCol]!==null && $e[$resCol]!=='') {
        $n = self::normalizeOutcome((string)$e[$resCol]);
        if (in_array($n, ['home','away','draw','void'], true)) return $n;
      }

      // 3) winner_id?
      $winnerCol = self::pickColOrNull($pdo,$eTable, ['winner_team_id','team_winner_id','winner_id']);
      if ($winnerCol && isset($e[$winnerCol]) && $e[$winnerCol]!==null && $e[$winnerCol]!=='') {
        $w = (int)$e[$winnerCol];
        if ($w === (int)$e[$homeIdCol]) return 'home';
        if ($w === (int)$e[$awayIdCol]) return 'away';
      }

      // Se non si capisce, consideriamo 'unknown' (verrà ignorato nel compute fino a quando l'admin non popola i risultati)
      return 'unknown';
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
      $tBuy = self::firstCol($pdo,$tT,['buyin_coins','buyin'],'0');
      $tCR  = self::firstCol($pdo,$tT,['current_round','round_current','rnd_cur'],'NULL');
      $tLock= self::firstCol($pdo,$tT,['lock_at','close_at','reg_close_at','subscription_end','start_time'],'NULL');

      $eT   = 'tournament_events';
      $eId  = self::firstCol($pdo,$eT,['id'],'id');
      $eTid = self::firstCol($pdo,$eT,['tournament_id','tid'],'tournament_id');
      $eRnd = self::firstCol($pdo,$eT,['round','rnd'],'round');
      $eHome= self::firstCol($pdo,$eT,['home_team_id','home_id','team_home_id'],'home_team_id');
      $eAway= self::firstCol($pdo,$eT,['away_team_id','away_id','team_away_id'],'away_team_id');

      $lT   = 'tournament_lives';
      $lId  = self::firstCol($pdo,$lT,['id'],'id');
      $lUid = self::firstCol($pdo,$lT,['user_id','uid'],'user_id');
      $lTid = self::firstCol($pdo,$lT,['tournament_id','tid'],'tournament_id');
      $lRnd = self::firstCol($pdo,$lT,['round','rnd'],'NULL'); // opzionale
      $lSt  = self::firstCol($pdo,$lT,['status','state'],'NULL'); // alive/lost

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

      return compact('tT','tId','tCode','tBuy','tCR','tLock',
                     'eT','eId','eTid','eRnd','eHome','eAway',
                     'lT','lId','lUid','lTid','lRnd','lSt',
                     'pT','pId','pLid','pTid','pEid','pRnd','pTm','pAt','pLock','pCode');
    }

    /* ===========================================
     *  Insiemi squadre del torneo e "disponibili"
     * =========================================== */

    /** Tutte le squadre che compaiono in almeno un evento del torneo (insieme base del “ciclo”). */
    public static function getAllTeamsForTournament(\PDO $pdo, int $tournamentId): array {
      $m = self::map($pdo);
      $sql = "SELECT DISTINCT x.team_id FROM (
                SELECT $m[eHome] AS team_id FROM $m[eT] WHERE $m[eTid]=?
                UNION
                SELECT $m[eAway] AS team_id FROM $m[eT] WHERE $m[eTid]=?
              ) x WHERE x.team_id IS NOT NULL";
      $st=$pdo->prepare($sql); $st->execute([$tournamentId,$tournamentId]);
      $out=[]; while($r=$st->fetch(\PDO::FETCH_ASSOC)){ $out[]=(int)$r['team_id']; }
      sort($out);
      return $out;
    }

    /** Squadre “scegliibili” nel round dato: coppie home/away degli eventi di quel round (niente logiche di lock per-team). */
    public static function getAvailableTeamsForRound(\PDO $pdo, int $tournamentId, int $round): array {
      $m = self::map($pdo);
      $sql = "SELECT DISTINCT x.team_id FROM (
                SELECT $m[eHome] AS team_id FROM $m[eT] WHERE $m[eTid]=? AND $m[eRnd]=?
                UNION
                SELECT $m[eAway] AS team_id FROM $m[eT] WHERE $m[eTid]=? AND $m[eRnd]=?
              ) x WHERE x.team_id IS NOT NULL";
      $st=$pdo->prepare($sql); $st->execute([$tournamentId,$round,$tournamentId,$round]);
      $out=[]; while($r=$st->fetch(\PDO::FETCH_ASSOC)){ $out[]=(int)$r['team_id']; }
      sort($out);
      return $out;
    }

    /* ====================================================
     *  Ricostruzione “ciclo principale” corrente della vita
     * ==================================================== */

    /**
     * Ritorna lo stato del ciclo corrente per una vita:
     * - used_in_cycle: set (array) delle squadre già scelte nel ciclo in corso
     * - last_pick_team: team_id dell’ultima pick (o null)
     * - cycle_completed_count: numero di cicli principali completati sinora
     *
     * Algoritmo: scorre le pick della vita in ordine cronologico; resetta l’insieme ogni volta che
     * l’insieme delle “scelte distinte” copre tutte le squadre del torneo.
     */
    public static function getLifeCycleState(\PDO $pdo, int $tournamentId, int $lifeId): array {
      $m = self::map($pdo);
      $allTeams = self::getAllTeamsForTournament($pdo, $tournamentId);
      $universe = array_fill_keys($allTeams, true);
      $needCount= count($universe);

      $sql = "SELECT $m[pTm] AS team_id
                FROM $m[pT]
               WHERE $m[pLid]=? ".($m['pTid']!=='NULL'?" AND $m[pTid]=?":'')."
               ORDER BY ".($m['pAt']!=='NULL' ? $m['pAt'].',' : '')."$m[pId] ASC";
      $st = $pdo->prepare($sql);
      $st->execute($m['pTid']!=='NULL' ? [$lifeId,$tournamentId] : [$lifeId]);

      $used = [];
      $usedSet = [];
      $cycles = 0;
      $lastTeam = null;

      while ($r = $st->fetch(\PDO::FETCH_ASSOC)) {
        $teamId = (int)($r['team_id'] ?? 0);
        if ($teamId<=0) continue;
        $lastTeam = $teamId;

        // registra nel set corrente
        if (isset($universe[$teamId])) {
          $usedSet[$teamId] = true;
        }

        // se abbiamo coperto tutto l’universo, chiudiamo un ciclo e ripartiamo
        if ($needCount>0 && count($usedSet) === $needCount) {
          $cycles++;
          $usedSet = []; // reset ciclo
        }
      }

      $used = array_map('intval', array_keys($usedSet));
      sort($used);

      return [
        'used_in_cycle'      => $used,
        'last_pick_team'     => $lastTeam ? (int)$lastTeam : null,
        'cycle_completed_count' => $cycles,
      ];
    }

    /* ===============================
     *  Validazione di una scelta (pick)
     * =============================== */

    /**
     * Regole:
     * 1) Se esiste almeno una squadra “nuova” (non ancora scelta in questo ciclo) tra le disponibili a round,
     *    l’utente DEVE sceglierne una nuova ⇒ non è permesso ripetere una già scelta nel ciclo.
     * 2) Se NON esistono squadre nuove disponibili, è consentito scegliere una squadra già scelta,
     *    ma NON la stessa della pick del round precedente.
     */
    public static function validatePick(\PDO $pdo, int $tournamentId, int $lifeId, int $round, int $teamId): array {
      $allTeams = self::getAllTeamsForTournament($pdo,$tournamentId);
      if (!in_array($teamId, $allTeams, true)) {
        return ['ok'=>false,'reason'=>'team_not_in_tournament','msg'=>'Squadra non fa parte del torneo.'];
      }

      $avail = self::getAvailableTeamsForRound($pdo,$tournamentId,$round);

      if (!in_array($teamId, $avail, true)) {
        return ['ok'=>false,'reason'=>'team_not_available','msg'=>'Questa squadra non è disponibile in questo round.'];
      }

      $stCycle = self::getLifeCycleState($pdo,$tournamentId,$lifeId);
      $used    = $stCycle['used_in_cycle'] ?? [];
      $usedSet = array_fill_keys($used, true);
      $last    = $stCycle['last_pick_team'] ?? null;

      // fresh pickable = disponibili - già usate nel ciclo
      $fresh = array_values(array_diff($avail, $used));

      if (count($fresh)>0) {
        // Ci sono squadre nuove disponibili: DEVE scegliere una di queste
        if (!in_array($teamId, $fresh, true)) {
          return [
            'ok'=>false,
            'reason'=>'must_pick_fresh_team',
            'msg'=>'Devi scegliere una squadra non ancora selezionata in questo ciclo.',
            'fresh_pickable'=>$fresh
          ];
        }
        return ['ok'=>true, 'reason'=>'fresh_ok'];
      }

      // Non ci sono squadre nuove disponibili → eccezione: può ripetere, ma non la stessa del round precedente
      if ($last !== null && $teamId === (int)$last) {
        return [
          'ok'=>false,
          'reason'=>'cannot_repeat_immediately',
          'msg'=>'Non puoi scegliere la stessa squadra del round precedente quando non ci sono squadre nuove.',
        ];
      }

      return ['ok'=>true, 'reason'=>'repeat_allowed_due_to_unavailability'];
    }

    /* ========================
     *  Seal / lock delle pick
     * ======================== */

    /**
     * Sigilla le pick del torneo/round:
     * - imposta locked_at (o equivalente) se la colonna esiste,
     * - assegna un codice univoco alla pick (se esiste colonna code/pick_code/…),
     * - ritorna statistiche.
     */
    public static function sealRoundPicks(\PDO $pdo, int $tournamentId, int $round): array {
      $m = self::map($pdo);

      // Prendi tutte le pick del round che NON sono ancora sealed (se colonna disponibile)
      $whereNotSealed = ($m['pLock']!=='NULL') ? " AND $m[pLock] IS NULL" : "";
      $sql = "SELECT $m[pId] AS id FROM $m[pT]
               WHERE ".($m['pTid']!=='NULL' ? "$m[pTid]=? AND " : "")."$m[pRnd]=? $whereNotSealed";
      $st = $pdo->prepare($sql);
      $st->execute($m['pTid']!=='NULL' ? [$tournamentId,$round] : [$round]);
      $ids=[]; while($r=$st->fetch(\PDO::FETCH_ASSOC)){ $ids[]=(int)$r['id']; }

      if (!count($ids)) {
        return ['ok'=>true,'sealed'=>0,'skipped'=>0,'codes'=>[]];
      }

      $codes = [];
      $sealed = 0;

      $pdo->beginTransaction();
      try {
        foreach ($ids as $pid) {
          $code = self::genCode(10);

          if ($m['pCode']!=='NULL') {
            // setta pick_code se disponibile
            $u = $pdo->prepare("UPDATE $m[pT] SET $m[pCode]=? ".($m['pLock']!=='NULL' ? ", $m[pLock]=NOW()" : "")." WHERE $m[pId]=?");
            $u->execute([$code,$pid]);
          } else if ($m['pLock']!=='NULL') {
            // almeno sealed timestamp
            $u = $pdo->prepare("UPDATE $m[pT] SET $m[pLock]=NOW() WHERE $m[pId]=?");
            $u->execute([$pid]);
          } // altrimenti nessun campo da aggiornare — ma va bene lo stesso

          $codes[$pid] = $code;
          $sealed++;
        }
        $pdo->commit();
      } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok'=>false,'error'=>'seal_failed','detail'=>$e->getMessage()];
      }

      return ['ok'=>true,'sealed'=>$sealed,'skipped'=>0,'codes'=>$codes];
    }

    /* =================
     *  Calcolo del round
     * ================= */

    /**
     * Calcola il risultato per tutte le pick sealed del round:
     * - outcome evento: home/away/draw/void/unknown
     * - regola: se la squadra scelta VINCE → vita sopravvive (passa round+1 se colonna round sulle vite esiste);
     *           se DRAW o PERDE → vita marcata persa;
     *           se VOID (postponed/annulled) → vita sopravvive (nessuna penalità).
     * Nota: se l’evento è "unknown" non tocca nulla (l’admin ripete quando i risultati sono presenti).
     */
    public static function computeRound(\PDO $pdo, int $tournamentId, int $round): array {
      $m = self::map($pdo);

      // Join pick + evento per conoscere home/away + outcome
      $cols = "$m[pT].$m[pId] AS pick_id, $m[pT].$m[pLid] AS life_id, $m[pT].$m[pTm] AS team_id,
               $m[eT].$m[eId] AS event_id, $m[eT].$m[eHome] AS home_id, $m[eT].$m[eAway] AS away_id";
      $sql = "SELECT $cols
                FROM $m[pT]
                JOIN $m[eT] ON $m[eT].$m[eId] = $m[pT].$m[pEid]
               WHERE ".($m['pTid']!=='NULL' ? "$m[pT].$m[pTid]=? AND " : "")."$m[pT].$m[pRnd]=?"
                 .(($m['pLock']!=='NULL') ? " AND $m[pT].$m[pLock] IS NOT NULL" : ""); // calcoliamo solo pick “sigillate”
      $st = $pdo->prepare($sql);
      $st->execute($m['pTid']!=='NULL' ? [$tournamentId,$round] : [$round]);

      $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
      if (!count($rows)) {
        return ['ok'=>true,'updated'=>0,'notes'=>'No sealed picks to compute'];
      }

      // carichiamo tutte le colonne dell’evento per dedurre outcome con i vari schemi
      $eidList = array_values(array_unique(array_map(fn($r)=>(int)$r['event_id'],$rows)));
      $in = implode(',', array_fill(0,count($eidList),'?'));
      $evq = $pdo->prepare("SELECT * FROM $m[eT] WHERE $m[eId] IN ($in)");
      $evq->execute($eidList);
      $evById = [];
      while ($e=$evq->fetch(\PDO::FETCH_ASSOC)) { $evById[(int)$e[$m['eId']]] = $e; }

      // colonne opzionali per aggiornare vite
      $hasLround = ($m['lRnd']!=='NULL');
      $lStatusCol= ($m['lSt']!=='NULL') ? $m['lSt'] : null;

      $updated = 0;
      $survivors = 0;
      $losts = 0;

      $pdo->beginTransaction();
      try {
        foreach ($rows as $r) {
          $ev = $evById[(int)$r['event_id']] ?? null;
          if (!$ev) continue;

                    $outcome = self::detectEventOutcome($pdo, $m['eT'], $ev, $m['eHome'], $m['eAway']);
          if ($outcome==='unknown') continue; // niente da fare finché non c’è esito

          $rawPick = $r['team_id']; // può essere ID numerico o 'HOME'/'AWAY' se la colonna mappata è 'choice'
          $homeId  = (int)$r['home_id'];
          $awayId  = (int)$r['away_id'];
          $lifeId  = (int)$r['life_id'];

          $isWin = false;

          if (is_numeric($rawPick)) {
            // caso standard: pick = team_id
            $pickTeam = (int)$rawPick;
            $isWin = ($outcome==='home' && $pickTeam === $homeId)
                  || ($outcome==='away' && $pickTeam === $awayId);
          } else {
            // tolleranza: pick salvata come 'HOME'/'AWAY'
            $pickSide = strtoupper(trim((string)$rawPick));
            $isWin = ($outcome==='home' && $pickSide === 'HOME')
                  || ($outcome==='away' && $pickSide === 'AWAY');
          }

          $isDraw = ($outcome==='draw');
          $isVoid = ($outcome==='void');

          if ($isWin || $isVoid) {
            // vita sopravvive
            if ($hasLround) {
              $u = $pdo->prepare("UPDATE $m[lT] SET $m[lRnd] = COALESCE($m[lRnd], 0) + 1 WHERE $m[lId]=?");
              $u->execute([$lifeId]);
            }
            $survivors++; $updated++;
          } else if ($isDraw || !$isWin) {
            // vita persa
            if ($lStatusCol) {
              $u = $pdo->prepare("UPDATE $m[lT] SET $lStatusCol = 'lost' WHERE $m[lId]=?");
              $u->execute([$lifeId]);
            }
            $losts++; $updated++;
          }
        }

        $pdo->commit();
      } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok'=>false,'error'=>'compute_failed','detail'=>$e->getMessage()];
      }

      return ['ok'=>true,'updated'=>$updated,'survivors'=>$survivors,'losts'=>$losts];
    }
}
