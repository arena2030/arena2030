<?php
declare(strict_types=1);

/**
 * API Torneo Flash — JSON only
 *
 * Azioni UTENTE: summary, my_lives, list_events (alias: events, round_events), submit_picks, buy_life, unjoin
 * Azioni ADMIN : create, add_event, publish, seal_round, reopen_round, compute_round, publish_next_round, finalize_tournament
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

/* ---------- utils ---------- */
$DBG = (($_GET['debug'] ?? '')==='1' || ($_POST['debug'] ?? '')==='1');
if ($DBG) { header('X-Debug: 1'); }
function out(array $p, int $code=200){ http_response_code($code); echo json_encode($p); exit; }
function only_post(){ if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') out(['ok'=>false,'error'=>'method','where'=>'api.flash.only_post','detail'=>'Use POST'],405); }
function is_admin(): bool {
  $r = strtoupper((string)($_SESSION['role'] ?? 'USER'));
  return in_array($r, ['ADMIN','PUNTO'], true) || (int)($_SESSION['is_admin'] ?? 0)===1;
}
function tid(\PDO $pdo, ?int $id, ?string $code): int { return FC::resolveId($pdo, $id, $code); }
function col_exists(PDO $pdo, string $table, string $col): bool {
  static $cache = [];
  $k="$table.$col";
  if(isset($cache[$k])) return $cache[$k];
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $q->execute([$table,$col]);
  return $cache[$k]=(bool)$q->fetchColumn();
}
function pick_col(PDO $pdo, string $table, array $candidates): ?string {
  foreach($candidates as $c){ if(col_exists($pdo,$table,$c)) return $c; }
  return null;
}

/** ritorna info seats + partecipanti (users) */
function seats_info(PDO $pdo, int $tId): array {
  $st=$pdo->prepare("SELECT seats_infinite, seats_max, lock_at, status FROM tournament_flash WHERE id=? LIMIT 1");
  $st->execute([$tId]); $t=$st->fetch(PDO::FETCH_ASSOC) ?: [];
  $inf = (int)($t['seats_infinite'] ?? 0);
  $max = isset($t['seats_max']) && $t['seats_max']!==null ? (int)$t['seats_max'] : null;
  $stc=$pdo->prepare("SELECT COUNT(*) FROM tournament_flash_users WHERE tournament_id=?");
  $stc->execute([$tId]); $users=(int)$stc->fetchColumn();
  $full = ($inf===0 && $max!==null && $max>0 && $users >= $max);
  return ['infinite'=>$inf, 'max'=>$max, 'users'=>$users, 'full'=>$full, 'lock_at'=>$t['lock_at']??null, 'status'=>$t['status']??null];
}

/* ---------- router ---------- */
try{
  $uid = (int)($_SESSION['uid'] ?? 0);
  if ($uid<=0) out(['ok'=>false,'error'=>'unauthorized','detail'=>'Login richiesto'],401);

  // alias compat
  $act = $_GET['action'] ?? $_POST['action'] ?? '';
  if ($act==='events' || $act==='round_events') { $act = 'list_events'; }
  if ($act==='') out(['ok'=>false,'error'=>'missing_action','where'=>'api.flash.router','detail'=>'Param action mancante'],400);

  // id o code (accetta vari alias)
  $id    = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id'])?(int)$_POST['id']:0);
  $tcode = $_GET['tid'] ?? $_POST['tid'] ?? $_GET['code'] ?? $_POST['code'] ?? $_GET['tcode'] ?? $_POST['tcode'] ?? null;
  $tId   = tid($pdo, $id, $tcode);

  // autodetect colonna round nelle VITE
  $LIVES_ROUND_COL = pick_col($pdo, 'tournament_flash_lives', ['round','round_no','rnd']);

  /* ===== SUMMARY ===== */
  if ($act==='summary'){
    if($tId<=0) out(['ok'=>false,'error'=>'bad_tournament','where'=>'api.flash.summary','detail'=>'ID o code non valido'],400);

    $st=$pdo->prepare("SELECT id, code, name, status, buyin, lives_max_user, lock_at,
                              COALESCE(guaranteed_prize,0) AS guaranteed_prize,
                              COALESCE(buyin_to_prize_pct,0) AS buyin_to_prize_pct,
                              COALESCE(seats_infinite,0) AS seats_infinite,
                              seats_max
                       FROM tournament_flash WHERE id=? LIMIT 1");
    $st->execute([$tId]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if(!$t) out(['ok'=>false,'error'=>'not_found','where'=>'api.flash.summary','detail'=>'Torneo inesistente'],404);

    // stats: vite totali (per pool) e utenti iscritti (per seats)
    $stLives = $pdo->prepare("SELECT COUNT(*) FROM tournament_flash_lives WHERE tournament_id=?");
    $stLives->execute([$tId]); $livesTotal = (int)$stLives->fetchColumn();

    $stUsers = $pdo->prepare("SELECT COUNT(*) FROM tournament_flash_users WHERE tournament_id=?");
    $stUsers->execute([$tId]); $participantsUsers = (int)$stUsers->fetchColumn();

    // partecipanti "legacy": se non ci sono users, usa distinct da vite
    $participantsLegacy = $participantsUsers;
    if ($participantsLegacy === 0) {
      $stPU = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM tournament_flash_lives WHERE tournament_id=?");
      $stPU->execute([$tId]); $participantsLegacy = (int)$stPU->fetchColumn();
    }

    // pool
    $buyin = (float)$t['buyin'];
    $pct   = (float)$t['buyin_to_prize_pct']; if ($pct>0 && $pct<=1) $pct *= 100.0; $pct = max(0.0, min(100.0, $pct));
    $poolFrom = round($buyin * $livesTotal * ($pct/100.0), 2);
    $pool = max($poolFrom, (float)$t['guaranteed_prize']);

    // seats policy
    $seatsInf = (int)$t['seats_infinite'];
    $seatsMax = isset($t['seats_max']) && $t['seats_max']!==null ? (int)$t['seats_max'] : null;
    $seatsFull = ($seatsInf===0 && $seatsMax!==null && $seatsMax>0 && $participantsUsers >= $seatsMax);

    // lock
    $lockTs = !empty($t['lock_at']) ? strtotime($t['lock_at']) : null;
    $lockPassed = ($lockTs && time() >= $lockTs);

    // mie vite per policy buy
    $stMyL=$pdo->prepare("SELECT COUNT(*) FROM tournament_flash_lives WHERE tournament_id=? AND user_id=?");
    $stMyL->execute([$tId,$uid]); $myLives=(int)$stMyL->fetchColumn();
    $lmax = (int)($t['lives_max_user'] ?? 1);
    $canBuy = !$lockPassed && ($lmax<=0 || $myLives < $lmax);

    // puoi disiscrivere solo se NON seatsFull e NON lock passato
    $canUnjoin = (!$seatsFull && !$lockPassed);

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
        'lives_total' => $livesTotal,
        'seats_infinite' => $seatsInf,
        'seats_max' => $seatsMax,
        'seats_full' => $seatsFull
      ],
      'stats' => [
        'participants' => $participantsLegacy,
        'participants_users' => $participantsUsers
      ],
      'policy' => [
        'can_unjoin' => $canUnjoin,
        'why_cannot_unjoin' => $seatsFull ? 'seats_full' : ($lockPassed ? 'locked' : null),
        'can_buy_life' => $canBuy,
        'why_cannot_buy_life' => $lockPassed ? 'locked' : (($lmax>0 && $myLives >= $lmax) ? 'lives_limit' : null)
      ]
    ]);
  }

  /* ===== CREATE (admin) ===== */
  if ($act==='create'){
    only_post(); if(!is_admin()) out(['ok'=>false,'error'=>'forbidden','detail'=>'Solo admin/punto'],403);
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
    only_post(); if(!is_admin()) out(['ok'=>false,'error'=>'forbidden','detail'=>'Solo admin/punto'],403);
    if($tId<=0) out(['ok'=>false,'error'=>'bad_tournament','detail'=>'ID/code mancante'],400);
    $round=(int)($_POST['round_no'] ?? 0);
    $home =(int)($_POST['home_team_id'] ?? 0);
    $away =(int)($_POST['away_team_id'] ?? 0);
    $res=FC::addEvent($pdo,$tId,$round,$home,$away); if($DBG) $res['debug']=['where'=>'api.flash.add_event']; out($res);
  }

  if ($act==='list_events'){
    if($tId<=0) out(['ok'=>false,'error'=>'bad_tournament','detail'=>'ID/code mancante'],400);
    $round=(int)($_GET['round_no'] ?? $_POST['round_no'] ?? 1);

    $res = FC::listEvents($pdo, $tId, $round);

    $rows = $res['rows'] ?? $res['events'] ?? [];
    $ids = [];
    foreach ($rows as $r){
      $h = (int)($r['home_id'] ?? $r['home_team_id'] ?? 0);
      $a = (int)($r['away_id'] ?? $r['away_team_id'] ?? 0);
      if ($h>0) $ids[] = $h; if ($a>0) $ids[] = $a;
    }
    $ids = array_values(array_unique(array_filter($ids)));

    $map = [];
    if ($ids){
      $in = implode(',', array_fill(0, count($ids), '?'));
      $st = $pdo->prepare("SELECT id, name, slug, logo_url, logo_key FROM teams WHERE id IN ($in)");
      $st->execute($ids);
      while($t = $st->fetch(PDO::FETCH_ASSOC)){
        $url = $t['logo_url'] ?: ( ($t['logo_key'] ?? '') ? '/'.ltrim($t['logo_key'],'/') : '' );
        $map[(int)$t['id']] = ['name'=>$t['name'] ?? null, 'logo'=>$url ?: null];
      }
    }
    foreach ($rows as $i=>$r){
      $hid = (int)($r['home_id'] ?? $r['home_team_id'] ?? 0);
      $aid = (int)($r['away_id'] ?? $r['away_team_id'] ?? 0);
      if (empty($r['home_logo']) && $hid && isset($map[$hid]['logo']))  $rows[$i]['home_logo'] = $map[$hid]['logo'];
      if (empty($r['away_logo']) && $aid && isset($map[$aid]['logo']))  $rows[$i]['away_logo'] = $map[$aid]['logo'];
      if (empty($r['home_name']) && $hid && isset($map[$hid]['name']))  $rows[$i]['home_name'] = $map[$hid]['name'];
      if (empty($r['away_name']) && $aid && isset($map[$aid]['name']))  $rows[$i]['away_name'] = $map[$aid]['name'];
    }
    if (isset($res['rows'])) $res['rows'] = $rows; else $res['events'] = $rows;
    if (!isset($res['events'])) $res['events'] = $rows;

    if($DBG) $res['debug']=['where'=>'api.flash.list_events','enriched'=>true];
    out($res);
  }

  /* ===== PUBLISH / LOCK ===== */
  if ($act==='publish'){
    only_post(); if(!is_admin()) out(['ok'=>false,'error'=>'forbidden','detail'=>'Solo admin/punto'],403);
    if($tId<=0) out(['ok'=>false,'error'=>'bad_tournament','detail'=>'ID/code mancante'],400);
    $res=FC::publish($pdo,$tId); if($DBG) $res['debug']=['where'=>'api.flash.publish']; out($res);
  }
  if ($act==='seal_round'){
    only_post(); if(!is_admin()) out(['ok'=>false,'error'=>'forbidden','detail'=>'Solo admin/punto'],403);
    if($tId<=0) out(['ok'=>false,'error'=>'bad_tournament','detail'=>'ID/code mancante'],400);
    $round=(int)($_POST['round_no'] ?? 0); if($round<=0) out(['ok'=>false,'error'=>'bad_round','detail'=>'round_no mancante'],400);
    $res=FC::sealRound($pdo,$tId,$round); if($DBG) $res['debug']=['where'=>'api.flash.seal_round']; out($res);
  }
  if ($act==='reopen_round'){
    only_post(); if(!is_admin()) out(['ok'=>false,'error'=>'forbidden','detail'=>'Solo admin/punto'],403);
    if($tId<=0) out(['ok'=>false,'error'=>'bad_tournament','detail'=>'ID/code mancante'],400);
    $round=(int)($_POST['round_no'] ?? 0); if($round<=0) out(['ok'=>false,'error'=>'bad_round','detail'=>'round_no mancante'],400);
    $res=FC::reopenRound($pdo,$tId,$round); if($DBG) $res['debug']=['where'=>'api.flash.reopen_round']; out($res);
  }

  /* ===== PICKS ===== */
  if ($act==='submit_picks'){
    only_post();
    if($tId<=0) out(['ok'=>false,'error'=>'bad_tournament','detail'=>'ID/code mancante'],400);
    csrf_verify_or_die();
    $payloadStr=(string)($_POST['payload'] ?? '[]');
    $payload=json_decode($payloadStr,true) ?: [];
    $res=FC::submitPicks($pdo,$tId,$uid,$payload); if($DBG) $res['debug']=['where'=>'api.flash.submit_picks']; out($res);
  }

  if ($act==='my_lives'){
    if($tId<=0) out(['ok'=>false,'error'=>'bad_tournament','detail'=>'ID/code mancante'],400);

    // coerenza: verifica che il torneo esista
    $chk=$pdo->prepare("SELECT 1 FROM tournament_flash WHERE id=? LIMIT 1");
    $chk->execute([$tId]);
    if(!$chk->fetchColumn()) out(['ok'=>false,'error'=>'not_found','where'=>'api.flash.my_lives','detail'=>'Torneo inesistente'],404);

    $sel = "SELECT id,life_no,status".($LIVES_ROUND_COL?(", `".$LIVES_ROUND_COL."` AS `round`"):"")."
            FROM tournament_flash_lives
            WHERE tournament_id=? AND user_id=?
            ORDER BY life_no ASC";
    $st=$pdo->prepare($sel);
    $st->execute([$tId,$uid]);
    $rows=$st->fetchAll(\PDO::FETCH_ASSOC);
    out(['ok'=>true,'lives'=>$rows]);
  }

/* ===== BUY LIFE ===== */
if ($act==='buy_life'){
  only_post();
  if($tId<=0) out(['ok'=>false,'error'=>'bad_tournament','detail'=>'ID/code mancante'],400);
  csrf_verify_or_die();

  // carica torneo (serve seats, lock, lives_max_user)
  $st=$pdo->prepare("SELECT id,buyin,lives_max_user,lock_at,COALESCE(seats_infinite,0) AS seats_infinite,seats_max FROM tournament_flash WHERE id=? LIMIT 1");
  $st->execute([$tId]); $t=$st->fetch(PDO::FETCH_ASSOC);
  if(!$t) out(['ok'=>false,'error'=>'not_found','detail'=>'Torneo inesistente'],404);

  // lock?
  $lockTs = !empty($t['lock_at']) ? strtotime($t['lock_at']) : null;
  if ($lockTs && time() >= $lockTs) out(['ok'=>false,'error'=>'locked','detail'=>'Round 1 in lock'],400);

  // già iscritto?
  $stJ=$pdo->prepare("SELECT 1 FROM tournament_flash_users WHERE tournament_id=? AND user_id=? LIMIT 1");
  $stJ->execute([$tId,$uid]); $already=(bool)$stJ->fetchColumn();

  // posti pieni? (se non iscritto, vieta nuova iscrizione tramite buy_life)
  $stC=$pdo->prepare("SELECT COUNT(*) FROM tournament_flash_users WHERE tournament_id=?");
  $stC->execute([$tId]); $usersCnt=(int)$stC->fetchColumn();
  $inf  = (int)$t['seats_infinite'];
  $max  = isset($t['seats_max']) && $t['seats_max']!==null ? (int)$t['seats_max'] : null;
  $full = ($inf===0 && $max!==null && $max>0 && $usersCnt >= $max);
  if (!$already && $full) out(['ok'=>false,'error'=>'seats_full','detail'=>'Posti esauriti: iscrizione chiusa'],400);

  $buyin = (float)$t['buyin'];
  $lmax  = (int)($t['lives_max_user'] ?? 0); // 0 o <0 = illimitato

  $pdo->beginTransaction();
  try{
    // iscrivi se necessario
    $pdo->prepare("INSERT IGNORE INTO tournament_flash_users (tournament_id,user_id,joined_at) VALUES (?,?,NOW())")
        ->execute([$tId,$uid]);

    // LOCK fondi utente
    $st=$pdo->prepare("SELECT coins FROM users WHERE id=? FOR UPDATE");
    $st->execute([$uid]); $coins=(float)$st->fetchColumn();

    // LOCK vite correnti dell'utente in questo torneo (per anti-race)
    // NB: COUNT(*) FOR UPDATE blocca le righe corrispondenti in InnoDB
    $stMy=$pdo->prepare("SELECT COUNT(*) FROM tournament_flash_lives WHERE tournament_id=? AND user_id=? FOR UPDATE");
    $stMy->execute([$tId,$uid]); $mine=(int)$stMy->fetchColumn();

    // ENFORCE limite vite qui dentro la transazione
    if ($lmax > 0 && $mine >= $lmax) { 
      $pdo->rollBack(); 
      out(['ok'=>false,'error'=>'lives_limit','detail'=>'Raggiunto limite vite'],400); 
    }

    // fondi sufficienti?
    if ($coins < $buyin){ 
      $pdo->rollBack(); 
      out(['ok'=>false,'error'=>'no_funds','detail'=>'Fondi insufficienti'],400); 
    }

    // addebito + nuova vita
    $pdo->prepare("UPDATE users SET coins=coins-? WHERE id=?")->execute([$buyin,$uid]);
    $lifeNo = $mine + 1;

    // colonna round autodetect (se nel tuo file hai già $LIVES_ROUND_COL definito, riusala)
    if (!isset($LIVES_ROUND_COL)) {
      // fallback: prova 'round', poi 'round_no', poi 'rnd'
      $LIVES_ROUND_COL = 'round';
      // opzionale: puoi inserire qui un controllo INFORMATION_SCHEMA se serve
    }

    // prova a inserire con round, se fallisce usa senza
    try {
      $sql = "INSERT INTO tournament_flash_lives (tournament_id,user_id,life_no,status,`$LIVES_ROUND_COL`) VALUES (?,?,?,?,1)";
      $pdo->prepare($sql)->execute([$tId,$uid,$lifeNo,'alive']);
    } catch (\Throwable $e) {
      // tabella senza colonna round -> versione minima
      $sql = "INSERT INTO tournament_flash_lives (tournament_id,user_id,life_no,status) VALUES (?,?,?,?)";
      $pdo->prepare($sql)->execute([$tId,$uid,$lifeNo,'alive']);
    }

    $pdo->commit();
    out(['ok'=>true,'life_no'=>$lifeNo]);
  }catch(\Throwable $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    out(['ok'=>false,'error'=>'tx_failed','detail'=>$e->getMessage()],500);
  }
}

  /* ===== UNJOIN ===== */
  if ($act==='unjoin'){
    only_post();
    if($tId<=0) out(['ok'=>false,'error'=>'bad_tournament','detail'=>'ID/code mancante'],400);
    csrf_verify_or_die();

    // carica torneo (serve seats e lock)
    $st=$pdo->prepare("SELECT id,buyin,lock_at,COALESCE(seats_infinite,0) AS seats_infinite,seats_max FROM tournament_flash WHERE id=? LIMIT 1");
    $st->execute([$tId]); $t=$st->fetch(PDO::FETCH_ASSOC);
    if(!$t) out(['ok'=>false,'error'=>'not_found','detail'=>'Torneo inesistente'],404);

    // posti pieni? (se sì, blocca SEMPRE la disiscrizione)
    $stC=$pdo->prepare("SELECT COUNT(*) FROM tournament_flash_users WHERE tournament_id=?");
    $stC->execute([$tId]); $usersCnt=(int)$stC->fetchColumn();
    $inf  = (int)$t['seats_infinite'];
    $max  = isset($t['seats_max']) && $t['seats_max']!==null ? (int)$t['seats_max'] : null;
    $full = ($inf===0 && $max!==null && $max>0 && $usersCnt >= $max);
    if ($full) out(['ok'=>false,'error'=>'seats_full_unjoin_blocked','detail'=>'Posti esauriti: disiscrizione bloccata'],400);

    // lock passato? (regola classica)
    $lockTs = !empty($t['lock_at']) ? strtotime($t['lock_at']) : null;
    if ($lockTs && time() >= $lockTs) out(['ok'=>false,'error'=>'locked','detail'=>'Round 1 in lock'],400);

    $pdo->beginTransaction();
    try{
      // vite possedute -> rimborso
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

  /* ===== COMPUTE / NEXT / FINALIZE (admin) ===== */
  if ($act==='compute_round'){
    only_post(); if(!is_admin()) out(['ok'=>false,'error'=>'forbidden','detail'=>'Solo admin/punto'],403);
    if($tId<=0) out(['ok'=>false,'error'=>'bad_tournament','detail'=>'ID/code mancante'],400);
    $round=(int)($_POST['round_no'] ?? 0); if($round<=0) out(['ok'=>false,'error'=>'bad_round','detail'=>'round_no mancante'],400);
    $res=FC::computeRound($pdo,$tId,$round); if($DBG) $res['debug']=['where'=>'api.flash.compute_round']; out($res);
  }
  if ($act==='publish_next_round'){
    only_post(); if(!is_admin()) out(['ok'=>false,'error'=>'forbidden','detail'=>'Solo admin/punto'],403);
    if($tId<=0) out(['ok'=>false,'error'=>'bad_tournament','detail'=>'ID/code mancante'],400);
    $round=(int)($_POST['round_no'] ?? 0); if($round<=0) out(['ok'=>false,'error'=>'bad_round','detail'=>'round_no mancante'],400);
    $res=FC::publishNextRound($pdo,$tId,$round); if($DBG) $res['debug']=['where'=>'api.flash.publish_next_round']; out($res);
  }
  if ($act==='finalize_tournament'){
    only_post(); if(!is_admin()) out(['ok'=>false,'error'=>'forbidden','detail'=>'Solo admin/punto'],403);
    if($tId<=0) out(['ok'=>false,'error'=>'bad_tournament','detail'=>'ID/code mancante'],400);
    $res=FF::finalize($pdo,$tId,(int)$uid); if($DBG) $res['debug']=['where'=>'api.flash.finalize_tournament']; out($res);
  }

  out(['ok'=>false,'error'=>'unknown_action','where'=>'api.flash.router','detail'=>'Azione non gestita: '.$act],400);

}catch(\Throwable $e){
  $payload=['ok'=>false,'error'=>'api_exception','detail'=>$e->getMessage(),'file'=>$e->getFile(),'line'=>$e->getLine()];
  if ($DBG){ $payload['trace']=$e->getTraceAsString(); }
  out($payload,500);
}
