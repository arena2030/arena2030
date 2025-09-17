<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$username = $_SESSION['username'] ?? 'Admin';
?>
<header class="hdr">
  <div class="container hdr__bar">
    <!-- SX: logo + ARENA -->
   <a href="/admin/dashboard.php" class="hdr__brand" aria-label="Home">
  <img class="brand-logo" src="/assets/logo_arena.png" alt="ARENA" width="70" height="70" />
</a>

    <!-- DX: utente + logout -->
    <nav class="nav" aria-label="Menu admin">
      <span class="hdr__link" aria-label="Amministratore">
        <?= htmlspecialchars($username) ?>
      </span>
      <a href="/logout.php" class="btn btn--outline btn--sm">Logout</a>
    </nav>
  </div>

  <!-- SUBHEADER -->
  <nav class="subhdr" aria-label="Navigazione secondaria admin">
    <div class="container">
      <ul class="subhdr__menu">
        <li><a class="subhdr__link" href="/admin/dashboard.php">Players</a></li>
        <li><a class="subhdr__link" href="/admin/crea-tornei.php">Crea tornei</a></li>
        <li><a class="subhdr__link" href="/admin/gestisci-tornei.php">Gestisci tornei</a></li>
        <li><a class="subhdr__link" href="/admin/amministrazione.php">Amministrazione</a></li>
        <li><a class="subhdr__link" href="/admin/punti.php">Punti</a></li>
        <li><a class="subhdr__link" href="/admin/premi.php">Premi</a></li>
      </ul>
    </div>
  </nav>
</header>
