<?php
require_once __DIR__ . '/../../partials/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Controllo accesso: solo admin
if (empty($_SESSION['uid']) || !(($_SESSION['role'] ?? 'USER') === 'ADMIN' || ($_SESSION['is_admin'] ?? 0) == 1)) {
  header('Location: /login.php'); exit;
}

$page_css = '/pages-css/admin-dashboard.css';

// HEAD + HEADER ADMIN
include __DIR__ . '/../../partials/head.php';
include __DIR__ . '/../../partials/header_admin.php';
?>
<main>
  <section class="section">
    <div class="container">
      <h1>Dashboard Admin</h1>
      <p>Benvenuto, <strong><?= htmlspecialchars($_SESSION['username']) ?></strong>.</p>
      <p>Questa è la tua area riservata da amministratore.</p>
    </div>
  </section>
</main>
<?php include __DIR__ . '/../../partials/footer.php'; ?>
