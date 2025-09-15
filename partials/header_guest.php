<?php /* Guest Header */ ?>
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
      <a href="/public/il_gioco.php">Il Gioco</a>
      <a href="/public/login.php">Login</a>
      <a class="btn btn--primary btn--sm" href="/public/registrazione.php">Registrati</a>
    </nav>
    <button class="hamburger" data-toggle="mobile-menu" aria-label="Menu">☰</button>
  </div>
  <nav class="mobile-menu" data-role="mobile-menu">
    <a href="/public/index.php">Home</a>
    <a href="/public/il_gioco.php">Il Gioco</a>
    <a href="/public/login.php">Login</a>
    <a href="/public/registrazione.php">Registrati</a>
  </nav>
</header>
