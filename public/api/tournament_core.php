<?php
declare(strict_types=1);

/**
 * API Torneo (admin): sigillo, riapertura, calcolo round, pubblicazione round successivo, finalizzazione torneo.
 * Risponde SEMPRE JSON, anche in caso di fatal/parse, grazie al guardiano in cima.
 */

/* ============== GUARDIANO FATAL → JSON (PRIMA DI QUALSIASI INCLUDE) ============== */
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log','/tmp/php_errors.log');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

ob_start();
$__api_context = [
  'action' => $_GET['action'] ?? $_POST['action'] ?? null,
  'tid'    => $_GET['tid'] ?? $_POST['tid'] ?? null,
];

function __emit_fatal_json(array $err, array $ctx): void {
  if (!headers_sent()) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
  }
  while (ob_get_level()) { ob_end_clean(); }
  $typeMap = [
    E_ERROR=>'E_ERROR', E_PARSE=>'E_PARSE', E_CORE_ERROR=>'E_CORE_ERROR', E_COMPILE_ERROR=>'E_COMPILE_ERROR',
    E_USER_ERROR=>'E_USER_ERROR', E_RECOVERABLE_ERROR=>'E_RECOVERABLE_ERROR'
  ];
  echo json_encode([
    'ok'          => false,
    'error'       => 'fatal',
    'type'        => $typeMap[$err['type']] ?? ('E_'.$err['type']),
    'message'     => $err['message'] ?? '',
    'file'        => $err['file'] ?? '',
    'line'        => $err['line'] ?? 0,
    'action'      => $ctx['action'] ?? null,
    'tid'         => $ctx['tid'] ?? null,
    'php_version' => PHP_VERSION,
    'ts'          => date('c'),
  ], JSON_UNESCAPED_SLASHES);
  exit;
}
register_shutdown_function(function() use (&$__api_context) {
  $err = error_get_last();
  if (!$err) return;
  if (in_array($err['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR,E_RECOVERABLE_ERROR], true)) {
    __emit_fatal_json($err, $__api_context);
  }
});
set_exception_handler(function(Throwable $e) use (&$__api_context){
  if (!headers_sent()) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
  }
  while (ob_get_level()) { ob_end_clean(); }
  echo json_encode([
    'ok'=>false,
    'error'=>'uncaught_exception',
    'message'=>$e->getMessage(),
    'file'=>$e->getFile(),
    'line'=>$e->getLine(),
    'action'=>$__api_context['action'] ?? null,
    'tid'=>$__api_context['tid'] ?? null,
    'php_version'=>PHP_VERSION,
    'ts'=>date('c'),
  ], JSON_UNESCAPED_SLASHES);
  exit;
});
/* ============================ /GUARDIANO ============================ */

require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }

define('APP_ROOT', dirname(__DIR__, 2));
require_once APP_ROOT . '/engine/TournamentCore.php';
require_once APP_ROOT . '/engine/TournamentFinalizer.php';

use \TournamentCore as TC;
use \TournamentFinalizer as TF;

/* ===== DEBUG FLAG ===== */
$DBG = (($_GET['debug'] ?? '')==='1') || (($_POST['debug'] ?? '')==='1');
if ($DBG) header('X-Debug','1');

/* ===== HELPERS ===== */
function out(array $payload, int $status=200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_SLASHES);
  exit;
}
function only_post(): void {
  if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') out(['ok'=>false,'error'=>'method'],405);
}
function require_admin_or_point(): void {
  $role = strtoupper((string)($_SESSION['role'] ?? 'USER'));
  $isAdminFlag = ((int)($_SESSION['is_admin'] ?? 0) === 1);
  if (!in_array($role, ['ADMIN','PUNTO'], true) && !$isAdminFlag) out(['ok'=>false,'error'=>'forbidden'],403);
}

/* ===== AUTH MINIMA ===== */
$uid  = (int)($_SESSION['uid'] ?? 0);
$role = strtoupper((string)($_SESSION['role'] ?? 'USER'));
if ($uid <= 0 || !in_array($role, ['USER','PUNTO','ADMIN'], true)) out(['ok'=>false,'error'=>'unauthorized'],401);

/* ===== PARSE ===== */
$action = $_GET['action'] ?? $_POST['action'] ?? '';
if ($action==='') out(['ok'=>false,'error'=>'missing_action'],400);

$id  = isset($_GET['id'])  ? (int)$_GET['id']  : (isset($_POST['id'])?(int)$_POST['id']:0);
$tid = isset($_GET['tid']) ? (string)$_GET['tid'] : (isset($_POST['tid'])?(string)$_POST['tid']:null);

/* ===== round helper ===== */
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

/* ===== ROUTING CON CATCH GLOBALE ===== */
try {
  $tournamentId = TC::resolveTournamentId($pdo, $id, $tid);
  if ($tournamentId<=0) out(['ok'=>false,'error'=>'bad_tournament'],400);

  if ($action === 'seal_round') {
    require_admin_or_point(); only_post();
    $round = (int)($_POST['round'] ?? 0); if ($round<=0) $round = detect_round($pdo,$tournamentId);
    $res = TC::sealRoundPicks($pdo, $tournamentId, $round);
    if (!$res['ok'] && ($res['error'] ?? '') === 'no_seal_backend') $res['error']='seal_column_missing';
    if ($DBG) $res['debug']=['act'=>'seal_round','tid'=>$tournamentId,'round'=>$round];
    out($res, 200);
  }

  if ($action === 'reopen_round') {
    require_admin_or_point(); only_post();
    $round = (int)($_POST['round'] ?? 0); if ($round<=0) $round = detect_round($pdo,$tournamentId);
    $res = TC::reopenRoundPicks($pdo, $tournamentId, $round);
    if (!$res['ok'] && ($res['error'] ?? '') === 'no_seal_backend') $res['error']='seal_column_missing';
    if ($DBG) $res['debug']=['act'=>'reopen_round','tid'=>$tournamentId,'round'=>$round];
    out($res, 200);
  }

  if ($action === 'compute_round') {
    require_admin_or_point(); only_post();
    $round = (int)($_POST['round'] ?? 0); if ($round<=0) out(['ok'=>false,'error'=>'bad_round'],400);
    $res = TC::computeRound($pdo, $tournamentId, $round);
    if ($DBG) $res['debug']=['act'=>'compute_round','tid'=>$tournamentId,'round'=>$round];
    out($res, 200);
  }

  if ($action === 'publish_next_round') {
    require_admin_or_point(); only_post();
    $round = (int)($_POST['round'] ?? 0); if ($round<=0) out(['ok'=>false,'error'=>'bad_round'],400);
    $res = TC::publishNextRound($pdo, $tournamentId, $round);
    if ($DBG) $res['debug']=['act'=>'publish_next_round','tid'=>$tournamentId,'round'=>$round];
    out($res, 200);
  }

  if ($action === 'finalize_tournament') {
    require_admin_or_point(); only_post();

    // opzionale: pre-check “si può chiudere?”
    $can = TF::shouldEndTournament($pdo, $tournamentId);
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

    if (!$res['ok']) {
      if ($DBG) $res['debug']=['act'=>'finalize_tournament','tid'=>$tournamentId,'precheck'=>$can];
      out($res, 200);
    }

    $names=[]; foreach (($res['winners'] ?? []) as $w) { $names[] = $w['username'] ?? ('user#'.(string)($w['user_id'] ?? '')); }
    $msg = 'Torneo finalizzato ('.$res['result'].'). Montepremi: '.number_format((float)($res['pool'] ?? 0),2,',','.');
    if ($names) $msg .= ' | Vincitori: '.implode(', ', $names);
    $out = $res + ['message'=>$msg];
    if ($DBG) $out['debug']=['act'=>'finalize_tournament','tid'=>$tournamentId,'precheck'=>$can];
    out($out, 200);
  }

  out(['ok'=>false,'error'=>'unknown_action'],400);

} catch (\Throwable $e) {
  out([
    'ok'=>false,
    'error'=>'api_exception',
    'detail'=>$e->getMessage(),
    'trace'=>$DBG ? $e->getTraceAsString() : null
  ], 500);
}
