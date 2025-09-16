<?php
/**
 * Presigned upload per Cloudflare R2 (S3 compatible)
 * POST fields:
 *   type:       team_logo | avatar | prize | generic
 *   mime:       es. image/png, image/webp, image/svg+xml, image/jpeg
 *   owner_id:   (int, per avatar)
 *   league:     (string, per team_logo) es. SERIE_A
 *   slug:       (string, per team_logo) es. ac-milan
 *   prize_id:   (int, per prize)
 *
 * Response (JSON):
 *   { ok, key, url, headers:{Content-Type}, cdn_url }
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../vendor/autoload.php';
use Aws\S3\S3Client;

/* ========== CONFIG da ENV ========== */
$endpoint = getenv('S3_ENDPOINT');   // es. https://<ACCOUNT_ID>.r2.cloudflarestorage.com
$bucket   = getenv('S3_BUCKET');     // es. arena-media
$keyId    = getenv('S3_KEY');        // Access Key ID
$secret   = getenv('S3_SECRET');     // Secret Access Key
$cdnBase  = rtrim(getenv('CDN_BASE') ?: '', '/'); // es. https://pub-xxxx.r2.dev (facoltativo)

if (!$endpoint || !$bucket || !$keyId || !$secret) {
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>'missing_env', 'detail'=>'S3_* env vars not set']); exit;
}

/* ========== INPUTS ========== */
$type    = $_POST['type'] ?? 'generic';
$mime    = trim($_POST['mime'] ?? '');
$ownerId = (int)($_POST['owner_id'] ?? 0);
$league  = trim($_POST['league'] ?? '');
$slug    = trim($_POST['slug'] ?? '');
$prizeId = (int)($_POST['prize_id'] ?? 0);

/* ========== VALIDAZIONI SEMPLICI ========== */
if ($mime === '') {
  http_response_code(400);
  echo json_encode(['ok'=>false, 'error'=>'mime_required']); exit;
}

$allowed = ['image/png','image/jpeg','image/webp','image/svg+xml'];
if (!in_array($mime, $allowed, true)) {
  http_response_code(400);
  echo json_encode(['ok'=>false, 'error'=>'mime_not_allowed', 'allowed'=>$allowed]); exit;
}

/* (opzionale) limite size: lato client passare content-length, qui potresti controllare policy POST.
   Con presigned PUT non blocchi size a livello SDK; lo gestiremo lato UI. */

/* ========== UTILS ========== */
function uuidv4(): string {
  $d = random_bytes(16);
  $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
  $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}
function extFromMime(string $mime): string {
  return match ($mime) {
    'image/png'  => 'png',
    'image/jpeg' => 'jpg',
    'image/webp' => 'webp',
    'image/svg+xml' => 'svg',
    default => 'bin'
  };
}

/* ========== COSTRUZIONE CHIAVE (PATH NEL BUCKET) ========== */
$ext = extFromMime($mime);

switch ($type) {
  case 'team_logo':
    if ($league === '' || $slug === '') {
      http_response_code(400);
      echo json_encode(['ok'=>false, 'error'=>'league_or_slug_missing']); exit;
    }
    $key = "teams/{$league}/{$slug}/logo.{$ext}";
    break;

  case 'avatar':
    if ($ownerId <= 0) {
      http_response_code(400);
      echo json_encode(['ok'=>false, 'error'=>'owner_id_missing']); exit;
    }
    $key = "users/{$ownerId}/avatars/".uuidv4().".{$ext}";
    break;

  case 'prize':
    if ($prizeId <= 0) {
      http_response_code(400);
      echo json_encode(['ok'=>false, 'error'=>'prize_id_missing']); exit;
    }
    $key = "prizes/{$prizeId}/".uuidv4().".{$ext}";
    break;

  default:
    $key = "uploads/".date('Y/m')."/".uuidv4().".{$ext}";
}

/* ========== S3 CLIENT (R2) ========== */
$s3 = new S3Client([
  'version' => 'latest',
  'region'  => 'auto',                 // R2
  'endpoint'=> $endpoint,              // https://<ACCOUNT_ID>.r2.cloudflarestorage.com
  'use_path_style_endpoint' => true,   // R2 preferisce path-style
  'credentials' => [
    'key'    => $keyId,
    'secret' => $secret,
  ],
]);

/* ========== CREA PRESIGNED URL (PUT) ========== */
try {
  $cmd = $s3->getCommand('PutObject', [
    'Bucket'      => $bucket,
    'Key'         => $key,
    'ContentType' => $mime,
    'ACL'         => 'public-read', // se il bucket è pubblico in lettura
  ]);
  $req = $s3->createPresignedRequest($cmd, '+5 minutes');
  $putUrl = (string)$req->getUri();

  // URL pubblico da usare in <img>. Se hai CDN_BASE usa quello, altrimenti costruisci URL S3.
  $publicUrl = $cdnBase ? "{$cdnBase}/{$key}"
                        : rtrim($endpoint, '/')."/{$bucket}/{$key}";

  echo json_encode([
    'ok'      => true,
    'key'     => $key,
    'url'     => $putUrl,                 // da usare per il PUT del file
    'headers' => ['Content-Type' => $mime],
    'cdn_url' => $publicUrl               // da salvare a DB / mostrare nella UI
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>'presign_failed', 'detail'=>$e->getMessage()]);
}
