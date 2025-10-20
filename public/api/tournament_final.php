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
if ($__DBG) { ini_set('display_errors','1'); error_reporting(E_ALL); header('X-Debug: 1'); }

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
/* >>> AGGIUNTA: alias ?code= come tid */
$code = isset($_GET['code']) ? (string)$_GET['code'] : (isset($_POST['code'])?(string)$_POST['code']:null);
if (!$tid && $code) { $tid = $code; }
/* <<< FINE AGGIUNTA */
$tournamentId = TF::resolveTournamentId($pdo, $id, $tid);

$act = $_GET['action'] ?? $_POST['action'] ?? '';
if ($act==='') { jsonOut(['ok'=>false,'error'=>'missing_action'],400); }

// Alias compatibilità: consenti anche action=finalize_tournament
if ($act === 'finalize_tournament') {
  $act = 'finalize';
}

/* ===== Helpers messaggi POPUP (AGGIUNTA) ===== */
// Messaggi ufficiali per il popup (SOLO / SPLIT / REFUND)
function tnMessage(string $type): string {
  switch (strtoupper($type)) {
    case 'SOLO':
      return "👑 Sei il Re dell’Arena!  Il montepremi è stato accreditato! ⚔️";
    case 'SPLIT':
      return "⚔️ Scontro leggendario! Sei uno dei Campioni dell’Arena! 🏆 Il montepremi è stato splittato tra i vincitori!";
    case 'REFUND':
      return "⚖️ L’Arena tace… nessun vincitore è emerso. 🩸 Le sabbie si sono placate, ma il tuo onore resta intatto. 💰 Il buy-in ti è stato rimborsato.";
    default:
      return '';
  }
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
  /* AGGIUNTA: se manca torneo specifico, non generare 400 (serve al check globale) */
  if ($tournamentId<=0) {
  // Fallback "al primo login": prova a risalire all'ultimo torneo con esito (payout/refund) per questo utente
  try {
    // 1) Esiste la tabella transactions?
    $qTx = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transactions'");
    $qTx->execute();
    if ($qTx->fetchColumn()) {
      // 2) Ultima transazione payout/refund dell'utente
      $st = $pdo->prepare("
        SELECT id, ref_id, kind, description, amount
        FROM transactions
        WHERE user_id=? AND (kind IN ('payout','refund') OR description LIKE '%payout%' OR description LIKE '%refund%')
        ORDER BY id DESC
        LIMIT 1
      ");
      $st->execute([$uid]);
      if ($tx = $st->fetch(PDO::FETCH_ASSOC)) {
        $refId = (int)($tx['ref_id'] ?? 0);
        $kind  = strtolower((string)($tx['kind'] ?? ''));
        $type  = ($kind === 'refund') ? 'REFUND' : 'SPLIT'; // default conservativo per payout

        // 3) Se è payout, prova a distinguere SOLO vs SPLIT guardando i payouts del torneo
        if ($type !== 'REFUND' && $refId > 0) {
          $winnersCnt = null;

          // tournaments normali
          $qTP = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tournament_payouts'");
          $qTP->execute();
          if ($qTP->fetchColumn()) {
            $qW = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM tournament_payouts WHERE tournament_id=?");
            $qW->execute([$refId]);
            $winnersCnt = (int)$qW->fetchColumn();
          }

          // flash payouts (se la tabella esiste e non abbiamo ancora un conteggio)
          if ($winnersCnt === null) {
            $qFP = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tournament_flash_payouts'");
            $qFP->execute();
            if ($qFP->fetchColumn()) {
              $qW2 = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM tournament_flash_payouts WHERE tournament_id=?");
              $qW2->execute([$refId]);
              $winnersCnt = (int)$qW2->fetchColumn();
            }
          }

          if ($winnersCnt !== null) {
            $type = ($winnersCnt === 1) ? 'SOLO' : 'SPLIT';
          }
        }

        // 4) Ricava un "code" per la notice_key (normale o flash)
        $code = null;
        if ($refId > 0) {
          // tournaments normali
          $qT = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tournaments'");
          $qT->execute();
          if ($qT->fetchColumn()) {
            $s = $pdo->prepare("SELECT UPPER(COALESCE(tour_code,code)) FROM tournaments WHERE id=? LIMIT 1");
            $s->execute([$refId]);
            $code = $s->fetchColumn() ?: $code;
          }
          // flash
          if (!$code) {
            $qF = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tournament_flash'");
            $qF->execute();
            if ($qF->fetchColumn()) {
              $s2 = $pdo->prepare("SELECT UPPER(code) FROM tournament_flash WHERE id=? LIMIT 1");
              $s2->execute([$refId]);
              $code = $s2->fetchColumn() ?: $code;
            }
          }
        }

        // 5) Costruisci messaggio + chiave idempotente e rispondi
        $message   = tnMessage($type);
        $noticeKey = sprintf('u%d:t%s:%s', $uid, $code ?: ($refId ? ('#'.$refId) : 'LAST'), $type);

        jsonOut([
          'ok'         => true,
          'show'       => true,
          'type'       => $type,
          'message'    => $message,
          'notice_key' => $noticeKey,
          'tid'        => ($refId ?: null),
          'code'       => $code ?: null
        ]);
      }
    }
  } catch (Throwable $e) {
    // silenzioso: in fallback non deve mai rompere
  }

  // Nessun esito rilevabile → silenzioso
  jsonOut(['ok'=>true,'show'=>false]);
}

  $res = TF::userNotice($pdo, $tournamentId, $uid);

  /* AGGIUNTA: inietta testo standard e chiave idempotenza */
  $type = strtoupper((string)($res['type'] ?? ''));

  // Prova a inferire il tipo se non fornito dal Finalizer
  if ($type === '') {
    if (!empty($res['refund'])) {
      $type = 'REFUND';
    } elseif (isset($res['winners_count']) && (int)$res['winners_count'] > 0) {
      $type = ((int)$res['winners_count'] === 1) ? 'SOLO' : 'SPLIT';
    } elseif (!empty($res['winners']) && is_array($res['winners'])) {
      $type = (count($res['winners']) === 1) ? 'SOLO' : 'SPLIT';
    }
  }

  if ($type !== '') {
    $res['type'] = $type;
    $res['message'] = tnMessage($type);
    if (!isset($res['show'])) { $res['show'] = true; }
    if (empty($res['notice_key'])) {
      $codeForKey = $res['code'] ?? ($tid ? strtoupper($tid) : ('#'.$tournamentId));
      $res['notice_key'] = sprintf('u%d:t%s:%s', $uid, $codeForKey, $type);
    }
  }

  jsonOut($res);
}

/**
 * GET ?action=notice_seen&key=...
 * Best-effort: risposta OK così il client evita doppi popup (persistenza lato client).
 */
if ($act==='notice_seen') {
  jsonOut(['ok'=>true]);
}

/* default */
jsonOut(['ok'=>false,'error'=>'unknown_action'],400);
