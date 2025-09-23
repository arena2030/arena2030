<?php
// /public/api/tournament_final.php — API Finalizzazione torneo (check_end, finalize, leaderboard, user_notice)
declare(strict_types=1);

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
function __emit_fatal_json_final(array $err, array $ctx): void {
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
    'ok'=>false,
    'error'=>'fatal',
    'type'=>$typeMap[$err['type']] ?? ('E_'.$err['type']),
    'message'=>$err['message'] ?? '',
    'file'=>$err['file'] ?? '',
    'line'=>$err['line'] ?? 0,
    'action'=>$ctx['action'] ?? null,
    'tid'=>$ctx['tid'] ?? null,
    'php_version'=>PHP_VERSION,
    'ts'=>date('c'),
  ], JSON_UNESCAPED_SLASHES);
  exit;
}
register_shutdown_function(function() use (&$__api_context) {
  $err = error_get_last();
  if (!$err) return;
  if (in_array($err['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR,E_RECOVERABLE_ERROR], true)) {
    __emit_fatal_json_final($err, $__api_context);
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

$DBG = (($_GET['debug'] ?? '')==='1') || (($_POST['debug'] ?? '')==='1');
if ($DBG) header('X-Debug','1');

function jsonOut($a, $code=200){ http_response_code($code); echo json_encode($a, JSON_UNESCAPED_SLASHES); exit; }
function only_post(){ if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST'){ jsonOut(['ok'=>false,'error'=>'method'],405); } }

/* ===== Auth minima ===== */
$uid  = (int)($_SESSION['uid'] ?? 0);
$role = strtoupper((string)($_SESSION['role'] ?? 'USER'));
if ($uid <= 0 || !in_array($role, ['USER','PUNTO','ADMIN'], true)) jsonOut(['ok'=>false,'error'=>'unauthorized'], 401);

/* ===== Parse ===== */
$action = $_GET['action'] ?? $_POST['action'] ?? '';
if ($action==='') jsonOut(['ok'=>false,'error'=>'missing_action'],400);

$id  = isset($_GET['id'])  ? (int)$_GET['id']  : (isset($_POST['id'])?(int)$_POST['id']:0);
$tid = isset($_GET['tid']) ? (string)$_GET['tid'] : (isset($_POST['tid'])?(string)$_POST['tid']:null);

try {
  define('APP_ROOT', dirname(__DIR__, 2));
  require_once APP_ROOT . '/engine/TournamentFinalizer.php';
  use \TournamentFinalizer as TF;

  $tournamentId = TF::resolveTournamentId($pdo, $id, $tid);

  if ($action==='check_end') {
    if ($tournamentId<=0) jsonOut(['ok'=>false,'error'=>'bad_tournament'],400);
    $res = TF::shouldEndTournament($pdo, $tournamentId);
    jsonOut(['ok'=>true] + $res);
  }

  if ($action === 'finalize') {
    $isAdminFlag = ((int)($_SESSION['is_admin'] ?? 0) === 1);
    if (!$isAdminFlag && !in_array($role, ['ADMIN','PUNTO'], true)) jsonOut(['ok' => false, 'error' => 'forbidden'], 403);

    only_post();
    if ($tournamentId <= 0) jsonOut(['ok' => false, 'error' => 'bad_tournament'], 400);

    try {
      $adminId = (int)($_SESSION['uid'] ?? 0);
      $res = TF::finalizeTournament($pdo, $tournamentId, $adminId);
    } catch (\Throwable $ex) {
      jsonOut([
        'ok'=>false,
        'error'=>'finalize_exception',
        'message'=>$ex->getMessage(),
        'file'=>$ex->getFile(),
        'line'=>$ex->getLine()
      ],500);
    }

    if (!$res['ok']) jsonOut($res, 200);
    jsonOut($res);
  }

  if ($action==='leaderboard') {
    if ($tournamentId<=0) jsonOut(['ok'=>false,'error'=>'bad_tournament'],400);
    $winnerIds=[];
    try {
      $has=$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tournament_payouts'")->fetchColumn();
      if ($has) {
        $st=$pdo->prepare("SELECT user_id FROM tournament_payouts WHERE tournament_id=? ORDER BY amount DESC");
        $st->execute([$tournamentId]);
        $winnerIds = array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN));
      }
    } catch (\Throwable $e) { /* no-op */ }
    $top10 = TF::buildLeaderboard($pdo, $tournamentId, $winnerIds);
    jsonOut(['ok'=>true,'top10'=>$top10]);
  }

  if ($action==='user_notice') {
    if ($tournamentId<=0) jsonOut(['ok'=>false,'error'=>'bad_tournament'],400);
    $res = TF::userNotice($pdo, $tournamentId, $uid);
    jsonOut($res);
  }

  jsonOut(['ok'=>false,'error'=>'unknown_action'],400);

} catch (\Throwable $e) {
  jsonOut([
    'ok'=>false,
    'error'=>'api_exception',
    'message'=>$e->getMessage(),
    'file'=>$e->getFile(),
    'line'=>$e->getLine(),
    'trace'=>$DBG ? $e->getTraceAsString() : null
  ], 500);
}
