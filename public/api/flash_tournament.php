<?php
declare(strict_types=1);

/**
 * API Torneo Flash — JSON only, con debug iper-specifico
 *
 * Azioni: create, add_event, list_events, publish, seal_round, reopen_round,
 *          submit_picks, my_lives, compute_round, publish_next_round, finalize_tournament
 */

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
function only_post(){ if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') out(['ok'=>false,'error'=>'method','where'=>'api.flash.only_post'],405); }
function is_admin(): bool {
  $r = strtoupper((string)($_SESSION['role'] ?? 'USER'));
  return in_array($r, ['ADMIN','PUNTO'], true) || (int)($_SESSION['is_admin'] ?? 0)===1;
}
function tid(\PDO $pdo, ?int $id, ?string $code): int {
  return FC::resolveId($pdo, $id, $code);
}

try{
  $uid = (int)($_SESSION['uid'] ?? 0);
  if ($uid<=0) out(['ok'=>false,'error'=>'unauthorized'],401);

  $act = $_GET['action'] ?? $_POST['action'] ?? '';
  if ($act==='') out(['ok'=>false,'error'=>'missing_action'],400);

  $id  = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id'])?(int)$_POST['id']:0);
  $tcode = $_GET['tid'] ?? $_POST['tid'] ?? null;
  $tId   = tid($pdo, $id, $tcode);

  /* ===== CREATE ===== */
  if ($act==='create'){
    only_post(); if(!is_admin()) out(['ok'=>false,'error'=>'forbidden'],403);
    $data = [
      'name'               => trim((string)($_POST['name'] ?? 'Torneo Flash')),
      'buyin'              => (float)($_POST['buyin'] ?? 0),
      'seats_infinite'     => (int)($_POST['seats_infinite'] ?? 0),
      'seats_max'          => isset($_POST['seats_max']) ? (int)$_POST['seats_max'] : null,
      'lives_max_user'     => (int)($_POST['lives_max_user'] ?? 1),
      'guaranteed_prize'   => strlen(trim((string)($_POST['guaranteed_prize'] ?? ''))) ? (float)$_POST['guaranteed_prize'] : null,
      'buyin_to_prize_pct' => (float)($_POST['buyin_to_prize_pct'] ?? 0),
      'rake_pct'           => (float)($_POST['rake_pct'] ?? 0),
    ];
    $res = FC::create($pdo,$data); if($DBG) $res['debug']=['where'=>'api.flash.create']; out($res);
  }

  /* ===== EVENTS ===== */
  if ($act==='add_event'){
    only_post(); if(!is_admin()) out(['ok'=>false,'error'=>'forbidden'],403);
    if($tId<=0) out(['ok'=>false,'error'=>'bad_tournament'],400);
    $round=(int)($_POST['round_no'] ?? 0);
    $home =(int)($_POST['home_team_id'] ?? 0);
    $away =(int)($_POST['away_team_id'] ?? 0);
    $res=FC::addEvent($pdo,$tId,$round,$home,$away); if($DBG) $res['debug']=['where'=>'api.flash.add_event']; out($res);
  }

if ($act==='list_events'){
  if($tId<=0) out(['ok'=>false,'error'=>'bad_tournament'],400);
  $round=(int)($_GET['round_no'] ?? $_POST['round_no'] ?? 1);

  // 1) prendi gli eventi dal core
  $res = FC::listEvents($pdo, $tId, $round);

  // 2) estrai gli id squadra (gestisce sia home_id/away_id che home_team_id/away_team_id)
  $rows = $res['rows'] ?? $res['events'] ?? [];
  $ids = [];
  foreach ($rows as $r){
    $h = (int)($r['home_id'] ?? $r['home_team_id'] ?? 0);
    $a = (int)($r['away_id'] ?? $r['away_team_id'] ?? 0);
    if ($h>0) $ids[] = $h;
    if ($a>0) $ids[] = $a;
  }
  $ids = array_values(array_unique(array_filter($ids)));
  
  // 3) mappa loghi (e nomi se mancanti) dalla tabella teams
  $map = [];
  if ($ids){
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT id, name, slug, logo_url, logo_key FROM teams WHERE id IN ($in)");
    $st->execute($ids);
    while($t = $st->fetch(PDO::FETCH_ASSOC)){
      $url = $t['logo_url'] ?: ( ($t['logo_key'] ?? '') ? '/'.ltrim($t['logo_key'],'/') : '' );
      $map[(int)$t['id']] = [
        'name' => $t['name'] ?? null,
        'logo' => $url ?: null
      ];
    }
  }

  // 4) arricchisci le righe con home_logo/away_logo (e name se assente)
  foreach ($rows as $i=>$r){
    $hid = (int)($r['home_id'] ?? $r['home_team_id'] ?? 0);
    $aid = (int)($r['away_id'] ?? $r['away_team_id'] ?? 0);

    if (empty($r['home_logo']) && $hid && isset($map[$hid]['logo'])) {
      $rows[$i]['home_logo'] = $map[$hid]['logo'];
    }
    if (empty($r['away_logo']) && $aid && isset($map[$aid]['logo'])) {
      $rows[$i]['away_logo'] = $map[$aid]['logo'];
    }
    if (empty($r['home_name']) && $hid && isset($map[$hid]['name'])) {
      $rows[$i]['home_name'] = $map[$hid]['name'];
    }
    if (empty($r['away_name']) && $aid && isset($map[$aid]['name'])) {
      $rows[$i]['away_name'] = $map[$aid]['name'];
    }
  }

  // 5) rimetti le righe arricchite e aggiungi alias "events"
  if (isset($res['rows'])) $res['rows'] = $rows; else $res['events'] = $rows;
  if (!isset($res['events'])) $res['events'] = $rows;

  if($DBG) $res['debug']=['where'=>'api.flash.list_events','enriched'=>true];
  out($res);
}

  if ($act==='publish'){
    only_post(); if(!is_admin()) out(['ok'=>false,'error'=>'forbidden'],403);
    if($tId<=0) out(['ok'=>false,'error'=>'bad_tournament'],400);
    $res=FC::publish($pdo,$tId); if($DBG) $res['debug']=['where'=>'api.flash.publish']; out($res);
  }

  /* ===== LOCK/REOPEN ===== */
  if ($act==='seal_round'){
    only_post(); if(!is_admin()) out(['ok'=>false,'error'=>'forbidden'],403);
    if($tId<=0) out(['ok'=>false,'error'=>'bad_tournament'],400);
    $round=(int)($_POST['round_no'] ?? 0); if($round<=0) out(['ok'=>false,'error'=>'bad_round'],400);
    $res=FC::sealRound($pdo,$tId,$round); if($DBG) $res['debug']=['where'=>'api.flash.seal_round']; out($res);
  }

  if ($act==='reopen_round'){
    only_post(); if(!is_admin()) out(['ok'=>false,'error'=>'forbidden'],403);
    if($tId<=0) out(['ok'=>false,'error'=>'bad_tournament'],400);
    $round=(int)($_POST['round_no'] ?? 0); if($round<=0) out(['ok'=>false,'error'=>'bad_round'],400);
    $res=FC::reopenRound($pdo,$tId,$round); if($DBG) $res['debug']=['where'=>'api.flash.reopen_round']; out($res);
  }

  /* ===== PICKS ===== */
  if ($act==='submit_picks'){
    only_post();
    if($tId<=0) out(['ok'=>false,'error'=>'bad_tournament'],400);
    $payloadStr=(string)($_POST['payload'] ?? '[]');
    $payload=json_decode($payloadStr,true) ?: [];
    $res=FC::submitPicks($pdo,$tId,$uid,$payload); if($DBG) $res['debug']=['where'=>'api.flash.submit_picks']; out($res);
  }

  if ($act==='my_lives'){
    if($tId<=0) out(['ok'=>false,'error'=>'bad_tournament'],400);
    $st=$pdo->prepare("SELECT id,life_no,status,`round` FROM tournament_flash_lives WHERE tournament_id=? AND user_id=? ORDER BY life_no ASC");
    $st->execute([$tId,$uid]); $rows=$st->fetchAll(\PDO::FETCH_ASSOC);
    out(['ok'=>true,'lives'=>$rows]);
  }

  /* ===== COMPUTE & NEXT ===== */
  if ($act==='compute_round'){
    only_post(); if(!is_admin()) out(['ok'=>false,'error'=>'forbidden'],403);
    if($tId<=0) out(['ok'=>false,'error'=>'bad_tournament'],400);
    $round=(int)($_POST['round_no'] ?? 0); if($round<=0) out(['ok'=>false,'error'=>'bad_round'],400);
    $res=FC::computeRound($pdo,$tId,$round); if($DBG) $res['debug']=['where'=>'api.flash.compute_round']; out($res);
  }

  if ($act==='publish_next_round'){
    only_post(); if(!is_admin()) out(['ok'=>false,'error'=>'forbidden'],403);
    if($tId<=0) out(['ok'=>false,'error'=>'bad_tournament'],400);
    $round=(int)($_POST['round_no'] ?? 0); if($round<=0) out(['ok'=>false,'error'=>'bad_round'],400);
    $res=FC::publishNextRound($pdo,$tId,$round); if($DBG) $res['debug']=['where'=>'api.flash.publish_next_round']; out($res);
  }

  /* ===== FINALIZE ===== */
  if ($act==='finalize_tournament'){
    only_post(); if(!is_admin()) out(['ok'=>false,'error'=>'forbidden'],403);
    if($tId<=0) out(['ok'=>false,'error'=>'bad_tournament'],400);
    $res=FF::finalize($pdo,$tId,(int)$uid); if($DBG) $res['debug']=['where'=>'api.flash.finalize_tournament']; out($res);
  }

  out(['ok'=>false,'error'=>'unknown_action','where'=>'api.flash.router'],400);

}catch(\Throwable $e){
  $payload=['ok'=>false,'error'=>'api_exception','message'=>$e->getMessage(),'file'=>$e->getFile(),'line'=>$e->getLine()];
  if ($DBG){ $payload['trace']=$e->getTraceAsString(); }
  out($payload,500);
}
