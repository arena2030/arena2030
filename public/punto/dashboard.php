<?php
require_once __DIR__ . '/../../partials/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (empty($_SESSION['uid']) || ($_SESSION['role'] ?? 'USER') !== 'PUNTO') {
  header('Location: /login.php'); exit;
}

$page_css = '/pages-css/punto-dashboard.css';
include __DIR__ . '/../../partials/head.php';
include __DIR__ . '/../../partials/header_user.php'; // usa questo finché non hai un header_punto
?>
<main>
  <section class="section">
    <div class="container">
      <!-- Dashboard Punto (vuota) -->
    </div>
  </section>
</main>
<?php include __DIR__ . '/../../partials/footer.php'; ?>
