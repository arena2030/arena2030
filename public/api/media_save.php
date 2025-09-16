<?php
// Salva metadati immagine in DB e aggiorna entità collegate (team logo / avatar / prize)
ini_set('display_errors','0'); ini_set('log_errors','1'); ini_set('error_log','/tmp/php_errors.log');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../partials/db.php';

// Input obbligatori
$type = $_POST['type'] ?? '';                        // team_logo | avatar | prize | generic
$url  = trim($_POST['url'] ?? '');                   // URL pubblico (cdn_url)
$key  = trim($_POST['storage_key'] ?? '');           // chiave nello storage (uploads/..., teams/..., ecc.)
$mime = trim($_POST['mime'] ?? '');                  // image/png, image/jpeg, ...
$w    = (int)($_POST['width']  ?? 0);
$h    = (int)($_POST['height'] ?? 0);
$size = (int)($_POST['size']   ?? 0);
$etag = $_POST['etag'] ?? null;

if ($type==='' || $url==='' || $key==='' || $mime==='') {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'missing_fields']); exit;
}

// Id legati (opzionali, dipende dal tipo)
$ownerId = (int)($_POST['owner_id'] ?? 0);   // avatar
$teamId  = (int)($_POST['team_id']  ?? 0);   // team_logo
$prizeId = (int)($_POST['prize_id'] ?? 0);   // prize

// 1) salva su tabella media (se non ce l'hai, te la do io dopo)
$st = $pdo->prepare("INSERT INTO media(owner_id, prize_id, type, storage_key, url, mime, width, height, size_bytes, etag)
                     VALUES(?,?,?,?,?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE url=VALUES(url), mime=VALUES(mime), width=VALUES(width), height=VALUES(height), size_bytes=VALUES(size_bytes), etag=VALUES(etag)");
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
  $etag
]);

// 2) aggiorna le entità collegate (se richiesto)
try {
  if ($type === 'team_logo' && $teamId > 0) {
    $u = $pdo->prepare("UPDATE teams SET logo_url=?, logo_key=? WHERE id=?");
    $u->execute([$url, $key, $teamId]);
  }
  if ($type === 'avatar' && $ownerId > 0) {
    $u = $pdo->prepare("UPDATE users SET avatar_url=?, avatar_key=? WHERE id=?");
    $u->execute([$url, $key, $ownerId]);
  }
  // per i prize teniamo più immagini in media: nessun campo singolo da aggiornare
} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'error'=>'update_link_failed','detail'=>$e->getMessage()]); exit;
}

echo json_encode(['ok'=>true]);
