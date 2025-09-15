<?php
// [HEAD USER] Sessione e lettura dati reali utente
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Percorso root (header_user.php sta in /public)
$ROOT = __DIR__;

// Config + DB
require_once $ROOT . '/src/config.php';
require_once $ROOT . '/src/db.php';

/* Flag visibilità pagina Premi */
$prizesEnabled = 1;
try {
  $st = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_key='prizes_enabled' LIMIT 1");
  $st->execute();
  $prizesEnabled = (int)($st->fetchColumn() ?? 1);
} catch (Throwable $e) {
  $prizesEnabled = 1;
}

// Username da sessione (fallback "DemoUser" se manca)
$username  = $_SESSION['username'] ?? 'DemoUser';
$avatarChr = mb_strtoupper(mb_substr($username, 0, 1));

// Lettura saldo reale (se loggato), altrimenti 0
$headerCredits = 0.0;
if (!empty($_SESSION['user_id'])) {
  $hq = $pdo->prepare("SELECT crediti FROM utenti WHERE id = :id LIMIT 1");
  $hq->execute([':id' => (int)$_SESSION['user_id']]);
  if ($row = $hq->fetch(PDO::FETCH_ASSOC)) {
    $headerCredits = (float)$row['crediti'];
  }
}
?>
<link rel="stylesheet" href="/assets/header_user.css">
<link rel="stylesheet" href="/assets/mobile.css?v=1">
<script defer src="/assets/mobile_boot.js?v=1"></script>

<header class="hdr" role="banner">
  <div class="hdr__inner">

<!-- Logo a sinistra -->
<div class="hdr__left">
  <a class="logo" href="/lobby.php" aria-label="Vai alla lobby">
    <img class="logo__img" src="/assets/logo_arena.png" alt="Logo Arena" width="56" height="56">
    <span class="logo__text">ARENA</span>
  </a>
</div>

    <!-- Azioni a destra -->
    <div class="hdr__right">

      <!-- Pulsante ricarica -->
      <a class="btn btn--primary" href="/ricarica.php">Ricarica</a>

      <!-- Saldo crediti -->
      <div class="user-credits">
        <span class="user-credits__label">Crediti:</span>
        <span class="user-credits__value" id="headerCrediti"><?php echo number_format($headerCredits, 0, ',', '.'); ?></span>
        <button class="credits-refresh" type="button" aria-label="Aggiorna saldo">&#x21bb;</button>
      </div>

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

  <!-- Auto-refresh del saldo (ogni 10s + click su ↻) -->
  <script>
    (function(){
      const el = document.getElementById('headerCrediti');
      const btn = document.querySelector('.credits-refresh');
      if(!el) return;

      function refreshCredits(){
        fetch('/api/user_credits.php', { credentials: 'same-origin' })
          .then(r => r.ok ? r.json() : null)
          .then(js => {
            if (!js || !js.ok) return;
            el.textContent = (js.credits || 0).toLocaleString('it-IT', { maximumFractionDigits: 0 });
          })
          .catch(()=>{});
      }

      // primo refresh + polling
      refreshCredits();
      setInterval(refreshCredits, 10000);

      // refresh manuale col pulsante ↻
      if (btn) btn.addEventListener('click', refreshCredits);
    })();
  </script>
</header>

<!-- Sub-header -->
<nav class="subhdr" role="navigation" aria-label="Navigazione principale">
  <div class="subhdr__inner">
    <ul class="subhdr__menu">
      <li class="subhdr__item"><a class="subhdr__link" href="/lobby.php">Lobby</a></li>
      <li class="subhdr__item"><a class="subhdr__link" href="/storico_tornei.php">Storico Tornei</a></li>
      <li class="subhdr__item"><a class="subhdr__link" href="/lista_movimenti.php">Lista movimenti</a></li>
      <li class="subhdr__item"><a class="subhdr__link" href="/dati_utente.php">Dati Utente</a></li>
      <?php if (!empty($prizesEnabled)): ?>
        <li class="subhdr__item"><a class="subhdr__link" href="/premi.php">Premi</a></li>
      <?php endif; ?>
    </ul>
  </div>
</nav>
</nav>

<!-- Apertura wrapper centrale elastico -->
<div class="page-root">
<script src="/assets/movements_popup.js?v=1"></script>
