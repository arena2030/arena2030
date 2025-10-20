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

  <!-- CSS globale -->
  <link rel="stylesheet" href="/assets/css/style.css">
  
  <?php
  // Determina login lato server
  $isLogged = !empty($_SESSION['uid']) && in_array(($_SESSION['role'] ?? 'USER'), ['USER','PUNTO','ADMIN'], true);
  ?>
  <link rel="stylesheet" href="/assets/css/style.css">

  <?php if ($isLogged): ?>
    <!-- MOBILE USER (solo CSS; lo script verrà caricato dal bootstrap) -->
    <link rel="stylesheet" href="/assets/css/mobile/header-user.mobile.css" media="(max-width: 768px)">
  <?php else: ?>
    <!-- MOBILE GUEST (solo CSS; lo script verrà caricato dal bootstrap) -->
    <link rel="stylesheet" href="/assets/css/mobile/header-guest.mobile.css" media="(max-width: 768px)">
  <?php endif; ?>

  <!-- CSS specifico pagina -->
  <?php foreach ($styles as $href): ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($href, ENT_QUOTES); ?>">
  <?php endforeach; ?>

  <!-- Bootstrap mobile: gestisce header mobile/desktop live -->
  <script src="/js/mobile_bootstrap.js" defer></script>
</head>
<?php
  // Identificatori automatici per pagina e percorso (usati da CSS/JS mobile)
  $pg   = basename($_SERVER['SCRIPT_NAME'], '.php');     // esempio: lobby
  $path = trim($_SERVER['SCRIPT_NAME'], '/');            // esempio: flash/torneo.php
?>
<body data-page="<?= htmlspecialchars($pg, ENT_QUOTES) ?>"
      data-path="<?= htmlspecialchars($path, ENT_QUOTES) ?>">
