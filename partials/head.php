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

<!-- CSS specifico pagina -->
<?php foreach ($styles as $href): ?>
  <link rel="stylesheet" href="<?php echo htmlspecialchars($href, ENT_QUOTES); ?>">
<?php endforeach; ?>

  <!-- ArenaNotice: script core -->
<script src="/js/arena_notice.js" defer></script>

<!-- ArenaNotice: boot idempotente (login, cambio pagina, focus, bfcache) -->
<script>
  (function(){
    if (window.__arenaNoticeBoot) return;
    window.__arenaNoticeBoot = true;

    function boot(){
      try{
        window.ArenaNotice
          ?.init({ endpoint: '/api/tournament_final.php' })
          .attachGlobalHooks();

        // Safari mobile / bfcache: quando si torna su una pagina, ricontrolla
        window.addEventListener('pageshow', function(){
          window.ArenaNotice?.checkNow();
        }, { passive:true });
      }catch(_){}
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', boot);
    } else {
      boot();
    }
  })();
</script>
<script src="/js/layout_switcher.js" defer></script>  
</head>
<?php
  // Identificatori automatici per pagina e percorso (usati da CSS/JS mobile)
  $pg   = basename($_SERVER['SCRIPT_NAME'], '.php');     // esempio: lobby
  $path = trim($_SERVER['SCRIPT_NAME'], '/');            // esempio: flash/torneo.php
?>
<body data-page="<?= htmlspecialchars($pg, ENT_QUOTES) ?>"
      data-path="<?= htmlspecialchars($path, ENT_QUOTES) ?>">
