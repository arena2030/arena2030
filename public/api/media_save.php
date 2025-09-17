<?php
// /public/api/media_save.php — salva metadati media in tabella `media`
declare(strict_types=1);

require_once __DIR__ . '/../../partials/db.php';
require_once __DIR__ . '/../../partials/csrf.php';

csrf_verify_or_die();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth_required']);
    exit;
}

$type   = $_POST['type'] ?? 'generic'; // es: avatar | team_logo | prize
$owner  = (int)($_POST['owner_id'] ?? $uid);
$key    = trim($_POST['storage_key'] ?? '');
$url    = trim($_POST['url'] ?? '');
$etag   = trim($_POST['etag'] ?? '');
$prize  = isset($_POST['prize_id']) ? (int)$_POST['prize_id'] : null;

if ($key === '' || $url === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'missing_fields']);
    exit;
}

try {
    $pdo->beginTransaction();

    $st = $pdo->prepare("INSERT INTO media (storage_key, type, owner_id, prize_id, created_at) VALUES (?, ?, ?, ?, NOW())");
    $st->execute([$key, $type, $owner, $prize]);
    $mid = (int)$pdo->lastInsertId();

    // Aggiorna loghi team se richiesto
    if ($type === 'team_logo') {
        $st2 = $pdo->prepare("UPDATE teams SET logo_key=?, logo_url=?, logo_etag=?, updated_at=NOW() WHERE id=?");
        $st2->execute([$key, $url, $etag !== '' ? $etag : null, $owner]);
    }

    $pdo->commit();
    echo json_encode(['ok' => true, 'media_id' => $mid]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error', 'detail' => $e->getMessage()]);
}
