<?php
// /app/partials/csrf.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Genera o restituisce il token CSRF salvato in sessione
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Ritorna un campo hidden HTML con il token
 */
function csrf_field(): string {
    $t = htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="'.$t.'">';
}

/**
 * Verifica il token CSRF per le richieste POST
 */
function csrf_verify_or_die(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    $ok = isset($_POST['csrf_token']) &&
          hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token']);
    if (!$ok) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'invalid_csrf']);
        exit;
    }
}
