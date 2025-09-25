<?php
// HEAD comune per tutte le pagine
// Se in una pagina vuoi caricare un CSS dedicato, imposta $page_css prima di includere questo file.
// Esempio: $page_css = '/pages-css/login.css';

$styles = [];
if (isset($page_css)) {
  $styles = is_array($page_css) ? $page_css : [$page_css];
}
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark">

  <!-- 🔐 CSRF token per AJAX POST -->
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">

  <title>Arena</title>

  <!-- CSS globale -->
  <link rel="stylesheet" href="/assets/css/style.css">

  <!-- CSS specifico della pagina -->
  <?php foreach ($styles as $href): ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($href, ENT_QUOTES); ?>">
  <?php endforeach; ?>
</head>
<body>
