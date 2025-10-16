/*! Header User – Mobile (hamburger rotondo + drawer destro)
   - Non tocca il desktop
   - Disattiva eventuale hamburger guest quando l’utente è loggato
   - Allinea a destra: Ricarica + Avatar + Username + Hamburger
*/
(function () {
  'use strict';

  // --------------------------------------------------
  // Util
  // --------------------------------------------------
  var isMobile = function(){ return window.matchMedia ? window.matchMedia('(max-width: 768px)').matches : (window.innerWidth||0) <= 768; };
  var qs  = function(s, r){ return (r||document).querySelector(s); };
  var qsa = function(s, r){ return Array.prototype.slice.call((r||document).querySelectorAll(s)); };
  var txt = function(el){ return (el && (el.textContent||'').trim()) || ''; };

  function svg(name){
    if (name==='menu')   return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 6h16M4 12h16M4 18h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    if (name==='x')      return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    if (name==='refresh')return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 12a8 8 0 0 1 14.32-4.906M20 12a8 8 0 0 1-14.32 4.906M18.5 4v4h-4" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    if (name==='msg')    return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M21 15a2 2 0 0 1-2 2H8l-5 3V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v10Z" stroke="currentColor" stroke-width="2" fill="none" stroke-linejoin="round"/></svg>';
    return '';
  }

  // --------------------------------------------------
  // Stato
  // --------------------------------------------------
  var state = {
    built: false,
    open:  false,
    lastActive: null
  };

  // --------------------------------------------------
  // Build Header (solo se loggato e mobile)
  // --------------------------------------------------
  function buildHeader(){
    if (state.built) return;
    if (!isMobile()) return;

    var bar   = qs('.hdr__bar');
    var right = qs('.hdr__right');
    var user  = qs('.hdr__usr');
    if (!bar || !right || !user) return; // non loggato

    // Nascondo eventuale hamburger guest
    var guestBtn = qs('#mbl-guestBtn, .mbl-guest-btn');
    if (guestBtn) guestBtn.style.display = 'none';

    // Avatar (iniziale da username)
    var name = txt(user);
    var initial = name ? name.charAt(0).toUpperCase() : '?';
    if (!qs('#mblUserAvatar', right)){
      var av = document.createElement('span');
      av.id = 'mblUserAvatar';
      av.setAttribute('aria-hidden','true');
      av.textContent = initial;
      right.insertBefore(av, user); // avatar a sinistra della username
    }

    // Hamburger rotondo
    if (!qs('#mblUserBtn', bar)){
      var btn = document.createElement('button');
      btn.id = 'mblUserBtn';
      btn.type = 'button';
      btn.setAttribute('aria-label','Apri menu utente');
      btn.setAttribute('aria-controls','mblUDrawer');
      btn.setAttribute('aria-expanded','false');
      btn.innerHTML = svg('menu');
      right.appendChild(btn); // dopo username
      btn.addEventListener('click', safeOpen, {passive:true});
      btn.addEventListener('keydown', function(e){
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); safeOpen(); }
      });
    }

    // Allineamento a destra: Ricarica + Avatar + Username + Hamburger
    // (il CSS mobile già forza l’allineamento; qui garantisco l’ordine)
    var btnHamb = qs('#mblUserBtn', right);
    if (btnHamb) right.appendChild(btnHamb);

    state.built = true;
  }

  // --------------------------------------------------
  // Drawer
  // --------------------------------------------------
  function ensureDrawer(){
    if (qs('#mblUDrawer')) return;

    // Backdrop
    var bd = document.createElement('div');
    bd.id = 'mblUserBackdrop';
    bd.setAttribute('hidden','hidden');
    document.body.appendChild(bd);

    // Drawer
    var dr = document.createElement('aside');
    dr.id = 'mblUDrawer';
    dr.setAttribute('role','dialog');
    dr.setAttribute('aria-modal','true');
    dr.setAttribute('aria-hidden','true');
    dr.tabIndex = -1;

    // Head
    var head = document.createElement('div');
    head.className = 'mbl-head';
    var ttl = document.createElement('div');
    ttl.className = 'mbl-title';
    ttl.textContent = 'Menu';
    var x = document.createElement('button');
    x.className = 'mbl-close';
    x.type = 'button';
    x.setAttribute('aria-label','Chiudi menu');
    x.innerHTML = svg('x');
    head.appendChild(ttl);
    head.appendChild(x);

    // Body scroll
    var sc = document.createElement('div');
    sc.className = 'mbl-scroll';

    dr.appendChild(head);
    dr.appendChild(sc);
    document.body.appendChild(dr);

    // Bind chiusure
    x.addEventListener('click', close);
    bd.addEventListener('click', close);
    dr.addEventListener('keydown', function(e){
      if (e.key === 'Escape') { e.preventDefault(); close(); }
      if (e.key === 'Tab') trapFocus(e, dr);
    });

    // Riempie le sezioni alla prima apertura
    fillUserSections(sc);
  }

  function fillUserSections(container){
    container.innerHTML = '';

    // Sezione ACCOUNT
    var secA = document.createElement('section');
    secA.className = 'mbl-sec';
    var hA = document.createElement('div');
    hA.className = 'mbl-sec__title';
    hA.textContent = 'Account';
    secA.appendChild(hA);

    // ArenaCoins (valore dal DOM; poi refresh)
    var rowAC = document.createElement('div');
    rowAC.className = 'mbl-kv';
    var kAC = document.createElement('div'); kAC.className = 'k'; kAC.textContent = 'ArenaCoins:';
    var vAC = document.createElement('div'); vAC.className = 'v'; vAC.id = 'mblU_ac'; vAC.textContent = readCoins();
    var rf  = document.createElement('button');
    rf.id = 'mblU_refresh'; rf.type = 'button'; rf.setAttribute('aria-label','Aggiorna ArenaCoins'); rf.innerHTML = svg('refresh');
    rf.addEventListener('click', refreshCoins);
    rowAC.appendChild(kAC); rowAC.appendChild(vAC); rowAC.appendChild(rf);
    secA.appendChild(rowAC);

    // Utente (avatar + username + messaggi a destra)
    var name = txt(qs('.hdr__usr'));
    var rowU = document.createElement('div');
    rowU.className = 'mbl-kv';
    var kU = document.createElement('div'); kU.className = 'k'; kU.textContent = 'Utente:';
    var vU = document.createElement('div'); vU.className = 'v'; vU.id='mblU_name';
    var bubble = document.createElement('span');
    bubble.style.cssText = 'display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:9999px;border:1px solid var(--c-border,#20314b);background:var(--c-bg-2,#0f172a);font-weight:900;margin-right:8px;';
    bubble.textContent = name ? name.charAt(0).toUpperCase() : '?';
    vU.appendChild(bubble);
    vU.appendChild(document.createTextNode(name || ''));
    // Messaggi (se esiste un link messaggi nel DOM, lo clono)
    var msgLink = findMsgLink();
    if (msgLink){
      var btnMsg = msgLink.cloneNode(true);
      btnMsg.innerHTML = svg('msg');
      btnMsg.setAttribute('aria-label','Messaggi');
      btnMsg.style.cssText = 'margin-left:10px;display:inline-flex;width:32px;height:32px;border-radius:9999px;border:1px solid var(--c-border,#20314b);background:var(--c-bg-2,#0f172a);align-items:center;justify-content:center;';
      btnMsg.addEventListener('click', close);
      vU.appendChild(btnMsg);
    }
    rowU.appendChild(kU); rowU.appendChild(vU);
    secA.appendChild(rowU);

    // CTA Ricarica / Logout
    var ctaRow = document.createElement('div'); ctaRow.className = 'mbl-ctaRow';
    var ricarica = findLink(/ricar/i);
    var logout   = findLink(/logout|esci/i);
    if (ricarica){
      var r = ricarica.cloneNode(true); r.classList.add('mbl-cta'); r.addEventListener('click', close);
      ctaRow.appendChild(r);
    }
    if (logout){
      var l = logout.cloneNode(true); l.classList.add('mbl-ghost'); l.addEventListener('click', close);
      ctaRow.appendChild(l);
    }
    secA.appendChild(ctaRow);
    container.appendChild(secA);

    // NAVIGAZIONE (voci del sub-header)
    var nav = gather('.subhdr .subhdr__menu a');
    if (nav.length){
      container.appendChild(section('Navigazione', nav, true));
    }

    // INFO (voci del footer)
    var info = gather('.site-footer .footer-menu a');
    if (info.length){
      container.appendChild(section('Info', info, false));
    }

    // Aggiorna saldo alla prima apertura (best-effort)
    refreshCoins({silent:true});
  }

  function gather(sel){
    return qsa(sel).filter(function(a){
      return a && a.tagName==='A' && (a.href||'').length>0 && txt(a)!==''; 
    }).map(function(a){
      var cp = a.cloneNode(true); cp.removeAttribute('id');
      cp.addEventListener('click', close); return cp;
    });
  }
  function section(title, links){
    var s = document.createElement('section'); s.className = 'mbl-sec';
    var h = document.createElement('div'); h.className = 'mbl-sec__title'; h.textContent = title;
    var ul = document.createElement('ul'); ul.className='mbl-list';
    links.forEach(function(l){ var li=document.createElement('li'); li.appendChild(l); ul.appendChild(li); });
    s.appendChild(h); s.appendChild(ul); return s;
  }
  function findLink(re){
    var all = qsa('.hdr__right a, .hdr a, header a, nav a, a');
    for (var i=0;i<all.length;i++){ if (re.test(all[i].href) || re.test(txt(all[i]))) return all[i]; }
    return null;
  }
  function findMsgLink(){
    // heuristics: link con "messaggi" o icona con aria-label
    var a = qsa('a, button').find(function(n){
      var t = txt(n).toLowerCase();
      var ar = (n.getAttribute('aria-label')||'').toLowerCase();
      var href = (n.getAttribute('href')||'').toLowerCase();
      return /messagg/i.test(t) || /messagg/i.test(ar) || /messagg/.test(href);
    });
    return a || null;
  }

  // --------------------------------------------------
  // Open/Close + focus trap
  // --------------------------------------------------
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

  function open(){
    ensureDrawer();
    var dr = qs('#mblUDrawer'), bd = qs('#mblUserBackdrop'), tg = qs('#mblUserBtn');
    if (!dr || !bd) return;
    state.lastActive = document.activeElement || tg;
    document.documentElement.classList.add('mbl-lock');
    document.body.classList.add('mbl-lock');
    bd.removeAttribute('hidden'); bd.classList.add('mbl-open');
    dr.classList.add('mbl-open'); dr.setAttribute('aria-hidden','false');
    if (tg) tg.setAttribute('aria-expanded','true');
    var f = getFocusable(dr); (f[0]||dr).focus({preventScroll:true});
    state.open = true;
  }
  function close(){
    var dr = qs('#mblUDrawer'), bd = qs('#mblUserBackdrop'), tg = qs('#mblUserBtn');
    if (!dr || !bd) return;
    dr.classList.remove('mbl-open'); dr.setAttribute('aria-hidden','true');
    bd.classList.remove('mbl-open'); bd.setAttribute('hidden','hidden');
    document.documentElement.classList.remove('mbl-lock');
    document.body.classList.remove('mbl-lock');
    if (tg) tg.setAttribute('aria-expanded','false');
    var back = state.lastActive || tg; if (back && back.focus) back.focus({preventScroll:true});
    state.open = false;
  }
  function toggle(){ state.open ? close() : open(); }

  function safeOpen(e){
    if (e) e.preventDefault();
    try{ open(); }catch(_){ /* no-op */ }
  }

  // --------------------------------------------------
  // Saldo
  // --------------------------------------------------
  function readCoins(){
    // legge un valore presente nel DOM (pill-balance .ac) oppure "--"
    var pill = qs('.pill-balance .ac') || qs('.hdr__balance .ac') || null;
    if (!pill) return '--';
    var v = txt(pill).replace(/[^\d.,]/g,'').replace(',', '.');
    return v || '--';
  }

  function refreshCoins(opts){
    opts = opts || {};
    // Best effort: endpoint già esistente in premi.php?action=me
    var url = '/premi.php?action=me&_=' + Date.now();
    fetch(url, {credentials:'same-origin'})
      .then(function(r){ return r.json().catch(function(){ return null; }); })
      .then(function(j){
        var v = null;
        if (j && j.ok && j.me && typeof j.me.coins !== 'undefined'){ v = Number(j.me.coins).toFixed(2); }
        if (!v){ v = readCoins(); }
        var out = qs('#mblU_ac'); if (out) out.textContent = v;
        // Aggiorna eventuale pill nel DOM se presente
        var pill = qs('.pill-balance .ac'); if (pill) pill.textContent = v;
        if (!opts.silent) animatePulse('#mblU_ac');
      })
      .catch(function(){ /* fallback silenzioso */ });
  }
  function animatePulse(sel){
    var el = qs(sel); if (!el) return;
    el.style.transition='transform .15s ease'; el.style.transform='scale(1.05)';
    setTimeout(function(){ el.style.transform=''; }, 150);
  }

  // --------------------------------------------------
  // Bootstrap
  // --------------------------------------------------
  function boot(){
    if (!isMobile()) return;
    if (!qs('.hdr__usr')) return; // non loggato: questo file resta inattivo

    // Costruzione header
    buildHeader();

    // Listener su hamburger
    var hb = qs('#mblUserBtn');
    if (hb){
      hb.removeEventListener('click', toggle);
      hb.addEventListener('click', toggle, {passive:true});
    }

    // Sicurezza: se c’è un guest-hamburger visibile, lo nascondo
    var guestBtn = qs('#mbl-guestBtn, .mbl-guest-btn');
    if (guestBtn) guestBtn.style.display = 'none';
  }

  // Avvio
  if (document.readyState === 'loading'){ document.addEventListener('DOMContentLoaded', boot); }
  else { boot(); }

  // Aggiorna su resize media query
  if (window.matchMedia){
    var mql = window.matchMedia('(max-width: 768px)');
    var onChange = function(e){ if (e.matches){ boot(); } };
    if (mql.addEventListener) mql.addEventListener('change', onChange);
    else if (mql.addListener) mql.addListener(onChange);
  }

})();
