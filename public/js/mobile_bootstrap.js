/*!
 * Mobile Bootstrap v1
 * – Attiva l’header mobile SOLO quando serve (≤768px)
 * – Nasconde tutto quando si esce da mobile
 * – Funziona per USER e GUEST, senza ricaricare la pagina
 * – Idempotente (non si avvia due volte)
 */
(function () {
  if (window.__ARENA_MBL_BOOT__) return;
  window.__ARENA_MBL_BOOT__ = true;

  var MQ = (window.matchMedia ? window.matchMedia('(max-width: 768px)') : null);

  /** Heuristics: capisce se l’utente è loggato senza toccare il PHP */
  function isLogged() {
    // 1) preferisci un flag globale se presente (puoi impostarlo lato PHP se vuoi)
    if (typeof window.__IS_LOGGED === 'boolean') return window.__IS_LOGGED;

    // 2) euristica DOM: elementi tipici dell’header utente desktop
    var sel = [
      '.hdr__usr',              // es. container utente
      '[data-user="1"]',
      '.user-menu',
      '.header-user',
      '#userMenu'
    ].join(',');

    return !!document.querySelector(sel);
  }

  /** Carica uno script una sola volta */
  function loadScriptOnce(src) {
    return new Promise(function (resolve) {
      // già caricato?
      var exists = Array.from(document.scripts || []).some(function (s) {
        return (s.src || '').indexOf(src) !== -1;
      });
      if (exists) { resolve(); return; }

      var s = document.createElement('script');
      s.src = src;
      s.defer = true;
      s.onload = function(){ resolve(); };
      s.onerror = function(){ resolve(); /* fail-silent: non deve bloccare */ };
      document.head.appendChild(s);
    });
  }

  /** Mostra gli elementi (se esistono) */
  function showEl(el){ if (!el) return; el.hidden = false; el.removeAttribute('aria-hidden'); }
  /** Nasconde gli elementi (se esistono) */
  function hideEl(el){ if (!el) return; el.hidden = true;  el.setAttribute('aria-hidden','true'); }

  /** Entra in modalità mobile */
  async function enterMobile() {
    try {
      if (isLogged()) {
        // USER mobile
        await loadScriptOnce('/assets/js/mobile/header-user.mobile.js');
        // elementi attesi (se lo script li crea con questi id)
        showEl(document.getElementById('mblu-bar'));
        hideEl(document.getElementById('mblu-backdrop')); // chiuso di default
        hideEl(document.getElementById('mblu-drawer'));   // chiuso di default
      } else {
        // GUEST mobile
        await loadScriptOnce('/assets/js/mobile/header-guest.mobile.js');
        showEl(document.getElementById('mbl-guestBtn'));
        hideEl(document.getElementById('mbl-guestDrawer'));
        hideEl(document.getElementById('mbl-guestBackdrop'));
      }
      // stato globale per eventuali CSS di supporto
      document.body.classList.add('is-mobile');
      document.body.classList.remove('is-desktop');

      // evento per chi vuole reagire (card, griglie, ecc.)
      window.dispatchEvent(new CustomEvent('layout:change', { detail: { mode: 'mobile' }}));
    } catch (_) {}
  }

  /** Esce dalla modalità mobile */
  function exitMobile() {
    try {
      // USER
      hideEl(document.getElementById('mblu-bar'));
      hideEl(document.getElementById('mblu-backdrop'));
      hideEl(document.getElementById('mblu-drawer'));
      // GUEST
      hideEl(document.getElementById('mbl-guestBtn'));
      hideEl(document.getElementById('mbl-guestDrawer'));
      hideEl(document.getElementById('mbl-guestBackdrop'));
    } catch (_) {}
    document.body.classList.add('is-desktop');
    document.body.classList.remove('is-mobile');
    window.dispatchEvent(new CustomEvent('layout:change', { detail: { mode: 'desktop' }}));
  }

  /** Sincronizza lo stato con la viewport */
  function sync() {
    if (!MQ) return;
    if (MQ.matches) enterMobile();
    else exitMobile();
  }

  // bootstrap iniziale
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', sync);
  } else {
    sync();
  }

  // ascolta cambi viewport / orientamento / bfcache (Safari iOS)
  if (MQ && MQ.addEventListener) MQ.addEventListener('change', sync);
  else if (MQ && MQ.addListener) MQ.addListener(sync);
  window.addEventListener('orientationchange', sync, { passive: true });
  window.addEventListener('pageshow', function(){ sync(); }, { passive: true });
})();
