<?php
// /public/api/upload_r2.php — upload server-side verso R2
declare(strict_types=1);

require_once __DIR__ . '/../../partials/db.php';
require_once __DIR__ . '/../../partials/csrf.php';

csrf_verify_or_die();

use Aws\S3\S3Client;
use Aws\Credentials\Credentials;
use Aws\Exception\AwsException;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

if (!isset($_FILES['file'])) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'missing_file']);
    exit;
}

$path     = $_POST['path']     ?? 'uploads/generic';
$filename = $_POST['filename'] ?? (bin2hex(random_bytes(8)) . '_' . ($_FILES['file']['name'] ?? 'file.bin'));
$key      = rtrim($path, '/') . '/' . $filename;
$contentType = $_FILES['file']['type'] ?? 'application/octet-stream';

$bucket   = getenv('S3_BUCKET');
$region   = getenv('S3_REGION') ?: 'auto';
$endpoint = getenv('S3_ENDPOINT');
$access   = getenv('S3_KEY');
$secret   = getenv('S3_SECRET');
$cdn      = getenv('S3_CDN_BASE'); // opzionale

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

    $etag = trim($result['ETag'] ?? '', '"');
    $url  = $cdn ? rtrim($cdn,'/') . '/' . $key : (rtrim($endpoint,'/') . '/' . $bucket . '/' . $key);

    echo json_encode([
        'ok'          => true,
        'storage_key' => $key,
        'url'         => $url,
        'etag'        => $etag,
    ]);
} catch (AwsException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'aws_error', 'detail' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error', 'detail' => $e->getMessage()]);
}
