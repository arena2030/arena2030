<?php
// [HEAD USER] Avvio sessione e variabili di test (qui non tocchiamo il DB)
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$username  = $_SESSION['username'] ?? 'DemoUser';          // username fittizio per test
$crediti   = $_SESSION['crediti']  ?? 100;                 // saldo fittizio per test
$avatarChr = mb_strtoupper(mb_substr($username, 0, 1));    // iniziale per l’avatar
?>
<link rel="stylesheet" href="/assets/header_admin.css">

<header class="hdr" role="banner">
  <div class="hdr__inner">

<!-- Logo a sinistra -->
<div class="hdr__left">
  <div class="logo" aria-label="Logo Arena">
    <img class="logo__img" src="/assets/logo_arena.png" alt="Logo Arena" width="56" height="56">
    <span class="logo__text">ARENA</span>
  </div>
</div>

    <!-- Azioni a destra -->
    <div class="hdr__right">

      <!-- Avatar + username -->
      <div class="user-display">
        <span class="user-avatar__circle"><?php echo htmlspecialchars($avatarChr); ?></span>
        <span class="user-display__name"><?php echo htmlspecialchars($username); ?></span>
      </div>

      <!-- Logout -->
      <form class="logout-form" action="/logout.php" method="post">
        <button class="btn btn--outline" type="submit">Logout</button>
      </form>

    </div>
  </div>
</header>

<!-- Sub-header (solo per admin) -->
<?php if (!empty($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
<nav class="subhdr" role="navigation" aria-label="Navigazione amministrazione">
  <div class="subhdr__inner">
    <ul class="subhdr__menu">
      <!-- Players: punta alla tua lista utenti admin (o dove preferisci) -->
      <li class="subhdr__item">
        <a class="subhdr__link" href="/admin/dashboard.php">Players</a>
      </li>

      <!-- Crea Tornei -->
      <li class="subhdr__item">
        <a class="subhdr__link" href="/admin/crea_torneo.php">Crea Tornei</a>
      </li>

      <!-- Gestisci Tornei (separeremo in una pagina dedicata in futuro; per ora può coincidere con crea_torneo) -->
      <li class="subhdr__item">
        <a class="subhdr__link" href="/admin/gestisci_tornei.php">Gestisci Tornei</a>
      </li>

      <!-- Amministrazione (home admin o altra pagina riassuntiva) -->
      <li class="subhdr__item">
        <a class="subhdr__link" href="/admin/amministrazione.php">Amministrazione</a>
      </li>

      <!-- ====== AGGIUNTA: link rapidi ====== -->
      <li class="subhdr__item">
        <a class="subhdr__link" href="/admin/tornei_chiusi.php">Tornei chiusi</a>
      </li>
      <li class="subhdr__item">
        <a class="subhdr__link" href="/admin/premi_richiesti.php">Premi richiesti</a>
      </li>
      <li class="subhdr__item">
        <a class="subhdr__link" href="/admin/premi_riscossi.php">Premi riscossi</a>
      </li>
      <!-- ====== FINE AGGIUNTA ====== -->
    </ul>
  </div>
</nav>
<?php endif; ?>
