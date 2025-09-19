<?php
// Upload proxy verso Cloudflare R2 (no CORS richiesti sul bucket)
// Richiesta: POST multipart/form-data con field "file"
// Opzionali: type=team_logo|avatar|prize|generic, league, slug, owner_id, prize_id

declare(strict_types=1);
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log','/tmp/php_errors.log');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

if (session_status()===PHP_SESSION_NONE) session_start();
$uid  = (int)($_SESSION['uid'] ?? 0);
$role = $_SESSION['role'] ?? 'USER';
if ($uid <= 0) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'auth_required']); exit; }

define('APP_ROOT', dirname(__DIR__, 2));
require_once APP_ROOT . '/partials/csrf.php';

// Solo POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  http_response_code(405); echo json_encode(['ok'=>false,'error'=>'method_not_allowed']); exit;
}

// CSRF **tollerante** per retro-compatibilità:
// - Se token presente ma diverso → 403
// - Se token assente → permetti (sei autenticato)
$expected = $_SESSION['csrf_token'] ?? '';
$token = $_POST['csrf_token'] ?? ($_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if ($token !== '') {
  if ($expected === '' || !hash_equals($expected, $token)) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'invalid_csrf']); exit;
  }
}

// anti-abuso minimo
usleep(200000); // 200ms

require_once __DIR__ . '/../../vendor/autoload.php';
use Aws\S3\S3Client;

// ---- ENV obbligatorie ----
$endpoint = getenv('S3_ENDPOINT');
$bucket   = getenv('S3_BUCKET');
$keyId    = getenv('S3_KEY');
$secret   = getenv('S3_SECRET');
$cdnBase  = rtrim(getenv('CDN_BASE') ?: '', '/');

if (!$endpoint || !$bucket || !$keyId || !$secret) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'missing_env']); exit;
}

// ---- Validazione input ----
if (empty($_FILES['file']['tmp_name']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'file_missing']); exit;
}

$type    = $_POST['type'] ?? 'generic';          // team_logo | avatar | prize | generic
$league  = trim($_POST['league'] ?? '');
$slug    = trim($_POST['slug'] ?? '');
$ownerId = (int)($_POST['owner_id'] ?? 0);
$prizeId = (int)($_POST['prize_id'] ?? 0);

// Regole di autorizzazione per tipo
if ($type === 'avatar') {
  if ($ownerId <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'owner_id_missing']); exit; }
  if ($ownerId !== $uid && $role !== 'ADMIN') { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'forbidden_avatar_owner']); exit; }
} elseif ($type === 'team_logo' || $type === 'prize') {
  // Se vuoi estendere ai PUNTO: sostituisci con in_array($role,['ADMIN','PUNTO'],true)
  if ($role !== 'ADMIN') { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'admin_only']); exit; }
}

$tmp   = $_FILES['file']['tmp_name'];
$mime  = $_FILES['file']['type'] ?: 'application/octet-stream';
$size  = (int)($_FILES['file']['size'] ?? 0);

// ---- Whitelist MIME e size (niente SVG per XSS) ----
$allowed = ['image/png','image/jpeg','image/webp'];
$maxBytes = 5 * 1024 * 1024;

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeDetected = finfo_file($finfo, $tmp); finfo_close($finfo);
if ($mimeDetected) { $mime = $mimeDetected; }

if (!in_array($mime, $allowed, true)) {
  http_response_code(415);
  echo json_encode(['ok'=>false,'error'=>'mime_not_allowed','allowed'=>$allowed]); exit;
}
if ($size > $maxBytes) {
  http_response_code(413);
  echo json_encode(['ok'=>false,'error'=>'file_too_large','max'=>$maxBytes]); exit;
}

function uuidv4(): string {
  $d=random_bytes(16); $d[6]=chr((ord($d[6])&0x0f)|0x40); $d[8]=chr((ord($d[8])&0x3f)|0x80);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d),4));
}
function extFromMime(string $m): string {
  return match ($m) {'image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp', default=>'bin'};
}
function slug(string $s): string {
  $s = preg_replace('~[^\pL0-9]+~u','-',$s);
  $s = trim($s,'-');
  $s = @iconv('UTF-8','ASCII//TRANSLIT',$s);
  $s = strtolower((string)$s);
  $s = preg_replace('~[^-a-z0-9]+~','', $s);
  return $s ?: 'n-a';
}
if ($slug !== '') $slug = slug($slug);
if ($league !== '') $league = slug($league);

$ext = extFromMime($mime);

// ---- Costruzione chiave nel bucket ----
switch ($type) {
  case 'team_logo':
    if ($league==='' || $slug===''){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'league_or_slug_missing']); exit; }
    $key = "teams/{$league}/{$slug}/logo.{$ext}";
    break;
  case 'avatar':
    if ($ownerId<=0){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'owner_id_missing']); exit; }
    // Sovrascrivi sempre lo stesso file: niente storico, niente duplicati
    $key = "users/{$ownerId}/avatar.{$ext}";
    break;
  case 'prize':
    if ($prizeId<=0){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'prize_id_missing']); exit; }
    $key = "prizes/{$prizeId}/".uuidv4().".{$ext}";
    break;
  default:
    $key = "uploads/".date('Y/m')."/".uuidv4().".{$ext}";
}

// ---- Client S3 (R2) ----
try {
  $s3 = new S3Client([
    'version'=>'latest',
    'region'=>'auto',
    'endpoint'=>$endpoint,
    'use_path_style_endpoint'=>true,
    'credentials'=>['key'=>$keyId,'secret'=>$secret],
  ]);

  // Caricamento server→R2 (niente CORS dal browser)
  $result = $s3->putObject([
    'Bucket'      => $bucket,
    'Key'         => $key,
    'ContentType' => $mime,
    'SourceFile'  => $tmp,
  ]);

  // URL pubblico (CDN se impostato)
  $publicUrl = $cdnBase ? "{$cdnBase}/{$key}" : rtrim($endpoint,'/')."/{$bucket}/{$key}";
  // Forza l’aggiornamento nelle UI quando l’avatar viene sovrascritto
  if ($type === 'avatar') {
    $publicUrl .= (strpos($publicUrl, '?') === false ? '?' : '&') . 'v=' . time();
  }

  echo json_encode([
    'ok'=>true,
    'key'=>$key,
    'cdn_url'=>$publicUrl,
    'etag'=> $result['ETag'] ?? null,
    'size'=> $size,
    'mime'=> $mime
  ]);
} catch (Throwable $e) {
  error_log('UPLOAD_R2_FATAL: '.$e->getMessage());
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'upload_failed','detail'=>$e->getMessage()]);
}
