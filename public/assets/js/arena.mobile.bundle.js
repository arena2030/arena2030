/*! Arena Mobile Layer — Right Drawer + Mobile polish (torneo Flash)
    Single-file bundle, no deps. Lavora SOLO sotto 768px.
    NOTE:
    - Applica una classe su <html> (mbl-active) per attivare gli override mobile.
    - Per la pagina Flash ( /flash/torneo.php ) ricompone KPI 2×2 e sposta i bottoni dentro card.
    - Per gli eventi Flash: ovale sopra, bottoni Casa/Pareggio/Trasferta sotto (con Observer).
*/
(function () {
  'use strict';

  // -----------------------------------------------------
  // Utils
  // -----------------------------------------------------
  var ric = window.requestIdleCallback || function (cb) { return setTimeout(cb, 0); };
  var cac = window.cancelIdleCallback || function (id) { clearTimeout(id); };
  var raf = window.requestAnimationFrame || function (cb) { return setTimeout(cb, 0); };

  function isMobileNow() {
    try {
      if (window.matchMedia) return window.matchMedia('(max-width: 768px)').matches;
    } catch (_) {}
    return (window.innerWidth || 0) <= 768;
  }
  function qs(s, r){ return (r||document).querySelector(s); }
  function qsa(s, r){ return Array.prototype.slice.call((r||document).querySelectorAll(s)); }
  function txt(el){ return (el && (el.textContent||'').trim()) || ''; }
  function containsText(el, needle){ return txt(el).toLowerCase().indexOf(needle) !== -1; }
  function debounce(fn, ms){ var t=null; return function(){ var a=arguments; clearTimeout(t); t=setTimeout(function(){ fn.apply(null,a); }, ms||50); }; }

  // Semplici SVG inline (colorati via currentColor)
  function svg(name){
    if (name==='close') return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    if (name==='menu')  return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 6h18M3 12h18M3 18h18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    if (name==='refresh') return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 4v6h6M20 20v-6h-6M20 9A8 8 0 0 0 4 9m0 6a8 8 0 0 0 16 0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    if (name==='msg') return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M21 15a2 2 0 0 1-2 2H8l-5 5V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>';
    return '';
  }

  // -----------------------------------------------------
  // CSS inline (mobile + fix responsivi). Desktop invariato.
  // -----------------------------------------------------
  function injectStyle(){
    if (qs('#mbl-style')) return;
    var css = `
/* ====== Arena Mobile Layer ====== */
html.mbl-active #mbl-trigger{ display:inline-flex; -webkit-tap-highlight-color:transparent; }
#mbl-backdrop{ position:fixed; inset:0; background:rgba(0,0,0,.45);
  backdrop-filter:saturate(120%) blur(1px); opacity:0; pointer-events:none; transition:opacity .2s ease; z-index:70; }
#mbl-backdrop.mbl-open{ opacity:1; pointer-events:auto; }
#mbl-drawer{ position:fixed; top:0; right:0; left:auto; height:100dvh; width:320px; max-width:92vw;
  transform:translateX(105%); transition:transform .24s ease;
  background:var(--c-bg,#0b1220); color:var(--c-text,#fff);
  border-left:1px solid var(--c-border,rgba(255,255,255,.08)); z-index:71;
  display:flex; flex-direction:column; }
#mbl-drawer.mbl-open{ transform:translateX(0); }

/* Head drawer */
#mbl-drawer .mbl-head{ display:flex; align-items:center; gap:12px; padding:14px; border-bottom:1px solid var(--c-border,rgba(255,255,255,.08)); }
#mbl-drawer .mbl-brand{ display:inline-flex; align-items:center; gap:8px; font-weight:900; }
#mbl-drawer .mbl-title{ font-weight:900; font-size:16px; }
#mbl-drawer .mbl-close{ margin-left:auto; width:36px; height:36px; border-radius:10px;
  border:1px solid var(--c-border,rgba(255,255,255,.12)); background:var(--c-bg-2,#0f172a); color:var(--c-text,#fff);
  display:inline-flex; align-items:center; justify-content:center; }

/* Corpo scroll + footer INFO fixed in basso */
#mbl-drawer .mbl-scroll{ flex:1; overflow:auto; }
#mbl-drawer .mbl-foot{ border-top:1px solid var(--c-border,rgba(255,255,255,.08)); padding:8px 12px 10px; }
#mbl-drawer .mbl-sec{ padding:12px 12px 6px; }
#mbl-drawer .mbl-sec__title{ font-size:12px; font-weight:900; color:var(--c-muted,#9fb7ff); letter-spacing:.3px;
  padding:2px 6px 8px; text-transform:uppercase; }
#mbl-drawer .mbl-list{ list-style:none; margin:0; padding:0; }
#mbl-drawer .mbl-list li{ margin:0; }
#mbl-drawer .mbl-list a{ display:block; padding:12px 8px; text-decoration:none; color:var(--c-text,#e5e7eb); border-radius:8px; }
#mbl-drawer .mbl-list a:hover{ filter:brightness(1.05); }
/* Navigazione più “bold” */
#mbl-drawer .mbl-sec--nav .mbl-list a{ font-weight:800; }

/* CTA row (Registrati/Accedi o Ricarica/Logout) */
#mbl-drawer .mbl-ctaRow{ display:flex; gap:8px; padding:8px 6px 0; flex-wrap:wrap; }
#mbl-drawer .mbl-cta, #mbl-drawer .mbl-ghost{
  display:inline-flex; align-items:center; justify-content:center; height:36px; padding:0 14px;
  border-radius:9999px; font-weight:800; text-decoration:none; white-space:nowrap;
}
#mbl-drawer .mbl-cta{
  background: var(--c-primary, #2563eb);
  border:1px solid color-mix(in lab, var(--c-primary,#2563eb) 85%, #000); color:#fff;
}
#mbl-drawer .mbl-ghost{
  background: transparent; color: var(--c-text,#e5e7eb); border:1px solid var(--c-border,#1f2937);
}

/* Riga account (ArenaCoins + Utente) */
#mbl-drawer .mbl-account{ padding:4px 6px 8px; }
#mbl-drawer .mbl-kv{ font-size:14px; display:flex; gap:6px; padding:6px 2px; align-items:center; }
#mbl-drawer .mbl-kv .k{ min-width:100px; color:var(--c-muted,#9fb7ff); font-weight:900; }
#mbl-drawer .mbl-kv .v{ font-weight:800; color:var(--c-text,#e5e7eb); }
#mbl-drawer .mbl-kv .mbl-refresh{ margin-left:8px; width:32px; height:32px; border-radius:9999px; display:inline-flex; align-items:center; justify-content:center;
  border:1px solid var(--c-border,#1e293b); background:var(--c-bg-2,#0f172a); }

/* ————— Mobile only ————— */
@media (max-width:768px){
  /* Nascondi sub-header e footer */
  html.mbl-active .subhdr, html.mbl-active .site-footer { display:none !important; }

  /* Header mobile: tieni solo avatar+username a destra e hamburger */
  html.mbl-active .hdr__nav { display:none !important; }
  html.mbl-active .hdr__right > *:not(.hdr__usr) { display:none !important; }

  /* Posizionamento hamburger a destra */
  .hdr__bar > #mbl-trigger{
    display:inline-flex; align-items:center; justify-content:center;
    width:40px; height:40px; margin-left:8px; border-radius:10px;
    border:1px solid var(--c-border,#1e293b); background:var(--c-bg-2,#0f172a); color:var(--c-text,#fff);
  }
  #mbl-trigger svg{ width:20px; height:20px; }
  main { padding-bottom:24px; }
}
/* Desktop: layer invisibile */
@media (min-width:769px){
  #mbl-trigger, #mbl-backdrop, #mbl-drawer{ display:none !important; }
}

/* ====== Fix layout pagina FLASH ====== */
html.mbl-active.mbl-page-flash .mbl-kpi-grid{ display:grid !important; grid-template-columns:1fr 1fr; gap:10px; }
html.mbl-active.mbl-page-flash .mbl-kpi{ min-width:0; }
html.mbl-active.mbl-page-flash .mbl-kpi *{ overflow-wrap:anywhere; }

html.mbl-active.mbl-page-flash .mbl-hero-actions{ display:flex; gap:10px; flex-wrap:wrap; margin-top:12px; }
html.mbl-active.mbl-page-flash .mbl-hero-actions .btn{ flex:1 1 48%; }
html.mbl-active.mbl-page-flash .mbl-fix-static{ position:static !important; float:none !important; }

html.mbl-active.mbl-page-flash .mbl-bet-actions{ display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin-top:10px; }
@media (max-width:360px){
  html.mbl-active.mbl-page-flash .mbl-bet-actions{ grid-template-columns:repeat(2,1fr); }
  html.mbl-active.mbl-page-flash .mbl-bet-actions > *:nth-child(3){ grid-column:1 / -1; }
}
`;
    var st = document.createElement('style'); st.id = 'mbl-style'; st.type='text/css'; st.appendChild(document.createTextNode(css));
    document.head.appendChild(st);
  }

  // -----------------------------------------------------
  // Right Drawer (idempotente)
  // -----------------------------------------------------
  var drawerState = { built:false, open:false, lastActive:null, idleId:null };

  function ensureTrigger(){
    var bar = qs('.hdr__bar'); if (!bar) return;
    if (!qs('#mbl-trigger', bar)){
      var t = document.createElement('button');
      t.id='mbl-trigger'; t.type='button';
      t.setAttribute('aria-label','Apri menu'); t.setAttribute('aria-controls','mbl-drawer'); t.setAttribute('aria-expanded','false');
      t.innerHTML = svg('menu');
      bar.appendChild(t); // a destra
    }
  }

  function ensureDrawer(){
    if (qs('#mbl-drawer')) return;

    var dr = document.createElement('aside');
    dr.id='mbl-drawer'; dr.setAttribute('role','dialog'); dr.setAttribute('aria-modal','true'); dr.setAttribute('aria-hidden','true'); dr.tabIndex=-1;

    // Head
    var head = document.createElement('div'); head.className='mbl-head';
    var brand = document.createElement('div'); brand.className='mbl-brand';
    var logo = qs('.hdr__logo') || qs('.hdr .logo');
    if (logo) brand.appendChild(logo.cloneNode(true));
    var ttl = document.createElement('div'); ttl.className='mbl-title'; ttl.textContent='Menu';
    brand.appendChild(ttl); head.appendChild(brand);

    var btnX = document.createElement('button'); btnX.type='button'; btnX.className='mbl-close'; btnX.setAttribute('aria-label','Chiudi menu');
    btnX.innerHTML = svg('close'); head.appendChild(btnX);

    dr.appendChild(head);

    // Corpo + foot
    var sc = document.createElement('div'); sc.className='mbl-scroll';
    var foot = document.createElement('div'); foot.className='mbl-foot';
    dr.appendChild(sc); dr.appendChild(foot);

    document.body.appendChild(dr);

    var bd = document.createElement('div'); bd.id='mbl-backdrop'; bd.setAttribute('hidden','hidden');
    document.body.appendChild(bd);

    // Costruzione contenuti in idle
    drawerState.idleId = ric(function(){ try{ fillDrawer(sc, foot); }catch(_){/* no-op */} });

    // Bind chiusure/base
    btnX.addEventListener('click', closeDrawer);
    bd.addEventListener('click', closeDrawer);
    dr.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape') { ev.preventDefault(); closeDrawer(); return; }
      if (ev.key === 'Tab') { trapFocus(ev, dr); }
    });

    // Trigger
    var trigger = qs('#mbl-trigger');
    if (trigger && !trigger._mblBound){
      trigger.addEventListener('click', toggleDrawer);
      trigger.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); toggleDrawer(); }
      });
      trigger._mblBound = true;
    }
  }

  function gather(sel){
    return qsa(sel).filter(function(a){ return a && a.tagName==='A' && (a.href||'').length>0 && txt(a)!==''; });
  }
  function makeList(links){
    var ul = document.createElement('ul'); ul.className='mbl-list';
    links.forEach(function(a){
      try{
        var li = document.createElement('li');
        var cp = a.cloneNode(true);
        cp.removeAttribute('id'); ['onclick','onmousedown','onmouseup','onmouseover','onmouseout'].forEach(function(k){ cp.removeAttribute(k); });

        // Bridge "Lista movimenti": prova ad aprire il modal del desktop; fallback pagina.
        var label = txt(cp).toLowerCase();
        if (label.indexOf('moviment') !== -1){
          cp.addEventListener('click', function(e){
            e.preventDefault();
            closeDrawer();
            var modalTrigger = qs('[data-open="movimenti"], [data-modal="movimenti"], .js-open-movimenti, .open-movimenti');
            if (modalTrigger) { modalTrigger.click(); }
            else { window.location.href = '/movimenti.php'; }
          });
        } else {
          cp.addEventListener('click', function(){ closeDrawer(); });
        }

        li.appendChild(cp); ul.appendChild(li);
      }catch(_){}
    });
    return ul;
  }
  function section(title, links, extraClass){
    if (!links || !links.length) return null;
    var wrap = document.createElement('section'); wrap.className='mbl-sec'+(extraClass?(' '+extraClass):'');
    var h = document.createElement('div'); h.className='mbl-sec__title'; h.textContent=title; wrap.appendChild(h);
    wrap.appendChild(makeList(links)); return wrap;
  }
  function addKV(container, k, v, afterNode){
    var row = document.createElement('div'); row.className='mbl-kv';
    var kk = document.createElement('div'); kk.className='k'; kk.textContent=k;
    var vv = document.createElement('div'); vv.className='v'; vv.textContent=v;
    row.appendChild(kk); row.appendChild(vv);
    if (afterNode) row.appendChild(afterNode);
    container.appendChild(row);
  }
  function buildCtaRow(parent, primaryA, secondaryA){
    var row = document.createElement('div'); row.className='mbl-ctaRow';
    if (primaryA){
      var p = primaryA.cloneNode(true);
      p.classList.add('mbl-cta'); p.removeAttribute('id');
      p.addEventListener('click', function(){ closeDrawer(); });
      row.appendChild(p);
    }
    if (secondaryA){
      var s = secondaryA.cloneNode(true);
      s.classList.add('mbl-ghost'); s.removeAttribute('id');
      s.addEventListener('click', function(){ closeDrawer(); });
      row.appendChild(s);
    }
    parent.appendChild(row);
  }

  function fillDrawer(scrollContainer, footContainer){
    if (!scrollContainer || !footContainer) return;
    scrollContainer.innerHTML=''; footContainer.innerHTML='';

    var userEl = qs('.hdr__usr');
    var balEl  = qs('.pill-balance .ac');

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
        buildCtaRow(secW, registrati, accedi);
        scrollContainer.appendChild(secW);
      }
    }

    // Utente: blocco ACCOUNT (ArenaCoins + Utente) + Ricarica (primaria) + Logout (ghost)
    if (isLogged){
      var secA = document.createElement('section'); secA.className='mbl-sec';
      var hA = document.createElement('div'); hA.className='mbl-sec__title'; hA.textContent='Account'; secA.appendChild(hA);

      var accountBox = document.createElement('div'); accountBox.className='mbl-account';

      // ArenaCoins (leggo la pillola saldo se esiste)
      var coins = balEl ? txt(balEl) : '0.00';
      var refreshBtn = document.createElement('button'); refreshBtn.type='button'; refreshBtn.className='mbl-refresh';
      refreshBtn.innerHTML = svg('refresh');
      refreshBtn.addEventListener('click', function(){
        // Provo a “triggerare” un eventuale aggiornamento del saldo se presente (evento custom usato nel progetto)
        document.dispatchEvent(new CustomEvent('refresh-balance'));
        setTimeout(function(){
          var be = qs('.pill-balance .ac'); if (be) coins = txt(be);
          vv1.textContent = coins || '0.00';
        }, 200);
      });

      var vv1 = document.createElement('span'); vv1.textContent = coins || '0.00';
      addKV(accountBox, 'ArenaCoins:', vv1.textContent, refreshBtn);

      // Utente + avatar click = stessa destinazione desktop (se esiste)
      var userTxt = userEl ? txt(userEl) : '';
      var userLink = (userEl && userEl.querySelector('a[href]')) || qs('a[href*="dati-utente"]') || null;
      var rowUser = document.createElement('div'); rowUser.className='mbl-kv';
      var ku = document.createElement('div'); ku.className='k'; ku.textContent='Utente:'; rowUser.appendChild(ku);
      var vu = document.createElement('div'); vu.className='v'; vu.textContent = userTxt || '-';
      if (userLink){ rowUser.style.cursor='pointer'; rowUser.addEventListener('click', function(){ window.location.href = userLink.href; }); }
      rowUser.appendChild(vu);
      accountBox.appendChild(rowUser);

      secA.appendChild(accountBox);

      // CTA: Ricarica (primaria) + Logout (ghost) se presenti
      var ricaricaA = accLinks.find(function(a){ return /ricar/i.test(a.href) || /ricar/i.test(txt(a)); }) || null;
      var logoutA   = accLinks.find(function(a){ return /logout|esci/i.test(a.href) || /logout|esci/i.test(txt(a)); }) || null;
      if (ricaricaA || logoutA) buildCtaRow(secA, ricaricaA, logoutA);

      // Altre azioni account (Messaggi, Dati utente, ecc.)
      var otherAcc = accLinks.filter(function(a){ return a !== ricaricaA && a !== logoutA; });
      if (otherAcc.length){ secA.appendChild(makeList(otherAcc)); }

      scrollContainer.appendChild(secA);
    }

    // NAVIGAZIONE (dal sub-header)
    if (navLinks.length){
      var secN = section('Navigazione', navLinks, 'mbl-sec--nav');
      scrollContainer.appendChild(secN);
    }

    // INFO (footer) in basso
    if (footLinks.length){
      var title = document.createElement('div'); title.className='mbl-sec__title'; title.textContent='Info';
      footContainer.appendChild(title);
      footContainer.appendChild(makeList(footLinks));
    }
  }

  // Apertura/chiusura + focus trap + scroll-lock
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
    drawerState.lastActive = document.activeElement || tg;
    document.documentElement.classList.add('mbl-lock'); document.body.classList.add('mbl-lock');
    bd.removeAttribute('hidden'); bd.classList.add('mbl-open'); dr.classList.add('mbl-open'); dr.setAttribute('aria-hidden','false'); if(tg) tg.setAttribute('aria-expanded','true');
    var f = getFocusable(dr); (f[0]||dr).focus({preventScroll:true});
    drawerState.open = true;
  }
  function closeDrawer(){
    var dr=qs('#mbl-drawer'), bd=qs('#mbl-backdrop'), tg=qs('#mbl-trigger'); if(!dr||!bd) return;
    dr.classList.remove('mbl-open'); dr.setAttribute('aria-hidden','true'); bd.classList.remove('mbl-open'); bd.setAttribute('hidden','hidden');
    document.documentElement.classList.remove('mbl-lock'); document.body.classList.remove('mbl-lock');
    if(tg) tg.setAttribute('aria-expanded','false');
    var back = drawerState.lastActive || tg; if(back && back.focus) back.focus({preventScroll:true});
    drawerState.open = false;
  }
  function toggleDrawer(){ drawerState.open ? closeDrawer() : openDrawer(); }

  // -----------------------------------------------------
  // Adattamento pagina FLASH (hero + eventi) con Observer
  // -----------------------------------------------------
  var scheduleFlashApply = debounce(function(){ try{ applyFlashLayout(); }catch(_){ } }, 40);

  function applyFlashLayout(){
    if (!document.documentElement.classList.contains('mbl-active')) return;
    if (!/\/flash\/torneo\.php/i.test(location.pathname)) return;

    normalizeFlashHero();
    normalizeFlashEvents();
  }

  function findBtn(rx, root){
    var btn = qsa('a,button', root||document).find(function(el){ return rx.test(txt(el)); });
    return btn || null;
  }
  function closestCard(el){
    if (!el) return null;
    return el.closest('.card, .tcard, .panel, .box, [class*="card"], .section') || el.closest('section') || el.parentElement;
  }
  function commonParent(nodes){
    if (!nodes || !nodes.length) return null;
    if (nodes.length===1) return nodes[0].parentElement;
    var p = nodes[0]; while (p){
      if (nodes.every(function(n){ return p.contains(n); })) return p;
      p = p.parentElement;
    }
    return nodes[0].parentElement || null;
  }

  function normalizeFlashHero(){
    // Anchor: bottone "Acquista una vita" o "Disiscrivi"
    var buy = findBtn(/acquista/i);
    var hero = closestCard(buy) || closestCard(findBtn(/disiscr/i)) || null;
    if (!hero) return;

    document.documentElement.classList.add('mbl-page-flash');

    // KPI boxes: individua 4 box per testo-etichetta
    var labels = ['Montepremi','Partecipanti','Vite','Lock'];
    var candidates = [];
    qsa('*', hero).forEach(function(el){
      var t = txt(el).toLowerCase();
      if (!t) return;
      if (labels.some(function(l){ return t.indexOf(l.toLowerCase()) === 0 || t.indexOf(l.toLowerCase()+' ')===0; })){
        var box = el.closest('div'); if (box && candidates.indexOf(box)===-1) candidates.push(box);
      }
    });
    if (candidates.length){
      var parent = commonParent(candidates);
      if (parent){
        parent.classList.add('mbl-kpi-grid');
        candidates.forEach(function(b){ b.classList.add('mbl-kpi'); });
      }
    }

    // Bottoni dentro card, sotto i KPI
    var actWrap = hero.querySelector('.mbl-hero-actions');
    if (!actWrap){
      actWrap = document.createElement('div'); actWrap.className='mbl-hero-actions';
      // Inserisci subito dopo la griglia KPI se esiste, altrimenti in coda alla card
      var kpi = hero.querySelector('.mbl-kpi-grid');
      if (kpi && kpi.parentNode) kpi.parentNode.insertBefore(actWrap, kpi.nextSibling);
      else hero.appendChild(actWrap);
    }
    var btnAcq = findBtn(/acquista/i, hero);
    var btnOut = findBtn(/disiscr/i, hero);
    [btnAcq, btnOut].forEach(function(b){
      if (b && !actWrap.contains(b)){ actWrap.appendChild(b); b.classList.add('mbl-fix-static'); }
    });
  }

  // Trova il contenitore "evento singolo" partendo da uno dei bottoni
  function findEventContainerFromButton(btn){
    var p = btn ? btn.parentElement : null;
    while (p && p !== document.body){
      // Un contenitore evento deve contenere almeno 2 dei tre bottoni target
      var b = qsa('a,button', p).filter(function(x){ return /^(casa|pareggio|trasferta)$/i.test(txt(x)); });
      if (b.length >= 2) return p;
      p = p.parentElement;
    }
    return null;
  }
  function normalizeFlashEvents(){
    // Raggruppa i bottoni Casa/Pareggio/Trasferta per evento e portali sotto all'ovale
    var all = qsa('a,button').filter(function(x){ return /^(casa|pareggio|trasferta)$/i.test(txt(x)); });
    if (!all.length) return;

    // Mappa: contenitore evento -> array di bottoni
    var map = new Map();
    all.forEach(function(b){
      var cont = findEventContainerFromButton(b);
      if (!cont) return;
      var arr = map.get(cont) || [];
      if (arr.indexOf(b) === -1) arr.push(b);
      map.set(cont, arr);
    });

    map.forEach(function(btns, cont){
      if (!btns || btns.length === 0) return;
      var wrap = cont.querySelector('.mbl-bet-actions');
      if (!wrap){
        wrap = document.createElement('div'); wrap.className='mbl-bet-actions';
        // Inserisci sotto il primo child "ovale". Se non c'è, append in coda.
        var first = cont.firstElementChild;
        if (first && first.nextSibling){ cont.insertBefore(wrap, first.nextSibling); }
        else cont.appendChild(wrap);
      }
      btns.forEach(function(b){ if (b.parentElement !== wrap){ wrap.appendChild(b); b.classList.add('mbl-fix-static'); } });
    });
  }

  // -----------------------------------------------------
  // Attivazione Mobile + init
  // -----------------------------------------------------
  function applyMobileClass(){
    var on = isMobileNow();
    var html = document.documentElement;
    if (on) html.classList.add('mbl-active');
    else { html.classList.remove('mbl-active'); closeDrawer(); }
  }

  function ensureHeaderAvatarClick(){
    var usr = qs('.hdr__usr');
    if (!usr) return;
    var link = usr.querySelector('a[href]') || qs('a[href*="dati-utente"]') || qs('a[href*="profilo"]');
    if (!link) return;
    if (!usr._mblClick){
      usr.style.cursor='pointer';
      usr.addEventListener('click', function(){ window.location.href = link.href; });
      usr._mblClick = true;
    }
  }

  function init(){
    injectStyle();
    applyMobileClass();

    if (isMobileNow()){
      ensureTrigger();
      ensureDrawer();
      ensureHeaderAvatarClick();
    }

    // Rilevazione live resize / mql
    var onChange = function(){ applyMobileClass(); if (isMobileNow()){ ensureTrigger(); ensureDrawer(); ensureHeaderAvatarClick(); } };
    window.addEventListener('resize', debounce(onChange, 100), { passive:true });
    if (window.matchMedia){
      var mql = window.matchMedia('(max-width: 768px)');
      if (mql.addEventListener) mql.addEventListener('change', onChange);
      else if (mql.addListener) mql.addListener(onChange);
    }

    // Observer per pagina FLASH (hero + eventi)
    if (/\/flash\/torneo\.php/i.test(location.pathname)){
      document.documentElement.classList.add('mbl-page-flash');
      // Prima applicazione
      ric(function(){ scheduleFlashApply(); });
      // Osserva cambi DOM
      var target = qs('main') || document.body;
      try{
        var mo = new MutationObserver(function(){ scheduleFlashApply(); });
        mo.observe(target, { childList:true, subtree:true });
      }catch(_){}
    }

    // Chiudi drawer quando si naviga fuori
    window.addEventListener('hashchange', closeDrawer);
    document.addEventListener('click', function(ev){
      if (!drawerState.open) return;
      var dr = qs('#mbl-drawer'); if (!dr) return;
      var a = ev.target && ev.target.closest && ev.target.closest('a'); if (a && !dr.contains(a)) closeDrawer();
    });
  }

  if (document.readyState === 'loading'){ document.addEventListener('DOMContentLoaded', init); }
  else { init(); }
})();
