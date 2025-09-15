<?php
// Link logo dinamico: guest → "/", loggato → "/lobby.php"
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$logoHref = !empty($_SESSION['user_id']) ? '/lobby.php' : '/';
?>
<!-- Header guest: sfondo nero, logo a sinistra, azioni a destra -->
<link rel="stylesheet" href="/assets/header_guest.css">
<link rel="stylesheet" href="/assets/mobile.css?v=1">
<script defer src="/assets/mobile_boot.js?v=1"></script>

<header class="hdr" role="banner" aria-label="Intestazione sito">
  <div class="hdr__inner">
    <div class="hdr__left">
      <a class="logo" href="<?php echo $logoHref; ?>" aria-label="Vai alla home">
        <img class="logo__img" src="/assets/logo_arena.png" alt="Logo Arena" width="56" height="56">
        <span class="logo__text">ARENA</span>
      </a>
    </div>

    <div class="hdr__right" aria-label="Azioni account">
      <a class="btn btn--primary" href="/registrazione.php">Registrati</a>
      <a class="btn btn--outline" href="/login.php">Login</a>
    </div>
  </div>
</header>

<!-- Sub-header di navigazione principale -->
<nav class="subhdr" role="navigation" aria-label="Navigazione principale">
  <div class="subhdr__inner">
    <ul class="subhdr__menu">
      <li class="subhdr__item"><a class="subhdr__link" href="/">Home</a></li>
      <li class="subhdr__item"><a class="subhdr__link" href="/il_gioco.php">Il Gioco</a></li>
      <li class="subhdr__item"><a class="subhdr__link" href="/assistenza.php">Contatti</a></li>
    </ul>
  </div>
</nav>
