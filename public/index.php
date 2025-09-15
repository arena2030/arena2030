<?php
// Pagina Home minimale — solo header guest + footer
$page_css = '/pages-css/index.css'; 
include __DIR__ . '/../partials/head.php'; 
include __DIR__ . '/../partials/header_guest.php'; 
?>

<main>
  <div class="container" style="padding:64px 0;text-align:center;">
    <h1>Benvenuto in Arena</h1>
    <p class="muted">Questa è la home page con solo header e footer.</p>
  </div>
</main>

<?php include __DIR__ . '/../partials/footer.php'; ?>
