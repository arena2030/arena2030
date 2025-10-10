/*! Arena Mobile Layer (Right Drawer) — single-file bundle, no deps
   Requisiti: mobile-only overrides, drawer a destra, header pulito, menu costruito dal DOM,
   accessibilità (role dialog, focus trap), scroll-lock, robustezza selettori.
*/
(function () {
  'use strict';

  // -----------------------------------------------------
  // Utils
  // -----------------------------------------------------
  var ric = window.requestIdleCallback || function (cb) { return setTimeout(cb, 0); };
  var cac = window.cancelIdleCallback || function (id) { clearTimeout(id); };
  function isMobile() { return (window.matchMedia ? window.matchMedia('(max-width: 768px)').matches : (window.innerWidth||0) <= 768); }
  function qs(s, r){ return (r||document).querySelector(s); }
  function qsa(s, r){ return Array.prototype.slice.call((r||document).querySelectorAll(s)); }
  function txt(el){ return (el && (el.textContent||'').trim()) || ''; }
  function hasText(el, needle){ return txt(el).toLowerCase().indexOf(needle) !== -1; }

  // -----------------------------------------------------
  // CSS inline (solo mobile). Desktop invariato.
  // -----------------------------------------------------
  function injectStyle(){
    if (qs('#mbl-style')) return;
    var css = `
/* ===== Arena Mobile Layer (drawer destro) ===== */
#mbl-trigger{ display:none; -webkit-tap-highlight-color:transparent; }
#mbl-backdrop{ position:fixed; inset:0; background:rgba(0,0,0,.45);
  backdrop-filter:saturate(120%) blur(1px); opacity:0; pointer-events:none; transition:opacity .2s ease; z-index:70; }
#mbl-backdrop.mbl-open{ opacity:1; pointer-events:auto; }
#mbl-drawer{ position:fixed; top:0; right:0; left:auto; height:100dvh; width:320px; max-width:92vw;
  transform:translateX(105%); transition:transform .24s ease;
  background:var(--c-bg,#0b1220); color:var(--c-text,#fff);
  border-left:1px solid var(--c-border,rgba(255,255,255,.08)); z-index:71;
  display:flex; flex-direction:column; }
#mbl-drawer.mbl-open{ transform:translateX(0); }

/* Header del drawer */
#mbl-drawer .mbl-head{ display:flex; align-items:center; gap:12px; padding:14px; border-bottom:1px solid var(--c-border,rgba(255,255,255,.08)); }
#mbl-drawer .mbl-brand{ display:inline-flex; align-items:center; gap:8px; font-weight:900; }
#mbl-drawer .mbl-title{ font-weight:900; font-size:16px; }
#mbl-drawer .mbl-close{ margin-left:auto; width:36px; height:36px; border-radius:10px;
  border:1px solid var(--c-border,rgba(255,255,255,.12)); background:var(--c-bg-2,#0f172a); color:var(--c-text,#fff);
  display:inline-flex; align-items:center; justify-content:center; }

/* Corpo scrollabile + footer “pinned” in basso */
#mbl-drawer .mbl-scroll{ flex:1; overflow:auto; }
#mbl-drawer .mbl-sec{ padding:12px 12px 6px; }
#mbl-drawer .mbl-sec__title{ font-size:12px; font-weight:900; color:var(--c-muted,#9fb7ff); letter-spacing:.3px;
  padding:2px 6px 8px; text-transform:uppercase; }
#mbl-drawer .mbl-list{ list-style:none; margin:0; padding:0; }
#mbl-drawer .mbl-list li{ margin:0; }
#mbl-drawer .mbl-list a{ display:block; padding:12px 8px; text-decoration:none; color:var(--c-text,#e5e7eb); border-radius:8px; }
#mbl-drawer .mbl-list a:hover{ filter:brightness(1.05); }
/* Navigazione più grassetta */
#mbl-drawer .mbl-sec--nav .mbl-list a{ font-weight:800; }

/* Row di CTA (guest o azioni account importanti) */
#mbl-drawer .mbl-ctaRow{ display:flex; gap:8px; padding:8px 6px 0; flex-wrap:wrap; }
#mbl-drawer .mbl-cta, #mbl-drawer .mbl-ghost{
  display:inline-flex; align-items:center; justify-content:center; height:36px; padding:0 14px;
  border-radius:9999px; font-weight:800; text-decoration:none; white-space:nowrap;
}
#mbl-drawer .mbl-cta{
  background: var(--c-primary, #3b82f6); color:#fff; border:1px solid color-mix(in lab, var(--c-primary,#3b82f6) 85%, #000);
}
#mbl-drawer .mbl-ghost{
  background: transparent; color: var(--c-text,#e5e7eb); border:1px solid var(--c-border,#1f2937);
}

/* Riga conto (saldo/utente) */
#mbl-drawer .mbl-account{ padding:4px 6px 8px; }
#mbl-drawer .mbl-kv{ font-size:14px; display:flex; gap:6px; padding:6px 2px; }
#mbl-drawer .mbl-kv .k{ min-width:70px; color:var(--c-muted,#9fb7ff); font-weight:900; }
#mbl-drawer .mbl-kv .v{ font-weight:800; color:var(--c-text,#e5e7eb); }

/* Footer (INFO) “pinned” in basso */
#mbl-drawer .mbl-foot{ border-top:1px solid var(--c-border,rgba(255,255,255,.08)); padding:8px 12px 10px; }

/* ————— Mobile only ————— */
@media (max-width:768px){
  /* Nascondi sub-header e footer sito */
  .subhdr, .site-footer { display:none !important; }

  /* Header mobile pulito:
     - guest: nasconde .hdr__nav (registrati/accedi andranno nel drawer)
     - user: mostra solo .hdr__usr; nasconde altri bottoni (messaggi, ricarica, saldo, logout ecc.)
  */
  .hdr__nav { display:none !important; }
  .hdr__right > *:not(.hdr__usr) { display:none !important; }

  /* Hamburger a destra della .hdr__bar */
  .hdr__bar > #mbl-trigger{
    display:inline-flex; align-items:center; justify-content:center;
    width:40px; height:40px; margin-left:8px; border-radius:10px;
    border:1px solid var(--c-border,#1e293b); background:var(--c-bg-2,#0f172a); color:var(--c-text,#fff);
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
  // Build UI (idempotente)
  // -----------------------------------------------------
  var state = { built:false, open:false, lastActive:null, idleId:null };

  function buildOnce(){
    if (state.built) return;
    var bar = qs('.hdr__bar'); if (!bar) return;

    // Trigger hamburger (a destra → append)
    if (!qs('#mbl-trigger', bar)){
      var t = document.createElement('button');
      t.id='mbl-trigger'; t.type='button';
      t.setAttribute('aria-label','Apri menu'); t.setAttribute('aria-controls','mbl-drawer'); t.setAttribute('aria-expanded','false');
      t.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 6h18M3 12h18M3 18h18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
      bar.appendChild(t); // destra
    }

    // Drawer + backdrop
    if (!qs('#mbl-drawer')){
      var dr = document.createElement('aside');
      dr.id='mbl-drawer'; dr.setAttribute('role','dialog'); dr.setAttribute('aria-modal','true'); dr.setAttribute('aria-hidden','true'); dr.tabIndex=-1;

      // Head del drawer: brand + titolo + close
      var head = document.createElement('div'); head.className='mbl-head';
      var brand = document.createElement('div'); brand.className='mbl-brand';
      // Clona il logo se presente
      var logo = qs('.hdr__logo') || qs('.hdr .logo');
      if (logo){ brand.appendChild(logo.cloneNode(true)); }
      var ttl = document.createElement('div'); ttl.className='mbl-title'; ttl.textContent='Menu';
      brand.appendChild(ttl);
      head.appendChild(brand);

      var btnX = document.createElement('button'); btnX.type='button'; btnX.className='mbl-close'; btnX.setAttribute('aria-label','Chiudi menu');
      btnX.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
      head.appendChild(btnX);
      dr.appendChild(head);

      // Corpo scrollabile + foot
      var sc = document.createElement('div'); sc.className='mbl-scroll';
      dr.appendChild(sc);
      var foot = document.createElement('div'); foot.className='mbl-foot';
      dr.appendChild(foot);

      document.body.appendChild(dr);

      var bd = document.createElement('div'); bd.id='mbl-backdrop'; bd.setAttribute('hidden','hidden');
      document.body.appendChild(bd);

      // Costruzione contenuti in idle
      state.idleId = ric(function(){ try{ fillSections(sc, foot); }catch(_){/* no-op */} });

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
  // Sezioni/menu
  // -----------------------------------------------------
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
    wrap.appendChild(makeList(links)); return wrap;
  }
  function addKV(container, k, v){
    var row = document.createElement('div'); row.className='mbl-kv';
    var kk = document.createElement('div'); kk.className='k'; kk.textContent=k;
    var vv = document.createElement('div'); vv.className='v'; vv.textContent=v;
    row.appendChild(kk); row.appendChild(vv); container.appendChild(row);
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

  function fillSections(scrollContainer, footContainer){
    if (!scrollContainer || !footContainer) return;
    scrollContainer.innerHTML=''; footContainer.innerHTML='';

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
      // Trova registrazione / login
      var registrati = accLinks.find(function(a){ return /registr/i.test(a.href) || /registr/i.test(txt(a)); }) || null;
      var accedi     = accLinks.find(function(a){ return /login|acced/i.test(a.href) || /acced/i.test(txt(a)); }) || null;

      if (registrati || accedi){
        var secW = document.createElement('section'); secW.className='mbl-sec';
        var hW = document.createElement('div'); hW.className='mbl-sec__title'; hW.textContent='Benvenuto'; secW.appendChild(hW);
        buildCtaRow(secW, registrati, accedi);
        scrollContainer.appendChild(secW);
      }
    }

    // Utente: blocco ACCOUNT (saldo/utente) + CTA Ricarica (primaria) + Logout (ghost)
    if (isLogged){
      var secA = document.createElement('section'); secA.className='mbl-sec';
      var hA = document.createElement('div'); hA.className='mbl-sec__title'; hA.textContent='Account'; secA.appendChild(hA);

      var accountBox = document.createElement('div'); accountBox.className='mbl-account';
      if (balEl)   addKV(accountBox, 'Saldo:', txt(balEl));
      if (userEl)  addKV(accountBox, 'Utente:', txt(userEl));
      secA.appendChild(accountBox);

      // CTA: Ricarica (primaria) + Logout (ghost) se presenti
      var ricaricaA = accLinks.find(function(a){ return /ricar/i.test(a.href) || /ricar/i.test(txt(a)); }) || null;
      var logoutA   = accLinks.find(function(a){ return /logout|esci/i.test(a.href) || /logout|esci/i.test(txt(a)); }) || null;

      if (ricaricaA || logoutA) buildCtaRow(secA, ricaricaA, logoutA);

      // Eventuali altre azioni account (Messaggi, Dati utente, ecc.)
      var otherAcc = accLinks.filter(function(a){ return a !== ricaricaA && a !== logoutA; });
      if (otherAcc.length){
        secA.appendChild(makeList(otherAcc));
      }
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

    // Chiudi quando si naviga esternamente al drawer
    window.addEventListener('hashchange', closeDrawer);
    document.addEventListener('click', function(ev){
      if (!state.open) return;
      var dr = qs('#mbl-drawer'); if (!dr) return;
      var a = ev.target.closest && ev.target.closest('a'); if (a && !dr.contains(a)) closeDrawer();
    });
  }

  if (document.readyState === 'loading'){ document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
