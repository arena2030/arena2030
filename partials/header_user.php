<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$username = $_SESSION['username'] ?? 'Utente';
$coins    = $_SESSION['coins']    ?? 0; // placeholder
?>
<header class="hdr">
  <div class="container hdr__bar">
    <!-- SX: logo + ARENA -->
    <a href="/lobby.php" class="hdr__brand" style="display:flex;align-items:center;gap:8px;">
      <img src="/assets/logo.svg" alt="" width="28" height="28" />
      <span>ARENA</span>
    </a>

    <!-- DX: ricarica + saldo + utente + logout -->
    <nav class="hdr__nav" aria-label="Menu utente">
      <a href="/ricarica.php" class="btn btn--outline">Ricarica</a>

      <div class="hdr__balance" title="Arena Coin" style="display:flex;align-items:center;gap:8px;">
        <span aria-hidden="true">🪙</span>
        <span data-balance-amount><?= htmlspecialchars((string)$coins) ?></span>
        <a href="#" class="hdr__link" title="Aggiorna saldo">↻</a>
      </div>

      <span class="hdr__link" aria-label="Utente loggato">
        <?= htmlspecialchars($username) ?>
      </span>

      <a href="/logout.php" class="btn btn--outline">Logout</a>
    </nav>
  </div>

  <!-- SUBHEADER: Lobby, Storico tornei, Premi, Lista movimenti, Dati utente -->
  <nav class="subhdr" aria-label="Navigazione secondaria utente">
    <div class="container">
      <ul class="subhdr__menu">
        <li><a class="subhdr__link" href="/lobby.php">Lobby</a></li>
        <li><a class="subhdr__link" href="/storico.php">Storico tornei</a></li>
        <li><a class="subhdr__link" href="/premi.php">Premi</a></li>
        <li><a class="subhdr__link" href="/movimenti.php">Lista movimenti</a></li>
        <li><a class="subhdr__link" href="/dati-utente.php">Dati utente</a></li>
      </ul>
    </div>
  </nav>
</header>
