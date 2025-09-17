<?php
/* Hardening: logga errori e non mostra HTML */
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log','/tmp/php_errors.log');

header('Content-Type: application/json; charset=utf-8');

/* Autoload robusto */
$autoload = __DIR__ . '/../../vendor/autoload.php';
if (!file_exists($autoload)) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'no_autoload','detail'=>'vendor/autoload.php non trovato (installa aws/aws-sdk-php)']); exit;
}
require_once $autoload;

use Aws\S3\S3Client;

/* ENV */
$endpoint = getenv('S3_ENDPOINT');   // es. https://<ACCOUNT_ID>.r2.cloudflarestorage.com
$bucket   = getenv('S3_BUCKET');     // es. arena-media
$keyId    = getenv('S3_KEY');        // Access Key ID
$secret   = getenv('S3_SECRET');     // Secret Access Key
$cdnBase  = rtrim(getenv('CDN_BASE') ?: '', '/');

if (!$endpoint || !$bucket || !$keyId || !$secret) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'missing_env','detail'=>[
    'S3_ENDPOINT'=>!!$endpoint,'S3_BUCKET'=>!!$bucket,'S3_KEY'=>!!$keyId,'S3_SECRET'=>!!$secret
  ]]); exit;
}

/* INPUT */
$type    = $_POST['type'] ?? 'generic';            // team_logo | avatar | prize | generic
$mime    = trim($_POST['mime'] ?? '');
$ownerId = (int)($_POST['owner_id'] ?? 0);
$league  = trim($_POST['league'] ?? '');
$slug    = trim($_POST['slug'] ?? '');
$prizeId = (int)($_POST['prize_id'] ?? 0);

if ($mime === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'mime_required']); exit; }
$allowed = ['image/png','image/jpeg','image/webp','image/svg+xml'];
if (!in_array($mime, $allowed, true)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'mime_not_allowed','allowed'=>$allowed]); exit;
}

/* Utils */
function uuidv4(){ $d=random_bytes(16); $d[6]=chr((ord($d[6])&0x0f)|0x40); $d[8]=chr((ord($d[8])&0x3f)|0x80); return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4)); }
function extFromMime($m){ return $m==='image/png'?'png':($m==='image/jpeg'?'jpg':($m==='image/webp'?'webp':($m==='image/svg+xml'?'svg':'bin'))); }
$ext = extFromMime($mime);

/* Key nel bucket */
switch ($type) {
  case 'team_logo':
    if ($league==='' || $slug===''){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'league_or_slug_missing']); exit; }
    $key = "teams/{$league}/{$slug}/logo.{$ext}";
    break;
  case 'avatar':
    if ($ownerId <= 0){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'owner_id_missing']); exit; }
    $key = "users/{$ownerId}/avatars/".uuidv4().".{$ext}";
    break;
  case 'prize':
    if ($prizeId <= 0){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'prize_id_missing']); exit; }
    $key = "prizes/{$prizeId}/".uuidv4().".{$ext}";
    break;
  default:
    $key = "uploads/".date('Y/m')."/".uuidv4().".{$ext}";
}

/* S3 Client (R2) */
try {
  $s3 = new S3Client([
    'version' => 'latest',
    'region'  => 'auto',
    'endpoint'=> $endpoint,
    'use_path_style_endpoint' => true,
    'credentials' => ['key'=>$keyId,'secret'=>$secret],
  ]);

$cmd = $s3->getCommand('PutObject', [
  'Bucket'      => $bucket,
  'Key'         => $key,
  'ContentType' => $mime
]);
  $req = $s3->createPresignedRequest($cmd, '+5 minutes');
  $putUrl = (string)$req->getUri();

  // URL pubblico per <img>
  $publicUrl = $cdnBase ? "{$cdnBase}/{$key}" : rtrim($endpoint,'/')."/{$bucket}/{$key}";

  echo json_encode([
    'ok'=>true,
    'key'=>$key,
    'url'=>$putUrl,
    'headers'=>['Content-Type'=>$mime],
    'cdn_url'=>$publicUrl
  ]);
} catch (Throwable $e) {
  error_log('PRESIGN_FATAL: '.$e->getMessage());
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'presign_failed','detail'=>$e->getMessage()]);
}
