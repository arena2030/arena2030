<?php
declare(strict_types=1);

ini_set('display_errors', (isset($_GET['debug']) && $_GET['debug']=='1') ? '1' : '0');
ini_set('log_errors','1');
ini_set('error_log','/tmp/php_errors.log');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }

define('APP_ROOT', dirname(__DIR__, 2));
require_once APP_ROOT . '/engine/TournamentCore.php';
use \TournamentCore as TC;

function out($a, int $code=200){
  http_response_code($code);
  while (ob_get_level()) { ob_end_clean(); }
  echo json_encode($a);
  exit;
}
function only_post(){ if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') out(['ok'=>false,'error'=>'method'],405); }

$uid  = (int)($_SESSION['uid'] ?? 0);
$role = strtoupper((string)($_SESSION['role'] ?? ''));
$isAdminFlag = (int)($_SESSION['is_admin'] ?? 0) === 1;
if ($uid <= 0) out(['ok'=>false,'error'=>'unauthorized'],401);

$needAdmin = function() use($role,$isAdminFlag){
  if (!in_array($role, ['ADMIN','PUNTO'], true) && !$isAdminFlag) {
    out(['ok'=>false,'error'=>'forbidden'],403);
  }
};

$act = $_GET['action'] ?? $_POST['action'] ?? '';
if ($act==='') out(['ok'=>false,'error'=>'missing_action'],400);

$id  = isset($_GET['id'])  ? (int)$_GET['id']  : (isset($_POST['id'])?(int)$_POST['id']:0);
$tid = $_GET['tid'] ?? $_POST['tid'] ?? null;
$tournamentId = TC::resolveTournamentId($pdo, $id, $tid);
if ($tournamentId<=0) out(['ok'=>false,'error'=>'bad_tournament'],400);

$round = (int)($_GET['round'] ?? $_POST['round'] ?? $_GET['round_no'] ?? $_POST['round_no'] ?? 0);
if ($round<=0) {
  // fallback a current_round o 1
  $rCol = null;
  $st=$pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tournaments' AND COLUMN_NAME IN ('current_round','round_current','round') LIMIT 1");
  $st->execute(); $rCol = $st->fetchColumn() ?: null;
  if ($rCol) {
    $x=$pdo->prepare("SELECT COALESCE($rCol,1) FROM tournaments WHERE id=? LIMIT 1"); $x->execute([$tournamentId]);
    $round = max(1,(int)$x->fetchColumn());
  } else {
    $round = 1;
  }
}

try{
  switch ($act) {

    /* ====== VALIDATE PICK (aggiunto) ====== */
    case 'validate_pick': {
      only_post();
      // Parametri necessari
      $lifeId = (int)($_POST['life_id'] ?? $_GET['life_id'] ?? 0);
      $teamId = (int)($_POST['team_id'] ?? $_GET['team_id'] ?? 0);

      if ($lifeId<=0 || $teamId<=0) {
        out(['ok'=>false,'error'=>'bad_params','detail'=>'life_id/team_id mancanti'],400);
      }

      // Chiama il core: valida la pick per (torneo, vita, round, team)
      // L’helper del core gestisce policy (lock, team già usato, ecc.)
      $res = TC::validatePick($pdo, $tournamentId, $lifeId, $round, $teamId);

      // Risposta standardizzata per il front
      out([
        'ok' => true,
        'validation' => [
          'ok'     => (bool)($res['ok'] ?? false),
          'reason' => $res['reason'] ?? null,
          'msg'    => $res['msg'] ?? null,
          // opzionale: suggerimenti dal core
          'fresh_pickable' => $res['fresh_pickable'] ?? null,
        ]
      ]);
    }

    case 'seal_round':
      $needAdmin(); only_post();
      $res = TC::sealRoundPicks($pdo, $tournamentId, $round);
      out($res, $res['ok'] ? 200 : 500);

    case 'reopen_round':
      $needAdmin(); only_post();
      $res = TC::reopenRoundPicks($pdo, $tournamentId, $round);
      out($res, $res['ok'] ? 200 : 500);

    case 'compute_round':
      $needAdmin(); only_post();
      $res = TC::computeRound($pdo, $tournamentId, $round);
      out($res, $res['ok'] ? 200 : 500);

    case 'publish_next_round':
      $needAdmin(); only_post();
      $res = TC::publishNextRound($pdo, $tournamentId, $round);
      out($res, $res['ok'] ? 200 : 500);

    default:
      out(['ok'=>false,'error'=>'unknown_action'],400);
  }
}catch(Throwable $e){
  out(['ok'=>false,'error'=>'api_exception','detail'=>$e->getMessage()],500);
}
