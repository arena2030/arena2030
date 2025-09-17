<?php
// public/api/media_save.php — salva metadati file caricati su R2 (avatar utente, logo team, ecc.)
// NON aggiorna colonne inesistenti su `users` (avatar_url/avatar_key), ma scrive su `media`.
// Per i loghi team aggiorna anche i campi in `teams` se presenti.
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/../../partials/db.php';
require_once __DIR__ . '/../../partials/csrf.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false, 'error'=>'method_not_allowed']); exit;
}

csrf_verify_or_die();

$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'auth_required']); exit; }

$type = $_POST['type'] ?? 'generic'; // 'avatar' | 'team_logo' | 'prize' | 'generic'
$owner_id = (int)($_POST['owner_id'] ?? $uid); // di default il proprietario è l'utente stesso
$storage_key = trim($_POST['storage_key'] ?? '');
$url = trim($_POST['url'] ?? '');
$etag = trim($_POST['etag'] ?? '');
$prize_id = isset($_POST['prize_id']) ? (int)$_POST['prize_id'] : null;

if ($storage_key === '' || $url === '') {
  http_response_code(422);
  echo json_encode(['ok'=>false, 'error'=>'missing_fields']); exit;
}

try {
  $pdo->beginTransaction();

  // Inserisci record media
  $stmt = $pdo->prepare("INSERT INTO media (storage_key, type, owner_id, prize_id, created_at) VALUES (?, ?, ?, ?, NOW())");
  $stmt->execute([$storage_key, $type, $owner_id, $prize_id]);
  $media_id = (int)$pdo->lastInsertId();

  // Aggiorna loghi team se richiesto (campi opzionali ma presenti nel tuo schema)
  if ($type === 'team_logo') {
    $team_id = $owner_id;
    $stmt2 = $pdo->prepare("UPDATE teams SET logo_key = ?, logo_url = ?, logo_etag = ?, updated_at = NOW() WHERE id = ?");
    $stmt2->execute([$storage_key, $url, $etag !== '' ? $etag : null, $team_id]);
  }

  $pdo->commit();
  echo json_encode(['ok'=>true, 'media_id'=>$media_id]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) { $pdo->rollBack(); }
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>'server_error', 'detail'=>$e->getMessage()]);
}
