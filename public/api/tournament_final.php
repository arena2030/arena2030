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

// 🔧 FIX percorso engine
define('APP_ROOT', dirname(__DIR__, 2));
// prima era: require_once __DIR__ . '/../../app/engine/TournamentFinalizer.php';
require_once APP_ROOT . '/engine/TournamentFinalizer.php';

use \TournamentFinalizer as TF;

/* ===== DEBUG opzionale ===== */
$__DBG = (isset($_GET['debug']) && $_GET['debug']=='1') || (isset($_POST['debug']) && $_POST['debug']=='1');
if ($__DBG) { ini_set('display_errors','1'); error_reporting(E_ALL); header('X-Debug','1'); }

/* ===== Auth minima ===== */
$uid  = (int)($_SESSION['uid'] ?? 0);
$role = $_SESSION['role'] ?? 'USER';
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

// Alias compatibilità: consenti anche action=finalize_tournament
if ($act === 'finalize_tournament') {
  $act = 'finalize';
}

/* ====== ACTIONS ====== */

/**
 * GET ?action=check_end&id=..|tid=..
 * Ritorna se il torneo deve finire ora: {should_end, reason, alive_users, round}
 */
if ($act==='check_end') {
  if ($tournamentId<=0) jsonOut(['ok'=>false,'error'=>'bad_tournament'],400);
  $res = TF::shouldEndTournament($pdo, $tournamentId);
  jsonOut(array_merge(['ok'=>true], $res));
}

/**
 * POST ?action=finalize&id=..|tid=..
 * Finalizza torneo (payout + chiusura). Solo ADMIN/PUNTO.
 * Ritorna winners (con avatar) e leaderboard_top10 (con avatar).
 */
if ($act === 'finalize') {
  // 🔒 Permessi: ADMIN, PUNTO oppure is_admin=1
  $roleUp = strtoupper((string)$role);
  $isAdminFlag = (int)($_SESSION['is_admin'] ?? 0) === 1;
  if (!in_array($roleUp, ['ADMIN','PUNTO'], true) && !$isAdminFlag) {
    jsonOut(['ok' => false, 'error' => 'forbidden'], 403);
  }

  only_post();
  if ($tournamentId <= 0) {
    jsonOut(['ok' => false, 'error' => 'bad_tournament'], 400);
  }

  $adminId = (int)($_SESSION['uid'] ?? 0);
$res = TF::finalizeTournament($pdo, $tournamentId, $adminId);
  if (!$res['ok']) {
    jsonOut($res, 500);
  }
  jsonOut($res);
}

/**
 * GET ?action=leaderboard&id=..|tid=..
 * Restituisce Top 10 classifica completa di avatar, con eventuali vincitori in testa.
 */
if ($act==='leaderboard') {
  if ($tournamentId<=0) jsonOut(['ok'=>false,'error'=>'bad_tournament'],400);
  // prova a leggere vincitori dal payout (se esiste), altrimenti nessun winnerSet
  // (serve per posizionarli in testa nella classifica)
  $winnerIds=[];
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tournament_payouts'");
  $q->execute();
  if ($q->fetchColumn()){
    $idCol = 'tournament_id';
    $uCol  = 'user_id';
    $st=$pdo->prepare("SELECT $uCol FROM tournament_payouts WHERE $idCol=? ORDER BY amount DESC");
    $st->execute([$tournamentId]);
    $winnerIds = array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN));
  }
  $top10 = TF::buildLeaderboard($pdo, $tournamentId, $winnerIds);
  jsonOut(['ok'=>true,'top10'=>$top10]);
}

/**
 * GET ?action=user_notice&id=..|tid=..
 * Se l’utente loggato è fra i vincitori, restituisce payload per il pop-up (avatar inclusi).
 */
if ($act==='user_notice') {
  if ($tournamentId<=0) jsonOut(['ok'=>false,'error'=>'bad_tournament'],400);
  $res = TF::userNotice($pdo, $tournamentId, $uid);
  jsonOut($res);
}

/* default */
jsonOut(['ok'=>false,'error'=>'unknown_action'],400);
