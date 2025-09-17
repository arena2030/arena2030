<?php /* Header Guest — sottile, blu scuro */ ?>
<header class="hdr">
  <div class="container hdr__bar" style="height:56px;"><!-- sottile -->
    <!-- SX: Logo + ARENA -->
    <a href="/index.php" class="hdr__brand" style="display:flex;align-items:center;gap:10px;color:#fff;">
      <!-- Logo minimal SVG (puoi sostituirlo con img) -->
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <defs>
          <linearGradient id="g" x1="0" y1="0" x2="24" y2="24">
            <stop stop-color="#2f80ff"/><stop offset="1" stop-color="#00c2ff"/>
          </linearGradient>
        </defs>
        <path d="M3 12L12 3l9 9-9 9-9-9z" stroke="url(#g)" stroke-width="2"/>
      </svg>
      <span>ARENA</span>
    </a>

    <!-- DX: Registrati + Login -->
    <nav class="hdr__nav" aria-label="Accesso">
      <a href="/registrazione.php" class="btn btn--primary">Registrati</a>
      <a href="/login.php" class="btn btn--outline">Login</a>
    </nav>
  </div>

  <!-- Sub header (menu secondario) -->
  <nav class="subhdr" aria-label="Sezioni principali">
  <div class="container">
    <ul class="subhdr__menu">
      <li><a class="subhdr__link" href="/index.php">Home</a></li>
      <li><a class="subhdr__link" href="/il_gioco.php">Il Gioco</a></li>
      <li><a class="subhdr__link" href="/contatti.php">Contatti</a></li>
    </ul>
  </div>
</nav>
</header>
