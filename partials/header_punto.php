<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$username = $_SESSION['username'] ?? 'Punto';
$coins    = $_SESSION['coins']    ?? 0; // placeholder
?>
<header class="hdr">
  <div class="container hdr__bar">
    <!-- SX: logo + ARENA -->
    <a href="/punto/dashboard.php" class="hdr__brand" style="display:flex;align-items:center;gap:8px;">
      <img src="/assets/logo.svg" alt="" width="28" height="28" />
      <span>ARENA</span>
    </a>

    <!-- DX: saldo + utente + logout -->
    <nav class="nav" aria-label="Menu punto">
      <div class="hdr__balance" title="Arena Coin" style="display:flex;align-items:center;gap:8px;">
        <span aria-hidden="true">🪙</span>
        <span data-balance-amount><?= htmlspecialchars((string)$coins) ?></span>
        <a href="#" class="hdr__link" title="Aggiorna saldo">↻</a>
      </div>

      <span class="hdr__link" aria-label="Operatore punto">
        <?= htmlspecialchars($username) ?>
      </span>

      <a href="/logout.php" class="btn btn--outline btn--sm">Logout</a>
    </nav>
  </div>

  <!-- SUBHEADER -->
  <nav class="subhdr" aria-label="Navigazione secondaria punto">
    <div class="container">
      <ul class="subhdr__menu">
        <li><a class="subhdr__link" href="/punto/players.php">Players</a></li>
        <li><a class="subhdr__link" href="/punto/commissioni.php">Commissioni</a></li>
        <li><a class="subhdr__link" href="/punto/premi.php">Premi</a></li>
      </ul>
    </div>
  </nav>
</header>
