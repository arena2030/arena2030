<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$username = trim($_SESSION['username'] ?? 'Punto');
$coins    = $_SESSION['coins'] ?? 0; // placeholder (aggiorna via AJAX se vuoi)
$initial  = strtoupper(mb_substr($username !== '' ? $username : 'P', 0, 1, 'UTF-8'));
?>
<style>
  .hdr{border-bottom:1px solid var(--c-border);}
  .hdr__bar{display:flex;justify-content:space-between;align-items:center;padding:10px 0;}
  .hdr__brand{color:#fff;text-decoration:none;font-weight:700;letter-spacing:.5px;display:flex;align-items:center;gap:8px}
  .hdr__right{display:flex;align-items:center;gap:12px}

  /* Pillola saldo */
  .pill-balance{
    display:flex;align-items:center;gap:8px;
    padding:6px 12px;border:1px solid var(--c-border);border-radius:9999px;
    background:rgba(255,255,255,0.04);
    font-weight:600;
  }
  .pill-balance .ac{opacity:.9}
  .pill-balance .refresh{color:#aaa;text-decoration:none;font-weight:700}
  .pill-balance .refresh:hover{color:#fff}

  /* Avatar tondo con iniziale */
  .avatar{
    width:28px;height:28px;border-radius:50%;
    display:inline-flex;align-items:center;justify-content:center;
    background:linear-gradient(135deg,#243249,#101623);
    border:1px solid var(--c-border);font-size:13px;font-weight:700;color:#eaeaea;
  }

  /* Subheader */
  .subhdr{border-top:1px solid var(--c-border);background:rgba(255,255,255,0.02)}
  .subhdr .container{display:flex;gap:12px;align-items:center;padding:8px 0;}
  .subhdr__menu{display:flex;gap:12px;margin:0;padding:0;list-style:none}
  .subhdr__link{color:#ddd;text-decoration:none;padding:6px 10px;border-radius:8px}
  .subhdr__link:hover{background:rgba(255,255,255,0.06);color:#fff}
</style>

<header class="hdr">
  <div class="container hdr__bar">
    <!-- SX: logo + ARENA -->
    <a href="/punto/dashboard.php" class="hdr__brand">
      <img src="/assets/logo.svg" alt="" width="28" height="28" onerror="this.style.display='none'"/>
      <span>ARENA</span>
    </a>

    <!-- DX: [pill saldo] [avatar] [username] [logout] -->
    <div class="hdr__right" aria-label="Menu punto">
      <div class="pill-balance" title="Arena Coins">
        <span aria-hidden="true">🪙</span>
        <span class="ac" data-balance-amount><?= htmlspecialchars(number_format((float)$coins, 2, '.', '')) ?></span>
        <a href="#" class="refresh" title="Aggiorna saldo"
           onclick="document.dispatchEvent(new CustomEvent('refresh-balance'));return false;">↻</a>
      </div>

      <span class="avatar" aria-hidden="true"><?= htmlspecialchars($initial) ?></span>
      <span class="hdr__link" aria-label="Operatore punto" style="color:#ddd;"><?= htmlspecialchars($username) ?></span>

      <a href="/logout.php" class="btn btn--outline btn--sm">Logout</a>
    </div>
  </div>

  <!-- SUBHEADER (ok così) -->
  <nav class="subhdr" aria-label="Navigazione secondaria punto">
    <div class="container">
      <ul class="subhdr__menu">
        <li><a class="subhdr__link" href="/punto/dashboard.php">Players</a></li>
        <li><a class="subhdr__link" href="/punto/commissioni.php">Commissioni</a></li>
        <li><a class="subhdr__link" href="/punto/premi.php">Premi</a></li>
      </ul>
    </div>
  </nav>
</header>
