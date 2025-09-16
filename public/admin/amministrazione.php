<?php
require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['uid']) || !(($_SESSION['role'] ?? 'USER')==='ADMIN' || (int)($_SESSION['is_admin'] ?? 0)===1)) {
  header('Location: /login.php'); exit;
}
$page_css = '/pages-css/admin-dashboard.css';
include __DIR__ . '/../../partials/head.php';
include __DIR__ . '/../../partials/header_admin.php';
?>
<main>
  <section class="section">
    <div class="container">
      <h1>Amministrazione</h1>

      <div class="card" style="max-width:640px;">
        <h2 class="card-title">Report pubblici</h2>
        <p class="muted">Sezione consultabile anche pubblicamente.</p>
        <div style="display:flex; gap:12px; margin-top:12px;">
          <a class="btn btn--primary" href="/tornei-chiusi.php">Tornei chiusi</a>
        </div>
      </div>

    </div>
  </section>
</main>
<?php include __DIR__ . '/../../partials/footer.php'; ?>
