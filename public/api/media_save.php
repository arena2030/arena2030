<?php
// /public/api/media_save.php — Registra metadati media e aggiorna entità collegate (team logo / prize image / avatar)
// Backwards-compatible: NON tocca colonne che non esistono.
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$__db = __DIR__ . '/../../partials/db.php';
if (!file_exists($__db)) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'db_bootstrap_missing']);
  exit;
}
require_once $__db;

// CSRF soft
$__csrf = __DIR__ . '/../../partials/csrf.php';
if (file_exists($__csrf)) {
  require_once $__csrf;
  if (function_exists('csrf_verify_or_die')) {
    csrf_verify_or_die();
  }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'method_not_allowed']);
  exit;
}

// --- Helpers
function column_exists(PDO $pdo, string $table, string $col): bool {
  $q = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $q->execute([$table,$col]);
  return (bool)$q->fetchColumn();
}

// --- Auth soft (lascia compatibilità se i tuoi endpoint storici non richiedono login)
$uid = (int)($_SESSION['uid'] ?? 0);

// --- Input
$type   = $_POST['type'] ?? 'generic';       // 'team_logo' | 'prize' | 'avatar' | 'generic'
$owner  = (int)($_POST['owner_id'] ?? ($uid ?: 0)); // per avatar: user id; per team_logo: team id
$key    = trim($_POST['storage_key'] ?? '');
$url    = trim($_POST['url'] ?? '');
$etag   = trim($_POST['etag'] ?? '');
$prize  = isset($_POST['prize_id']) ? (int)$_POST['prize_id'] : null;

if ($key === '' || $url === '') {
  http_response_code(422);
  echo json_encode(['ok'=>false,'error'=>'missing_fields']);
  exit;
}

try {
  $pdo->beginTransaction();

  // 1) Media record
  $st = $pdo->prepare("INSERT INTO media (storage_key, type, owner_id, prize_id, created_at) VALUES (?, ?, ?, ?, NOW())");
  $st->execute([$key, $type, $owner ?: null, $prize]);
  $media_id = (int)$pdo->lastInsertId();

  // 2) Aggiornamenti condizionali (solo se le colonne esistono)

  // 2.a) Team logo
  if ($type === 'team_logo' && $owner > 0) {
    $cols = [];
    if (column_exists($pdo,'teams','logo_key'))  $cols[] = "logo_key=?";
    if (column_exists($pdo,'teams','logo_url'))  $cols[] = "logo_url=?";
    if (column_exists($pdo,'teams','logo_etag')) $cols[] = "logo_etag=?";
    if ($cols) {
      $sql = "UPDATE teams SET ".implode(',', $cols).", updated_at=NOW() WHERE id=?";
      $params = [];
      if (column_exists($pdo,'teams','logo_key'))  $params[] = $key;
      if (column_exists($pdo,'teams','logo_url'))  $params[] = $url;
      if (column_exists($pdo,'teams','logo_etag')) $params[] = ($etag !== '' ? $etag : null);
      $params[] = $owner;
      $pdo->prepare($sql)->execute($params);
    }
  }

  // 2.b) Prize image (collega anche prizes.image_media_id)
  if (($type === 'prize' || $prize) && column_exists($pdo,'prizes','image_media_id')) {
    $pid = $prize ?: ($owner ?: 0); // fallback: se invii owner_id=prize_id
    if ($pid > 0) {
      $pdo->prepare("UPDATE prizes SET image_media_id=? WHERE id=?")->execute([$media_id, $pid]);
    }
  }

  // 2.c) Avatar utente/punto
  // Non tocchiamo users.* se le colonne non esistono (compatibilità massima).
  if ($type === 'avatar' && $owner > 0) {
    $hasKey  = column_exists($pdo,'users','avatar_key');
    $hasUrl  = column_exists($pdo,'users','avatar_url');
    $hasEtag = column_exists($pdo,'users','avatar_etag');
    if ($hasKey || $hasUrl || $hasEtag) {
      $sets = [];
      $params = [];
      if ($hasKey)  { $sets[] = "avatar_key=?";  $params[] = $key; }
      if ($hasUrl)  { $sets[] = "avatar_url=?";  $params[] = $url; }
      if ($hasEtag) { $sets[] = "avatar_etag=?"; $params[] = ($etag !== '' ? $etag : null); }
      $params[] = $owner;
      $pdo->prepare("UPDATE users SET ".implode(',', $sets)." WHERE id=?")->execute($params);
    }
    // In ogni caso il record in media è stato creato, così la tua app può sempre leggere l'ultimo avatar da `media`.
  }

  $pdo->commit();
  echo json_encode(['ok'=>true,'media_id'=>$media_id]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'server_error','detail'=>$e->getMessage()]);
}
