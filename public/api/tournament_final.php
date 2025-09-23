<?php
// /public/api/tournament_final.php — API Finalizzazione torneo (check_end, finalize, leaderboard, user_notice)

declare(strict_types=1);
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log','/tmp/php_errors.log');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }

/* PATH ENGINE DETERMINISTICO */
if (!defined('APP_ROOT')) {
  define('APP_ROOT', dirname(__DIR__, 2));
}
require_once APP_ROOT . '/engine/TournamentFinalizer.php';

use \TournamentFinalizer as TF;

/* ===== DEBUG opzionale ===== */
$__DBG = (($_GET['debug'] ?? '')==='1') || (($_POST['debug'] ?? '')==='1');
if ($__DBG) { header('X-Debug','1'); }

/* ===== Auth minima ===== */
$uid  = (int)($_SESSION['uid'] ?? 0);
$role = strtoupper((string)($_SESSION['role'] ?? 'USER'));
if ($uid <= 0 || !in_array($role, ['USER','PUNTO','ADMIN'], true)) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit;
}

function jsonOut($a, $code=200){ http_response_code($code); echo json_encode($a); exit; }
function only_post(){ if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST'){ jsonOut(['ok'=>false,'error'=>'method'],405); } }

/* ===== Parse torneo target ===== */
$id  = isset($_GET['id'])  ? (int)$_GET['id']  : (isset($_POST['id'])?(int)$_POST['id']:0);
$tid = isset($_GET['tid']) ? (string)$_GET['tid'] : (isset($_POST['tid'])?(string)$_POST['tid']:null);
$tournamentId = TF::resolveTournamentId($pdo, $id, $tid);

$act = $_GET['action'] ?? $_POST['action'] ?? '';
if ($act==='') { jsonOut(['ok'=>false,'error'=>'missing_action'],400); }

/* ====== ACTIONS ====== */

if ($act==='check_end') {
  if ($tournamentId<=0) jsonOut(['ok'=>false,'error'=>'bad_tournament'],400);
  $res = TF::shouldEndTournament($pdo, $tournamentId);
  jsonOut(array_merge(['ok'=>true], $res));
}

if ($act === 'finalize') {
  $isAdminFlag = ((int)($_SESSION['is_admin'] ?? 0) === 1);
  if (!$isAdminFlag && !in_array($role, ['ADMIN','PUNTO'], true)) {
    jsonOut(['ok' => false, 'error' => 'forbidden'], 403);
  }

  only_post();
  if ($tournamentId <= 0) jsonOut(['ok' => false, 'error' => 'bad_tournament'], 400);

  $adminId = (int)($_SESSION['uid'] ?? 0);
  try {
    $res = TF::finalizeTournament($pdo, $tournamentId, $adminId);
  } catch (\Throwable $ex) {
    jsonOut(['ok'=>false,'error'=>'finalize_exception','detail'=>$ex->getMessage()],500);
  }
  if (!$res['ok']) {
    jsonOut($res, 200); // not_final ecc. → 200 applicativo
  }
  jsonOut($res);
}

if ($act==='leaderboard') {
  if ($tournamentId<=0) jsonOut(['ok'=>false,'error'=>'bad_tournament'],400);
  $winnerIds=[];
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tournament_payouts'");
  $q->execute();
  if ($q->fetchColumn()){
    $st=$pdo->prepare("SELECT user_id FROM tournament_payouts WHERE tournament_id=? ORDER BY amount DESC");
    $st->execute([$tournamentId]);
    $winnerIds = array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN));
  }
  $top10 = TF::buildLeaderboard($pdo, $tournamentId, $winnerIds);
  jsonOut(['ok'=>true,'top10'=>$top10]);
}

if ($act==='user_notice') {
  if ($tournamentId<=0) jsonOut(['ok'=>false,'error'=>'bad_tournament'],400);
  $res = TF::userNotice($pdo, $tournamentId, $uid);
  jsonOut($res);
}

/* default */
jsonOut(['ok'=>false,'error'=>'unknown_action'],400);
