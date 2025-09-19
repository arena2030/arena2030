<?php
declare(strict_types=1);

/**
 * CSRF helpers: genera e verifica token legato alla sessione.
 * Accetta token da:
 *  - POST 'csrf_token' oppure 'csrf'
 *  - Header 'X-CSRF-Token'
 */

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

function csrf_token(): string {
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf_token'];
}

/**
 * Verifica il token CSRF e termina con 403 su errore.
 * Opzionale: puoi passare un token esplicito; se null, lo pesca da POST/header.
 */
function csrf_verify_or_die(?string $token = null): void {
  $expected = $_SESSION['csrf_token'] ?? '';
  if ($token === null) {
    $token = $_POST['csrf_token'] ?? $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
  }
  $ok = is_string($token) && is_string($expected) && $expected !== '' && hash_equals($expected, $token);
  if (!$ok) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'invalid_csrf']);
    exit;
  }
}
