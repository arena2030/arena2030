/*! Arena Mobile Layer (Right Drawer, full-scroll) — single-file bundle, no deps
   - Drawer a destra, tutto il contenuto scorre (header sticky)
   - Header mobile pulito: logo sx, a dx solo avatar+username e hamburger
   - Menu costruito dal DOM (subhdr/nav, azioni account, footer)
   - ArenaCoins mostrati e aggiornati (con bottone refresh vicino)
   - Icona Messaggi visibile nella lista (se esiste la voce)
   - Avatar fallback (cerchio con iniziale) sia in header sia nel blocco Account
   - Accessibilità: role="dialog", aria-modal, focus trap, ESC; scroll-lock
*/
(function () {
  'use strict';

  // -----------------------------------------------------
  // Utils
  // -----------------------------------------------------
  var ric = window.requestIdleCallback || function (cb) { return setTimeout(cb, 0); };
  function isMobile() { return (window.matchMedia ? window.matchMedia('(max-width: 768px)').matches : (window.innerWidth||0) <= 768); }
  function qs(s, r){ return (r||document).querySelector(s); }
  function qsa(s, r){ return Array.prototype.slice.call((r||document).querySelectorAll(s)); }
  function txt(el){ return (el && (el.textContent||'').trim()) || ''; }

  // -----------------------------------------------------
  // SVG icone inline
  // -----------------------------------------------------
  function svg(name){
    if (name==='close') return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    if (name==='menu')  return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    if (name==='refresh') return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4v6h6M20 20v-6h-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/><path d="M20 14a8 8 0 0 1-14.9 3M4 10a8 8 0 0 1 14.9-3" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/></svg>';
    if (name==='msg') return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H8l-4 3V7a2 2 0 0 1 2-2z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M22 7l-10 7L2 7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    return '';
  }

  // -----------------------------------------------------
  // CSS inline (solo mobile). Desktop invariato.
  // -----------------------------------------------------
  function injectStyle(){
    if (qs('#mbl-style')) return;
    var css = `
/* ===== Arena Mobile Layer (drawer destro, full-scroll) ===== */
#mbl-trigger{ display:none; -webkit-tap-highlight-color:transparent; }
#mbl-backdrop{
  position:fixed; inset:0; background:rgba(0,0,0,.45);
  backdrop-filter:saturate(120%) blur(1px);
  opacity:0; pointer-events:none; transition:opacity .2s ease; z-index:70;
}
#mbl-backdrop.mbl-open{ opacity:1; pointer-events:auto; }

/* Drawer a destra, tutto scorre (header sticky) */
#mbl-drawer{
  position:fixed; top:0; right:0; left:auto;
  height:100dvh; width:320px; max-width:92vw;
  transform:translateX(105%); transition:transform .24s ease;
  background:var(--c-bg,#0b1220); color:var(--c-text,#fff);
  border-left:1px solid var(--c-border,rgba(255,255,255,.08));
  z-index:71; display:flex; flex-direction:column;
  overflow:auto; -webkit-overflow-scrolling:touch;
}
#mbl-drawer.mbl-open{ transform:translateX(0); }

/* Testata drawer sticky */
#mbl-drawer .mbl-head{
  position:sticky; top:0; z-index:1;
  display:flex; align-items:center; gap:12px;
  padding:14px; border-bottom:1px solid var(--c-border,rgba(255,255,255,.08));
  background:var(--c-bg,#0b1220);
}
#mbl-drawer .mbl-brand{ display:inline-flex; align-items:center; gap:8px; font-weight:900; }
#mbl-drawer .mbl-title{ font-weight:900; font-size:16px; }
#mbl-drawer .mbl-close{
  margin-left:auto; width:36px; height:36px; border-radius:10px;
  border:1px solid var(--c-border,rgba(255,255,255,.12));
  background:var(--c-bg-2,#0f172a); color:var(--c-text,#fff);
  display:inline-flex; align-items:center; justify-content:center;
}

/* Corpo sezioni */
#mbl-drawer .mbl-body{ padding:8px 0 12px; }
#mbl-drawer .mbl-sec{ padding:12px 12px 6px; }
#mbl-drawer .mbl-sec__title{
  font-size:12px; font-weight:900; color:var(--c-muted,#9fb7ff);
  letter-spacing:.3px; padding:2px 6px 8px; text-transform:uppercase;
}
#mbl-drawer .mbl-list{ list-style:none; margin:0; padding:0; }
#mbl-drawer .mbl-list li{ margin:0; }
#mbl-drawer .mbl-list a{
  display:flex; align-items:center; gap:10px;
  padding:12px 8px; text-decoration:none; color:var(--c-text,#e5e7eb);
  border-radius:8px;
}
#mbl-drawer .mbl-list a:hover{ filter:brightness(1.05); }
#mbl-drawer .mbl-sec--nav .mbl-list a{ font-weight:800; } /* navigazione più marcata */

.mbl-ico{ width:18px; height:18px; display:inline-flex; align-items:center; justify-content:center; opacity:.95; }
.mbl-ico svg{ width:18px; height:18px; display:block; }
.mbl-t{ flex:1 1 auto; }

/* CTA row (guest: Registrati blu, Login ghost / user: Ricarica, Logout) */
#mbl-drawer .mbl-ctaRow{ display:flex; gap:8px; padding:8px 6px 0; flex-wrap:wrap; }
#mbl-drawer .mbl-cta, #mbl-drawer .mbl-ghost{
  display:inline-flex; align-items:center; justify-content:center;
  height:36px; padding:0 14px; border-radius:9999px; font-weight:800; text-decoration:none; white-space:nowrap;
}
#mbl-drawer .mbl-cta{
  background: var(--c-primary, #3b82f6); color:#fff;
  border:1px solid var(--c-border,#1f2937);
}
#mbl-drawer .mbl-ghost{
  background: transparent; color: var(--c-text,#e5e7eb);
  border:1px solid var(--c-border,#1f2937);
}

/* Dati account (ArenaCoins / Utente) */
#mbl-drawer .mbl-account{ padding:4px 6px 8px; }
#mbl-drawer .mbl-kv{ font-size:14px; display:flex; gap:6px; padding:6px 2px; align-items:center; }
#mbl-drawer .mbl-kv .k{ min-width:110px; color:var(--c-muted,#9fb7ff); font-weight:900; }
#mbl-drawer .mbl-kv .v{ font-weight:800; color:var(--c-text,#e5e7eb); display:inline-flex; align-items:center; gap:8px; }
#mbl-drawer .mbl-refresh{
  width:28px; height:28px; border-radius:50%;
  display:inline-flex; align-items:center; justify-content:center;
  border:1px solid var(--c-border,#1f2937); background:transparent; color:var(--c-text,#e5e7eb);
}
#mbl-drawer .mbl-refresh svg{ width:16px; height:16px; }
@keyframes mblspin { to{ transform:rotate(360deg); } }
#mbl-drawer .mbl-refresh.spin svg{ animation:mblspin .9s linear infinite; }

/* avatar inline nel valore Utente */
#mbl-drawer .mbl-kv .v .mbl-av{ width:22px; height:22px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; background:var(--c-bg-2,#0f172a); color:#fff; font-size:11px; font-weight:900; }
#mbl-drawer .mbl-kv .v img{ width:22px; height:22px; border-radius:50%; display:inline-block; }

/* ————— Header mobile pulito ————— */
@media (max-width:768px){
  .subhdr, .site-footer { display:none !important; } /* nasconde sub-header e footer */

  /* pulizia header */
  .hdr__nav { display:none !important; } /* guest actions vanno nel menu */

  .hdr__bar{ display:flex; align-items:center; }
  .hdr__right{ margin-left:auto !important; display:flex !important; align-items:center; gap:8px; }

  /* mostra solo utente nel blocco destro, il resto nel menu */
  .hdr__right > *:not(.hdr__usr){ display:none !important; }

  /* avatar + username a destra */
  .hdr__bar .hdr__usr{
    display:inline-flex !important; align-items:center; gap:6px;
    margin-left:auto; order:998; font-weight:800;
  }
  .hdr__bar .hdr__usr .mbl-av{
    width:28px; height:28px; border-radius:50%;
    display:inline-flex; align-items:center; justify-content:center;
    background:var(--c-bg-2,#0f172a); color:#fff; font-size:13px; font-weight:900;
  }
  .hdr__bar .hdr__usr img, .hdr__bar .hdr__usr .avatar{
    display:block !important; width:28px; height:28px; border-radius:9999px; object-fit:cover;
  }

  /* hamburger subito dopo l'utente, a destra */
  .hdr__bar > #mbl-trigger{
    display:inline-flex; align-items:center; justify-content:center;
    width:40px; height:40px; margin-left:8px; order:999;
    border-radius:10px; border:1px solid var(--c-border,#1e293b);
    background:var(--c-bg-2,#0f172a); color:var(--c-text,#fff);
  }
  #mbl-trigger svg{ width:20px; height:20px; }

  main { padding-bottom:24px; }
}

/* ————— Desktop: layer invisibile ————— */
@media (min-width:769px){
  #mbl-trigger, #mbl-backdrop, #mbl-drawer{ display:none !important; }
}
`;
    var st = document.createElement('style'); st.id = 'mbl-style'; st.type='text/css'; st.appendChild(document.createTextNode(css));
    document.head.appendChild(st);
  }

  // -----------------------------------------------------
  // Username pulito (senza avatar/icone) + avatar node
  // -----------------------------------------------------
  function readUsername(){
    var u = qs('.hdr__usr'); if (!u) return '';
    var el = u.querySelector('[data-username]') || u.querySelector('.username') || u.querySelector('a') || u;
    var clone = el.cloneNode(true);
    qsa('.mbl-av, img, .avatar, svg', clone).forEach(function(n){ try{ n.remove(); }catch(_){ } });
    return (clone.textContent || '').trim();
  }
  function createAvatarNode(small){
    // prova a clonare un'immagine avatar già esistente
    var img = qs('.hdr__usr img, .hdr__usr .avatar');
    if (img){
      var c = img.cloneNode(true);
      if (small){ c.style.width='22px'; c.style.height='22px'; }
      else { c.style.width='28px'; c.style.height='28px'; }
      return c;
    }
    // fallback: cerchio con iniziale
    var n = document.createElement('span');
    n.className='mbl-av';
    n.textContent = (readUsername()||'?').charAt(0).toUpperCase();
    return n;
  }

  // -----------------------------------------------------
  // Header user: aggiunge avatar fallback se manca
  // -----------------------------------------------------
  function patchHeaderUser(){
    var usrWrap = qs('.hdr__bar .hdr__usr'); if (!usrWrap || usrWrap._mblDone) return;
    var target = usrWrap.firstElementChild && usrWrap.firstElementChild.tagName==='A' ? usrWrap.firstElementChild : usrWrap;

    if (!target.querySelector('img, .avatar, .mbl-av')){
      target.insertBefore(createAvatarNode(false), target.firstChild);
    }
    usrWrap._mblDone = true;
  }

  // -----------------------------------------------------
  // Build UI (idempotente)
  // -----------------------------------------------------
  var state = { built:false, open:false, lastActive:null, idleId:null, balanceObserver:null };

  function buildOnce(){
    if (state.built) return;
    var bar = qs('.hdr__bar'); if (!bar) return;

    patchHeaderUser();

    // Trigger hamburger (a destra → append)
    if (!qs('#mbl-trigger', bar)){
      var t = document.createElement('button');
      t.id='mbl-trigger'; t.type='button';
      t.setAttribute('aria-label','Apri menu'); t.setAttribute('aria-controls','mbl-drawer'); t.setAttribute('aria-expanded','false');
      t.innerHTML = svg('menu');
      bar.appendChild(t); // destra
    }

    // Drawer + backdrop
    if (!qs('#mbl-drawer')){
      var dr = document.createElement('aside');
      dr.id='mbl-drawer'; dr.setAttribute('role','dialog'); dr.setAttribute('aria-modal','true'); dr.setAttribute('aria-hidden','true'); dr.tabIndex=-1;

      // Head drawer
      var head = document.createElement('div'); head.className='mbl-head';
      var brand = document.createElement('div'); brand.className='mbl-brand';
      var logo = qs('.hdr__logo') || qs('.hdr .logo');
      if (logo){ brand.appendChild(logo.cloneNode(true)); }
      var ttl = document.createElement('div'); ttl.className='mbl-title'; ttl.textContent='Menu';
      brand.appendChild(ttl);
      head.appendChild(brand);

      var btnX = document.createElement('button'); btnX.type='button'; btnX.className='mbl-close'; btnX.setAttribute('aria-label','Chiudi menu');
      btnX.innerHTML = svg('close');
      head.appendChild(btnX);
      dr.appendChild(head);

      // Corpo (tutto dentro, scorre insieme)
      var body = document.createElement('div'); body.className='mbl-body';
      dr.appendChild(body);

      document.body.appendChild(dr);

      var bd = document.createElement('div'); bd.id='mbl-backdrop'; bd.setAttribute('hidden','hidden');
      document.body.appendChild(bd);

      // Costruzione contenuti in idle
      state.idleId = ric(function(){ try{ fillSections(body); }catch(_){/* no-op */} });

      // Bind chiusure/base
      btnX.addEventListener('click', closeDrawer);
      bd.addEventListener('click', closeDrawer);
      dr.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape') { ev.preventDefault(); closeDrawer(); return; }
        if (ev.key === 'Tab') { trapFocus(ev, dr); }
      });
    }

    var trigger = qs('#mbl-trigger');
    if (trigger && !trigger._mblBound){
      trigger.addEventListener('click', toggleDrawer);
      trigger.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); toggleDrawer(); }
      });
      trigger._mblBound = true;
    }

    state.built = true;
  }

  // -----------------------------------------------------
  // Filtri link "rumore" (es. ↻ senza testo)
  // -----------------------------------------------------
  function isNoiseLink(a){
    var t = (txt(a) || '').trim();
    var href = (a.getAttribute('href')||'').toLowerCase();
    if (!t) return true;
    // icone sole / simboli brevi (evita FAQ: 3 lettere e alfanumerico)
    if (t.length <= 2 && !/[a-z0-9]/i.test(t)) return true;
    // link di refresh/aggiorna
    if (/refresh|aggiorn|reload/.test(t.toLowerCase())) return true;
    if (/refresh|aggiorn|reload/.test(href)) return true;
    return false;
  }

  // -----------------------------------------------------
  // Sezioni/menu
  // -----------------------------------------------------
  function gather(sel){
    return qsa(sel).filter(function(a){
      return a && a.tagName==='A' && (a.href||'').length>0 && !isNoiseLink(a);
    });
  }

  function makeList(links){
    var ul = document.createElement('ul'); ul.className='mbl-list';
    links.forEach(function(a){
      try{
        var labelLower = txt(a).toLowerCase();
        // clona e ripulisce
        var li = document.createElement('li');
        var cp = a.cloneNode(true);
        cp.removeAttribute('id'); ['onclick','onmousedown','onmouseup','onmouseover','onmouseout'].forEach(function(k){ cp.removeAttribute(k); });

        // Decorazione: icona Messaggi
        if (/messagg/.test(labelLower)){
          var text = txt(cp);
          cp.innerHTML = '';
          var ico = document.createElement('span'); ico.className='mbl-ico'; ico.innerHTML = svg('msg');
          var t = document.createElement('span'); t.className='mbl-t'; t.textContent = text;
          cp.appendChild(ico); cp.appendChild(t);
        }

        cp.addEventListener('click', function(){ closeDrawer(); });
        li.appendChild(cp); ul.appendChild(li);
      }catch(_){}
    });
    return ul;
  }

  function section(title, links, extraClass){
    if (!links || !links.length) return null;
    var wrap = document.createElement('section'); wrap.className='mbl-sec'+(extraClass?(' '+extraClass):'');
    var h = document.createElement('div'); h.className='mbl-sec__title'; h.textContent=title; wrap.appendChild(h);
    wrap.appendChild(makeList(links));
    return wrap;
  }

  function addKV(container, k, vTextOrNode){
    var row = document.createElement('div'); row.className='mbl-kv';
    var kk = document.createElement('div'); kk.className='k'; kk.textContent=k;
    var vv = document.createElement('div'); vv.className='v';
    if (typeof vTextOrNode === 'string'){ vv.textContent = vTextOrNode; }
    else if (vTextOrNode) { vv.appendChild(vTextOrNode); }
    row.appendChild(kk); row.appendChild(vv); container.appendChild(row);
  }
  function addBalanceKV(container, valueText){
    var row = document.createElement('div'); row.className='mbl-kv';
    var kk = document.createElement('div'); kk.className='k'; kk.textContent = 'ArenaCoins:';
    var vv = document.createElement('div'); vv.className='v';
    var span = document.createElement('span'); span.id='mbl-balance'; span.textContent = valueText || '—';
    var btn = document.createElement('button'); btn.type='button'; btn.className='mbl-refresh'; btn.setAttribute('aria-label','Aggiorna ArenaCoins');
    btn.innerHTML = svg('refresh');
    btn.addEventListener('click', function(){
      btn.classList.add('spin');
      try { document.dispatchEvent(new CustomEvent('refresh-balance')); } catch(_){}
      setTimeout(updateBalanceFromDOM, 300);
      setTimeout(function(){ updateBalanceFromDOM(); btn.classList.remove('spin'); }, 1200);
    });
    vv.appendChild(span); vv.appendChild(btn);
    row.appendChild(kk); row.appendChild(vv); container.appendChild(row);
  }
  function addUserKV(container){
    var frag = document.createDocumentFragment();
    frag.appendChild(createAvatarNode(true));
    var s = document.createElement('span'); s.textContent = ' ' + (readUsername() || '-');
    frag.appendChild(s);
    addKV(container, 'Utente:', frag);
  }

  function fillSections(body){
    if (!body) return;
    body.innerHTML='';

    // Dati utente (se presenti)
    var userEl = qs('.hdr__usr');
    var balEl  = qs('.pill-balance .ac');

    // Link di base
    var navLinks  = gather('.subhdr .subhdr__menu a');             // Navigazione
    var isLogged  = !!userEl || !!qs('.hdr__right');
    var accLinks  = isLogged ? gather('.hdr__right a') : gather('.hdr__nav a');  // Azioni account
    var footLinks = gather('.site-footer .footer-menu a');         // Info/footer

    // Guest: CTA Registrati blu + Accedi ghost
    if (!isLogged){
      var registrati = accLinks.find(function(a){ return /registr/i.test(a.href) || /registr/i.test(txt(a)); }) || null;
      var accedi     = accLinks.find(function(a){ return /login|acced/i.test(a.href) || /acced/i.test(txt(a)); }) || null;

      if (registrati || accedi){
        var secW = document.createElement('section'); secW.className='mbl-sec';
        var hW = document.createElement('div'); hW.className='mbl-sec__title'; hW.textContent='Benvenuto'; secW.appendChild(hW);
        var row = document.createElement('div'); row.className='mbl-ctaRow';
        if (registrati){ var r = registrati.cloneNode(true); r.classList.add('mbl-cta'); r.addEventListener('click', closeDrawer); row.appendChild(r); }
        if (accedi){ var g = accedi.cloneNode(true); g.classList.add('mbl-ghost'); g.addEventListener('click', closeDrawer); row.appendChild(g); }
        secW.appendChild(row); body.appendChild(secW);
      }
    }

    // Utente: blocco ACCOUNT
    if (isLogged){
      var secA = document.createElement('section'); secA.className='mbl-sec';
      var hA = document.createElement('div'); hA.className='mbl-sec__title'; hA.textContent='Account'; secA.appendChild(hA);

      var accountBox = document.createElement('div'); accountBox.className='mbl-account';
      addBalanceKV(accountBox, balEl ? txt(balEl) : '');
      addUserKV(accountBox);
      secA.appendChild(accountBox);

      var ricaricaA = accLinks.find(function(a){ return /ricar/i.test(a.href) || /ricar/i.test(txt(a)); }) || null;
      var logoutA   = accLinks.find(function(a){ return /logout|esci/i.test(a.href) || /logout|esci/i.test(txt(a)); }) || null;
      if (ricaricaA || logoutA){
        var row = document.createElement('div'); row.className='mbl-ctaRow';
        if (ricaricaA){ var r = ricaricaA.cloneNode(true); r.classList.add('mbl-cta'); r.addEventListener('click', closeDrawer); row.appendChild(r); }
        if (logoutA){ var g = logoutA.cloneNode(true); g.classList.add('mbl-ghost'); g.addEventListener('click', closeDrawer); row.appendChild(g); }
        secA.appendChild(row);
      }

      // Altre azioni (es. Messaggi, Dati utente, ecc.) — già filtrato rumore
      var others = accLinks.filter(function(a){ return a !== ricaricaA && a !== logoutA; });
      if (others.length){ secA.appendChild(makeList(others)); }
      body.appendChild(secA);
    }

    // NAVIGAZIONE (dal sub-header)
    if (navLinks.length){
      var secN = section('Navigazione', navLinks, 'mbl-sec--nav');
      body.appendChild(secN);
    }

    // INFO (footer del sito) — inserito nella stessa colonna, scorre insieme
    if (footLinks.length){
      var secF = section('Info', footLinks, '');
      body.appendChild(secF);
    }

    setupBalanceSync(); // keep in sync
  }

  // -----------------------------------------------------
  // Sync ArenaCoins con il DOM globale
  // -----------------------------------------------------
  function updateBalanceFromDOM(){
    var span = qs('#mbl-balance'); if (!span) return;
    var src  = qs('.pill-balance .ac');
    var val = src ? txt(src) : span.textContent;
    if (val) span.textContent = val;
  }

  function setupBalanceSync(){
    if (state.balanceObserver){ try{ state.balanceObserver.disconnect(); }catch(_){}
      state.balanceObserver = null;
    }
    var src = qs('.pill-balance .ac');
    if (src && window.MutationObserver){
      state.balanceObserver = new MutationObserver(function(){ updateBalanceFromDOM(); });
      state.balanceObserver.observe(src, { childList:true, characterData:true, subtree:true });
    }
    ['balance:updated', 'arena:balance:updated', 'refresh-balance-done'].forEach(function(ev){
      document.addEventListener(ev, updateBalanceFromDOM, { passive:true });
    });
  }

  // -----------------------------------------------------
  // Apertura/chiusura + focus trap + scroll-lock
  // -----------------------------------------------------
  function getFocusable(root){
    var sel = ['a[href]','button:not([disabled])','input:not([disabled])','select:not([disabled])','textarea:not([disabled])','[tabindex]:not([tabindex="-1"])'].join(',');
    return qsa(sel, root).filter(function(el){ return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length); });
  }
  function trapFocus(ev, root){
    var items = getFocusable(root); if (!items.length) return;
    var first = items[0], last = items[items.length-1];
    if (ev.shiftKey){
      if (document.activeElement === first || !root.contains(document.activeElement)){ ev.preventDefault(); last.focus(); }
    }else{
      if (document.activeElement === last){ ev.preventDefault(); first.focus(); }
    }
  }
  function openDrawer(){
    var dr=qs('#mbl-drawer'), bd=qs('#mbl-backdrop'), tg=qs('#mbl-trigger'); if(!dr||!bd) return;
    updateBalanceFromDOM(); // aggiorna saldo quando apro
    state.lastActive = document.activeElement || tg;
    document.documentElement.classList.add('mbl-lock'); document.body.classList.add('mbl-lock');
    bd.removeAttribute('hidden'); bd.classList.add('mbl-open'); dr.classList.add('mbl-open'); dr.setAttribute('aria-hidden','false'); if(tg) tg.setAttribute('aria-expanded','true');
    var f = getFocusable(dr); (f[0]||dr).focus({preventScroll:true});
    state.open = true;
  }
  function closeDrawer(){
    var dr=qs('#mbl-drawer'), bd=qs('#mbl-backdrop'), tg=qs('#mbl-trigger'); if(!dr||!bd) return;
    dr.classList.remove('mbl-open'); dr.setAttribute('aria-hidden','true'); bd.classList.remove('mbl-open'); bd.setAttribute('hidden','hidden');
    document.documentElement.classList.remove('mbl-lock'); document.body.classList.remove('mbl-lock');
    if(tg) tg.setAttribute('aria-expanded','false');
    var back = state.lastActive || tg; if(back && back.focus) back.focus({preventScroll:true});
    state.open = false;
  }
  function toggleDrawer(){ state.open ? closeDrawer() : openDrawer(); }

  // -----------------------------------------------------
  // Init non bloccante
  // -----------------------------------------------------
  function init(){
    injectStyle();
    if (isMobile()) buildOnce();

    if (window.matchMedia){
      var mql = window.matchMedia('(max-width: 768px)');
      var onChange = function(e){ if (e.matches){ buildOnce(); } else { closeDrawer(); } };
      if (mql.addEventListener) mql.addEventListener('change', onChange);
      else if (mql.addListener) mql.addListener(onChange);
    }

    // Chiudi quando si naviga fuori dal drawer
    window.addEventListener('hashchange', closeDrawer);
    document.addEventListener('click', function(ev){
      if (!state.open) return;
      var dr = qs('#mbl-drawer'); if (!dr) return;
      var a = ev.target.closest && ev.target.closest('a'); if (a && !dr.contains(a)) closeDrawer();
    });
  }

  if (document.readyState === 'loading'){ document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
