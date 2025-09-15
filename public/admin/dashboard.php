<?php
require_once __DIR__ . '/../../partials/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (empty($_SESSION['uid']) || !(
      ($_SESSION['role'] ?? 'USER') === 'ADMIN' || (int)($_SESSION['is_admin'] ?? 0) === 1
   )) {
  header('Location: /login.php'); exit;
}

$page_css = '/pages-css/admin-dashboard.css';
include __DIR__ . '/../../partials/head.php';
include __DIR__ . '/../../partials/header_admin.php';
?>
<main>
  <section class="section">
    <div class="container">
      <!-- Dashboard Admin (vuota) -->
    </div>
  </section>
</main>
<?php include __DIR__ . '/../../partials/footer.php'; ?>
