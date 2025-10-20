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

  <?php if ($isLogged): ?>
    <!-- MOBILE USER -->
    <link rel="stylesheet" href="/assets/css/mobile/header-user.mobile.css" media="(max-width: 768px)">
    <script src="/assets/js/mobile/header-user.mobile.js" defer></script>
  <?php else: ?>
    <!-- MOBILE GUEST -->
    <link rel="stylesheet" href="/assets/css/mobile/header-guest.mobile.css" media="(max-width: 768px)">
    <script src="/assets/js/mobile/header-guest.mobile.js" defer></script>
  <?php endif; ?>

  <!-- CSS specifico pagina -->
  <?php foreach (($styles ?? []) as $href): ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($href, ENT_QUOTES); ?>">
  <?php endforeach; ?>

  <!-- ArenaNotice: script core -->
  <script src="/js/arena_notice.js" defer></script>

  <!-- ArenaNotice: boot idempotente (login, cambio pagina, focus, bfcache) -->
  <?php if ($isLogged): // opzionale: abilita solo per utenti loggati ?>
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
  <?php endif; ?>
</head>
<?php
  // Identificatori automatici per pagina e percorso (usati da CSS/JS mobile)
  $pg   = basename($_SERVER['SCRIPT_NAME'], '.php');     // esempio: lobby
  $path = trim($_SERVER['SCRIPT_NAME'], '/');            // esempio: flash/torneo.php
?>
<body data-page="<?= htmlspecialchars($pg, ENT_QUOTES) ?>"
      data-path="<?= htmlspecialchars($path, ENT_QUOTES) ?>">
