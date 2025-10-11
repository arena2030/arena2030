/*! Arena Mobile JS — leggero e robusto.
    - Inietta hamburger a destra in .hdr__bar se manca (guest e utente)
    - Drawer destro (costruito dal DOM): account, navigazione, info
    - Accessibilità: role=dialog, focus trap, Esc, backdrop
    - Scroll-lock sul body
    - Tabelle→card, avatar click bridge, movimenti bridge
    - Marcature CSS-only per torneo flash
*/
(function(){
  'use strict';

  var MQL = '(max-width: 768px)';
  function isMobile(){ try{ return window.matchMedia ? window.matchMedia(MQL).matches : (window.innerWidth||0)<=768; }catch(_){ return (window.innerWidth||0)<=768; } }
  function onReady(fn){ if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn); else fn(); }
  function q(s,r){ return (r||document).querySelector(s); }
  function qa(s,r){ return Array.prototype.slice.call((r||document).querySelectorAll(s)); }
  function txt(el){ return (el && (el.textContent||'').trim()) || ''; }
  function debounce(fn,ms){ var t=null; return function(){ var a=arguments; clearTimeout(t); t=setTimeout(function(){ fn.apply(null,a); }, ms||90); }; }

  /* ---------- page markers ---------- */
  function ensurePageData(){
    var b=document.body; if(!b) return;
    if(!b.dataset.page){
      var pg = (location.pathname.split('/').pop() || '').replace(/\.php$/,'') || 'index';
      b.dataset.page = pg;
    }
    if(!b.dataset.path){ b.dataset.path = location.pathname.replace(/^\/+/,''); }
  }

  /* =====================================================
     DRAWER
     ===================================================== */
  var state = { built:false, open:false, lastActive:null };

  function ensureHamburger(){
    if (!isMobile()) return;
    var bar = q('.hdr__bar'); if (!bar) return;

    // se c'è già, non duplicare
    if (q('#mbl-trigger', bar)) return;

    var btn = document.createElement('button');
    btn.id = 'mbl-trigger';
    btn.type = 'button';
    btn.setAttribute('aria-label','Apri menu');
    btn.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';

    var grp = q('.hdr__right', bar) || q('.hdr__nav', bar);
    if (grp) grp.insertAdjacentElement('afterend', btn); else bar.appendChild(btn);

    btn.addEventListener('click', function(){
      if (!state.built) ensureDrawer();
      toggleDrawer();
    });
  }

  function ensureDrawer(){
    if (state.built) return;
    if (!isMobile()) return;

    var dr = document.createElement('aside');
    dr.id = 'mbl-drawer';
    dr.setAttribute('role','dialog');
    dr.setAttribute('aria-modal','true');
    dr.setAttribute('aria-hidden','true');
    dr.tabIndex = -1;

    // head
    var head = document.createElement('div'); head.className = 'mbl-head';
    var title = document.createElement('div'); title.className = 'mbl-title'; title.textContent = 'Menu';
    var close = document.createElement('button'); close.className='mbl-close'; close.type='button';
    close.setAttribute('aria-label','Chiudi');
    close.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    head.appendChild(title); head.appendChild(close);

    var sc = document.createElement('div'); sc.className='mbl-scroll';
    dr.appendChild(head); dr.appendChild(sc);

    var bd = document.createElement('div'); bd.id='mbl-backdrop'; bd.setAttribute('hidden','hidden');

    document.body.appendChild(dr); document.body.appendChild(bd);

    // contenuti
    fillDrawer(sc);

    // bind
    close.addEventListener('click', closeDrawer);
    bd.addEventListener('click', closeDrawer);
    dr.addEventListener('keydown', function(ev){
      if (ev.key === 'Escape') { ev.preventDefault(); closeDrawer(); return; }
      if (ev.key === 'Tab') { trapFocus(ev, dr); }
    });

    state.built = true;
  }

  function sectionTitle(t){ var h=document.createElement('div'); h.className='mbl-sec__title'; h.textContent=t; return h; }
  function makeList(links){
    var ul=document.createElement('ul'); ul.className='mbl-list';
    links.forEach(function(a){
      var li=document.createElement('li');
      var cp=a.cloneNode(true);
      cp.removeAttribute('id'); cp.addEventListener('click', closeDrawer);
      li.appendChild(cp); ul.appendChild(li);
    });
    return ul;
  }
  function gather(sel){
    return qa(sel).filter(function(a){
      return a && a.tagName==='A' && (a.href||'').length>0 && txt(a)!=='';
    });
  }

  function addKV(container,k,v){
    var row=document.createElement('div'); row.className='mbl-kv';
    var kk=document.createElement('div'); kk.className='k'; kk.textContent=k;
    var vv=document.createElement('div'); vv.className='v'; vv.textContent=v;
    row.appendChild(kk); row.appendChild(vv); container.appendChild(row);
    return row;
  }

  function fillDrawer(sc){
    sc.innerHTML='';

    // blocco ACCOUNT (se utente loggato)
    var userEl = q('.hdr__usr');
    var balanceEl = q('.pill-balance .ac');
    var accLinks  = userEl ? gather('.hdr__right a') : gather('.hdr__nav a'); // user → right, guest → nav
    var navLinks  = gather('.subhdr .subhdr__menu a');
    var infoLinks = gather('.site-footer .footer-menu a');

    if (userEl || accLinks.length){
      var secA=document.createElement('section'); secA.className='mbl-sec';
      secA.appendChild(sectionTitle('Account'));

      // dati
      var box=document.createElement('div'); box.className='mbl-account';
      // ArenaCoins
      var coinsTxt = balanceEl ? txt(balanceEl) : '';
      var rowCoins = addKV(box, 'ArenaCoins:', coinsTxt || '—');
      // refresh accanto
      var refresh=document.createElement('button'); refresh.type='button'; refresh.className='refresh';
      refresh.setAttribute('aria-label','Aggiorna saldo');
      refresh.innerHTML='<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12a8 8 0 1 1 2.34 5.66M4 12H2m0 0V8m20 4a8 8 0 0 0-13.66-5.66" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/></svg>';
      refresh.addEventListener('click', function(){
        // dispatch evento usato nel progetto per aggiornare il saldo (se esiste)
        try{ document.dispatchEvent(new CustomEvent('refresh-balance')); }catch(_){}
        // sync dal pill-balance dopo un attimo
        setTimeout(function(){
          var b = q('.pill-balance .ac'); if(!b) return;
          rowCoins.querySelector('.v').textContent = txt(b);
        }, 400);
      });
      rowCoins.appendChild(refresh);

      // Utente (con “avatar” — se hai un cerchio con lettera lo copiamo come testo)
      var username = txt(userEl) || (accLinks.find(function(a){ return /profil|dati-utente/i.test((a.href||'')+txt(a)); }) ? txt(userEl) : '');
      addKV(box, 'Utente:', username || '—');
      secA.appendChild(box);

      // CTA: ricarica e logout se presenti
      var ricarica = accLinks.find(function(a){ return /ricar/i.test((a.href||'')+txt(a)); });
      var logout   = accLinks.find(function(a){ return /logout|esci/i.test((a.href||'')+txt(a)); });
      if (ricarica || logout){
        var row=document.createElement('div'); row.className='mbl-ctaRow';
        if (ricarica){ var p=ricarica.cloneNode(true); p.classList.add('mbl-cta'); p.addEventListener('click', closeDrawer); row.appendChild(p); }
        if (logout){ var g=logout.cloneNode(true); g.classList.add('mbl-ghost'); g.addEventListener('click', closeDrawer); row.appendChild(g); }
        secA.appendChild(row);
      }

      // Eventuali altre azioni (Messaggi, ecc.)
      var others = accLinks.filter(function(a){ return a!==ricarica && a!==logout; });
      if (others.length){
        secA.appendChild(makeList(others));
      }
      sc.appendChild(secA);
    } else {
      // guest: CTA registrati + accedi
      var guest=document.createElement('section'); guest.className='mbl-sec';
      guest.appendChild(sectionTitle('Benvenuto'));
      var reg = accLinks.find(function(a){ return /registr/i.test((a.href||'')+txt(a));});
      var log = accLinks.find(function(a){ return /login|acced/i.test((a.href||'')+txt(a));});
      var row=document.createElement('div'); row.className='mbl-ctaRow';
      if (reg){ var p=reg.cloneNode(true); p.classList.add('mbl-cta'); p.addEventListener('click', closeDrawer); row.appendChild(p); }
      if (log){ var g=log.cloneNode(true); g.classList.add('mbl-ghost'); g.addEventListener('click', closeDrawer); row.appendChild(g); }
      guest.appendChild(row); sc.appendChild(guest);
    }

    // Navigazione (subheader)
    if (navLinks.length){
      var secN=document.createElement('section'); secN.className='mbl-sec';
      secN.appendChild(sectionTitle('Navigazione'));
      secN.appendChild(makeList(navLinks));
      sc.appendChild(secN);
    }

    // Info (footer)
    if (infoLinks.length){
      var secI=document.createElement('section'); secI.className='mbl-sec';
      secI.appendChild(sectionTitle('Info'));
      secI.appendChild(makeList(infoLinks));
      sc.appendChild(secI);
    }
  }

  function getFocusable(root){
    var sel = ['a[href]','button:not([disabled])','input:not([disabled])','select:not([disabled])','textarea:not([disabled])','[tabindex]:not([tabindex="-1"])'].join(',');
    return qa(sel, root).filter(function(el){ return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length); });
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
    var dr=q('#mbl-drawer'), bd=q('#mbl-backdrop'), tg=q('#mbl-trigger'); if(!dr||!bd) return;
    state.lastActive = document.activeElement || tg;
    document.documentElement.classList.add('mbl-lock'); document.body.classList.add('mbl-lock');
    bd.removeAttribute('hidden'); bd.classList.add('mbl-open'); dr.classList.add('mbl-open'); dr.setAttribute('aria-hidden','false'); if(tg) tg.setAttribute('aria-expanded','true');
    var f = getFocusable(dr); (f[0]||dr).focus({preventScroll:true});
    state.open = true;
  }
  function closeDrawer(){
    var dr=q('#mbl-drawer'), bd=q('#mbl-backdrop'), tg=q('#mbl-trigger'); if(!dr||!bd) return;
    dr.classList.remove('mbl-open'); dr.setAttribute('aria-hidden','true'); bd.classList.remove('mbl-open'); bd.setAttribute('hidden','hidden');
    document.documentElement.classList.remove('mbl-lock'); document.body.classList.remove('mbl-lock');
    if(tg) tg.setAttribute('aria-expanded','false');
    var back = state.lastActive || tg; if(back && back.focus) back.focus({preventScroll:true});
    state.open = false;
  }
  function toggleDrawer(){ if (state.open) closeDrawer(); else openDrawer(); }

  /* =====================================================
     Altri adattamenti
     ===================================================== */
  // Tabelle → card (aggiunge data-th)
  function labelTables(){
    if (!isMobile()) return;
    qa('table, .table').forEach(function(tb){
      var th = qa('thead th', tb).map(function(x){ return txt(x); });
      if (!th.length) return;
      qa('tbody tr', tb).forEach(function(tr){
        qa('td', tr).forEach(function(td,i){
          if (!td.hasAttribute('data-th')) td.setAttribute('data-th', th[i] || '');
        });
      });
    });
  }

  // Avatar: click = stessa pagina desktop (dati-utente/profilo)
  function bindAvatarClick(){
    var usr = q('.hdr__usr'); if (!usr) return;
    if (usr._mblBound) return;
    var link = usr.querySelector('a[href]') || q('a[href*="dati-utente"]') || q('a[href*="profilo"]');
    if (link){
      usr.style.cursor='pointer';
      usr.addEventListener('click', function(){ location.href = link.href; });
      usr._mblBound = true;
    }
  }

  // Bridge “Lista movimenti”: su mobile richiama modal se esiste, altrimenti pagina /movimenti.php
  function bindMovimentiBridge(){
    qa('a').forEach(function(a){
      if(a._mblMov) return;
      var t = txt(a).toLowerCase();
      if (t.indexOf('moviment') !== -1){
        a.addEventListener('click', function(ev){
          if (!isMobile()) return;
          var trigger = q('[data-open="movimenti"], [data-modal="movimenti"], .js-open-movimenti, .open-movimenti');
          if (trigger){ ev.preventDefault(); trigger.click(); }
          else if (!/\/movimenti\.php$/.test(a.getAttribute('href')||'')){
            a.setAttribute('href','/movimenti.php'); /* fallback pagina */
          }
        });
        a._mblMov = true;
      }
    });
  }

  // Torneo Flash: KPI 2×2 e bottoni sotto l’ovale (solo marcature CSS)
  function markFlashHero(){
    if (!/\/flash\/torneo\.php/i.test(location.pathname) || !isMobile()) return;

    var buy = qa('a,button').find(function(b){ return /acquista/i.test(txt(b)); });
    var hero = buy ? (buy.closest('.card, .tcard, .panel, .box, [class*="card"]') || buy.closest('section')) : null;
    if (!hero) return;

    var labs = ['Montepremi','Partecipanti','Vite','Lock'];
    var kpis = [];
    qa('*', hero).forEach(function(el){
      var t = txt(el).toLowerCase();
      if(!t) return;
      if (labs.some(function(L){ return t.indexOf(L.toLowerCase())===0; })){
        var box = el.closest('div'); if(box && kpis.indexOf(box)===-1) kpis.push(box);
      }
    });
    if (kpis.length){
      var parent = kpis[0].parentElement;
      if (parent){ parent.classList.add('mbl-kpi-grid'); kpis.forEach(function(b){ b.classList.add('mbl-kpi'); }); }
    }
    [buy].concat( qa('a,button', hero).filter(function(b){ return /disiscr/i.test(txt(b)); }) )
        .filter(Boolean).forEach(function(b){ b.classList.add('mbl-fix-static'); });
  }

  function markFlashEvents(){
    if (!/\/flash\/torneo\.php/i.test(location.pathname) || !isMobile()) return;

    var all = qa('a,button').filter(function(x){ return /^(casa|pareggio|trasferta)$/i.test(txt(x)); });
    if (!all.length) return;

    function findEventContainerFromButton(btn){
      var p = btn.parentElement;
      while(p && p!==document.body){
        var count = qa('a,button', p).filter(function(x){ return /^(casa|pareggio|trasferta)$/i.test(txt(x)); }).length;
        if (count >= 2) return p;
        p = p.parentElement;
      }
      return null;
    }

    all.forEach(function(b){
      var cont = findEventContainerFromButton(b); if(!cont) return;
      cont.classList.add('mbl-event');
      b.classList.add('mbl-bet');
      var oval = qa(':scope > *', cont).find(function(el){
        if (el === b) return false;
        if (el.tagName === 'A' || el.tagName === 'BUTTON') return false;
        if (qa('img', el).length >= 1) return true;
        if (txt(el).length >= 5) return true;
        return false;
      });
      if (oval) oval.classList.add('mbl-oval');
    });
  }

  /* ---------- init ---------- */
  function init(){
    ensurePageData();
    ensureHamburger();
    ensureDrawer();
    bindAvatarClick();
    bindMovimentiBridge();
    labelTables();
    markFlashHero();
    markFlashEvents();
  }

  onReady(init);
  window.addEventListener('resize', debounce(function(){
    ensurePageData();
    ensureHamburger();
    labelTables();
    markFlashHero();
    markFlashEvents();
  }, 180));

})();
