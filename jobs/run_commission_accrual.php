<?php
// Path base del progetto: cartella padre di /jobs
$APP_ROOT = dirname(__DIR__);

// DB + Service
require_once $APP_ROOT . '/partials/db.php';
require_once $APP_ROOT . '/services/CommissionService.php';

// Parse argomenti CLI semplici
$tournamentId = null;
foreach ($argv ?? [] as $arg){
  if (strpos($arg,'--tournament_id=')===0){
    $tournamentId = (int)substr($arg, strlen('--tournament_id='));
  }
}

// Modalità 1: torneo specifico
if ($tournamentId){
  $rep = CommissionService::accrueForTournament($pdo, $tournamentId);
  echo "[ACCRUE] Tournament #{$rep['tournament_id']} -> inserted: {$rep['inserted_events']} | skipped: {$rep['skipped_events']}\n";
  foreach(($rep['rows'] ?? []) as $r){
    echo "  - point_user_id={$r['point_user_id']} period={$r['period_ym']} rake_site={$r['rake_site']} pct={$r['pct']} commission={$r['commission']}\n";
  }
  exit(0);
}

// Modalità 2: scan tornei finiti non ancora processati
$todo = CommissionService::findFinishedTournamentIds($pdo, 200);
if (!$todo){
  echo "[ACCRUE] Nessun torneo finito da processare.\n";
  exit(0);
}

echo "[ACCRUE] Trovati ".count($todo)." tornei finiti da processare...\n";
$totalIns=0; $totalSkip=0;
foreach ($todo as $tid){
  $rep = CommissionService::accrueForTournament($pdo, (int)$tid);
  $totalIns += (int)$rep['inserted_events'];
  $totalSkip+= (int)$rep['skipped_events'];
  echo "  • Torneo #{$tid}: inserted={$rep['inserted_events']} skipped={$rep['skipped_events']}\n";
}
echo "[ACCRUE] Done. inserted_total={$totalIns} skipped_total={$totalSkip}\n";
