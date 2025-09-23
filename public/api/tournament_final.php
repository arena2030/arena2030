<?php
// /public/api/tournament_final.php — API Finalizzazione torneo (check_end, finalize, leaderboard, user_notice)

declare(strict_types=1);
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log','/tmp/php_errors.log');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }

/* ==== Utils out ==== */
function jsonOut($a, $code=200){ http_response_code($code); echo json_encode($a); exit; }
function only_post(){ if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST'){ jsonOut(['ok'=>false,'error'=>'method'],405); } }

/* ===== DEBUG opzionale ===== */
$__DBG = (isset($_GET['debug']) && $_GET['debug']=='1') || (isset($_POST['debug']) && $_POST['debug']=='1');
if ($__DBG) { header('X-Debug','1'); /* display_errors resta off per evitare bad_json */ }

/* ===== Auth minima ===== */
$uid  = (int)($_SESSION['uid'] ?? 0);
$role = $_SESSION['role'] ?? 'USER';
if ($uid <= 0 || !in_array($role, ['USER','PUNTO','ADMIN'], true)) {
  jsonOut(['ok'=>false,'error'=>'unauthorized'], 401);
}

/* ===== Caricamento sicuro Finalizer ===== */
define('APP_ROOT', dirname(__DIR__, 2));
$__finalizer_loaded = false;
$__finalizer_paths = [
  APP_ROOT . '/engine/TournamentFinalizer.php',
  APP_ROOT . '/app/engine/TournamentFinalizer.php',
  __DIR__   . '/../../engine/TournamentFinalizer.php',
  __DIR__   . '/../../app/engine/TournamentFinalizer.php',
];
foreach ($__finalizer_paths as $__p) {
  if (is_file($__p)) { include_once $__p; if (class_exists('\TournamentFinalizer', false) || class_exists('TournamentFinalizer', false)) { $__finalizer_loaded = true; break; } }
}
if (!$__finalizer_loaded) {
  jsonOut(['ok'=>false,'error'=>'finalizer_missing','detail'=>'TournamentFinalizer non trovato','searched'=>$__finalizer_paths], 500);
}

use \TournamentFinalizer as TF;

/* ===== Parse torneo target ===== */
$id  = isset($_GET['id'])  ? (int)$_GET['id']  : (isset($_POST['id'])?(int)$_POST['id']:0);
$tid = isset($_GET['tid']) ? (string)$_GET['tid'] : (isset($_POST['tid'])?(string)$_POST['tid']:null);
$tournamentId = TF::resolveTournamentId($pdo, $id, $tid);

$act = $_GET['action'] ?? $_POST['action'] ?? '';
if ($act==='') { jsonOut(['ok'=>false,'error'=>'missing_action'],400); }

/* ====== ACTIONS ====== */

/**
 * GET ?action=check_end&id=..|tid=..
 */
if ($act==='check_end') {
  if ($tournamentId<=0) jsonOut(['ok'=>false,'error'=>'bad_tournament'],400);
  $res = TF::shouldEndTournament($pdo, $tournamentId);
  jsonOut(['ok'=>true] + $res);
}

/**
 * POST ?action=finalize&id=..|tid=..
 */
if ($act === 'finalize') {
  // 🔒 Permessi: ADMIN, PUNTO oppure is_admin=1
  $roleUp = strtoupper((string)$role);
  $isAdminFlag = (int)($_SESSION['is_admin'] ?? 0) === 1;
  if (!in_array($roleUp, ['ADMIN','PUNTO'], true) && !$isAdminFlag) {
    jsonOut(['ok' => false, 'error' => 'forbidden'], 403);
  }

  only_post();
  if ($tournamentId <= 0) jsonOut(['ok' => false, 'error' => 'bad_tournament'], 400);

  try {
    $adminId = (int)($_SESSION['uid'] ?? 0);
    $res = TF::finalizeTournament($pdo, $tournamentId, $adminId);
  } catch (Throwable $e) {
    jsonOut([
      'ok'=>false,
      'error'=>'finalize_exception',
      'detail'=>$e->getMessage(),
      'trace'=>$__DBG ? $e->getTraceAsString() : null
    ], 500);
  }

  // Se il finalizer ha un errore applicativo (es. not_final), tienilo a 200
  if (!$res['ok']) { jsonOut($res, 200); }

  jsonOut($res);
}

/**
 * GET ?action=leaderboard&id=..|tid=..
 */
if ($act==='leaderboard') {
  if ($tournamentId<=0) jsonOut(['ok'=>false,'error'=>'bad_tournament'],400);

  // (Opzionale) prova a leggere i vincitori per metterli in testa — mapping dinamico, altrimenti salta
  $winnerIds=[];
  try {
    $tbl = 'tournament_payouts';
    $chk=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $chk->execute([$tbl]);
    if ($chk->fetchColumn()) {
      // colonne possibili
      $colTid = null; foreach (['tournament_id','tid'] as $c){ $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?"); $q->execute([$tbl,$c]); if($q->fetchColumn()){ $colTid=$c; break; } }
      $colUid = null; foreach (['user_id','uid']          as $c){ $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?"); $q->execute([$tbl,$c]); if($q->fetchColumn()){ $colUid=$c; break; } }
      $colAmt = null; foreach (['amount','coins','payout_coins'] as $c){ $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?"); $q->execute([$tbl,$c]); if($q->fetchColumn()){ $colAmt=$c; break; } }

      if ($colTid && $colUid && $colAmt) {
        $sql="SELECT $colUid FROM $tbl WHERE $colTid=? ORDER BY $colAmt DESC";
        $st=$pdo->prepare($sql); $st->execute([$tournamentId]);
        $winnerIds = array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN));
      }
    }
  } catch(Throwable $e){ /* ignora: non deve bloccare la classifica */ }

  $top10 = TF::buildLeaderboard($pdo, $tournamentId, $winnerIds);
  jsonOut(['ok'=>true,'top10'=>$top10]);
}

/**
 * GET ?action=user_notice&id=..|tid=..
 */
if ($act==='user_notice') {
  if ($tournamentId<=0) jsonOut(['ok'=>false,'error'=>'bad_tournament'],400);
  $res = TF::userNotice($pdo, $tournamentId, $uid);
  jsonOut($res);
}

/* default */
jsonOut(['ok'=>false,'error'=>'unknown_action'],400);
