<?php
/**
 * CommissionService
 *
 * Calcolo commissioni "agi" per i Punti alla chiusura di un torneo.
 * - Idempotente: per torneo/punto scrive 1 sola volta (UNIQUE point_user_id+tournament_id).
 * - Aggrega nel mese (point_commission_monthly) con UPSERT + somma.
 *
 * Dipendenze lato schema:
 * - Tabella points: columns -> user_id (id utente PUNTO), rake_pct (DECIMAL)
 * - Tabella users: varie colonne "elastiche" per mappare l'utente al PUNTO (vedi resolvePointForUser).
 * - Tabella tornei: "tournaments" (preferito) con qualche colonna di stato/data fine (vedi autodetect).
 * - Sorgenti "rake": autodetect su tabelle candidate che abbiano tournament_id, user_id e una colonna rake/fee.
 *
 * NOTE: Se la tua tabella sorgente della rake è nota e unica, puoi impostarla direttamente in $CANDIDATE_SOURCES in fondo.
 */
class CommissionService
{
  /* ==================== Utilities schema ==================== */

  public static function tableExists(PDO $pdo, $table){
    $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $q->execute([$table]); return (bool)$q->fetchColumn();
  }

  public static function columnExists(PDO $pdo, $table, $col){
    $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $q->execute([$table,$col]); return (bool)$q->fetchColumn();
  }

  public static function firstExistingColumn(PDO $pdo, $table, array $candidates){
    foreach($candidates as $c){
      if (self::columnExists($pdo,$table,$c)) return $c;
    }
    return null;
  }

  /* ==================== Rilevazione tornei finiti ==================== */

  /**
   * Trova tornei finiti e non ancora accreditati (per almeno un punto).
   * Usa euristiche sullo schema: tournaments.status / is_finished / ended_at ecc.
   * Ritorna una lista di tournament_id (max $limit).
   */
  public static function findFinishedTournamentIds(PDO $pdo, $limit=100){
    $ids = [];

    if (!self::tableExists($pdo,'tournaments')){
      // Se non esiste, non possiamo scan-automatico. Lavorerai per "tournament_id" diretto.
      return $ids;
    }

    $hasStatus     = self::columnExists($pdo,'tournaments','status');
    $hasIsFinished = self::columnExists($pdo,'tournaments','is_finished');
    $hasEnded      = self::columnExists($pdo,'tournaments','ended_at') || self::columnExists($pdo,'tournaments','finished_at') || self::columnExists($pdo,'tournaments','end_time');

    // criterio base: finito = (is_finished=1) OR status in (...) OR ended_at non null/passato
    $where = [];
    $pars  = [];

    if ($hasIsFinished) $where[] = "t.is_finished = 1";
    if ($hasStatus)     $where[] = "t.status IN ('ENDED','CLOSED','FINISHED','PAID_OUT','COMPLETED','SETTLED')";
    if ($hasEnded){
      $endedCol = self::columnExists($pdo,'tournaments','ended_at') ? 'ended_at'
                : (self::columnExists($pdo,'tournaments','finished_at') ? 'finished_at' : 'end_time');
      $where[] = "t.$endedCol IS NOT NULL";
    }
    if (!$where) return $ids; // nessun segnale affidabile -> niente scan automatico

    // Escludi già accreditati: NOT EXISTS evento per qualunque punto (basta che esista almeno 1 punto accreditato)
    $sql = "SELECT t.id
            FROM tournaments t
            WHERE (".implode(' OR ', $where).")
              AND NOT EXISTS (
                SELECT 1 FROM point_commission_event e WHERE e.tournament_id = t.id
              )
            ORDER BY t.id DESC
            LIMIT ".(int)$limit;

    $st=$pdo->prepare($sql);
    $st->execute($pars);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    return array_map('intval',$ids);
  }

  /**
   * Periodo YYYY-MM associato al torneo: usa la colonna di fine se presente, altrimenti mese corrente.
   */
  public static function getPeriodYMFromTournament(PDO $pdo, $tournamentId){
    if (!self::tableExists($pdo,'tournaments')) return date('Y-m');

    $cols = ['ended_at','finished_at','end_time','updated_at','created_at'];
    $col  = null; foreach($cols as $c){ if (self::columnExists($pdo,'tournaments',$c)){ $col=$c; break; } }
    if (!$col) return date('Y-m');

    $st=$pdo->prepare("SELECT $col FROM tournaments WHERE id=?");
    $st->execute([$tournamentId]);
    $dt=$st->fetchColumn();
    if (!$dt) return date('Y-m');
    $ts=strtotime($dt);
    if ($ts===false) return date('Y-m');
    return date('Y-m',$ts);
  }

  /* ==================== Mapping utente -> Punto ==================== */

  /**
   * Ricava l'user_id del PUNTO proprietario dell'utente $userId.
   * Cerca su users: point_user_id | presenter (id o username) | presenter_code/point_code -> points.* (user_id).
   */
  public static function resolvePointForUser(PDO $pdo, $userId){
    // 1) users.point_user_id (diretto)
    if (self::columnExists($pdo,'users','point_user_id')){
      $st=$pdo->prepare("SELECT point_user_id FROM users WHERE id=?");
      $st->execute([$userId]);
      $pid=(int)$st->fetchColumn();
      if ($pid>0){
        // verifica che sia davvero un PUNTO
        $st2=$pdo->prepare("SELECT 1 FROM users WHERE id=? AND role='PUNTO' LIMIT 1");
        $st2->execute([$pid]);
        if ($st2->fetchColumn()) return $pid;
      }
    }

    // 2) users.presenter (può essere id numerico o username)
    if (self::columnExists($pdo,'users','presenter')){
      $st=$pdo->prepare("SELECT presenter FROM users WHERE id=?");
      $st->execute([$userId]);
      $pr=$st->fetchColumn();
      if ($pr!==false && $pr!==''){
        if (ctype_digit((string)$pr)){ // id numerico
          $pid=(int)$pr;
          $chk=$pdo->prepare("SELECT 1 FROM users WHERE id=? AND role='PUNTO' LIMIT 1");
          $chk->execute([$pid]);
          if ($chk->fetchColumn()) return $pid;
        } else {
          // username
          $chk=$pdo->prepare("SELECT id FROM users WHERE username=? AND role='PUNTO' LIMIT 1");
          $chk->execute([$pr]);
          $pid=(int)($chk->fetchColumn() ?: 0);
          if ($pid>0) return $pid;
        }
      }
    }

    // 3) presenter_code / point_code -> tabella points
    if (self::tableExists($pdo,'points')){
      $codeCol = null;
      if (self::columnExists($pdo,'users','presenter_code')) $codeCol='presenter_code';
      elseif (self::columnExists($pdo,'users','point_code')) $codeCol='point_code';

      if ($codeCol){
        $st=$pdo->prepare("SELECT $codeCol FROM users WHERE id=?");
        $st->execute([$userId]);
        $code=$st->fetchColumn();
        if ($code){
          // cerca su points.point_code oppure points.code
          $pcol = self::columnExists($pdo,'points','point_code') ? 'point_code'
                : (self::columnExists($pdo,'points','code') ? 'code' : null);
          if ($pcol && self::columnExists($pdo,'points','user_id')){
            $pp=$pdo->prepare("SELECT user_id FROM points WHERE $pcol=? LIMIT 1");
            $pp->execute([$code]);
            $pid=(int)($pp->fetchColumn() ?: 0);
            if ($pid>0) return $pid;
          }
        }
      }
    }

    // 4) point_username su users
    if (self::columnExists($pdo,'users','point_username')){
      $st=$pdo->prepare("SELECT point_username FROM users WHERE id=?");
      $st->execute([$userId]);
      $pun=$st->fetchColumn();
      if ($pun){
        $chk=$pdo->prepare("SELECT id FROM users WHERE username=? AND role='PUNTO' LIMIT 1");
        $chk->execute([$pun]);
        $pid=(int)($chk->fetchColumn() ?: 0);
        if ($pid>0) return $pid;
      }
    }

    return null;
  }

  /* ==================== Raccolta rake per torneo ==================== */

  /**
   * Sorgenti candidate per la rake del sito.
   * Puoi mettere direttamente qui la tua/e tabella/e se già note.
   * Requisiti minimi per una sorgente: colonne tournament_id, user_id e UNA colonna tra rake_site_coins/rake_coins/rake/fee/fee_coins.
   */
  protected static function candidateSources()
  {
    // Per massima compatibilità, elenchiamo più tabelle comuni.
    // Se la tua è diversa, aggiungila qui con la colonna esatta della rake.
    return [
      ['table'=>'tournament_orders',   'rake_cols'=>['rake_site_coins','rake_coins','rake','fee_coins','fee'], 'status_col'=>'status', 'status_ok'=>['PAID','CONFIRMED','COMPLETED','SUCCESS']],
      ['table'=>'tournament_payments', 'rake_cols'=>['rake_site_coins','rake_coins','rake','fee_coins','fee'], 'status_col'=>'status', 'status_ok'=>['PAID','CONFIRMED','COMPLETED','SUCCESS']],
      ['table'=>'tournament_entries',  'rake_cols'=>['rake_site_coins','rake_coins','rake','fee_coins','fee'], 'status_col'=>'status', 'status_ok'=>['PAID','CONFIRMED','COMPLETED','SUCCESS']],
      ['table'=>'tournament_lives',    'rake_cols'=>['rake_site_coins','rake_coins','rake','fee_coins','fee'], 'status_col'=>'status', 'status_ok'=>['PAID','CONFIRMED','COMPLETED','SUCCESS']],
      ['table'=>'payments',            'rake_cols'=>['rake_site_coins','rake_coins','rake','fee_coins','fee'], 'status_col'=>'status', 'status_ok'=>['PAID','CONFIRMED','COMPLETED','SUCCESS']],
      ['table'=>'orders',              'rake_cols'=>['rake_site_coins','rake_coins','rake','fee_coins','fee'], 'status_col'=>'status', 'status_ok'=>['PAID','CONFIRMED','COMPLETED','SUCCESS']],
      ['table'=>'wallet_log',          'rake_cols'=>['rake_site_coins','rake_coins','rake','fee_coins','fee'], 'status_col'=>null,     'status_ok'=>[]],
      ['table'=>'transactions',        'rake_cols'=>['rake_site_coins','rake_coins','rake','fee_coins','fee'], 'status_col'=>'status', 'status_ok'=>['PAID','CONFIRMED','COMPLETED','SUCCESS']],
    ];
  }

  /**
   * Ritorna mappa user_id => rake_sito_totale per uno specifico torneo.
   * Somma su tutte le sorgenti candidate disponibili nello schema.
   */
  public static function collectTournamentRakeByUser(PDO $pdo, $tournamentId)
  {
    $out = [];

    foreach (self::candidateSources() as $src){
      $tbl = $src['table'];
      if (!self::tableExists($pdo, $tbl)) continue;
      if (!self::columnExists($pdo,$tbl,'tournament_id')) continue;
      if (!self::columnExists($pdo,$tbl,'user_id')) continue;

      $rakeCol = self::firstExistingColumn($pdo,$tbl, $src['rake_cols']);
      if (!$rakeCol) continue;

      $where = "tournament_id = ?";
      $pars  = [$tournamentId];

      // Filtri comuni di "sanità"
      if (self::columnExists($pdo,$tbl,'is_refund'))  { $where .= " AND (is_refund = 0 OR is_refund IS NULL)"; }
      if (self::columnExists($pdo,$tbl,'voided'))     { $where .= " AND (voided = 0 OR voided IS NULL)"; }

      // Filtro status se disponibile
      if ($src['status_col'] && self::columnExists($pdo,$tbl,$src['status_col']) && !empty($src['status_ok'])){
        $ok = $src['status_ok'];
        $ph = implode(',', array_fill(0, count($ok), '?'));
        $where .= " AND {$src['status_col']} IN ($ph)";
        foreach($ok as $v) $pars[] = $v;
      }

      $sql = "SELECT user_id, SUM($rakeCol) AS r FROM $tbl WHERE $where GROUP BY user_id HAVING r > 0";
      $st  = $pdo->prepare($sql);
      $st->execute($pars);

      while($row = $st->fetch(PDO::FETCH_ASSOC)){
        $uid = (int)$row['user_id'];
        $r   = (float)$row['r'];
        if ($r <= 0) continue;
        if (!isset($out[$uid])) $out[$uid] = 0.0;
        $out[$uid] += $r;
      }
    }

    return $out;
  }

  /**
   * Raggruppa la rake per PUNTO (somma su tutta la propria downline) per il torneo indicato.
   * Ritorna mappa point_user_id => rake_sito_totale.
   */
  public static function collectTournamentRakeByPoint(PDO $pdo, $tournamentId)
  {
    $byUser = self::collectTournamentRakeByUser($pdo, $tournamentId);
    if (!$byUser) return [];

    $byPoint = [];
    foreach($byUser as $uid=>$rake){
      $pid = self::resolvePointForUser($pdo, $uid);
      if (!$pid) continue; // utente non ha punto -> nessuna commissione
      if (!isset($byPoint[$pid])) $byPoint[$pid] = 0.0;
      $byPoint[$pid] += (float)$rake;
    }
    return $byPoint;
  }

  /* ==================== Accrual idempotente ==================== */

  /**
   * Esegue l'accredito (maturazione) commissioni per un torneo.
   * - Scrive evento puntuale (INSERT IGNORE) per idempotenza
   * - Aggiorna aggregato mensile con somma (UPSERT) solo se l'evento è stato inserito ora
   *
   * Ritorna report: ['tournament_id'=>.., 'inserted_events'=>N, 'skipped_events'=>M, 'rows'=>[...]]
   */
  public static function accrueForTournament(PDO $pdo, $tournamentId)
  {
    $report = ['tournament_id'=>(int)$tournamentId, 'inserted_events'=>0, 'skipped_events'=>0, 'rows'=>[]];

    // Mappa per punto
    $rakeByPoint = self::collectTournamentRakeByPoint($pdo, $tournamentId);
    if (!$rakeByPoint) return $report;

    // Periodo YYYY-MM
    $period = self::getPeriodYMFromTournament($pdo, $tournamentId);

    // Prepara statement riusabili
    $getPct = $pdo->prepare("SELECT COALESCE(rake_pct,0) FROM points WHERE user_id=? LIMIT 1");

    $insEvt = $pdo->prepare(
      "INSERT IGNORE INTO point_commission_event
        (point_user_id, tournament_id, period_ym, rake_site_coins, pct, commission_coins, details, created_at)
       VALUES (?, ?, ?, ?, ?, ?, NULL, NOW())"
    );

    $upsertMonth = $pdo->prepare(
      "INSERT INTO point_commission_monthly
        (point_user_id, period_ym, amount_coins, calculated_at)
       VALUES (?, ?, ?, NOW())
       ON DUPLICATE KEY UPDATE
         amount_coins = amount_coins + VALUES(amount_coins),
         calculated_at = NOW()"
    );

    // Transazione (facoltativa ma consigliata)
    $pdo->beginTransaction();
    try{
      foreach($rakeByPoint as $pointUserId=>$rakeSite){
        // pct del punto (snapshot)
        $getPct->execute([$pointUserId]);
        $pct = (float)($getPct->fetchColumn() ?: 0.0);

        // commissione (2 decimali)
        $commission = round((float)$rakeSite * ($pct / 100.0), 2);
        if ($commission <= 0) { $report['skipped_events']++; continue; }

        // evento puntuale (idempotente)
        $insEvt->execute([$pointUserId, $tournamentId, $period, (float)$rakeSite, $pct, $commission]);

        if ($insEvt->rowCount() > 0){
          // primo inserimento -> aggiorna aggregato mese
          $upsertMonth->execute([$pointUserId, $period, $commission]);
          $report['inserted_events']++;
          $report['rows'][] = [
            'point_user_id' => (int)$pointUserId,
            'period_ym'     => $period,
            'rake_site'     => (float)$rakeSite,
            'pct'           => $pct,
            'commission'    => $commission
          ];
        } else {
          // già presente -> skip
          $report['skipped_events']++;
        }
      }
      $pdo->commit();
    }catch(Exception $e){
      $pdo->rollBack();
      throw $e;
    }

    return $report;
  }
}
