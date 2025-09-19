<?php
// /public/api/tournament_policy.php
// Policy centralizzata: controlla se è consentito iscriversi/disiscriversi/acquistare vite
// (solo fino al lock del round 1) e se è consentito fare una scelta (pick) fino al lock del round corrente.

require_once __DIR__ . '/../../partials/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function out($arr, int $status=200){ http_response_code($status); echo json_encode($arr); exit; }

/* ===== Helpers auto-detect colonne ===== */
function colExists(PDO $pdo, string $table, string $col): bool {
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $q->execute([$table,$col]); return (bool)$q->fetchColumn();
}
function detectTourCodeCol(PDO $pdo): string {
  foreach (['tour_code','code','t_code','short_id'] as $c) if (colExists($pdo,'tournaments',$c)) return $c;
  return 'tour_code';
}
function detectRoundCol(PDO $pdo): ?string {
  foreach (['current_round','round_current','round'] as $c) if (colExists($pdo,'tournaments',$c)) return $c;
  return null;
}
function detectStatusCol(PDO $pdo): ?string {
  foreach (['status','state'] as $c) if (colExists($pdo,'tournaments',$c)) return $c;
  return null;
}

/* ===== Lookup torneo ===== */
function getTournament(PDO $pdo, ?int $id, ?string $code) {
  if ($id && $id>0) {
    $st=$pdo->prepare("SELECT * FROM tournaments WHERE id=? LIMIT 1");
    $st->execute([$id]); return $st->fetch(PDO::FETCH_ASSOC) ?: false;
  }
  $code = trim((string)$code);
  if ($code==='') return false;
  $col = detectTourCodeCol($pdo);
  $st=$pdo->prepare("SELECT * FROM tournaments WHERE $col=? LIMIT 1");
  $st->execute([$code]); return $st->fetch(PDO::FETCH_ASSOC) ?: false;
}

/* ===== Normalizzazioni ===== */
function statusIsClosed(PDO $pdo, array $tour): bool {
  $sc = detectStatusCol($pdo);
  $s = $sc ? strtolower((string)($tour[$sc] ?? '')) : '';
  return in_array($s, ['closed','ended','finished','chiuso','terminato'], true);
}
function statusIsPublished(PDO $pdo, array $tour): bool {
  $sc = detectStatusCol($pdo);
  if (!$sc) return true; // se non esiste la colonna status, non limitiamo
  $s = strtolower((string)($tour[$sc] ?? ''));
  return in_array($s, ['published','open','aperto','in corso','running'], true);
}
function nowTs(): int { return time(); }
function lockTs(?string $iso): ?int { if(!$iso) return null; $t=strtotime($iso); return $t===false?null:$t; }

/* ===== Regole =====
 * - Join/Unjoin/Buy: consentiti SOLO fino al lock del Round 1 (tournaments.lock_at > now).
 *   Dopo tale istante, e per tutta la durata del torneo (round >= 1, 2, ...), sono bloccati.
 * - Pick: consentite fino al lock del round corrente (round==current_round e lock_at > now).
 */

$action = $_GET['action'] ?? $_POST['action'] ?? 'check';          // 'check' | 'guard'
$what   = strtolower((string)($_GET['what'] ?? $_POST['what'] ?? '')); // 'join' | 'unjoin' | 'buy_life' | 'pick'
$rid    = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id'])?(int)$_POST['id']:0);
$code   = trim($_GET['tid'] ?? $_POST['tid'] ?? ($_GET['code'] ?? $_POST['code'] ?? ''));
$round  = isset($_GET['round']) ? (int)$_GET['round'] : (isset($_POST['round'])?(int)$_POST['round']:0);

$tour = getTournament($pdo, $rid, $code);
if (!$tour) out(['ok'=>false,'error'=>'tour_not_found'], 404);

$roundCol = detectRoundCol($pdo);
$curRound = $roundCol ? (int)($tour[$roundCol] ?? 1) : 1;
$lockIso  = colExists($pdo,'tournaments','lock_at') ? ($tour['lock_at'] ?? null) : null;
$lock     = lockTs($lockIso);
$now      = nowTs();

/* ===== Calcolo permessi ===== */
$allowed  = false;
$reason   = '';
$popupMsg = '';

if ($what === 'join' || $what === 'unjoin' || $what === 'buy_life') {
  // Vietato se torneo chiuso
  if (statusIsClosed($pdo, $tour)) {
    $allowed=false; $reason='tournament_closed';
    $popupMsg='Operazione non disponibile: il torneo è chiuso.';
  }
  // Ammesso solo se stato pubblicato (se esiste la colonna)
  elseif (!statusIsPublished($pdo, $tour)) {
    $allowed=false; $reason='tournament_not_published';
    $popupMsg='Non puoi eseguire questa operazione finché il torneo non è pubblicato.';
  }
  // Solo fino al lock del Round 1: se lock passato → blocco
  elseif ($lock !== null && $now >= $lock) {
    $allowed=false; $reason='r1_lock_passed';
    $popupMsg='Le iscrizioni, disiscrizioni e l’acquisto vite sono consentiti solo fino al lock del Round 1.';
  } else {
    // consentito (anche se il lock non è impostato ancora)
    $allowed=true; $reason='ok';
  }
}
elseif ($what === 'pick') {
  $reqRound = $round > 0 ? $round : $curRound;

  if (statusIsClosed($pdo, $tour)) {
    $allowed=false; $reason='tournament_closed';
    $popupMsg='Torneo chiuso: non è più possibile effettuare scelte.';
  }
  elseif ($reqRound !== $curRound) {
    // Le pick sono consentite esclusivamente per il round corrente
    $allowed=false; $reason='round_mismatch';
    $popupMsg="Le scelte sono abilitate solo per il Round corrente (Round {$curRound}).";
  }
  elseif ($lock !== null && $now >= $lock) {
    // lock del round corrente scaduto
    $allowed=false; $reason='round_lock_passed';
    $popupMsg='Il lock per questo round è scaduto: non puoi più effettuare scelte.';
  } else {
    $allowed=true; $reason='ok';
  }
}
else {
  out(['ok'=>false,'error'=>'invalid_what','hint'=>'Usa what=join|unjoin|buy_life|pick'], 400);
}

/* ===== Risposte =====
 * /api/tournament_policy.php?action=check&what=...   -> 200 sempre, con allowed true/false + messaggio
 * /api/tournament_policy.php?action=guard&what=...   -> 200 se consentito, 403 se vietato (con popup da mostrare)
 */
$payload = [
  'ok'=>true,
  'allowed'=>$allowed,
  'what'=>$what,
  'round'=>$what==='pick' ? ($round ?: $curRound) : null,
  'current_round'=>$curRound,
  'lock_at'=>$lockIso,
  'reason'=>$reason,
  'popup'=> $allowed ? null : ($popupMsg ?: 'Operazione non consentita in questo momento.')
];

if ($action === 'guard') {
  if ($allowed) out($payload, 200);
  out($payload, 403);
}
out($payload, 200);
