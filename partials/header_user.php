<?php /* User Header (UI only) */ ?>
<header class="site-header">
  <div class="container navbar">
    <div class="brand">
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <path d="M3 12L12 3l9 9-9 9-9-9z" stroke="url(#g)" stroke-width="2" />
        <defs><linearGradient id="g" x1="0" y1="0" x2="24" y2="24"><stop stop-color="#2f80ff"/><stop offset="1" stop-color="#00c2ff"/></linearGradient></defs>
      </svg>
      <span>ARENA</span>
    </div>
    <nav class="nav">
      <a href="/public/index.php">Home</a>
      <a href="/public/lobby.php">Lobby</a>
      <a href="/public/torneo.php">Torneo</a>
      <a href="/public/storico_tornei.php">Storico</a>
      <a href="/public/premi.php">Premi</a>
      <a href="/public/ricarica.php">Ricarica</a>
      <a href="/public/dati_utente.php">Profilo</a>
      <a class="btn btn--secondary btn--sm" href="/public/logout.php">Logout</a>
    </nav>
    <button class="hamburger" data-toggle="mobile-menu" aria-label="Menu">☰</button>
  </div>
  <nav class="mobile-menu" data-role="mobile-menu">
    <a href="/public/index.php">Home</a>
    <a href="/public/lobby.php">Lobby</a>
    <a href="/public/torneo.php">Torneo</a>
    <a href="/public/storico_tornei.php">Storico</a>
    <a href="/public/premi.php">Premi</a>
    <a href="/public/ricarica.php">Ricarica</a>
    <a href="/public/dati_utente.php">Profilo</a>
    <a href="/public/logout.php">Logout</a>
  </nav>
</header>
