<?php
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log','/tmp/php_errors.log');

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../partials/db.php';
require_once __DIR__ . '/../../partials/api_auth_guard.php';

require_login();        // 401 se non loggato
require_csrf_or_die();  // 419 se CSRF errato/mancante

// ---- Input ----
$type = $_POST['type'] ?? '';
$url  = trim($_POST['url'] ?? '');
$key  = trim($_POST['storage_key'] ?? '');
$mime = trim($_POST['mime'] ?? '');
$w    = (int)($_POST['width'] ?? 0);
$h    = (int)($_POST['height'] ?? 0);
$size = (int)($_POST['size'] ?? 0);
$etag = $_POST['etag'] ?? null;

$ownerId = (int)($_POST['owner_id'] ?? 0);
$teamId  = (int)($_POST['team_id']  ?? 0);
$prizeId = (int)($_POST['prize_id'] ?? 0);

// ---- Validate campi base ----
if ($type==='' || $url==='' || $key==='' || $mime==='') {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'missing_fields']);
  exit;
}

// ---- Whitelist type ----
$ALLOWED_TYPES = ['avatar','team_logo','prize']; // aggiungi qui altri tipi leciti se servono
if (!in_array($type, $ALLOWED_TYPES, true)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'invalid_type']);
  exit;
}

// (opzionale ma consigliato) accetta solo immagini
if (strpos($mime, 'image/') !== 0) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'invalid_mime']);
  exit;
}

// ---- Ownership (server-side) ----
if ($type === 'avatar') {
  // Solo il proprietario (ownerId) o admin può cambiare l'avatar di quell'utente
  require_owner_or_admin($ownerId);
}
if ($type === 'team_logo' && $teamId > 0) {
  $st = $pdo->prepare('SELECT owner_id FROM teams WHERE id = ?');
  $st->execute([$teamId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) { json_fail(404, 'team_not_found'); }
  require_owner_or_admin((int)$row['owner_id']);
}

// ---- Operazioni atomiche ----
try {
  $pdo->beginTransaction();

  // Insert/Upsert su media
  $st = $pdo->prepare("
    INSERT INTO media(owner_id,prize_id,type,storage_key,url,mime,width,height,size_bytes,etag)
    VALUES(?,?,?,?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE
      url=VALUES(url),
      mime=VALUES(mime),
      width=VALUES(width),
      height=VALUES(height),
      size_bytes=VALUES(size_bytes),
      etag=VALUES(etag)
  ");
  $st->execute([
    $ownerId ?: 0,
    $prizeId ?: null,
    $type,
    $key,
    $url,
    $mime,
    $w ?: null,
    $h ?: null,
    $size ?: null,
    $etag ?: null
  ]);

  // Aggiornamenti collegati
  if ($type === 'team_logo' && $teamId > 0) {
    $u = $pdo->prepare("UPDATE teams SET logo_url=?, logo_key=? WHERE id=?");
    $u->execute([$url, $key, $teamId]);
  }

  if ($type === 'avatar' && $ownerId > 0) {
    $u = $pdo->prepare("UPDATE users SET avatar_url=?, avatar_key=? WHERE id=?");
    $u->execute([$url, $key, $ownerId]);
  }

  $pdo->commit();
  echo json_encode(['ok'=>true]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) { $pdo->rollBack(); }
  // Logga lato server, NON esporre dettagli al client
  error_log('[media_save] '.$e->getMessage());
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'update_failed']);
}
