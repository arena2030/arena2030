<?php
declare(strict_types=1);

/**
 * API Torneo (admin): sigillo, riapertura, calcolo round, pubblicazione round successivo, finalizzazione torneo.
 * Dipendenze:
 *   - engine/TournamentCore.php        → sealRoundPicks, reopenRoundPicks, computeRound, publishNextRound
 *   - engine/TournamentFinalizer.php   → finalizeTournament
 */

ini_set('display_errors','0');                // niente warning a video (evita bad_json)
ini_set('log_errors','1');
ini_set('error_log','/tmp/php_errors.log');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }

define('APP_ROOT', dirname(__DIR__, 2));
require_once APP_ROOT . '/engine/TournamentCore.php';
require_once APP_ROOT . '/engine/TournamentFinalizer.php';

use \TournamentCore as TC;
use \TournamentFinalizer as TF;

/* ===== debug flag (non stampa errori, aggiunge info nel JSON) ===== */
$DBG = (isset($_GET['debug']) && $_GET['debug']=='1') || (isset($_POST['debug']) && $_POST['debug']=='1');
if ($DBG) header('X-Debug','1');

/* ===== helpers ===== */
function out(array $payload, int $status=200): void {
  http_response_code($status);
  echo json_encode($payload);
  exit;
}
function only_post(): void {
  if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') out(['ok'=>false,'error'=>'method'],405);
}
function require_admin_or_point(): void {
  $role = strtoupper((string)$_SESSION['role'] ?? 'USER');
  $isAdminFlag = ((int)($_SESSION['is_admin'] ?? 0) === 1);
  if (!in_array($role, ['ADMIN','PUNTO'], true) && !$isAdminFlag) out(['ok'=>false,'error'=>'forbidden'],403);
}

/* ===== auth minima ===== */
$uid  = (int)($_SESSION['uid'] ?? 0);
$role = strtoupper((string)$_SESSION['role'] ?? 'USER');
if ($uid <= 0 || !in_array($role, ['USER','PUNTO','ADMIN'], true)) out(['ok'=>false,'error'=>'unauthorized'],401);

/* ===== parse ===== */
$action = $_GET['action'] ?? $_POST['action'] ?? '';
if ($action==='') out(['ok'=>false,'error'=>'missing_action'],400);

$id  = isset($_GET['id'])  ? (int)$_GET['id']  : (isset($_POST['id'])?(int)$_POST['id']:0);
$tid = isset($_GET['tid']) ? (string)$_GET['tid'] : (isset($_POST['tid'])?(string)$_POST['tid']:null);

/* round helper (se manca) */
function detect_round(PDO $pdo, int $tournamentId): int {
  $st=$pdo->prepare(
    "SELECT COALESCE(
       (SELECT current_round  FROM tournaments WHERE id=? LIMIT 1),
       (SELECT round_current  FROM tournaments WHERE id=? LIMIT 1),
       (SELECT round          FROM tournaments WHERE id=? LIMIT 1),
       1
     ) AS r"
  );
  $st->execute([$tournamentId,$tournamentId,$tournamentId]);
  $r=(int)($st->fetchColumn() ?: 1);
  return max(1,$r);
}

/* ===== routing con try/catch globale ===== */
try {
  $tournamentId = TC::resolveTournamentId($pdo, $id, $tid);
  if ($tournamentId<=0) out(['ok'=>false,'error'=>'bad_tournament'],400);

  /* ---- seal_round ---- */
  if ($action === 'seal_round') {
    require_admin_or_point(); only_post();
    $round = (int)($_POST['round'] ?? 0); if ($round<=0) $round = detect_round($pdo,$tournamentId);
    $res = TC::sealRoundPicks($pdo, $tournamentId, $round);
    if (!$res['ok'] && ($res['error'] ?? '') === 'no_seal_backend') $res['error']='seal_column_missing';
    if ($DBG) $res['debug']=['act'=>'seal_round','tid'=>$tournamentId,'round'=>$round];
    out($res, 200);
  }

  /* ---- reopen_round ---- */
  if ($action === 'reopen_round') {
    require_admin_or_point(); only_post();
    $round = (int)($_POST['round'] ?? 0); if ($round<=0) $round = detect_round($pdo,$tournamentId);
    $res = TC::reopenRoundPicks($pdo, $tournamentId, $round);
    if (!$res['ok'] && ($res['error'] ?? '') === 'no_seal_backend') $res['error']='seal_column_missing';
    if ($DBG) $res['debug']=['act'=>'reopen_round','tid'=>$tournamentId,'round'=>$round];
    out($res, 200);
  }

  /* ---- compute_round ---- */
  if ($action === 'compute_round') {
    require_admin_or_point(); only_post();
    $round = (int)($_POST['round'] ?? 0); if ($round<=0) out(['ok'=>false,'error'=>'bad_round'],400);
    $res = TC::computeRound($pdo, $tournamentId, $round);
    if ($DBG) $res['debug']=['act'=>'compute_round','tid'=>$tournamentId,'round'=>$round];
    out($res, 200);
  }

  /* ---- publish_next_round ---- */
  if ($action === 'publish_next_round') {
    require_admin_or_point(); only_post();
    $round = (int)($_POST['round'] ?? 0); if ($round<=0) out(['ok'=>false,'error'=>'bad_round'],400);
    $res = TC::publishNextRound($pdo, $tournamentId, $round);
    if ($DBG) $res['debug']=['act'=>'publish_next_round','tid'=>$tournamentId,'round'=>$round];
    out($res, 200);
  }

  /* ---- finalize_tournament ---- */
  if ($action === 'finalize_tournament') {
    require_admin_or_point(); only_post();

    if (!class_exists('\TournamentFinalizer')) {
      out(['ok'=>false,'error'=>'finalizer_missing','detail'=>'engine/TournamentFinalizer.php non caricato'],500);
    }

    // opzionale: pre-check “si può chiudere?” (informativo)
    $can = TF::shouldEndTournament($pdo, $tournamentId);
    // esegui finalizzazione
    $adminId = (int)($_SESSION['uid'] ?? 0);

    try {
      $res = TF::finalizeTournament($pdo, $tournamentId, $adminId);
    } catch (\Throwable $ex) {
      out([
        'ok'=>false,
        'error'=>'finalize_exception',
        'detail'=>$ex->getMessage(),
        'hint'=>'Controlla /tmp/php_errors.log sul server',
        'context'=>$DBG ? ['tid'=>$tournamentId,'admin_id'=>$adminId] : null
      ], 500);
    }

    // se il finalizer torna ok=false, passa 200 ma con errore applicativo (es. “not_final”)
    if (!$res['ok']) {
      if ($DBG) $res['debug']=['act'=>'finalize_tournament','tid'=>$tournamentId,'precheck'=>$can];
      out($res, 200);
    }

    // arricchisci con messaggio umano
    $names=[]; foreach (($res['winners'] ?? []) as $w) { $names[] = $w['username'] ?? ('user#'.(string)($w['user_id'] ?? '')); }
    $msg = 'Torneo finalizzato ('.$res['result'].'). Montepremi: '.number_format((float)($res['pool'] ?? 0),2,',','.');
    if ($names) $msg .= ' | Vincitori: '.implode(', ', $names);
    $out = $res + ['message'=>$msg];
    if ($DBG) $out['debug']=['act'=>'finalize_tournament','tid'=>$tournamentId,'precheck'=>$can];
    out($out, 200);
  }

  /* ---- default ---- */
  out(['ok'=>false,'error'=>'unknown_action'],400);

} catch (\Throwable $e) {
  // Qualsiasi fatal/exception non gestita diventa JSON (niente RAW vuoto)
  out([
    'ok'=>false,
    'error'=>'api_exception',
    'detail'=>$e->getMessage(),
    'trace'=>$DBG ? $e->getTraceAsString() : null
  ], 500);
}
