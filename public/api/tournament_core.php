<?php
// /public/api/tournament_core.php — API “core” del torneo (sigillo, calcolo unico, pubblicazione round)

require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }

define('APP_ROOT', dirname(__DIR__, 2));
require_once APP_ROOT . '/engine/TournamentCore.php';

use \TournamentCore as TC;

/* ===== DEBUG opzionale ===== */
$__DBG = (isset($_GET['debug']) && $_GET['debug']=='1') || (isset($_POST['debug']) && $_POST['debug']=='1');
if ($__DBG) { ini_set('display_errors','1'); error_reporting(E_ALL); header('X-Debug','1'); }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function J($a,$code=200){ http_response_code($code); echo json_encode($a); exit; }
function only_post(){ if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST'){ J(['ok'=>false,'error'=>'method'],405); } }

/* ===== Auth minima ===== */
$uid  = (int)($_SESSION['uid'] ?? 0);
$role = (string)($_SESSION['role'] ?? 'USER');
$roleUp = strtoupper($role);
$isAdmin = (int)($_SESSION['is_admin'] ?? 0) === 1;

if ($uid <= 0 || !in_array($roleUp, ['USER','PUNTO','ADMIN'], true)) {
  J(['ok'=>false,'error'=>'unauthorized'],401);
}

/* ===== Parse torneo target ===== */
$id  = isset($_GET['id'])  ? (int)$_GET['id']  : (isset($_POST['id'])?(int)$_POST['id']:0);
$tidCode = isset($_GET['tid']) ? (string)$_GET['tid'] : (isset($_POST['tid'])?(string)$_POST['tid']:null);
$tournamentId = TC::resolveTournamentId($pdo, $id, $tidCode);

$act = $_GET['action'] ?? $_POST['action'] ?? '';
if ($act==='') J(['ok'=>false,'error'=>'missing_action'],400);

// helper per round
function detectRound(PDO $pdo, int $tournamentId): int {
  // usa current_round se c'è; altrimenti 1
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

/**
 * POST ?action=seal_round&id=..|tid=..[&round=..]
 * Sigilla tutte le pick del round indicato (o del current_round se non passato).
 * Solo ADMIN/PUNTO (o is_admin=1).
 */
if ($act==='seal_round') {
  if (!in_array($roleUp, ['ADMIN','PUNTO'], true) && !$isAdmin) J(['ok'=>false,'error'=>'forbidden'],403);
  only_post();
  if ($tournamentId<=0) J(['ok'=>false,'error'=>'bad_tournament'],400);
  $round = (int)($_POST['round'] ?? 0);
  if ($round<=0) $round = detectRound($pdo,$tournamentId);
  $res = TC::sealRoundPicks($pdo, $tournamentId, $round);
  if (!$res['ok']) J($res, 500);
  J(['ok'=>true] + $res);
}

/**
 * POST ?action=reopen_round&id=..|tid=..[&round=..]
 * Rimuove il sigillo per tutte le pick del round indicato (o current_round).
 * Solo ADMIN/PUNTO (o is_admin=1).
 */
if ($act==='reopen_round') {
  if (!in_array($roleUp, ['ADMIN','PUNTO'], true) && !$isAdmin) J(['ok'=>false,'error'=>'forbidden'],403);
  only_post();
  if ($tournamentId<=0) J(['ok'=>false,'error'=>'bad_tournament'],400);
  $round = (int)($_POST['round'] ?? 0);
  if ($round<=0) $round = detectRound($pdo,$tournamentId);
  $res = TC::reopenRoundPicks($pdo, $tournamentId, $round);
  if (!$res['ok']) J($res, 500);
  J(['ok'=>true] + $res);
}

/**
 * POST ?action=compute_round&id=..|tid=..&round=..
 * Calcolo unico del round (idempotente, con reset prima della pubblicazione).
 * Solo ADMIN/PUNTO (o is_admin=1).
 */
if ($act==='compute_round') {
  if (!in_array($roleUp, ['ADMIN','PUNTO'], true) && !$isAdmin) J(['ok'=>false,'error'=>'forbidden'],403);
  only_post();
  if ($tournamentId<=0) J(['ok'=>false,'error'=>'bad_tournament'],400);
  $round = (int)($_POST['round'] ?? 0);
  if ($round<=0) J(['ok'=>false,'error'=>'bad_round'],400);

  $res = TC::computeRound($pdo, $tournamentId, $round);
  // result: {ok, passed, out, next_round, alive_users, needs_finalize} | error
  if (!$res['ok']) {
    // passa errori dettagliati così come prodotti dall'engine
    $code = in_array($res['error'] ?? '', ['results_missing','duplicate_picks','invalid_pick_team','round_already_published']) ? 409 : 500;
    J($res, $code);
  }
  J($res);
}

/**
 * POST ?action=publish_next_round&id=..|tid=..&round=..
 * Aggiorna current_round a R+1 e resetta lock_at.
 * Solo ADMIN/PUNTO (o is_admin=1).
 */
if ($act==='publish_next_round') {
  if (!in_array($roleUp, ['ADMIN','PUNTO'], true) && !$isAdmin) J(['ok'=>false,'error'=>'forbidden'],403);
  only_post();
  if ($tournamentId<=0) J(['ok'=>false,'error'=>'bad_tournament'],400);
  $round = (int)($_POST['round'] ?? 0);
  if ($round<=0) J(['ok'=>false,'error'=>'bad_round'],400);

  $res = TC::publishNextRound($pdo, $tournamentId, $round);
  if (!$res['ok']) J($res, 500);
  J($res);
}

/* ===== legacy endpoints utili che vuoi mantenere? =====
   Se servono ancora policy_guard, validate_pick, selectable_teams, policy_info,
   lasciali in un file separato o reintroducili qui.
*/

J(['ok'=>false,'error'=>'unknown_action'],400);
