<?php
require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }

define('APP_ROOT', dirname(__DIR__, 2));
require_once APP_ROOT . '/engine/TournamentCore.php';

use \TournamentCore as TC;

$__DBG = (isset($_GET['debug']) && $_GET['debug']=='1') || (isset($_POST['debug']) && $_POST['debug']=='1');
if ($__DBG) { ini_set('display_errors','1'); error_reporting(E_ALL); header('X-Debug','1'); }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function J($a,$code=200){ http_response_code($code); echo json_encode($a); exit; }
function only_post(){ if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST'){ J(['ok'=>false,'error'=>'method'],405); } }

$uid  = (int)($_SESSION['uid'] ?? 0);
$role = strtoupper((string)($_SESSION['role'] ?? 'USER'));
$isAdmin = (int)($_SESSION['is_admin'] ?? 0) === 1;
if ($uid <= 0 || !in_array($role, ['USER','PUNTO','ADMIN'], true)) J(['ok'=>false,'error'=>'unauthorized'],401);

$id  = isset($_GET['id'])  ? (int)$_GET['id']  : (isset($_POST['id'])?(int)$_POST['id']:0);
$tidCode = isset($_GET['tid']) ? (string)$_GET['tid'] : (isset($_POST['tid'])?(string)$_POST['tid']:null);
$tournamentId = TC::resolveTournamentId($pdo, $id, $tidCode);

$act = $_GET['action'] ?? $_POST['action'] ?? '';
if ($act==='') J(['ok'=>false,'error'=>'missing_action'],400);

// helper round
function detectRound(PDO $pdo, int $tournamentId): int {
  $rCol = null;
  foreach (['current_round','round_current','round'] as $c) {
    $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tournaments' AND COLUMN_NAME=?");
    $q->execute([$c]); if ($q->fetchColumn()) { $rCol=$c; break; }
  }
  if ($rCol) {
    $st=$pdo->prepare("SELECT COALESCE($rCol,1) FROM tournaments WHERE id=?");
    $st->execute([$tournamentId]);
    $r=(int)$st->fetchColumn();
    return max(1,$r);
  }
  return 1;
}

/* ====== ACTIONS ====== */

if ($act==='seal_round') {
  if (!in_array($role, ['ADMIN','PUNTO'], true) && !$isAdmin) J(['ok'=>false,'error'=>'forbidden'],403);
  only_post();
  if ($tournamentId<=0) J(['ok'=>false,'error'=>'bad_tournament'],400);
  $round = (int)($_POST['round'] ?? 0);
  if ($round<=0) $round = detectRound($pdo,$tournamentId);
  $res = TC::sealRoundPicks($pdo, $tournamentId, $round);
  if (!$res['ok']) J($res, 500);
  J(['ok'=>true] + $res);
}

if ($act==='reopen_round') {
  if (!in_array($role, ['ADMIN','PUNTO'], true) && !$isAdmin) J(['ok'=>false,'error'=>'forbidden'],403);
  only_post();
  if ($tournamentId<=0) J(['ok'=>false,'error'=>'bad_tournament'],400);
  $round = (int)($_POST['round'] ?? 0);
  if ($round<=0) $round = detectRound($pdo,$tournamentId);
  $res = TC::reopenRoundPicks($pdo, $tournamentId, $round);
  if (!$res['ok']) J($res, 500);
  J(['ok'=>true] + $res);
}

if ($act==='compute_round') {
  if (!in_array($role, ['ADMIN','PUNTO'], true) && !$isAdmin) J(['ok'=>false,'error'=>'forbidden'],403);
  only_post();
  if ($tournamentId<=0) J(['ok'=>false,'error'=>'bad_tournament'],400);
  $round = (int)($_POST['round'] ?? 0);
  if ($round<=0) J(['ok'=>false,'error'=>'bad_round'],400);

  $res = TC::computeRound($pdo, $tournamentId, $round);
  if (!$res['ok']) {
    $code = in_array($res['error'] ?? '', [
      'results_missing','duplicate_picks','invalid_pick_team',
      'round_already_published','seal_backend_missing','lock_not_set','lock_not_reached'
    ]) ? 409 : 500;
    J($res, $code);
  }
  J($res);
}

if ($act==='publish_next_round') {
  if (!in_array($role, ['ADMIN','PUNTO'], true) && !$isAdmin) J(['ok'=>false,'error'=>'forbidden'],403);
  only_post();
  if ($tournamentId<=0) J(['ok'=>false,'error'=>'bad_tournament'],400);
  $round = (int)($_POST['round'] ?? 0);
  if ($round<=0) J(['ok'=>false,'error'=>'bad_round'],400);

  $res = TC::publishNextRound($pdo, $tournamentId, $round);
  if (!$res['ok']) J($res, 500);
  J($res);
}

J(['ok'=>false,'error'=>'unknown_action'],400);
