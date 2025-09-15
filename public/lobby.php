<?php
require_once __DIR__ . '/../partials/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (empty($_SESSION['uid'])) {
  header('Location: /login.php'); exit;
}

$page_css = '/pages-css/lobby.css';
include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/header_user.php';
?>
<main>
  <section class="section">
    <div class="container">
      <!-- Lobby utente (vuota) -->
    </div>
  </section>
</main>
<?php include __DIR__ . '/../partials/footer.php'; ?>
