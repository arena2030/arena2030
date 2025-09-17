<?php
// partials/csrf.php — CSRF token helpers (include in pages with POST forms)
if (session_status() === PHP_SESSION_NONE) { session_start(); }

function csrf_token(): string {
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf_token'];
}

function csrf_field(): string {
  $t = htmlspecialchars(csrf_token(), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
  return '<input type="hidden" name="csrf_token" value="'.$t.'">';
}

function csrf_verify_or_die(): void {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') { return; } // allow GET routes
  $ok = isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token']);
  if (!$ok) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'invalid_csrf']);
    exit;
  }
}
