/*! Arena Mobile Layer — single-file bundle (no deps)
   - Nasconde sub-header e footer su mobile
   - Aggiunge hamburger a sinistra e drawer accessibile
   - Clona i link dal DOM (subhdr, header right/nav, footer)
   - Desktop invariato
*/
(function () {
  'use strict';

  // ————————————————————————————————————————————
  // Utilità compatibili
  // ————————————————————————————————————————————
  var ric = window.requestIdleCallback || function (cb) { return setTimeout(cb, 0); };
  var cac = window.cancelIdleCallback || function (id) { clearTimeout(id); };

  function isMobile() {
    if (window.matchMedia) return window.matchMedia('(max-width: 768px)').matches;
    return (window.innerWidth || 0) <= 768;
  }

  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  function safeText(el) { return (el && (el.textContent || '').trim()) || ''; }

  // ————————————————————————————————————————————
  // Iniezione CSS (solo una volta)
  // ————————————————————————————————————————————
  function injectStyle() {
    if (document.getElementById('mbl-style')) return;
    var css = `
/* ===== Arena Mobile Layer (scoped) ===== */
#mbl-trigger{ display:none; -webkit-tap-highlight-color:transparent; }
#mbl-backdrop{ position:fixed; inset:0; background:rgba(0,0,0,.45);
  backdrop-filter:saturate(120%) blur(1px); opacity:0; pointer-events:none; transition:opacity .2s ease; z-index:70; }
#mbl-backdrop.mbl-open{ opacity:1; pointer-events:auto; }
#mbl-drawer{ position:fixed; top:0; left:0; height:100dvh; width:300px; max-width:86vw;
  transform:translateX(-105%); transition:transform .24s ease;
  background:var(--c-bg,#0b1220); color:var(--c-text,#fff);
  border-right:1px solid var(--c-border,rgba(255,255,255,.08)); z-index:71;
  display:flex; flex-direction:column; }
#mbl-drawer.mbl-open{ transform:translateX(0); }
#mbl-drawer .mbl-head{ display:flex; align-items:center; gap:12px; padding:14px; border-bottom:1px solid var(--c-border,rgba(255,255,255,.08)); }
#mbl-drawer .mbl-user{ display:flex; flex-direction:column; gap:2px; min-width:0; }
#mbl-drawer .mbl-user__name{ font-weight:800; font-size:15px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
#mbl-drawer .mbl-user__balance{ font-size:12px; opacity:.85; }
#mbl-drawer .mbl-close{ margin-left:auto; width:36px; height:36px; border-radius:10px;
  border:1px solid var(--c-border,rgba(255,255,255,.12)); background:var(--c-bg-2,#0f172a);
  color:var(--c-text,#fff); display:inline-flex; align-items:center; justify-content:center; }
#mbl-drawer .mbl-sec{ padding:10px 10px; }
#mbl-drawer .mbl-sec__title{ font-size:12px; font-weight:900; color:var(--c-muted,#9fb7ff); letter-spacing:.3px;
  padding:6px 6px 8px; text-transform:uppercase; }
#mbl-drawer .mbl-list{ list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:4px; }
#mbl-drawer .mbl-list a{ display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius:10px;
  text-decoration:none; color:var(--c-text,#e5e7eb); background:var(--c-bg-2,#0f172a); border:1px solid var(--c-border,#1f2937); }
#mbl-drawer .mbl-list a:hover{ filter:brightness(1.05); }
html.mbl-lock, body.mbl-lock{ overflow:hidden !important; height:100%; }

/* Solo su mobile */
@media (max-width:768px){
  /* Nascondi sub-header e footer; sistema il main */
  .subhdr, .site-footer { display:none !important; }
  main { padding-bottom:24px; }

  /* Mostra hamburger nella .hdr__bar, a sinistra del logo */
  .hdr__bar > #mbl-trigger{
    display:inline-flex; align-items:center; justify-content:center;
    width:40px; height:40px; margin-right:8px; border-radius:10px;
    border:1px solid var(--c-border,#1e293b); background:var(--c-bg-2,#0f172a); color:var(--c-text,#fff);
  }
  #mbl-trigger svg{ width:20px; height:20px; }
}

/* Su desktop, gli elementi mobile non si vedono */
@media (min-width:769px){
  #mbl-trigger, #mbl-backdrop, #mbl-drawer{ display:none !important; }
}
`;
    var st = document.createElement('style');
    st.id = 'mbl-style';
    st.type = 'text/css';
    st.appendChild(document.createTextNode(css));
    document.head.appendChild(st);
  }

  // ————————————————————————————————————————————
  // Costruzione drawer + hamburger (idempotente)
  // ————————————————————————————————————————————
  var state = { built: false, open: false, lastActive: null, idleId: null };

  function buildOnce() {
    if (state.built) return;
    var hdrBar = qs('.hdr__bar');
    if (!hdrBar) return; // niente header → no-op

    // 1) Trigger hamburger (prima del logo)
    if (!qs('#mbl-trigger', hdrBar)) {
      var btn = document.createElement('button');
      btn.id = 'mbl-trigger';
      btn.type = 'button';
      btn.setAttribute('aria-label', 'Apri menu');
      btn.setAttribute('aria-controls', 'mbl-drawer');
      btn.setAttribute('aria-expanded', 'false');
      btn.innerHTML = (
        '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
          '<path d="M3 6h18M3 12h18M3 18h18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>' +
        '</svg>'
      );
      hdrBar.insertBefore(btn, hdrBar.firstElementChild || null);
    }

    // 2) Drawer + backdrop
    if (!qs('#mbl-drawer')) {
      var dr = document.createElement('aside');
      dr.id = 'mbl-drawer';
      dr.setAttribute('role', 'dialog');
      dr.setAttribute('aria-modal', 'true');
      dr.setAttribute('aria-hidden', 'true');
      dr.setAttribute('aria-labelledby', 'mbl-drawer-title');
      dr.tabIndex = -1;

      // Header utente + close
      var head = document.createElement('div');
      head.className = 'mbl-head';

      var userBox = document.createElement('div');
      userBox.className = 'mbl-user';

      var usr = qs('.hdr__usr');
      var bal = qs('.pill-balance .ac');

      if (usr) {
        var nm = document.createElement('div');
        nm.className = 'mbl-user__name';
        nm.id = 'mbl-drawer-title';
        nm.textContent = safeText(usr);
        userBox.appendChild(nm);
      }
      if (bal) {
        var bc = document.createElement('div');
        bc.className = 'mbl-user__balance';
        bc.textContent = safeText(bal);
        userBox.appendChild(bc);
      }

      head.appendChild(userBox);

      var btnClose = document.createElement('button');
      btnClose.type = 'button';
      btnClose.className = 'mbl-close';
      btnClose.setAttribute('aria-label', 'Chiudi menu');
      btnClose.innerHTML = (
        '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
          '<path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>' +
        '</svg>'
      );
      head.appendChild(btnClose);
      dr.appendChild(head);

      // Sezioni (inserite via idle)
      var navWrap = document.createElement('div'); // contenitore sezioni
      dr.appendChild(navWrap);

      document.body.appendChild(dr);

      var bd = document.createElement('div');
      bd.id = 'mbl-backdrop';
      bd.setAttribute('hidden', 'hidden');
      document.body.appendChild(bd);

      // Costruzione menu in idle (non blocca)
      state.idleId = ric(function () {
        try { fillSections(navWrap); } catch (e) { /* no-op */ }
      });

      // Bind chiusure
      btnClose.addEventListener('click', closeDrawer);
      bd.addEventListener('click', closeDrawer);

      // Focus/ESC trap
      dr.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape') { ev.preventDefault(); closeDrawer(); return; }
        if (ev.key === 'Tab') { trapFocus(ev, dr); }
      });
    }

    // 3) Bind trigger
    var trigger = qs('#mbl-trigger');
    if (trigger && !trigger._mblBound) {
      trigger.addEventListener('click', toggleDrawer);
      trigger.addEventListener('keydown', function (ev) {
        // Accessibilità: spazio/enter aprono
        if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); toggleDrawer(); }
      });
      trigger._mblBound = true;
    }

    state.built = true;
  }

  // Clona in sicurezza una lista di <a> in <ul>
  function makeList(links) {
    var ul = document.createElement('ul');
    ul.className = 'mbl-list';
    links.forEach(function (a) {
      try {
        if (!a || !a.href) return;
        var li = document.createElement('li');
        // clone profondo per mantenere eventuali icone inline; puliamo id/class e inline handlers
        var copy = a.cloneNode(true);
        copy.removeAttribute('id');
        copy.removeAttribute('onclick');
        copy.removeAttribute('onmousedown');
        copy.removeAttribute('onmouseup');
        copy.removeAttribute('onmouseover');
        copy.removeAttribute('onmouseout');
        // chiudi drawer al click
        copy.addEventListener('click', function () { closeDrawer(); });
        li.appendChild(copy);
        ul.appendChild(li);
      } catch (_) {}
    });
    return ul;
  }

  // Crea una sezione con titolo + ul (se ci sono link)
  function section(title, links) {
    if (!links || !links.length) return null;
    var wrap = document.createElement('section');
    wrap.className = 'mbl-sec';
    var h = document.createElement('div');
    h.className = 'mbl-sec__title';
    h.textContent = title;
    wrap.appendChild(h);
    wrap.appendChild(makeList(links));
    return wrap;
  }

  // Raccolta link dal DOM con robustezza (no errori se mancano)
  function gather(selector) {
    return qsa(selector).filter(function (a) {
      return a && a.tagName === 'A' && (a.href || '').length > 0 && safeText(a) !== '';
    });
  }

  function fillSections(container) {
    if (!container) return;
    container.innerHTML = '';

    // Navigazione (sub-header)
    var navLinks = gather('.subhdr .subhdr__menu a');

    // Account: se utente loggato → .hdr__right a; altrimenti guest → .hdr__nav a
    var isUser = !!qs('.hdr__usr') || !!qs('.hdr__right');
    var accLinks = isUser ? gather('.hdr__right a') : gather('.hdr__nav a');

    // Informazioni dal footer
    var infoLinks = gather('.site-footer .footer-menu a');

    var s1 = section('Navigazione', navLinks);
    var s2 = section('Account', accLinks);
    var s3 = section('Informazioni', infoLinks);

    if (s1) container.appendChild(s1);
    if (s2) container.appendChild(s2);
    if (s3) container.appendChild(s3);
  }

  // ————————————————————————————————————————————
  // Apertura/chiusura + focus trap + scroll lock
  // ————————————————————————————————————————————
  function toggleDrawer() {
    if (state.open) closeDrawer(); else openDrawer();
  }

  function openDrawer() {
    var dr = qs('#mbl-drawer'), bd = qs('#mbl-backdrop'), trg = qs('#mbl-trigger');
    if (!dr || !bd) return;
    state.lastActive = document.activeElement || trg;
    document.documentElement.classList.add('mbl-lock');
    document.body.classList.add('mbl-lock');
    bd.removeAttribute('hidden');
    bd.classList.add('mbl-open');
    dr.classList.add('mbl-open');
    dr.setAttribute('aria-hidden', 'false');
    if (trg) trg.setAttribute('aria-expanded', 'true');
    // focus iniziale
    var focusable = getFocusable(dr);
    (focusable[0] || dr).focus({ preventScroll: true });
    state.open = true;
  }

  function closeDrawer() {
    var dr = qs('#mbl-drawer'), bd = qs('#mbl-backdrop'), trg = qs('#mbl-trigger');
    if (!dr || !bd) return;
    dr.classList.remove('mbl-open');
    dr.setAttribute('aria-hidden', 'true');
    bd.classList.remove('mbl-open');
    bd.setAttribute('hidden', 'hidden');
    document.documentElement.classList.remove('mbl-lock');
    document.body.classList.remove('mbl-lock');
    if (trg) trg.setAttribute('aria-expanded', 'false');
    // ritorno focus al trigger
    var backTo = state.lastActive || trg;
    if (backTo && backTo.focus) backTo.focus({ preventScroll: true });
    state.open = false;
  }

  function getFocusable(root) {
    var sel = [
      'a[href]', 'button:not([disabled])', 'input:not([disabled])',
      'select:not([disabled])', 'textarea:not([disabled])',
      '[tabindex]:not([tabindex="-1"])'
    ].join(',');
    return qsa(sel, root).filter(function (el) {
      return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
    });
  }

  function trapFocus(ev, root) {
    var items = getFocusable(root);
    if (!items.length) return;
    var first = items[0], last = items[items.length - 1];
    if (ev.shiftKey) {
      if (document.activeElement === first || !root.contains(document.activeElement)) {
        ev.preventDefault(); last.focus(); }
    } else {
      if (document.activeElement === last) {
        ev.preventDefault(); first.focus(); }
    }
  }

  // ————————————————————————————————————————————
  // Inizializzazione non bloccante
  // ————————————————————————————————————————————
  function init() {
    injectStyle();
    // Build solo se mobile; se si passa a mobile in seguito, re-check via media query
    if (isMobile()) buildOnce();

    // Ascolta cambi breakpoint
    if (window.matchMedia) {
      var mql = window.matchMedia('(max-width: 768px)');
      var onChange = function (e) {
        if (e.matches) buildOnce();
        else closeDrawer();
      };
      if (mql.addEventListener) mql.addEventListener('change', onChange);
      else if (mql.addListener) mql.addListener(onChange);
    }

    // Close su navigazione (hashchange/spa-like)
    window.addEventListener('hashchange', closeDrawer);
    document.addEventListener('click', function (ev) {
      // se click su un link al di fuori del drawer quando aperto, chiudi
      if (!state.open) return;
      var dr = qs('#mbl-drawer');
      if (!dr) return;
      var a = ev.target.closest && ev.target.closest('a');
      if (a && !dr.contains(a)) closeDrawer();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    // Già pronto
    init();
  }
})();
