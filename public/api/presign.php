<?php
declare(strict_types=1);
ini_set('display_errors','0'); ini_set('log_errors','1'); ini_set('error_log','/tmp/php_errors.log');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

if (session_status()===PHP_SESSION_NONE) session_start();
$uid  = (int)($_SESSION['uid'] ?? 0);
$role = $_SESSION['role'] ?? 'USER';
if ($uid <= 0) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'auth_required']); exit; }

define('APP_ROOT', dirname(__DIR__, 2));
require_once APP_ROOT . '/partials/csrf.php';

// Solo POST con CSRF valido
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'method_not_allowed']); exit; }
csrf_verify_or_die();

require_once __DIR__ . '/../../partials/db.php';

$type = $_POST['type'] ?? '';
$url  = trim($_POST['url'] ?? '');
$key  = trim($_POST['storage_key'] ?? '');
$mime = trim($_POST['mime'] ?? '');
$w    = (int)($_POST['width'] ?? 0);
$h    = (int)($_POST['height'] ?? 0);
$size = (int)($_POST['size'] ?? 0);
$etag = $_POST['etag'] ?? null;

if ($type==='' || $url==='' || $key==='' || $mime==='') {
  http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing_fields']); exit;
}

// Whitelist MIME coerente con upload/presign
$allowed = ['image/png','image/jpeg','image/webp'];
if (!in_array($mime, $allowed, true)) {
  http_response_code(415); echo json_encode(['ok'=>false,'error'=>'mime_not_allowed']); exit;
}

$ownerId = (int)($_POST['owner_id'] ?? 0);
$teamId  = (int)($_POST['team_id']  ?? 0);
$prizeId = (int)($_POST['prize_id'] ?? 0);

// Regole di autorizzazione per tipo
if ($type==='avatar') {
  if ($ownerId<=0){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'owner_id_missing']); exit; }
  if ($ownerId !== $uid && $role !== 'ADMIN') { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'forbidden_avatar_owner']); exit; }
} elseif ($type==='team_logo') {
  if ($role !== 'ADMIN') { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'admin_only']); exit; }
  if ($teamId <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'team_id_missing']); exit; }
} elseif ($type==='prize') {
  if ($role !== 'ADMIN') { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'admin_only']); exit; }
  if ($prizeId <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'prize_id_missing']); exit; }
}

// (opzionale) Coerenza path-key rispetto al tipo
if ($type === 'avatar'   && strpos($key, 'users/') !== 0)   { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'key_mismatch_avatar']); exit; }
if ($type === 'team_logo'&& strpos($key, 'teams/') !== 0)   { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'key_mismatch_team']); exit; }
if ($type === 'prize'    && strpos($key, 'prizes/') !== 0)  { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'key_mismatch_prize']); exit; }

$st = $pdo->prepare("INSERT INTO media(owner_id,prize_id,type,storage_key,url,mime,width,height,size_bytes,etag)
                     VALUES(?,?,?,?,?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE url=VALUES(url),mime=VALUES(mime),width=VALUES(width),
                                             height=VALUES(height),size_bytes=VALUES(size_bytes),etag=VALUES(etag)");
$st->execute([$ownerId?:0, $prizeId?:null, $type, $key, $url, $mime, $w?:null, $h?:null, $size?:null, $etag]);

try {
  if ($type==='team_logo' && $teamId>0) {
    $u=$pdo->prepare("UPDATE teams SET logo_url=?, logo_key=? WHERE id=?");
    $u->execute([$url, $key, $teamId]);
  }
  if ($type==='avatar' && $ownerId>0) {
    $u=$pdo->prepare("UPDATE users SET avatar_url=?, avatar_key=? WHERE id=?");
    $u->execute([$url, $key, $ownerId]);
  }
  // prize: tieni più immagini in media, nessun campo singolo da aggiornare qui.
} catch(Throwable $e){
  echo json_encode(['ok'=>false,'error'=>'update_link_failed','detail'=>$e->getMessage()]); exit;
}

echo json_encode(['ok'=>true]);
