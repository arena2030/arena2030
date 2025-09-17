<?php
// public/login.php — handler login sicuro
if (session_status() === PHP_SESSION_NONE) { 
  // session flags hardening
  ini_set('session.cookie_httponly', '1');
  ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? '1' : '0');
  ini_set('session.cookie_samesite', 'Strict');
  session_start(); 
}

require_once __DIR__ . '/../partials/db.php';
require_once __DIR__ . '/../partials/csrf.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  // Render form minimale (o includi il tuo template)
  $csrf = csrf_token();
  ?><!doctype html><html lang="it"><meta charset="utf-8"><title>Login</title>
  <body>
    <form method="post" action="/login.php">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') ?>">
      <label>Email</label><input type="email" name="email" required><br>
      <label>Password</label><input type="password" name="password" required><br>
      <button type="submit">Accedi</button>
    </form>
  </body></html><?php
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json; charset=utf-8');
  csrf_verify_or_die();

  $email = trim($_POST['email'] ?? '');
  $password = $_POST['password'] ?? '';

  if ($email === '' || $password === '') {
    http_response_code(422);
    echo json_encode(['ok'=>false, 'error'=>'missing_fields']); exit;
  }

  $stmt = $pdo->prepare("SELECT id, username, email, password_hash, role, is_admin FROM users WHERE email = ? LIMIT 1");
  $stmt->execute([$email]);
  $u = $stmt->fetch(PDO::FETCH_ASSOC);

  $ok = $u && password_verify($password, $u['password_hash']);
  if (!$ok) {
    http_response_code(401);
    echo json_encode(['ok'=>false, 'error'=>'invalid_credentials']); exit;
  }

  // Rigenera session id per sicurezza post-login
  session_regenerate_id(true);
  $_SESSION['uid'] = (int)$u['id'];
  $_SESSION['username'] = $u['username'];
  $_SESSION['role'] = $u['role'];
  $_SESSION['is_admin'] = (int)$u['is_admin'];

  echo json_encode(['ok'=>true, 'redirect'=> ($u['role']==='ADMIN' || (int)$u['is_admin']===1) ? '/admin/' : '/']);
  exit;
}

http_response_code(405);
