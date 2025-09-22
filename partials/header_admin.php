<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$username = $_SESSION['username'] ?? 'Admin';
?>
<header class="hdr">
  <div class="container hdr__bar">
    <!-- SX: logo + AREN -->
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

<!-- SUBEADER -->
<nav class="subhdr" aria-label="Navigazione secondaria admin">
  <div class="container">
    <ul class="subhdr__menu">
      <li><a class="subhdr__link" href="/admin/dashboard.php">Players</a></li>
      <li><a class="subhdr__link" href="/admin/crea-tornei.php">Crea tornei</a></li>
      <li><a class="subhdr__link" href="/admin/gestisci-tornei.php">Gestisci tornei</a></li>
      <li><a class="subhdr__link" href="/admin/amministrazione.php">Amministrazione</a></li>
      <li><a class="subhdr__link" href="/admin/punti.php">Punti</a></li>
      <li><a class="subhdr__link" href="/admin/premi.php">Premi</a></li>

      <!-- nuovo -->
      <li><a class="subhdr__link" href="#" id="btnAdminMsg">Messaggi</a></li>
    </ul>
  </div>
</nav>
<?php include __DIR__ . '/../partials/messages_admin_widget.php'; ?>
<script>
// Collega "Messaggi" al composer del widget anche se il DOM non è pronto
(function(){
  function bindAdminMsg(){
    var link = document.getElementById('btnAdminMsg');
    if (!link) return false;

    // Evita di bindare due volte
    if (link.__msgBound) return true;
    link.__msgBound = true;

    link.addEventListener('click', function(e){
      e.preventDefault();
      // 1) API globale esposta dal widget
      if (window.msgwOpenComposer && typeof window.msgwOpenComposer === 'function') {
        window.msgwOpenComposer();
        return;
      }
      // 2) Fallback: clicca il bottone interno del widget
      var btn = document.getElementById('msgwOpen');
      if (btn) { btn.click(); return; }

      // 3) Diagnostica se il widget non è stato renderizzato (ruolo, include, ecc.)
      console.warn('[messages] widget non presente in pagina.');
      alert('Il widget dei messaggi non è disponibile su questa pagina.');
    });
    return true;
  }

  // Prova subito
  if (bindAdminMsg()) return;

  // Riprova quando il DOM è pronto
  document.addEventListener('DOMContentLoaded', bindAdminMsg);

  // Riprova per qualche frame (header può montarsi tardi)
  var attempts = 0;
  var t = setInterval(function(){
    attempts++;
    if (bindAdminMsg() || attempts > 40) clearInterval(t); // ~2s di retry
  }, 50);
})();
</script>
