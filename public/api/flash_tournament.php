<?php
declare(strict_types=1);

ini_set('display_errors','0'); ini_set('log_errors','1'); ini_set('error_log','/tmp/php_errors.log');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }

define('APP_ROOT', dirname(__DIR__, 2));
require_once APP_ROOT . '/engine/FlashTournamentCore.php';
require_once APP_ROOT . '/engine/FlashTournamentFinalizer.php';

use \FlashTournamentCore as FC;
use \FlashTournamentFinalizer as FF;

$DBG = (($_GET['debug'] ?? '')==='1' || ($_POST['debug'] ?? '')==='1');
if ($DBG) { header('X-Debug: 1'); }

function out(array $p, int $code=200){ http_response_code($code); echo json_encode($p); exit; }
function only_post(){ if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') out(['ok'=>false,'error'=>'method'],405); }
function as_admin(): bool {
  $r = strtoupper((string)($_SESSION['role'] ?? 'USER'));
  return in_array($r, ['ADMIN','PUNTO'], true) || (int)($_SESSION['is_admin'] ?? 0)===1;
}

function resolve_tid(PDO $pdo, ?int $id, ?string $code): int {
  if ($id && $id>0) return $id;
  if ($code){
    $st=$pdo->prepare("SELECT id FROM flash_tournaments WHERE UPPER(code)=UPPER(?) LIMIT 1");
    $st->execute([$code]); $x=$st->fetchColumn();
    return $x? (int)$x : 0;
  }
  return 0;
}

try{
  $uid = (int)($_SESSION['uid'] ?? 0);
  if ($uid<=0) out(['ok'=>false,'error'=>'unauthorized'],401);

  $act = $_GET['action'] ?? $_POST['action'] ?? '';
  if ($act==='') out(['ok'=>false,'error'=>'missing_action'],400);

  $id  = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id'])?(int)$_POST['id']:0);
  $tidCode = $_GET['tid'] ?? $_POST['tid'] ?? null;
  $tid = resolve_tid($pdo, $id, $tidCode);

  /* CREATE */
  if ($act==='create'){
    only_post(); if (!as_admin()) out(['ok'=>false,'error'=>'forbidden'],403);
    $data = [
      'name' => trim((string)($_POST['name'] ?? 'Torneo Flash')),
      'total_rounds' => max(1,(int)($_POST['total_rounds'] ?? 1)),
      'events_per_round' => max(1,(int)($_POST['events_per_round'] ?? 1)),
      'prize_pool' => (float)($_POST['prize_pool'] ?? 0)
    ];
    $res = FC::create($pdo, $data); out($res);
  }

  /* ADD EVENT */
  if ($act==='add_event'){
    only_post(); if (!as_admin()) out(['ok'=>false,'error'=>'forbidden'],403);
    $round=(int)($_POST['round_no'] ?? 0); $home=(int)($_POST['home_team_id'] ?? 0); $away=(int)($_POST['away_team_id'] ?? 0);
    if ($tid<=0) out(['ok'=>false,'error'=>'bad_tournament'],400);
    $res = FC::addEvent($pdo,$tid,$round,$home,$away); out($res);
  }

  /* LIST EVENTS */
  if ($act==='list_events'){
    $round = (int)($_GET['round_no'] ?? $_POST['round_no'] ?? 1);
    if ($tid<=0) out(['ok'=>false,'error'=>'bad_tournament'],400);
    $res = FC::listEvents($pdo,$tid,$round); out($res);
  }

  /* PUBLISH */
  if ($act==='publish'){
    only_post(); if (!as_admin()) out(['ok'=>false,'error'=>'forbidden'],403);
    if ($tid<=0) out(['ok'=>false,'error'=>'bad_tournament'],400);
    $res = FC::publish($pdo,$tid); out($res);
  }

  /* SEAL / REOPEN */
  if ($act==='seal_round'){
    only_post(); if (!as_admin()) out(['ok'=>false,'error'=>'forbidden'],403);
    $round=(int)($_POST['round_no'] ?? 0); if ($round<=0) out(['ok'=>false,'error'=>'bad_round'],400);
    $res=FC::sealRound($pdo,$tid,$round); out($res);
  }
  if ($act==='reopen_round'){
    only_post(); if (!as_admin()) out(['ok'=>false,'error'=>'forbidden'],403);
    $round=(int)($_POST['round_no'] ?? 0); if ($round<=0) out(['ok'=>false,'error'=>'bad_round'],400);
    $res=FC::reopenRound($pdo,$tid,$round); out($res);
  }

  /* SUBMIT PICKS UPFRONT (utente) */
  if ($act==='submit_picks'){
    only_post(); $payload = json_decode((string)($_POST['payload'] ?? '[]'), true) ?: [];
    if ($tid<=0) out(['ok'=>false,'error'=>'bad_tournament'],400);
    $res = FC::submitPicks($pdo,$tid,$uid,$payload); out($res);
  }

  if ($act==='my_lives'){
    $st=$pdo->prepare("SELECT id,life_no,status,round FROM flash_tournament_lives WHERE tournament_id=? AND user_id=? ORDER BY life_no ASC");
    $st->execute([$tid,$uid]); out(['ok'=>true,'lives'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
  }

  /* COMPUTE & PUBLISH NEXT */
  if ($act==='compute_round'){
    only_post(); if (!as_admin()) out(['ok'=>false,'error'=>'forbidden'],403);
    $round=(int)($_POST['round_no'] ?? 0); if ($round<=0) out(['ok'=>false,'error'=>'bad_round'],400);
    $res=FC::computeRound($pdo,$tid,$round); out($res);
  }
  if ($act==='publish_next_round'){
    only_post(); if (!as_admin()) out(['ok'=>false,'error'=>'forbidden'],403);
    $round=(int)($_POST['round_no'] ?? 0); if ($round<=0) out(['ok'=>false,'error'=>'bad_round'],400);
    $res=FC::publishNextRound($pdo,$tid,$round); out($res);
  }

  /* FINALIZE */
  if ($act==='finalize_tournament'){
    only_post(); if (!as_admin()) out(['ok'=>false,'error'=>'forbidden'],403);
    $res = FF::finalize($pdo,$tid,(int)$uid); out($res);
  }

  out(['ok'=>false,'error'=>'unknown_action'],400);

}catch(\Throwable $e){
  $pay = ['ok'=>false,'error'=>'api_exception','message'=>$e->getMessage(),'file'=>$e->getFile(),'line'=>$e->getLine()];
  if ($DBG){ $pay['trace']=$e->getTraceAsString(); }
  out($pay, 500);
}
