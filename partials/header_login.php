<?php
// [SCOPO] Header minimale per la pagina di login.
//         Logo a sinistra, a destra un solo tasto "Esci" che riporta alla home guest.
?>
<link rel="stylesheet" href="/assets/header_login.css">
<link rel="stylesheet" href="/assets/mobile.css?v=1">
<script defer src="/assets/mobile_boot.js?v=1"></script>

<header class="hdr" role="banner" aria-label="Intestazione sito">
  <div class="hdr__inner">
    
<!-- Logo a sinistra -->
<div class="hdr__left">
  <div class="logo" aria-label="Logo Arena">
    <img class="logo__img" src="/assets/logo_arena.png" alt="Logo Arena" width="56" height="56">
    <span class="logo__text">ARENA</span>
  </div>
</div>

    <!-- Pulsante "Esci" -->
    <div class="hdr__right" aria-label="Azioni account">
      <a class="btn btn--outline" href="/index.php">Esci</a>
    </div>

  </div>
</header>
