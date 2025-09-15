<?php
// Pagina Home — include head, header e footer
$page_css = '/pages-css/index.css';
include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/header_guest.php';
?>

<main>
  <div class="container" style="padding:64px 0;text-align:center;">
    <h1>Benvenuto in Arena</h1>
    <p class="muted">Questa è la Home page di test, con solo header e footer.</p>
  </div>
</main>

<?php include __DIR__ . '/../partials/footer.php'; ?>
