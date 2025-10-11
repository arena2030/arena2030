/*! Header User – Mobile
   - Unico hamburger (disattiva quello guest se presente)
   - Header: [Messaggi] [Ricarica] [Avatar+username] [Hamburger]
   - Drawer: Account (ArenaCoins con refresh vicino, Utente con avatar + icona messaggi), Nav, Info, CTA (Ricarica/Logout)
*/
(function(){
  'use strict';

  var isMobile = function(){
    return (window.matchMedia ? window.matchMedia('(max-width: 768px)').matches : (window.innerWidth||0) <= 768);
  };
  var qs  = function(s, r){ return (r||document).querySelector(s); };
  var qsa = function(s, r){ return Array.prototype.slice.call((r||document).querySelectorAll(s)); };
  var on  = function(el,ev,fn){ el && el.addEventListener(ev,fn,{passive:false}); };
  var ric = window.requestIdleCallback || function(cb){ return setTimeout(cb,0); };

  // SVG inline
  function svg(name){
    if (name==='hamb'){
      return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    }
    if (name==='close'){
      return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    }
    if (name==='msg'){
      return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a4 4 0 0 1-4 4H9l-4 3v-3H5a4 4 0 0 1-4-4V7a4 4 0 0 1 4-4h12a4 4 0 0 1 4 4v8Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>';
    }
    if (name==='refresh'){
      return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4v6h6M20 20v-6h-6" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round"/><path d="M20 10A8 8 0 0 0 4 10M4 14a8 8 0 0 0 16 0" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round"/></svg>';
    }
    return '';
  }

  // Disattiva elementi guest se presenti (niente doppio hamburger)
  function killGuest(){
    var gb = qs('#mbl-guestBtn'); if (gb) gb.remove();
    var gd = qs('#mbl-guestDrawer'); if (gd) gd.remove();
    var gbk= qs('#mbl-guestBackdrop'); if (gbk) gbk.remove();
  }

  // Recupera il nome utente + avatar dalle parti esistenti
  function getUserInfo(){
    var u = {name:'', href:'#'};
    var usr = qs('.hdr__usr');
    if (usr){
      var a = qs('a', usr);
      u.name = (a ? (a.textContent||'').trim() : (usr.textContent||'').trim()) || '';
      u.href = (a && a.href) ? a.href : '#';
      // Avatar: se c'è già un cerchio con iniziale, lo cloneremo via JS (testo -> prima lettera)
    }
    return u;
  }

  // Recupera link utili (messaggi, ricarica, logout)
  function findLinkByText(rootSel, needles){
    var links = qsa(rootSel).filter(function(a){ return a && a.tagName==='A'; });
    var rx = new RegExp(needles.join('|'), 'i');
    for (var i=0;i<links.length;i++){
      var t = (links[i].textContent||'') + ' ' + (links[i].href||'');
      if (rx.test(t)) return links[i];
    }
    return null;
  }

  // Legge le AC dall’header (pill), o da #meCoins, o con regex sul testo.
  function readACFromDOM(){
    var el = qs('.pill-balance') || qs('#meCoins') || null;
    if (!el) return null;
    var s = (el.textContent||'').replace(',', '.');
    var m = s.match(/(\d+(?:\.\d+)?)/);
    return m ? parseFloat(m[1]) : null;
  }

  // Mutazione per allineare il valore del drawer quando cambia la pill nell’header
  function balanceObserver(target, onUpdate){
    if (!target || !('MutationObserver' in window)) return;
    var mo = new MutationObserver(function(){ onUpdate(readACFromDOM()); });
    mo.observe(target, {subtree:true, childList:true, characterData:true});
  }

  // Costruzione header mobile utente (cluster a destra + hamburger)
  function buildHeader(){
    var bar = qs('.hdr__bar'); if (!bar) return;

    // Cluster destro (se non esiste)
    var cluster = qs('.mbl-u-right', bar);
    if (!cluster){
      cluster = document.createElement('div'); cluster.className='mbl-u-right';
      bar.appendChild(cluster);
    }

    // Messaggi (se esiste link nei controlli utente)
    var msgLink = findLinkByText('.hdr__right a', ['messagg']); // "Messaggi"
    if (msgLink && !qs('.mbl-u-msgBtn', cluster)){
      var mbtn = document.createElement('a');
      mbtn.className='mbl-u-msgBtn';
      mbtn.href = msgLink.href;
      mbtn.setAttribute('aria-label','Messaggi');
      mbtn.innerHTML = svg('msg');
      cluster.appendChild(mbtn);
    }

    // Sposta Ricarica dal .hdr__right dentro il cluster (non cloniamo: lo spostiamo così non resta doppio)
    var ricarica = findLinkByText('.hdr__right a, .hdr__right button', ['ricar']);
    if (ricarica && !cluster.contains(ricarica)){
      cluster.appendChild(ricarica);
      ricarica.classList.add('mbl-moved-ricarica');
    }

    // Avatar + username
    var info = getUserInfo();
    if (info.name && !qs('.mbl-u-usr', cluster)){
      var uwrap = document.createElement('a');
      uwrap.href = info.href || '#';
      uwrap.className = 'mbl-u-usr';
      var ava = document.createElement('span'); ava.className='ava'; ava.textContent = (info.name||'?').trim().charAt(0).toUpperCase();
      var nm  = document.createElement('span'); nm.className='name'; nm.textContent = info.name;
      uwrap.appendChild(ava); uwrap.appendChild(nm);
      cluster.appendChild(uwrap);
    }

    // Hamburger a destra (rotondo)
    if (!qs('#mbl-userBtn', bar)){
      var btn = document.createElement('button');
      btn.id = 'mbl-userBtn'; btn.type='button'; btn.className='mbl-u-icon';
      btn.setAttribute('aria-label','Apri menu');
      btn.setAttribute('aria-controls','mbl-userDrawer');
      btn.setAttribute('aria-expanded','false');
      btn.innerHTML = svg('hamb');
      cluster.appendChild(btn);
      on(btn,'click', toggleDrawer);
      on(btn,'keydown', function(e){ if (e.key==='Enter'||e.key===' ') { e.preventDefault(); toggleDrawer(); }});
    }
  }

  // Drawer contenuti
  function buildDrawer(){
    if (qs('#mbl-userDrawer')) return;

    var dr = document.createElement('aside');
    dr.id='mbl-userDrawer'; dr.setAttribute('role','dialog'); dr.setAttribute('aria-modal','true'); dr.setAttribute('aria-hidden','true'); dr.tabIndex=-1;

    var head = document.createElement('div'); head.className='mbl-head';
    var ttl  = document.createElement('div'); ttl.className='mbl-title'; ttl.textContent='Menu';
    var x = document.createElement('button'); x.type='button'; x.className='mbl-close'; x.setAttribute('aria-label','Chiudi menu'); x.innerHTML = svg('close');
    head.appendChild(ttl); head.appendChild(x); dr.appendChild(head);

    var sc = document.createElement('div'); sc.className='mbl-scroll'; dr.appendChild(sc);

    // Account
    var secA = document.createElement('section'); secA.className='mbl-sec';
    var tA = document.createElement('div'); tA.className='mbl-sec__title'; tA.textContent='Account';
    secA.appendChild(tA);

    // ArenaCoins con refresh vicino al numero
    var rowCoins = document.createElement('div'); rowCoins.className='mbl-kv';
    var kC = document.createElement('div'); kC.className='k'; kC.textContent='ArenaCoins:';
    var vC = document.createElement('div'); vC.className='v'; vC.id='mbl-coins-val'; vC.textContent='—';
    var afterC = document.createElement('div'); afterC.className='after';
    var btnRef = document.createElement('button'); btnRef.type='button'; btnRef.className='mbl-ref'; btnRef.setAttribute('aria-label','Aggiorna ArenaCoins'); btnRef.innerHTML=svg('refresh');
    afterC.appendChild(btnRef);
    rowCoins.appendChild(kC); rowCoins.appendChild(vC); rowCoins.appendChild(afterC);
    secA.appendChild(rowCoins);

    // Utente: avatar + nome + messaggi a destra
    var info = getUserInfo();
    var rowUser = document.createElement('div'); rowUser.className='mbl-userRow';
    var ava = document.createElement('span'); ava.className='ava'; ava.textContent=(info.name||'?').charAt(0).toUpperCase();
    var nm  = document.createElement('a');   nm.className='name'; nm.href=info.href||'#'; nm.textContent=info.name||'';
    var msgLink = findLinkByText('.hdr__right a', ['messagg']);
    var msgBtn = null;
    if (msgLink){
      msgBtn = document.createElement('a'); msgBtn.href=msgLink.href; msgBtn.className='msgBtn'; msgBtn.setAttribute('aria-label','Messaggi'); msgBtn.innerHTML = svg('msg');
    }
    rowUser.appendChild(ava); rowUser.appendChild(nm); if (msgBtn) rowUser.appendChild(msgBtn);
    secA.appendChild(rowUser);

    // CTA Ricarica / Logout (stessa altezza)
    var accLinks = qsa('.hdr__right a');
    var lRicarica = findLinkByText('.hdr__right a', ['ricar']);
    var lLogout   = findLinkByText('.hdr__right a', ['logout','esci']);
    var ctas = document.createElement('div'); ctas.className='mbl-ctaRow';
    if (lRicarica){ var a1=lRicarica.cloneNode(true); a1.classList.add('mbl-cta'); ctas.appendChild(a1); }
    if (lLogout){ var a2=lLogout.cloneNode(true); a2.classList.add('mbl-ghost'); ctas.appendChild(a2); }
    if (ctas.children.length) secA.appendChild(ctas);

    sc.appendChild(secA);

    // Navigazione dal sub-header
    var navLinks = qsa('.subhdr .subhdr__menu a');
    if (navLinks.length){
      var secN = document.createElement('section'); secN.className='mbl-sec';
      var tN = document.createElement('div'); tN.className='mbl-sec__title'; tN.textContent='Navigazione'; secN.appendChild(tN);
      var ulN = document.createElement('ul'); ulN.className='mbl-list';
      navLinks.forEach(function(a){ var li=document.createElement('li'); var cp=a.cloneNode(true); cp.addEventListener('click', closeDrawer); li.appendChild(cp); ulN.appendChild(li); });
      secN.appendChild(ulN); sc.appendChild(secN);
    }

    // Info dal footer
    var infoLinks = qsa('.site-footer .footer-menu a');
    if (infoLinks.length){
      var secI = document.createElement('section'); secI.className='mbl-sec';
      var tI = document.createElement('div'); tI.className='mbl-sec__title'; tI.textContent='Info'; secI.appendChild(tI);
      var ulI = document.createElement('ul'); ulI.className='mbl-list';
      infoLinks.forEach(function(a){ var li=document.createElement('li'); var cp=a.cloneNode(true); cp.addEventListener('click', closeDrawer); li.appendChild(cp); ulI.appendChild(li); });
      secI.appendChild(ulI); sc.appendChild(secI);
    }

    document.body.appendChild(dr);

    // Backdrop
    var bd = document.createElement('div'); bd.id='mbl-userBackdrop'; bd.setAttribute('hidden','hidden');
    document.body.appendChild(bd);

    // Eventi
    on(x,'click', closeDrawer);
    on(bd,'click', closeDrawer);
    on(dr,'keydown', function(e){
      if (e.key==='Escape'){ e.preventDefault(); closeDrawer(); }
      if (e.key==='Tab'){ trapFocus(e, dr); }
    });

    // Inizializza valore AC + observer + refresh
    updateCoinsText(readACFromDOM());
    balanceObserver(qs('.pill-balance') || qs('#meCoins') || null, updateCoinsText);

    on(btnRef,'click', function(){
      // 1) prova a leggere via endpoint se esiste su questa pagina (/premi.php?action=me)
      var setFrom = function(val){ if (typeof val==='number') updateCoinsText(val); else updateCoinsText(readACFromDOM()); };
      var tryFetch = function(url){
        return fetch(url, {credentials:'same-origin', headers:{'Accept':'application/json'}})
          .then(function(r){ return r.ok ? r.json() : Promise.reject(); })
          .then(function(j){ 
            var v = (j && j.ok && j.me && (j.me.coins!=null)) ? parseFloat(j.me.coins) : null;
            setFrom(v);
          });
      };
      tryFetch(location.pathname + (location.search ? location.search+'&' : '?') + 'action=me')
      .catch(function(){ return tryFetch('/premi.php?action=me'); })
      .catch(function(){ setFrom(null); });
    });
  }

  function updateCoinsText(val){
    var out = qs('#mbl-coins-val'); if (!out) return;
    if (typeof val === 'number' && !isNaN(val)) out.textContent = val.toFixed(2);
    else out.textContent = (readACFromDOM()||0).toFixed(2);
  }

  // Apertura/chiusura + focus trap + scroll-lock
  function getFocusable(root){
    var sel=['a[href]','button:not([disabled])','input:not([disabled])','select:not([disabled])','textarea:not([disabled])','[tabindex]:not([tabindex="-1"])'].join(',');
    return qsa(sel, root).filter(function(el){ return !!(el.offsetWidth||el.offsetHeight||el.getClientRects().length); });
  }
  var lastActive=null;
  function openDrawer(){
    var dr=qs('#mbl-userDrawer'), bd=qs('#mbl-userBackdrop'), tg=qs('#mbl-userBtn'); if(!dr||!bd) return;
    lastActive = document.activeElement || tg;
    document.documentElement.classList.add('mbl-lock'); document.body.classList.add('mbl-lock');
    bd.removeAttribute('hidden'); bd.classList.add('mbl-open'); dr.classList.add('mbl-open'); dr.setAttribute('aria-hidden','false'); if(tg) tg.setAttribute('aria-expanded','true');
    var f=getFocusable(dr); (f[0]||dr).focus({preventScroll:true});
  }
  function closeDrawer(){
    var dr=qs('#mbl-userDrawer'), bd=qs('#mbl-userBackdrop'), tg=qs('#mbl-userBtn'); if(!dr||!bd) return;
    dr.classList.remove('mbl-open'); dr.setAttribute('aria-hidden','true'); bd.classList.remove('mbl-open'); bd.setAttribute('hidden','hidden');
    document.documentElement.classList.remove('mbl-lock'); document.body.classList.remove('mbl-lock');
    if(tg) tg.setAttribute('aria-expanded','false');
    var back=lastActive||tg; if(back && back.focus) back.focus({preventScroll:true});
  }
  function toggleDrawer(){ if (qs('#mbl-userDrawer')?.classList.contains('mbl-open')) closeDrawer(); else openDrawer(); }
  function trapFocus(ev, root){
    var items=getFocusable(root); if(!items.length) return;
    var first=items[0], last=items[items.length-1];
    if(ev.shiftKey){ if (document.activeElement===first || !root.contains(document.activeElement)){ ev.preventDefault(); last.focus(); } }
    else{ if (document.activeElement===last){ ev.preventDefault(); first.focus(); } }
  }

  // Init
  function init(){
    if (!isMobile()) return;

    // Se c’era il layer guest, rimuovilo per evitare doppio hamburger
    killGuest();

    buildHeader();
    buildDrawer();

    // Chiudi quando clicchi link fuori dal drawer
    on(document,'click', function(e){
      var dr = qs('#mbl-userDrawer');
      if (!dr || !dr.classList.contains('mbl-open')) return;
      var a = e.target.closest && e.target.closest('a');
      if (a && !dr.contains(a)) closeDrawer();
    });

    // Riadatta su resize oltre il breakpoint
    if (window.matchMedia){
      var mql = window.matchMedia('(max-width: 768px)');
      var onChange = function(e){ if (!e.matches) closeDrawer(); };
      if (mql.addEventListener) mql.addEventListener('change', onChange);
      else if (mql.addListener) mql.addListener(onChange);
    }
  }

  if (document.readyState==='loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
