<?php
// partials/api_auth_guard.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

function json_fail(int $status, string $msg) {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE);
  exit;
}

function require_login(): void {
  if (empty($_SESSION['uid'])) { json_fail(401, 'login_required'); }
}

function is_admin(): bool {
  return (!empty($_SESSION['role']) && $_SESSION['role'] === 'ADMIN')
      || (!empty($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1);
}

function require_csrf_or_die(): void {
  require_once __DIR__ . '/csrf.php';
  $expected = $_SESSION['csrf_token'] ?? '';
  $token = $_POST['csrf_token'] ?? ($_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
  if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
    json_fail(419, 'invalid_csrf');
  }
}

function require_owner_or_admin(int $ownerId): void {
  if (is_admin()) return;
  if (empty($_SESSION['uid']) || (int)$_SESSION['uid'] !== (int)$ownerId) {
    json_fail(403, 'forbidden_not_owner');
  }
}
