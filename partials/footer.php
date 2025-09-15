<!-- Footer unico per tutte le pagine (guest, user, admin).
     Include questo file a fine pagina, prima di </body>.
     Richiede che in header_user.php, subito dopo la subheader, sia aperto:
     <div class="page-root">  (vedi sotto patch 2) -->

<link rel="stylesheet" href="/assets/footer.css">

<!-- Chiudo il wrapper centrale aperto in header_user.php -->
</div><!-- /.page-root -->

<footer class="site-footer" role="contentinfo">
  <div class="site-footer__inner">

    <nav class="site-footer__nav" aria-label="Link utili e legali">
      <ul class="footer-menu">
<li><a href="/chi-siamo.php">Chi siamo</a></li>
<li><a href="/contatti.php">Contatti</a></li>
<li><a href="/regolamento.php">Regolamento</a></li>
<li><a href="/termini.php">Termini e condizioni</a></li>
<li><a href="/condizioni-generali.php">Condizioni generali</a></li>
<li><a href="/privacy.php">Privacy e sicurezza</a></li>
<li><a href="/cookie-policy.php">Cookie policy</a></li>
<li><a href="/faq.php">FAQ</a></li>
<li><a href="/assistenza.php">Assistenza</a></li>
<li><a href="mailto:assistenza.arena@gmail.com">Segnalazione abusi</a></li>
      </ul>
    </nav>

    <div class="site-footer__meta">
      <span>© <span id="footer-year"></span> ARENA. Tutti i diritti riservati.</span>
      <a class="back-to-top" href="#" aria-label="Torna all’inizio">↑</a>
    </div>

  </div>
</footer>

<script>
  // Anno corrente
  var fy = document.getElementById('footer-year');
  if (fy) fy.textContent = new Date().getFullYear();

     <script>
(function(){
  function fixFooter() {
    var footer = document.querySelector('.site-footer');
    if (!footer) return;

    // reset
    footer.style.marginTop = '';

    // altezza documento complessiva vs viewport
    var docH = Math.max(
      document.body.scrollHeight,
      document.documentElement.scrollHeight
    );
    var gap = window.innerHeight - docH;

    // se la pagina è più corta della viewport, spingi il footer giù
    if (gap > 0) {
      footer.style.marginTop = gap + 'px';
    }
  }

  // al primo paint + su resize/font-loading
  window.addEventListener('load', fixFooter);
  window.addEventListener('resize', fixFooter);
  // in caso di immagini/font che cambiano l'altezza dopo qualche ms
  setTimeout(fixFooter, 50);
})();
     
  // Torna su
  document.addEventListener('click', function(e){
    var a = e.target.closest('.back-to-top');
    if (!a) return;
    e.preventDefault();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });
</script>

<!-- [AGGIUNTA FAVICON GLOBALE] -->
<script>
(function () {
  try {
    // Favicon (se non già presente)
    if (!document.querySelector('link[rel="icon"]')) {
      var l = document.createElement('link');
      l.rel = 'icon';
      l.type = 'image/png';
      l.href = '/assets/logo_arena.png'; // favicon su sfondo nero
      document.head.appendChild(l);
    }
    // Theme color per mobile (se non già presente)
    if (!document.querySelector('meta[name="theme-color"]')) {
      var m = document.createElement('meta');
      m.name = 'theme-color';
      m.content = '#000000';
      document.head.appendChild(m);
    }
  } catch(e) {}
})();
</script>
