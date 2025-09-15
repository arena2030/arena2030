<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$username = $_SESSION['username'] ?? 'Admin';
?>
<header class="hdr">
  <div class="container hdr__bar">
    <!-- SX: logo + ARENA -->
    <a href="/admin/dashboard.php" class="hdr__brand" style="display:flex;align-items:center;gap:8px;">
      <img src="/assets/logo.svg" alt="" width="28" height="28" />
      <span>ARENA</span>
    </a>

    <!-- DX: utente + logout -->
    <nav class="hdr__nav" aria-label="Menu admin">
      <span class="hdr__link" aria-label="Amministratore">
        <?= htmlspecialchars($username) ?>
      </span>
      <a href="/logout.php" class="btn btn--primary">Logout</a>
    </nav>
  </div>

  <!-- SUBHEADER: Players, Crea tornei, Gestisci tornei, Amministrazione, Punti, Premi -->
  <nav class="subhdr" aria-label="Navigazione secondaria admin">
    <div class="container">
      <ul class="subhdr__menu">
        <li><a class="subhdr__link" href="/admin/players.php">Players</a></li>
        <li><a class="subhdr__link" href="/admin/crea-torneo.php">Crea tornei</a></li>
        <li><a class="subhdr__link" href="/admin/tornei.php">Gestisci tornei</a></li>
        <li><a class="subhdr__link" href="/admin/amministrazione.php">Amministrazione</a></li>
        <li><a class="subhdr__link" href="/admin/punti.php">Punti</a></li>
        <li><a class="subhdr__link" href="/admin/premi.php">Premi</a></li>
      </ul>
    </div>
  </nav>
</header>
