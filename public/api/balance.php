<?php
// /public/api/balance.php — ritorna il saldo reale dal DB (sessione richiesta)
declare(strict_types=1);
ini_set('display_errors','0'); ini_set('log_errors','1'); ini_set('error_log','/tmp/php_errors.log');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }

$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'auth_required']); exit;
}

try {
  $st = $pdo->prepare("SELECT COALESCE(coins,0) AS coins FROM users WHERE id=? LIMIT 1");
  $st->execute([$uid]);
  $coins = (float)($st->fetchColumn() ?? 0);

  echo json_encode([
    'ok'        => true,
    'coins'     => $coins,
    'formatted' => number_format($coins, 2, '.', ''),
    'ts'        => time()
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'db_error','detail'=>$e->getMessage()]);
}
