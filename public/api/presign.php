<?php
// public/api/presign.php — genera URL presignato (PUT) per Cloudflare R2 (S3 compatibile)
declare(strict_types=1);
require_once __DIR__ . '/../../vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Credentials\Credentials;
use Aws\Exception\AwsException;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'method_not_allowed']); exit;
}

$key = $_POST['key'] ?? null; // es: uploads/users/123/avatar_abc123.jpg
if (!$key) { http_response_code(422); echo json_encode(['ok'=>false,'error'=>'missing_key']); exit; }

$bucket = getenv('S3_BUCKET');
$region = getenv('S3_REGION') ?: 'auto';
$endpoint = getenv('S3_ENDPOINT'); // es: https://<accountid>.r2.cloudflarestorage.com
$access = getenv('S3_KEY');
$secret = getenv('S3_SECRET');

try {
  $client = new S3Client([
    'version' => 'latest',
    'region' => $region,
    'endpoint' => $endpoint,
    'use_path_style_endpoint' => true,
    'credentials' => new Credentials($access, $secret),
  ]);

  $cmd = $client->getCommand('PutObject', [
    'Bucket' => $bucket,
    'Key'    => $key,
    'ACL'    => 'private',
    'ContentType' => $_POST['content_type'] ?? 'application/octet-stream',
  ]);
  $req = $client->createPresignedRequest($cmd, '+10 minutes');
  $url = (string)$req->getUri();

  echo json_encode(['ok'=>true, 'url'=>$url]);
} catch (AwsException $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'aws_error','detail'=>$e->getMessage()]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'server_error','detail'=>$e->getMessage()]);
}
