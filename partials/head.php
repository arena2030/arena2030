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

  <title>Arena</title>

  <!-- ============================= -->
  <!-- CSS GLOBALE -->
  <!-- ============================= -->
  <link rel="stylesheet" href="/assets/css/style.css">

  <link rel="stylesheet" href="/assets/css/mobile/header-guest.mobile.css" media="(max-width: 768px)">
<script src="/assets/js/mobile/header-guest.mobile.js" defer></script>
  
  <!-- ============================= -->
  <!-- CSS SPECIFICO DELLA PAGINA (se definito in $page_css) -->
  <!-- ============================= -->
  <?php foreach ($styles as $href): ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($href, ENT_QUOTES); ?>">
  <?php endforeach; ?>
</head>
<?php
  // Identificatori automatici per pagina e percorso (usati da CSS/JS mobile)
  $pg   = basename($_SERVER['SCRIPT_NAME'], '.php');     // esempio: lobby
  $path = trim($_SERVER['SCRIPT_NAME'], '/');            // esempio: flash/torneo.php
?>
<body data-page="<?= htmlspecialchars($pg, ENT_QUOTES) ?>"
      data-path="<?= htmlspecialchars($path, ENT_QUOTES) ?>">
