<?php
declare(strict_types=1);

/**
 * API Torneo Flash — JSON only, con debug iper-specifico
 *
 * Azioni utenti: summary, my_lives, list_events (alias: events, round_events), submit_picks, buy_life, unjoin
 * Azioni admin : create, add_event, publish, seal_round, reopen_round, compute_round, publish_next_round, finalize_tournament
 */

ini_set('display_errors','0'); ini_set('log_errors','1'); ini_set('error_log','/tmp/php_errors.log');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }

define('APP_ROOT', dirname(__DIR__, 2));
require_once APP_ROOT . '/engine/FlashTournamentCore.php';
require_once APP_ROOT . '/engine/FlashTournamentFinalizer.php';
require_once APP_ROOT . '/partials/csrf.php';

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

  // --- Router action (accetta GET o POST); alias compat per FE
  $act = $_GET['action'] ?? $_POST['action'] ?? '';
  if ($act==='events' || $act==='round_events') { $act = 'list_events'; }
  if ($act==='') out(['ok'=>false,'error'=>'missing_action','where'=>'api.flash.router'],400);

  // --- Risoluzione torneo: accetta id e vari alias di code
  $id    = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id'])?(int)$_POST['id']:0);
  $tcode = $_GET['tid'] ?? $_POST['tid'] ?? $_GET['code'] ?? $_POST['code'] ?? $_GET['tcode'] ?? $_POST['tcode'] ?? null;
  $tId   = tid($pdo, $id, $tcode);

  /* ===== SUMMARY (usato dalla pagina flash) ===== */
  if ($act==='summary'){
    if($tId<=0) out(['ok'=>false,'error'=>'bad_tournament','where'=>'api.flash.summary'],400);

    // torneo base
    $st=$pdo->prepare("SELECT id, code, name, status, buyin, lives_max_user, lock_at,
                              COALESCE(guaranteed_prize,0) AS guaranteed_prize,
                              COALESCE(buyin_to_prize_pct,0) AS buyin_to_prize_pct
                       FROM tournament_flash WHERE id=? LIMIT 1");
    $st->execute([$tId]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if(!$t) out(['ok'=>false,'error'=>'not_found','where'=>'api.flash.summary'],404);

    // stats
    $stLives = $pdo->prepare("SELECT COUNT(*) FROM tournament_flash_lives WHERE tournament_id=?");
    $stLives->execute([$tId]); 
    $livesTotal = (int)$stLives->fetchColumn();

    $stPlayers = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM tournament_flash_lives WHERE tournament_id=?");
    $stPlayers->execute([$tId]); 
    $participants = (int)$stPlayers->fetchColumn();
    if ($participants===0){
      $stAlt = $pdo->prepare("SELECT COUNT(*) FROM tournament_flash_users WHERE tournament_id=?");
      $stAlt->execute([$tId]); 
      $participants = (int)$stAlt->fetchColumn();
    }

    // pool dinamico
    $buyin = (float)$t['buyin'];
    $pct   = (float)$t['buyin_to_prize_pct'];
    if ($pct>0 && $pct<=1) $pct *= 100.0;
    $pct = max(0.0, min(100.0, $pct));
    $poolFrom = round($buyin * $livesTotal * ($pct/100.0), 2);
    $pool = max($poolFrom, (float)$t['guaranteed_prize']);

    out([
      'ok' => true,
      'tournament' => [
        'id' => (int)$t['id'],
        'code' => $t['code'],
        'name' => $t['name'],
        'status' => $t['status'],
        'buyin' => $buyin,
        'lives_max_user' => isset($t['lives_max_user']) ? (int)$t['lives_max_user'] : null,
        'lock_at' => $t['lock_at'],
        'guaranteed_prize' => (float)$t['guaranteed_prize'],
        'buyin_to_prize_pct' => (float)$t['buyin_to_prize_pct'],
        'pool_coins' => $pool,
        'lives_total' => $livesTotal
      ],
      'stats' => [
        'participants' => $participants
      ]
    ]);
  }

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
      'buyin_to_prize_pct' => (float)$_POST['buyin_to_prize_pct'] ?? 0,
      'rake_pct'           => (float)$_POST['rake_pct'] ?? 0,
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

    // 1) eventi dal core
    $res = FC::listEvents($pdo, $tId, $round);

    // 2) id squadre
    $rows = $res['rows'] ?? $res['events'] ?? [];
    $ids = [];
    foreach ($rows as $r){
      $h = (int)($r['home_id'] ?? $r['home_team_id'] ?? 0);
      $a = (int)($r['away_id'] ?? $r['away_team_id'] ?? 0);
      if ($h>0) $ids[] = $h;
      if ($a>0) $ids[] = $a;
    }
    $ids = array_values(array_unique(array_filter($ids)));

    // 3) mappa loghi/nome da teams
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

    // 4) arricchisci righe
    foreach ($rows as $i=>$r){
      $hid = (int)($r['home_id'] ?? $r['home_team_id'] ?? 0);
      $aid = (int)($r['away_id'] ?? $r['away_team_id'] ?? 0);

      if (empty($r['home_logo']) && $hid && isset($map[$hid]['logo']))  $rows[$i]['home_logo'] = $map[$hid]['logo'];
      if (empty($r['away_logo']) && $aid && isset($map[$aid]['logo']))  $rows[$i]['away_logo'] = $map[$aid]['logo'];
      if (empty($r['home_name']) && $hid && isset($map[$hid]['name']))  $rows[$i]['home_name'] = $map[$hid]['name'];
      if (empty($r['away_name']) && $aid && isset($map[$aid]['name']))  $rows[$i]['away_name'] = $map[$aid]['name'];
    }

    // 5) out coerente
    if (isset($res['rows'])) $res['rows'] = $rows; else $res['events'] = $rows;
    if (!isset($res['events'])) $res['events'] = $rows;

    if($DBG) $res['debug']=['where'=>'api.flash.list_events','enriched'=>true];
    out($res);
  }

  /* ===== PUBLISH ===== */
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
    csrf_assert_token($_POST['csrf_token'] ?? ''); // protezione CSRF
    $payloadStr=(string)($_POST['payload'] ?? '[]');
    $payload=json_decode($payloadStr,true) ?: [];
    $res=FC::submitPicks($pdo,$tId,$uid,$payload); if($DBG) $res['debug']=['where'=>'api.flash.submit_picks']; out($res);
  }

  if ($act==='my_lives'){
    if($tId<=0) out(['ok'=>false,'error'=>'bad_tournament'],400);
    $st=$pdo->prepare("SELECT id,life_no,status,`round` FROM tournament_flash_lives WHERE tournament_id=? AND user_id=? ORDER BY life_no ASC");
    $st->execute([$tId,$uid]); 
    $rows=$st->fetchAll(\PDO::FETCH_ASSOC);
    out(['ok'=>true,'lives'=>$rows]);
  }

  /* ===== BUY LIFE (utente) ===== */
  if ($act==='buy_life'){
    only_post();
    if($tId<=0) out(['ok'=>false,'error'=>'bad_tournament'],400);
    csrf_assert_token($_POST['csrf_token'] ?? '');

    // Torneo
    $st=$pdo->prepare("SELECT id,buyin,lives_max_user,lock_at FROM tournament_flash WHERE id=? LIMIT 1");
    $st->execute([$tId]); $t=$st->fetch(PDO::FETCH_ASSOC);
    if(!$t) out(['ok'=>false,'error'=>'not_found'],404);

    // Lock passato?
    $lockTs = !empty($t['lock_at']) ? strtotime($t['lock_at']) : null;
    if ($lockTs && time() >= $lockTs) out(['ok'=>false,'error'=>'locked','detail'=>'Round 1 in lock'],400);

    // Quante vite ho già?
    $st=$pdo->prepare("SELECT COUNT(*) FROM tournament_flash_lives WHERE tournament_id=? AND user_id=?");
    $st->execute([$tId,$uid]); $mine=(int)$st->fetchColumn();
    $max = (int)($t['lives_max_user'] ?? 1);
    if ($max>0 && $mine >= $max) out(['ok'=>false,'error'=>'lives_limit'],400);

    $buyin = (float)$t['buyin'];

    $pdo->beginTransaction();
    try{
      // iscrivimi se non presente
      $pdo->prepare("INSERT IGNORE INTO tournament_flash_users (tournament_id,user_id,joined_at) VALUES (?,?,NOW())")
          ->execute([$tId,$uid]);

      // fondi
      $st=$pdo->prepare("SELECT coins FROM users WHERE id=? FOR UPDATE");
      $st->execute([$uid]); $coins=(float)$st->fetchColumn();
      if ($coins < $buyin){ $pdo->rollBack(); out(['ok'=>false,'error'=>'no_funds'],400); }

      // addebito + nuova vita
      $pdo->prepare("UPDATE users SET coins=coins-? WHERE id=?")->execute([$buyin,$uid]);
      $lifeNo = $mine + 1;
      $pdo->prepare("INSERT INTO tournament_flash_lives (tournament_id,user_id,life_no,status,`round`) VALUES (?,?,?,?,1)")
          ->execute([$tId,$uid,$lifeNo,'alive']);

      $pdo->commit();
      out(['ok'=>true,'life_no'=>$lifeNo]);
    }catch(\Throwable $e){
      if($pdo->inTransaction()) $pdo->rollBack();
      out(['ok'=>false,'error'=>'tx_failed','detail'=>$e->getMessage()],500);
    }
  }

  /* ===== UNJOIN (utente) ===== */
  if ($act==='unjoin'){
    only_post();
    if($tId<=0) out(['ok'=>false,'error'=>'bad_tournament'],400);
    csrf_assert_token($_POST['csrf_token'] ?? '');

    $st=$pdo->prepare("SELECT id,buyin,lock_at FROM tournament_flash WHERE id=? LIMIT 1");
    $st->execute([$tId]); $t=$st->fetch(PDO::FETCH_ASSOC);
    if(!$t) out(['ok'=>false,'error'=>'not_found'],404);

    $lockTs = !empty($t['lock_at']) ? strtotime($t['lock_at']) : null;
    if ($lockTs && time() >= $lockTs) out(['ok'=>false,'error'=>'locked','detail'=>'Round 1 in lock'],400);

    $pdo->beginTransaction();
    try{
      // vite possedute
      $st=$pdo->prepare("SELECT COUNT(*) FROM tournament_flash_lives WHERE tournament_id=? AND user_id=?");
      $st->execute([$tId,$uid]); $cnt=(int)$st->fetchColumn();
      $refund = (float)$t['buyin'] * $cnt;

      // cancella vite + iscrizione
      $pdo->prepare("DELETE FROM tournament_flash_lives  WHERE tournament_id=? AND user_id=?")->execute([$tId,$uid]);
      $pdo->prepare("DELETE FROM tournament_flash_users WHERE tournament_id=? AND user_id=?")->execute([$tId,$uid]);

      // rimborso
      if ($refund>0){
        $pdo->prepare("UPDATE users SET coins=coins+? WHERE id=?")->execute([$refund,$uid]);
      }

      $pdo->commit();
      out(['ok'=>true,'refunded'=>$refund]);
    }catch(\Throwable $e){
      if($pdo->inTransaction()) $pdo->rollBack();
      out(['ok'=>false,'error'=>'tx_failed','detail'=>$e->getMessage()],500);
    }
  }

  /* ===== COMPUTE & NEXT (admin) ===== */
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

  /* ===== FINALIZE (admin) ===== */
  if ($act==='finalize_tournament'){
    only_post(); if(!is_admin()) out(['ok'=>false,'error'=>'forbidden'],403);
    if($tId<=0) out(['ok'=>false,'error'=>'bad_tournament'],400);
    $res=FF::finalize($pdo,$tId,(int)$uid); if($DBG) $res['debug']=['where'=>'api.flash.finalize_tournament']; out($res);
  }

  // Fallback router
  out(['ok'=>false,'error'=>'unknown_action','where'=>'api.flash.router'],400);

}catch(\Throwable $e){
  $payload=['ok'=>false,'error'=>'api_exception','message'=>$e->getMessage(),'file'=>$e->getFile(),'line'=>$e->getLine()];
  if ($DBG){ $payload['trace']=$e->getTraceAsString(); }
  out($payload,500);
}
