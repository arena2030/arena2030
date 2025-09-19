<?php
// /public/api/tournament_core.php — API “core” del torneo (file separato e non invasivo)
require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }

// 🔧 FIX percorso engine
define('APP_ROOT', dirname(__DIR__, 2));
// prima era: require_once __DIR__ . '/../../app/engine/TournamentCore.php';
require_once APP_ROOT . '/engine/TournamentCore.php';

use \TournamentCore as TC;

/* ===== DEBUG opzionale ===== */
$__DBG = (isset($_GET['debug']) && $_GET['debug']=='1') || (isset($_POST['debug']) && $_POST['debug']=='1');
if ($__DBG) { ini_set('display_errors','1'); error_reporting(E_ALL); header('X-Debug','1'); }

/* ===== Auth minima ===== */
$uid  = (int)($_SESSION['uid'] ?? 0);
$role = $_SESSION['role'] ?? 'USER';
if ($uid <= 0 || !in_array($role, ['USER','PUNTO','ADMIN'], true)) {
  http_response_code(401);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit;
}

function json($a){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($a); exit; }
function only_post(){ if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST'){ http_response_code(405); json(['ok'=>false,'error'=>'method']); } }

/* ===== Parse torneo target ===== */
$id  = isset($_GET['id'])  ? (int)$_GET['id']  : (isset($_POST['id'])?(int)$_POST['id']:0);
$tid = isset($_GET['tid']) ? (string)$_GET['tid'] : (isset($_POST['tid'])?(string)$_POST['tid']:null);
$tournamentId = TC::resolveTournamentId($pdo, $id, $tid);

$act = $_GET['action'] ?? $_POST['action'] ?? '';
if ($act==='') { http_response_code(400); json(['ok'=>false,'error'=>'missing_action']); }

/* ====== ACTIONS ====== */

/**
 * GET/POST ?action=validate_pick&id=..|tid=..&life_id=..&team_id=..&round=..
 * Verifica se la pick è consentita nel rispetto del “ciclo principale”.
 */
if ($act==='validate_pick') {
  $lifeId = (int)($_GET['life_id'] ?? $_POST['life_id'] ?? 0);
  $teamId = (int)($_GET['team_id'] ?? $_POST['team_id'] ?? 0);
  $round  = (int)($_GET['round']   ?? $_POST['round']   ?? 0);

  if ($tournamentId<=0 || $lifeId<=0 || $teamId<=0 || $round<=0) {
    http_response_code(400); json(['ok'=>false,'error'=>'bad_params']);
  }

  $chk = TC::validatePick($pdo, $tournamentId, $lifeId, $round, $teamId);
  json(array_merge(['ok'=>true], ['validation'=>$chk]));
}
/**
 * GET ?action=selectable_teams&id=..|tid=..&life_id=..&round=..
 * Admin/Punto: restituisce elenco squadre selezionabili ORA per la vita+round, con motivazione.
 */
if ($act==='selectable_teams') {
  if (!in_array($role, ['ADMIN','PUNTO'], true)) { http_response_code(403); json(['ok'=>false,'error'=>'forbidden']); }
  $lifeId = (int)($_GET['life_id'] ?? 0);
  $round  = (int)($_GET['round']   ?? 0);
  if ($tournamentId<=0 || $lifeId<=0 || $round<=0) {
    http_response_code(400); json(['ok'=>false,'error'=>'bad_params']);
  }

  // universo squadre del torneo + disponibili nel round
  $allTeams   = TC::getAllTeamsForTournament($pdo, $tournamentId);
  $available  = TC::getAvailableTeamsForRound($pdo, $tournamentId, $round);
  $cycleState = TC::getLifeCycleState($pdo, $tournamentId, $lifeId);
  $usedNow    = $cycleState['used_in_cycle'] ?? [];
  $lastTeam   = $cycleState['last_pick_team'] ?? null;

  // valida tutte per sapere "perché"
  $allowed = [];
  $blocked = [];
  foreach ($available as $teamId) {
    $v = TC::validatePick($pdo, $tournamentId, $lifeId, $round, $teamId);
    if (($v['ok'] ?? false) && ($v['reason'] ?? '')!=='team_not_in_tournament' && ($v['reason'] ?? '')!=='team_not_available') {
      $allowed[] = ['team_id'=>$teamId, 'reason'=>$v['reason']];
    } else {
      $blocked[] = ['team_id'=>$teamId, 'reason'=>$v['reason'] ?? 'blocked'];
    }
  }

  json([
    'ok'=>true,
    'tournament_id'=>$tournamentId,
    'life_id'=>$lifeId,
    'round'=>$round,
    'all_teams'=>$allTeams,
    'available_now'=>$available,
    'cycle_state'=>[
      'used_in_cycle'=>$usedNow,
      'last_pick_team'=>$lastTeam,
      'cycle_completed_count'=>$cycleState['cycle_completed_count'] ?? 0
    ],
    'allowed'=>$allowed,
    'blocked'=>$blocked
  ]);
}

/**
 * GET ?action=policy_info&id=..|tid=..&life_id=..&round=..
 * Admin/Punto: stato policy della vita + universo squadre + disponibili nel round.
 */
if ($act==='policy_info') {
  if (!in_array($role, ['ADMIN','PUNTO'], true)) { http_response_code(403); json(['ok'=>false,'error'=>'forbidden']); }
  $lifeId = (int)($_GET['life_id'] ?? 0);
  $round  = (int)($_GET['round']   ?? 0);
  if ($tournamentId<=0 || $lifeId<=0 || $round<=0) {
    http_response_code(400); json(['ok'=>false,'error'=>'bad_params']);
  }

  $allTeams   = TC::getAllTeamsForTournament($pdo, $tournamentId);
  $available  = TC::getAvailableTeamsForRound($pdo, $tournamentId, $round);
  $cycleState = TC::getLifeCycleState($pdo, $tournamentId, $lifeId);

  json([
    'ok'=>true,
    'tournament_id'=>$tournamentId,
    'life_id'=>$lifeId,
    'round'=>$round,
    'all_teams'=>$allTeams,
    'available_now'=>$available,
    'cycle_state'=>$cycleState
  ]);
}
/**
 * POST ?action=lock_round&id=..|tid=..&round=..
 * Sigilla tutte le pick del round (assegna codice univoco).
 * Solo ADMIN/PUNTO.
 */
if ($act==='lock_round') {
  if (!in_array($role, ['ADMIN','PUNTO'], true)) { http_response_code(403); json(['ok'=>false,'error'=>'forbidden']); }
  only_post();

  $round = (int)($_POST['round'] ?? 0);
  if ($tournamentId<=0 || $round<=0) { http_response_code(400); json(['ok'=>false,'error'=>'bad_params']); }

  $res = TC::sealRoundPicks($pdo, $tournamentId, $round);
  json($res);
}

/**
 * POST ?action=compute_round&id=..|tid=..&round=..
 * Applica i risultati evento alle vite (promuove/elimina).
 * Solo ADMIN/PUNTO.
 */
if ($act==='compute_round') {
  if (!in_array($role, ['ADMIN','PUNTO'], true)) { http_response_code(403); json(['ok'=>false,'error'=>'forbidden']); }
  only_post();

  $round = (int)($_POST['round'] ?? 0);
  if ($tournamentId<=0 || $round<=0) { http_response_code(400); json(['ok'=>false,'error'=>'bad_params']); }

  $res = TC::computeRound($pdo, $tournamentId, $round);
  json($res);
}

/* default */
http_response_code(400); json(['ok'=>false,'error'=>'unknown_action']);
