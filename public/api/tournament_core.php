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

/* ===== Helpers aggiunti per policy ===== */
function colExists(PDO $pdo, string $table, string $col): bool {
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $q->execute([$table,$col]); return (bool)$q->fetchColumn();
}
function detectRoundCol(PDO $pdo): ?string {
  foreach (['current_round','round_current','round'] as $c) if (colExists($pdo,'tournaments',$c)) return $c;
  return null;
}
function detectStatusCol(PDO $pdo): ?string {
  foreach (['status','state'] as $c) if (colExists($pdo,'tournaments',$c)) return $c;
  return null;
}
/**
 * Guard policy:
 * - join/unjoin/buy_life: consentiti solo fino al lock del Round 1 (oppure comunque bloccati se current_round > 1).
 * - pick: consentite solo per il round corrente e fino al lock di quel round.
 * Ritorna ['allowed'=>bool, 'reason'=>..., 'popup'=>..., 'current_round'=>int, 'lock_at'=>?string]
 */
function policy_guard_check(PDO $pdo, int $tournamentId, string $what, int $round=0): array {
  if ($tournamentId<=0) return ['allowed'=>false,'reason'=>'bad_tournament','popup'=>'Torneo non valido.'];

  $rCol = detectRoundCol($pdo);
  $sCol = detectStatusCol($pdo);
  $hasLock = colExists($pdo,'tournaments','lock_at');

  $sel = "id".($rCol?",$rCol AS r":"").($hasLock?",lock_at":"").($sCol?",$sCol AS status":"");
  $st = $pdo->prepare("SELECT $sel FROM tournaments WHERE id=? LIMIT 1");
  $st->execute([$tournamentId]);
  $T = $st->fetch(PDO::FETCH_ASSOC);
  if (!$T) return ['allowed'=>false,'reason'=>'tour_not_found','popup'=>'Torneo non trovato.'];

  $curRound = (int)($T['r'] ?? 1);
  $lockIso  = $T['lock_at'] ?? null;
  $lockTs   = $lockIso ? strtotime($lockIso) : null;
  $now      = time();
  $status   = $sCol ? strtolower((string)($T['status'] ?? '')) : '';

  $isClosed = in_array($status, ['closed','ended','finished','chiuso','terminato'], true);

  $deny = function($reason, $msg) use($curRound,$lockIso){ return [
    'allowed'=>false,'reason'=>$reason,'popup'=>$msg,'current_round'=>$curRound,'lock_at'=>$lockIso
  ];};
  $allow = function() use($curRound,$lockIso){ return [
    'allowed'=>true,'reason'=>'ok','popup'=>null,'current_round'=>$curRound,'lock_at'=>$lockIso
  ];};

  $w = strtolower(trim($what));

  if (in_array($w, ['join','unjoin','buy_life'], true)) {
    if ($isClosed)                     return $deny('tournament_closed','Operazione non disponibile: il torneo è chiuso.');
    if ($curRound > 1)                 return $deny('after_r1','Operazione non consentita dopo il lock del Round 1.');
    if ($lockTs !== null && $now >= $lockTs) return $deny('r1_lock_passed','Operazione consentita solo fino al lock del Round 1.');
    return $allow();
  }

  if ($w === 'pick') {
    $reqRound = $round > 0 ? $round : $curRound;
    if ($isClosed)                        return $deny('tournament_closed','Torneo chiuso: non è più possibile effettuare scelte.');
    if ($reqRound !== $curRound)          return $deny('round_mismatch',"Le scelte sono abilitate solo per il Round corrente (Round {$curRound}).");
    if ($lockTs !== null && $now >= $lockTs) return $deny('round_lock_passed','Il lock per questo round è scaduto: non puoi più effettuare scelte.');
    return $allow();
  }

  return $deny('invalid_what','Operazione non riconosciuta.');
}

/* ===== Parse torneo target ===== */
$id  = isset($_GET['id'])  ? (int)$_GET['id']  : (isset($_POST['id'])?(int)$_POST['id']:0);
$tid = isset($_GET['tid']) ? (string)$_GET['tid'] : (isset($_POST['tid'])?(string)$_POST['tid']:null);
$tournamentId = TC::resolveTournamentId($pdo, $id, $tid);

$act = $_GET['action'] ?? $_POST['action'] ?? '';
if ($act==='') { http_response_code(400); json(['ok'=>false,'error'=>'missing_action']); }

/* ====== ACTIONS ====== */

/**
 * GET/POST ?action=policy_guard&id=..|tid=..&what=join|unjoin|buy_life|pick[&round=..]
 * Endpoint di guardia lato server: true/false + messaggio popup.
 */
if ($act==='policy_guard') {
  $what  = strtolower((string)($_GET['what'] ?? $_POST['what'] ?? ''));
  $round = (int)($_GET['round'] ?? $_POST['round'] ?? 0);
  if (!in_array($what, ['join','unjoin','buy_life','pick'], true)) {
    http_response_code(400); json(['ok'=>false,'error'=>'invalid_what']);
  }
  $g = policy_guard_check($pdo, $tournamentId, $what, $round);
  json(['ok'=>true] + $g);
}

/**
 * GET/POST ?action=validate_pick&id=..|tid=..&life_id=..&team_id=..&round=..
 * Verifica se la pick è consentita nel rispetto del “ciclo principale”.
 * + Blocco policy: le pick sono consentite solo nel round corrente e fino al lock.
 */
if ($act==='validate_pick') {
  $lifeId = (int)($_GET['life_id'] ?? $_POST['life_id'] ?? 0);
  $teamId = (int)($_GET['team_id'] ?? $_POST['team_id'] ?? 0);
  $round  = (int)($_GET['round']   ?? $_POST['round']   ?? 0);

  if ($tournamentId<=0 || $lifeId<=0 || $teamId<=0 || $round<=0) {
    http_response_code(400); json(['ok'=>false,'error'=>'bad_params']);
  }

  // 🔒 Policy: blocchiamo l'utente (non admin/punto) se il round è lockato o non è quello corrente
  if ($role === 'USER') {
    $g = policy_guard_check($pdo, $tournamentId, 'pick', $round);
    if (!$g['allowed']) { http_response_code(403); json(['ok'=>false,'error'=>'pick_forbidden','popup'=>$g['popup'],'reason'=>$g['reason']]); }
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
