<?php
// Upload proxy verso Cloudflare R2 (no CORS richiesti sul bucket)
// Richiesta: POST multipart/form-data con field "file"
// Opzionali: type=team_logo|avatar|prize|generic, league, slug, owner_id, prize_id

ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log','/tmp/php_errors.log');
header('Content-Type: application/json; charset=utf-8');

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

$tmp   = $_FILES['file']['tmp_name'];
$mime  = $_FILES['file']['type'] ?: 'application/octet-stream';
$size  = (int)($_FILES['file']['size'] ?? 0);

$allowed = ['image/png','image/jpeg','image/webp','image/svg+xml'];
if (!in_array($mime, $allowed, true)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'mime_not_allowed','allowed'=>$allowed]); exit;
}

function uuidv4(): string {
  $d=random_bytes(16); $d[6]=chr((ord($d[6])&0x0f)|0x40); $d[8]=chr((ord($d[8])&0x3f)|0x80);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d),4));
}
function extFromMime(string $m): string {
  return match ($m) {'image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp','image/svg+xml'=>'svg', default=>'bin'};
}
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
    'SourceFile'  => $tmp,            // carica dal file temporaneo
    // 'ACL'      => 'public-read'     // NON necessario se il bucket è pubblico in lettura
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
