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

    <!-- OROLOGIO (inserito subito dopo il logo) -->
    <span id="admClock" class="hdr__clock" title="Orario di sistema"></span>

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

  <!-- Stile orologio -->
  <style>
    .hdr__clock{
      margin-left:12px;
      margin-right:auto; /* spinge il nav a destra mantenendo il clock subito dopo il logo */
      font-variant-numeric: tabular-nums;
      font-weight: 800;
      letter-spacing: .3px;
      color:#9fb7ff;
      white-space:nowrap;
    }
    @media (max-width:700px){
      .hdr__clock{ font-size:12px; }
    }
  </style>

  <!-- Script: orologio sincronizzato con server (fallback locale se non disponibile) -->
  <script>
    (function(){
      var el = document.getElementById('admClock');
      if (!el) return;

      var skewSec = 0;        // differenza (server - client) in secondi
      var lastSync = 0;       // ms
      var SYNC_EVERY_MS = 5 * 60 * 1000; // ogni 5 minuti

      function pad2(n){ n = Math.floor(n); return (n<10?'0':'')+n; }
      function render(ts){
        var d = new Date(ts*1000);
        var Y=d.getFullYear(), M=pad2(d.getMonth()+1), D=pad2(d.getDate());
        var h=pad2(d.getHours()), m=pad2(d.getMinutes()), s=pad2(d.getSeconds());
        el.textContent = D+'/'+M+'/'+Y+' '+h+':'+m+':'+s;
      }

      async function syncNow(){
        try{
          var r = await fetch('/api/time_now.php', {cache:'no-store', credentials:'same-origin'});
          var j = await r.json();
          var refUnix = null;
          if (j && j.ok && j.db && typeof j.db.unix === 'number' && j.db.unix>0) {
            refUnix = j.db.unix; // preferisci il tempo del DB (coerente con NOW())
          } else if (j && j.ok && j.php && typeof j.php.unix === 'number') {
            refUnix = j.php.unix; // fallback: tempo del server PHP
          }
          if (refUnix){
            var clientUnix = Math.floor(Date.now()/1000);
            skewSec = refUnix - clientUnix;
            lastSync = Date.now();
            render(clientUnix + skewSec);
            return true;
          }
        }catch(e){}
        return false;
      }

      function tick(){
        var nowLocal = Math.floor(Date.now()/1000);
        render(nowLocal + skewSec);
        if (Date.now() - lastSync > SYNC_EVERY_MS) { syncNow(); }
        // allineamento al secondo
        requestAnimationFrame(function(){ setTimeout(tick, 1000 - (Date.now()%1000)); });
      }

      // prima sincronizza, poi tick; se fallisce, usa subito il locale
      syncNow().finally(tick);
    })();
  </script>

  <script>
    // collega il link della subheader alla modale del widget
    (function(){
      function bind(){ 
        var l=document.getElementById('btnAdminMsg'); 
        if(!l) return false; 
        if(l.__b){return true;} l.__b=true;
        l.addEventListener('click', function(e){ e.preventDefault(); if(window.msgwOpenComposer) window.msgwOpenComposer(); });
        return true;
      }
      if (!bind()) {
        document.addEventListener('DOMContentLoaded', bind);
        var t=0, h=setInterval(()=>{ if(bind()|| ++t>40) clearInterval(h); },50);
      }
    })();
  </script>
</header>
