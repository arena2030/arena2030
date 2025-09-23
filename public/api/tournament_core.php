<?php
declare(strict_types=1);

/**
 * API Torneo (admin): sigillo, riapertura, calcolo round, pubblicazione round successivo, finalizzazione torneo.
 * Dipendenze:
 *   - engine/TournamentCore.php        → sealRoundPicks, reopenRoundPicks, computeRound, publishNextRound
 *   - engine/TournamentFinalizer.php   → finalizeTournament
 */

ini_set('display_errors','0');                // niente warning in output (evita bad_json)
ini_set('log_errors','1');
ini_set('error_log','/tmp/php_errors.log');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }

define('APP_ROOT', dirname(__DIR__, 2));
require_once APP_ROOT . '/engine/TournamentCore.php';
require_once APP_ROOT . '/engine/TournamentFinalizer.php';

use \TournamentCore as TC;
use \TournamentFinalizer as TF;

/* ===== Helpers ===== */
$__DBG = (isset($_GET['debug']) && $_GET['debug']=='1') || (isset($_POST['debug']) && $_POST['debug']=='1');
if ($__DBG) { header('X-Debug', '1'); /* lasciamo display_errors=0 per avere JSON pulito */ }

function out(array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload);
  exit;
}

function require_admin_or_point(): void {
  $role = strtoupper((string)($_SESSION['role'] ?? 'USER'));
  $isAdminFlag = ((int)($_SESSION['is_admin'] ?? 0) === 1);
  if (!in_array($role, ['ADMIN','PUNTO'], true) && !$isAdminFlag) {
    out(['ok'=>false, 'error'=>'forbidden'], 403);
  }
}

/* ===== Auth minima ===== */
$uid  = (int)($_SESSION['uid'] ?? 0);
$role = strtoupper((string)($_SESSION['role'] ?? 'USER'));
if ($uid <= 0 || !in_array($role, ['USER','PUNTO','ADMIN'], true)) {
  out(['ok'=>false,'error'=>'unauthorized'], 401);
}

/* ===== Parse torneo target ===== */
$id  = isset($_GET['id'])  ? (int)$_GET['id']  : (isset($_POST['id'])?(int)$_POST['id']:0);
$tid = isset($_GET['tid']) ? (string)$_GET['tid'] : (isset($_POST['tid'])?(string)$_POST['tid']:null);
$tournamentId = TC::resolveTournamentId($pdo, $id, $tid);

$action = $_GET['action'] ?? $_POST['action'] ?? '';
if ($action==='') { out(['ok'=>false,'error'=>'missing_action'], 400); }

/* ========== ROUTER ========== */

/**
 * POST ?action=seal_round&tid=..|id=..&round=R
 * Sigilla le pick del round corrente (priorità: pick-lock → event-lock → tour.lock_at).
 */
if ($action === 'seal_round') {
  require_admin_or_point();
  $round = (int)($_POST['round'] ?? 0);
  if ($tournamentId<=0 || $round<=0) out(['ok'=>false,'error'=>'bad_params'], 400);

  $res = TC::sealRoundPicks($pdo, $tournamentId, $round);

  // normalizza nomenclatura errore per la UI
  if (!$res['ok'] && ($res['error'] ?? '') === 'no_seal_backend') {
    $res['error'] = 'seal_column_missing';
  }

  out($res);
}

/**
 * POST ?action=reopen_round&tid=..|id=..&round=R
 * Riapre le scelte sigillate nel round (annulla pick-lock / event-lock / azzera tournaments.lock_at).
 */
if ($action === 'reopen_round') {
  require_admin_or_point();
  $round = (int)($_POST['round'] ?? 0);
  if ($tournamentId<=0 || $round<=0) out(['ok'=>false,'error'=>'bad_params'], 400);

  $res = TC::reopenRoundPicks($pdo, $tournamentId, $round);

  // normalizza nomenclatura errore per la UI
  if (!$res['ok'] && ($res['error'] ?? '') === 'no_seal_backend') {
    $res['error'] = 'seal_column_missing';
  }

  out($res);
}

/**
 * POST ?action=compute_round&tid=..|id=..&round=R
 * Calcola il round: promuove/elimina vite, segnala conflitti/risultati mancanti.
 */
if ($action === 'compute_round') {
  require_admin_or_point();
  $round = (int)($_POST['round'] ?? 0);
  if ($tournamentId<=0 || $round<=0) out(['ok'=>false,'error'=>'bad_params'], 400);

  $res = TC::computeRound($pdo, $tournamentId, $round);
  out($res);
}

/**
 * POST ?action=publish_next_round&tid=..|id=..&round=R
 * Pubblica R+1 (aggiorna current_round e azzera lock_at se presente).
 */
if ($action === 'publish_next_round') {
  require_admin_or_point();
  $round = (int)($_POST['round'] ?? 0);
  if ($tournamentId<=0 || $round<=0) out(['ok'=>false,'error'=>'bad_params'], 400);

  $res = TC::publishNextRound($pdo, $tournamentId, $round);
  out($res);
}

/**
 * POST ?action=finalize_tournament&tid=..|id=..
 * Finalizza: ripartisce montepremi, accredita, chiude torneo, restituisce elenco vincitori.
 * (usa il motore dedicato TournamentFinalizer)
 */
if ($action === 'finalize_tournament') {
  require_admin_or_point();
  if ($tournamentId<=0) out(['ok'=>false,'error'=>'bad_tournament'], 400);

  $adminId = (int)($_SESSION['uid'] ?? 0);
  $res = TF::finalizeTournament($pdo, $tournamentId, $adminId);

  if (!$res['ok']) {
    // Passa l'errore del finalizer così com'è, utile per debug mirato
    if ($__DBG) $res['debug'] = ['action'=>'finalize_tournament','tournament_id'=>$tournamentId,'admin_id'=>$adminId];
    out($res, 200);
  }

  // messaggio riassuntivo per UI
  $names = [];
  foreach (($res['winners'] ?? []) as $w) { $names[] = $w['username'] ?? ('user#'.(string)($w['user_id'] ?? '')); }
  $msg = 'Torneo finalizzato ('.$res['result'].'). Montepremi: '.number_format((float)($res['pool'] ?? 0), 2, ',', '.');
  if ($names) $msg .= ' | Vincitori: '.implode(', ', $names);

  $out = $res + ['message'=>$msg];
  if ($__DBG) $out['debug'] = ['action'=>'finalize_tournament','tournament_id'=>$tournamentId,'admin_id'=>$adminId];
  out($out);
}

/* default */
out(['ok'=>false,'error'=>'unknown_action'], 400);
