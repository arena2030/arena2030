<?php
// /public/partials/header_point.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// helper per evidenziare la voce corrente del menu
if (!function_exists('is_active')) {
  function is_active(string $file): string {
    return basename($_SERVER['PHP_SELF']) === $file ? 'active' : '';
  }
}

// dati header
$username = $_SESSION['username'] ?? 'Punto';
$coins    = $_SESSION['coins']    ?? 0; // se vuoi, poi lo aggiorni via AJAX

?>
<style>
  .hdr{border-bottom:1px solid var(--c-border);}
  .hdr__bar{display:flex;justify-content:space-between;align-items:center;padding:10px 0;}
  .hdr__brand{color:#fff;text-decoration:none;font-weight:700;letter-spacing:.5px}
  .hdr .nav{display:flex;gap:12px;align-items:center}
  .hdr__link{color:#ddd;text-decoration:none}
  .subhdr{border-top:1px solid var(--c-border);background:rgba(255,255,255,0.02)}
  .subhdr .container{display:flex;gap:12px;align-items:center;padding:8px 0;}
  .subhdr a{color:#ddd;text-decoration:none;padding:6px 10px;border-radius:8px}
  .subhdr a.active{background:rgba(255,255,255,0.06);color:#fff}
</style>

<header class="hdr">
  <div class="container hdr__bar">
    <!-- SX: logo + ARENA -->
    <a href="/punto/dashboard.php" class="hdr__brand" style="display:flex;align-items:center;gap:8px;">
      <img src="/assets/logo.svg" alt="" width="28" height="28" onerror="this.style.display='none'"/>
      <span>ARENA</span>
    </a>

    <!-- DX: saldo + utente + logout -->
    <nav class="nav" aria-label="Menu punto">
      <div class="hdr__balance" title="Arena Coins" style="display:flex;align-items:center;gap:8px;">
        <span aria-hidden="true">🪙</span>
        <span data-balance-amount><?= htmlspecialchars((string)$coins) ?></span>
        <a href="#" class="hdr__link" title="Aggiorna saldo" onclick="document.dispatchEvent(new CustomEvent('refresh-balance'));return false;">↻</a>
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
      <a class="<?= is_active('dashboard.php') ?>"   href="/punto/dashboard.php">Dashboard</a>
      <a class="<?= is_active('commissioni.php') ?>" href="/punto/commissioni.php">Commissioni</a>
      <a class="<?= is_active('premi.php') ?>"       href="/punto/premi.php">Premi</a>
    </div>
  </nav>
</header>
