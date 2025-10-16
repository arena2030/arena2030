/*! Mobile Header – USER (guest intatto) */
(function () {
  'use strict';

  // Run solo su mobile e se esiste il nodo utente
  const isMobile = () =>
    (window.matchMedia ? window.matchMedia('(max-width: 768px)').matches : (window.innerWidth || 0) <= 768);
  const qs = (s, r) => (r || document).querySelector(s);
  const qsa = (s, r) => Array.prototype.slice.call((r || document).querySelectorAll(s));
  const txt = (el) => (el && (el.textContent || '').trim()) || '';

  const userNode = qs('.hdr__usr');
  if (!userNode || !isMobile()) return;

  // Elimina residui guest se presenti
  ['#mbl-guestBtn', '#mbl-guestDrawer', '#mbl-guestBackdrop'].forEach((sel) => {
    const n = qs(sel);
    if (n && n.parentNode) n.parentNode.removeChild(n);
  });

  const bar = qs('.hdr__bar');
  const right = qs('.hdr__right');
  if (!bar || !right) return;

  function svg(name) {
    if (name === 'menu')
      return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    if (name === 'close')
      return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    if (name === 'refresh')
      return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 12a8 8 0 1 1-2.34-5.66M20 4v6h-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    if (name === 'msg')
      return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H8l-4 4V5a2 2 0 0 1 2-2h13a2 2 0 0 1 2 2z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>';
    return '';
  }

  function findRicarica() {
    const all = qsa('.hdr__right a,.hdr__nav a');
    return all.find((a) => /ricar/i.test(((a.href || '') + txt(a)))) || null;
  }
  function findLogout() {
    const all = qsa('.hdr__right a, a');
    return all.find((a) => /logout|esci/i.test(((a.href || '') + txt(a)))) || null;
  }
  function findMsgLink() {
    const cand = qsa('.hdr__right a,.hdr__nav a');
    return cand.find((a) => /mess/i.test(((a.getAttribute('title') || '') + txt(a) + (a.href || '')))) || null;
  }

  /* ---------------- Header: Ricarica → Avatar/Username → Hamburger ---------------- */
  function mountHeaderGroup() {
    // Clean
    ['#mbl-userGroup', '#mbl-userBtn'].forEach((sel) => {
      const n = qs(sel);
      if (n && n.parentNode) n.parentNode.removeChild(n);
    });

    const group = document.createElement('div');
    group.id = 'mbl-userGroup';

    const ricarica = findRicarica();

    // Mantieni solo ricarica + utente, nascondi il resto
    const keep = new Set([userNode]);
    if (ricarica) keep.add(ricarica);
    qsa('.hdr__right > *').forEach((node) => {
      if (!keep.has(node)) node.style.display = 'none';
    });
    userNode.style.removeProperty('display');
    if (ricarica) ricarica.style.removeProperty('display');

    // Avatar fallback se mancante
    if (!userNode.querySelector('img,[class*="avatar"],.mbl-badge')) {
      const name = txt(userNode) || 'U';
      const ch = name.trim().charAt(0).toUpperCase() || 'U';
      const badge = document.createElement('span');
      badge.className = 'mbl-badge';
      badge.textContent = ch;
      userNode.prepend(badge);
    }

    // Ordine richiesto
    if (ricarica) group.appendChild(ricarica);
    group.appendChild(userNode);

    // Hamburger rotondo
    const btn = document.createElement('button');
    btn.id = 'mbl-userBtn';
    btn.type = 'button';
    btn.setAttribute('aria-label', 'Apri menu');
    btn.setAttribute('aria-controls', 'mbl-userDrawer');
    btn.setAttribute('aria-expanded', 'false');
    btn.innerHTML = svg('menu');

    // Monta alla fine della barra
    bar.appendChild(group);
    bar.appendChild(btn);

    // BIND robusto
    const safeOpen = (ev) => {
      try {
        ev && ev.preventDefault && ev.preventDefault();
        ev && ev.stopPropagation && ev.stopPropagation();
      } catch (_) {}
      toggleDrawer();
    };
    btn.addEventListener('click', safeOpen, { passive: false });
    btn.addEventListener('pointerup', safeOpen, { passive: false });
    btn.addEventListener('touchend', safeOpen, { passive: false });
    btn.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        safeOpen(e);
      }
    });
    document.addEventListener(
      'click',
      (e) => {
        if (e.target && e.target.id === 'mbl-userBtn') safeOpen(e);
      },
      true
    );
  }

  /* ---------------- Drawer ---------------- */
  let _built = false,
    _open = false,
    _lastFocus = null;

  function ensureDrawer() {
    if (_built) return;

    const dr = document.createElement('aside');
    dr.id = 'mbl-userDrawer';
    dr.setAttribute('role', 'dialog');
    dr.setAttribute('aria-modal', 'true');
    dr.setAttribute('aria-hidden', 'true');
    dr.tabIndex = -1;

    const head = document.createElement('div');
    head.className = 'mbl-head';
    const ttl = document.createElement('div');
    ttl.className = 'mbl-title';
    ttl.textContent = 'Menu';
    const cls = document.createElement('button');
    cls.type = 'button';
    cls.className = 'mbl-close';
    cls.setAttribute('aria-label', 'Chiudi menu');
    cls.innerHTML = svg('close');
    head.appendChild(ttl);
    head.appendChild(cls);
    dr.appendChild(head);

    const sc = document.createElement('div');
    sc.className = 'mbl-scroll';
    dr.appendChild(sc);

    const bd = document.createElement('div');
    bd.id = 'mbl-userBackdrop';
    bd.setAttribute('hidden', 'hidden');

    document.body.appendChild(dr);
    document.body.appendChild(bd);

    fillUserSections(sc); // ← costruisce le sezioni

    function getFocusable(root) {
      return qsa(
        'a[href],button:not([disabled]),input,select,textarea,[tabindex]:not([tabindex="-1"])',
        root
      ).filter((el) => !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length));
    }
    function trap(ev) {
      const it = getFocusable(dr);
      if (!it.length) return;
      const first = it[0],
        last = it[it.length - 1];
      if (ev.shiftKey) {
        if (document.activeElement === first || !dr.contains(document.activeElement)) {
          ev.preventDefault();
          last.focus();
        }
      } else {
        if (document.activeElement === last) {
          ev.preventDefault();
          first.focus();
        }
      }
    }

    function open() {
      if (_open) return;
      _lastFocus = document.activeElement || qs('#mbl-userBtn');
      document.documentElement.classList.add('mbl-lock');
      document.body.classList.add('mbl-lock');
      bd.removeAttribute('hidden');
      bd.classList.add('mbl-open');
      dr.classList.add('mbl-open');
      dr.setAttribute('aria-hidden', 'false');
      qs('#mbl-userBtn')?.setAttribute('aria-expanded', 'true');
      (getFocusable(dr)[0] || dr).focus({ preventScroll: true });
      _open = true;
    }
    function close() {
      if (!_open) return;
      dr.classList.remove('mbl-open');
      dr.setAttribute('aria-hidden', 'true');
      bd.classList.remove('mbl-open');
      bd.setAttribute('hidden', 'hidden');
      document.documentElement.classList.remove('mbl-lock');
      document.body.classList.remove('mbl-lock');
      qs('#mbl-userBtn')?.setAttribute('aria-expanded', 'false');
      (_lastFocus || qs('#mbl-userBtn'))?.focus({ preventScroll: true });
      _open = false;
    }
    function toggle() {
      _open ? close() : open();
    }

    window.__mblUserDrawer = { open, close, toggle };

    cls.addEventListener('click', close);
    bd.addEventListener('click', close);
    dr.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') close();
      if (e.key === 'Tab') trap(e);
    });
    document.addEventListener('click', (e) => {
      if (bd.hasAttribute('hidden')) return;
      const a = e.target.closest && e.target.closest('a');
      if (a && !dr.contains(a)) close();
    });

    _built = true;
  }

  function toggleDrawer() {
    ensureDrawer();
    try {
      window.__mblUserDrawer.toggle();
    } catch (_) {}
  }

  /* ---------------- Sezioni Drawer ---------------- */
  function gather(sel) {
    return qsa(sel).filter((a) => a && a.tagName === 'A' && (a.href || '').length && txt(a));
  }
  function makeList(links) {
    const ul = document.createElement('ul');
    ul.className = 'mbl-list';
    links.forEach((a) => {
      const li = document.createElement('li');
      const cp = a.cloneNode(true);
      cp.removeAttribute('id');
      li.appendChild(cp);
      ul.appendChild(li);
    });
    return ul;
  }
  function section(title, links, sc, extraClass) {
    if (!links || !links.length) return;
    const wrap = document.createElement('section');
    wrap.className = 'mbl-sec' + (extraClass ? ' ' + extraClass : '');
    const h = document.createElement('div');
    h.className = 'mbl-sec__title';
    h.textContent = title;
    wrap.appendChild(h);
    wrap.appendChild(makeList(links));
    sc.appendChild(wrap);
  }

  function readAC() {
    const pill = qs('.pill-balance .ac');
    if (pill && !isNaN(parseFloat(pill.textContent))) return parseFloat(pill.textContent);
    const me = qs('#meCoins');
    if (me && !isNaN(parseFloat(me.textContent))) return parseFloat(me.textContent);
    return null;
  }

  async function refreshCoins() {
    try {
      const r = await fetch('/premi.php?action=me', { credentials: 'same-origin', cache: 'no-store' });
      const j = await r.json().catch(() => null);
      let val = j && j.ok && j.me && j.me.coins != null ? parseFloat(j.me.coins) : readAC();
      if (val != null) {
        const out = document.getElementById('mbl-coins-val') || qs('#mbl-userDrawer #mbl-coins-val');
        if (out) out.textContent = val.toFixed(2);
        const pill = qs('.pill-balance .ac');
        if (pill) pill.textContent = val.toFixed(2);
        const me = qs('#meCoins');
        if (me) me.textContent = val.toFixed(2);
      }
    } catch (_) {
      const v = readAC();
      const out = document.getElementById('mbl-coins-val') || qs('#mbl-userDrawer #mbl-coins-val');
      if (v != null && out) out.textContent = v.toFixed(2);
    }
  }

  function fillUserSections(sc) {
    sc.innerHTML = '';

    // ACCOUNT
    const secA = document.createElement('section');
    secA.className = 'mbl-sec';
    const hA = document.createElement('div');
    hA.className = 'mbl-sec__title';
    hA.textContent = 'Account';
    secA.appendChild(hA);

    // ArenaCoins + refresh compatto (punta SEMPRE a nodi locali)
    const rowC = document.createElement('div');
    rowC.className = 'mbl-kv';
    rowC.innerHTML =
      '<div class="k">ArenaCoins:</div><div class="v" id="mbl-coins-val">—</div><div class="after"></div>';
    const ref = document.createElement('button');
    ref.type = 'button';
    ref.className = 'mbl-ref';
    ref.setAttribute('aria-label', 'Aggiorna ArenaCoins');
    ref.innerHTML = svg('refresh');
    rowC.querySelector('.after').appendChild(ref);
    secA.appendChild(rowC);

    const outLocal = rowC.querySelector('#mbl-coins-val'); // ← locale, non sul document
    const initAC = readAC();
    if (outLocal) outLocal.textContent = initAC != null ? initAC.toFixed(2) : '0.00';
    ref.addEventListener('click', refreshCoins);

    // Utente + icona messaggi a destra
    const rowU = document.createElement('div');
    rowU.className = 'mbl-kv';
    const kU = document.createElement('div');
    kU.className = 'k';
    kU.textContent = 'Utente:';
    const vU = document.createElement('div');
    vU.className = 'v';
    vU.textContent = txt(userNode);
    const aft = document.createElement('div');
    aft.className = 'after';
    const msg = findMsgLink();
    if (msg) {
      const m = msg.cloneNode(true);
      m.innerHTML = svg('msg');
      m.className = 'mbl-ref';
      m.setAttribute('aria-label', 'Messaggi');
      aft.appendChild(m);
    }
    rowU.appendChild(kU);
    rowU.appendChild(vU);
    rowU.appendChild(aft);
    secA.appendChild(rowU);

    // CTA: Ricarica + Logout
    const row = document.createElement('div');
    row.className = 'mbl-ctaRow';
    const ric = findRicarica();
    if (ric) {
      const p = ric.cloneNode(true);
      p.classList.add('mbl-cta');
      row.appendChild(p);
    }
    const lo = findLogout();
    if (lo) {
      const g = lo.cloneNode(true);
      g.classList.add('mbl-ghost');
      row.appendChild(g);
    }
    secA.appendChild(row);
    sc.appendChild(secA);

    // NAV + INFO
    const nav = gather('.subhdr .subhdr__menu a');
    section('Navigazione', nav, sc, 'mbl-sec--nav');
    const info = gather('.site-footer .footer-menu a');
    section('Info', info, sc, '');
  }

  /* Bootstrap */
  mountHeaderGroup();
  ensureDrawer();

  // Rientro/uscita breakpoint
  if (window.matchMedia) {
    const mql = window.matchMedia('(max-width: 768px)');
    const onChange = (e) => {
      if (!e.matches) {
        ['#mbl-userGroup', '#mbl-userBtn', '#mbl-userDrawer', '#mbl-userBackdrop'].forEach((sel) => {
          const n = qs(sel);
          if (n && n.parentNode) n.parentNode.removeChild(n);
        });
        qsa('.hdr__right > *').forEach((n) => n.style.removeProperty('display'));
      } else {
        mountHeaderGroup();
        ensureDrawer();
      }
    };
    if (mql.addEventListener) mql.addEventListener('change', onChange);
    else if (mql.addListener) mql.addListener(onChange);
  }
})();
