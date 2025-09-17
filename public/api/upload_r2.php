<?php
// /public/api/upload_r2.php — Upload server-side verso R2, compatibile con loghi/prizes/avatar
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// --- Bootstrap DB (non usato direttamente ma mantiene coerenza con il tuo progetto)
$__db = __DIR__ . '/../../partials/db.php';
if (file_exists($__db)) {
  require_once $__db;
}

// --- CSRF soft: verifica solo se il file esiste e la funzione è disponibile
$__csrf = __DIR__ . '/../../partials/csrf.php';
if (file_exists($__csrf)) {
  require_once $__csrf;
  if (function_exists('csrf_verify_or_die')) {
    csrf_verify_or_die();
  }
}

// --- Autoload AWS SDK (soft): se non esiste, errore chiaro
$__autoload = __DIR__ . '/../../vendor/autoload.php';
if (!file_exists($__autoload)) {
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>'autoload_missing', 'detail'=>'vendor/autoload.php non trovato (AWS SDK mancante)']);
  exit;
}
require_once $__autoload;

use Aws\S3\S3Client;
use Aws\Credentials\Credentials;
use Aws\Exception\AwsException;

// --- Metodo
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false, 'error'=>'method_not_allowed']);
  exit;
}

// --- Dati upload
if (!isset($_FILES['file'])) {
  http_response_code(422);
  echo json_encode(['ok'=>false, 'error'=>'missing_file']);
  exit;
}
$path     = trim($_POST['path']     ?? 'uploads/generic', '/ ');
$filename = trim($_POST['filename'] ?? '');
if ($filename === '') {
  $ext = pathinfo($_FILES['file']['name'] ?? 'file.bin', PATHINFO_EXTENSION) ?: 'bin';
  $filename = bin2hex(random_bytes(8)) . '_' . time() . '.' . strtolower($ext);
}
$key         = $path . '/' . $filename;
$contentType = $_FILES['file']['type'] ?? 'application/octet-stream';

// --- Config R2
$bucket   = getenv('S3_BUCKET');
$region   = getenv('S3_REGION') ?: 'auto';
$endpoint = getenv('S3_ENDPOINT');
$access   = getenv('S3_KEY');
$secret   = getenv('S3_SECRET');
$cdn      = getenv('S3_CDN_BASE'); // opzionale

if (!$bucket || !$endpoint || !$access || !$secret) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'config_missing','detail'=>'S3_ENDPOINT/S3_BUCKET/S3_KEY/S3_SECRET non configurati']);
  exit;
}

// --- Errori PHP upload
if (!empty($_FILES['file']['error'])) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'php_upload_error','detail'=>(string)$_FILES['file']['error']]);
  exit;
}

// --- Upload
try {
  $client = new S3Client([
    'version' => 'latest',
    'region'  => $region,
    'endpoint'=> $endpoint,
    'use_path_style_endpoint' => true,
    'credentials' => new Credentials($access, $secret),
  ]);

  $result = $client->putObject([
    'Bucket'      => $bucket,
    'Key'         => $key,
    'SourceFile'  => $_FILES['file']['tmp_name'],
    'ContentType' => $contentType,
    'ACL'         => 'private',
  ]);

  $etag = trim((string)($result['ETag'] ?? ''), '"');
  $url  = $cdn ? rtrim($cdn,'/').'/'.$key : rtrim($endpoint,'/').'/'.$bucket.'/'.$key;

  echo json_encode([
    'ok'          => true,
    'storage_key' => $key,
    'url'         => $url,
    'etag'        => $etag,
  ]);
} catch (AwsException $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'aws_error','detail'=>$e->getMessage()]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'server_error','detail'=>$e->getMessage()]);
}
