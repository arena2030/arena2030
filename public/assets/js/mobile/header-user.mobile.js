/*! Header User (mobile) — hamburger destro + drawer
    - Aggiunge: icona messaggi (se esiste link), CTA Ricarica, avatar+username e hamburger
    - Drawer: Account (ArenaCoins con refresh mini + Utente + CTA), Navigazione, Info
    - Rimuove eventuali elementi guest per evitare doppio hamburger
*/
(function(){
  'use strict';

  // ------- Utils -------
  var mm = (window.matchMedia && window.matchMedia('(max-width: 768px)')) || null;
  function isMobile(){ return mm ? mm.matches : (window.innerWidth||0) <= 768; }
  function qs(s, r){ return (r||document).querySelector(s); }
  function qsa(s, r){ return Array.prototype.slice.call((r||document).querySelectorAll(s)); }
  function txt(el){ return (el && (el.textContent||'').trim()) || ''; }
  function contains(el, s){ return txt(el).toLowerCase().indexOf(s) !== -1; }

  // SVG icone inline
  function ico(n){
    if (n==='hamb') return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    if (n==='close') return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M6 18L18 6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    if (n==='msg') return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a4 4 0 0 1-4 4H9l-6 3 2-5a4 4 0 0 1-2-2V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4v8Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    if (n==='refresh') return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12a9 9 0 1 1-2.64-6.36" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M21 5v5h-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    return '';
  }

  // ------- Build header (user) -------
  var built = false, open = false, lastFocus = null;

  function cleanupGuest(){
    qsa('.mbl-guest-btn').forEach(function(n){ n.remove(); });
    var gd = qs('#mbl-guestDrawer'); if (gd) gd.remove();
    var gb = qs('#mbl-guestBackdrop'); if (gb) gb.remove();
  }

  function getUserName(){
    var u = qs('.hdr__usr');
    // se c'è <img> + testo, prendo solo il testo
    var t = txt(u);
    return t || '';
  }
  function getAvatarNode(){
    var u = qs('.hdr__usr'); if (!u) return null;
    var img = u.querySelector('img'); 
    if (img) return img.cloneNode(true);
    // fallback: iniziale
    var s = document.createElement('span'); s.textContent = (getUserName().charAt(0)||'U').toUpperCase();
    return s;
  }
  function getCoinsText(){
    var el = qs('.pill-balance .ac');
    return el ? txt(el) : '—';
  }
  function findLink(re){
    // cerca tra i link della .hdr__right
    var links = qsa('.hdr__right a');
    return links.find(function(a){
      return re.test(a.href) || re.test(txt(a));
    }) || null;
  }

  function buildHeader(){
    if (built) return;
    var bar = qs('.hdr__bar'); var right = qs('.hdr__right');
    if (!isMobile() || !bar || !right) return;

    cleanupGuest(); // evita doppio hamburger

    // cluster destro
    var cluster = document.createElement('div'); cluster.className='mbl-rside';

    // Messaggi (se esiste un link "messaggi")
    var msgA = findLink(/mess/i);
    if (msgA){
      var m = msgA.cloneNode(true); m.className='mbl-rounded-btn mbl-msg-btn'; m.innerHTML = ico('msg'); m.title = txt(msgA)||'Messaggi';
      cluster.appendChild(m);
    }

    // CTA Ricarica (primaria)
    var ricA = findLink(/ricar/i);
    if (ricA){
      var r = ricA.cloneNode(true); r.className='mbl-primary mbl-ric-btn'; r.textContent = txt(ricA) || 'Ricarica';
      cluster.appendChild(r);
    }

    // User pill (avatar + nome) cliccabile come desktop
    var usr = qs('.hdr__usr'); if (usr){
      var um = document.createElement(usr.tagName==='A' ? 'a' : 'div');
      um.className='mbl-usr';
      if (usr.tagName==='A') um.href = usr.href;

      var av = document.createElement('span'); av.className='mbl-ava';
      var avn = getAvatarNode(); if (avn) av.appendChild(avn);
      um.appendChild(av);

      var nm = document.createElement('span'); nm.className='mbl-name'; nm.textContent = getUserName();
      um.appendChild(nm);

      cluster.appendChild(um);
    }

    // Hamburger
    var hb = document.createElement('button');
    hb.type='button'; hb.id='mbl-user-trigger'; hb.className='mbl-rounded-btn'; hb.setAttribute('aria-label','Apri menu');
    hb.setAttribute('aria-controls','mbl-user-drawer'); hb.setAttribute('aria-expanded','false');
    hb.innerHTML = ico('hamb');
    cluster.appendChild(hb);

    bar.appendChild(cluster);

    // Drawer + backdrop
    createDrawer();

    hb.addEventListener('click', toggle);
    hb.addEventListener('keydown', function(e){ if (e.key==='Enter' || e.key===' ') { e.preventDefault(); toggle(); } });

    // Chiudo se cambia media query (torno desktop)
    if (mm){
      var onChange = function(e){ if (!e.matches) { close(); } };
      if (mm.addEventListener) mm.addEventListener('change', onChange);
      else if (mm.addListener) mm.addListener(onChange);
    }

    built = true;
  }

  // ------- Drawer -------
  function createDrawer(){
    if (qs('#mbl-user-drawer')) return;

    var dr = document.createElement('aside');
    dr.id='mbl-user-drawer'; dr.setAttribute('role','dialog'); dr.setAttribute('aria-modal','true'); dr.setAttribute('aria-hidden','true'); dr.tabIndex=-1;

    // head
    var hd = document.createElement('div'); hd.className='mbl-head';
    var tt = document.createElement('div'); tt.className='mbl-title'; tt.textContent='Menu';
    var x  = document.createElement('button'); x.type='button'; x.className='mbl-rounded-btn mbl-close'; x.setAttribute('aria-label','Chiudi menu'); x.innerHTML = ico('close');
    hd.appendChild(tt); hd.appendChild(x);
    dr.appendChild(hd);

    // body
    var sc = document.createElement('div'); sc.className='mbl-scroll';
    // Account
    var secA = document.createElement('section'); secA.className='mbl-sec';
    var tA   = document.createElement('div'); tA.className='mbl-sec__title'; tA.textContent='Account';
    secA.appendChild(tA);

    var rowAC = document.createElement('div'); rowAC.className='mbl-row';
    var k1 = document.createElement('div'); k1.className='k'; k1.textContent='ArenaCoins:';
    var v1 = document.createElement('div'); v1.className='v'; v1.id='mbl-ac-val'; v1.textContent = getCoinsText();
    var rbtn = document.createElement('button'); rbtn.type='button'; rbtn.className='mbl-refresh'; rbtn.setAttribute('aria-label','Aggiorna ArenaCoins'); rbtn.innerHTML = ico('refresh');
    rowAC.appendChild(k1); rowAC.appendChild(v1); rowAC.appendChild(rbtn);
    secA.appendChild(rowAC);

    var rowU = document.createElement('div'); rowU.className='mbl-row';
    var k2 = document.createElement('div'); k2.className='k'; k2.textContent='Utente:';
    var v2 = document.createElement('div'); v2.className='v'; v2.textContent = getUserName();
    rowU.appendChild(k2); rowU.appendChild(v2);
    secA.appendChild(rowU);

    // CTA: ricarica + logout (se esistono)
    var ricA = findLink(/ricar/i); var outA = findLink(/logout|esci/i);
    if (ricA || outA){
      var rowC = document.createElement('div'); rowC.className='mbl-ctaRow';
      if (ricA){ var pr = ricA.cloneNode(true); pr.className='mbl-primary'; pr.textContent = txt(ricA)||'Ricarica'; rowC.appendChild(pr); }
      if (outA){ var gh = outA.cloneNode(true); gh.className='mbl-ghost'; gh.textContent = txt(outA)||'Logout'; rowC.appendChild(gh); }
      secA.appendChild(rowC);
    }
    sc.appendChild(secA);

    // Navigazione (sub-header)
    var secN = document.createElement('section'); secN.className='mbl-sec';
    var tN = document.createElement('div'); tN.className='mbl-sec__title'; tN.textContent='Navigazione';
    secN.appendChild(tN);
    var navLinks = qsa('.subhdr .subhdr__menu a');
    secN.appendChild(makeList(navLinks));
    sc.appendChild(secN);

    // Info (footer)
    var secF = document.createElement('section'); secF.className='mbl-sec';
    var tF = document.createElement('div'); tF.className='mbl-sec__title'; tF.textContent='Info';
    secF.appendChild(tF);
    var infoLinks = qsa('.site-footer .footer-menu a');
    secF.appendChild(makeList(infoLinks));
    sc.appendChild(secF);

    dr.appendChild(sc);

    document.body.appendChild(dr);

    var bd = document.createElement('div'); bd.id='mbl-user-backdrop'; bd.setAttribute('hidden','hidden');
    document.body.appendChild(bd);

    // bind close
    x.addEventListener('click', close);
    bd.addEventListener('click', close);
    dr.addEventListener('keydown', function(e){
      if (e.key === 'Escape'){ e.preventDefault(); close(); }
      if (e.key === 'Tab'){ focusTrap(e, dr); }
    });

    // refresh ArenaCoins (scrape della pagina corrente)
    rbtn.addEventListener('click', refreshCoins);
  }

  function makeList(links){
    var ul = document.createElement('ul'); ul.className='mbl-list';
    links.forEach(function(a){
      if (!a || a.tagName!=='A') return;
      var li = document.createElement('li');
      var cp = a.cloneNode(true);
      cp.removeAttribute('id');
      cp.addEventListener('click', close);
      li.appendChild(cp); ul.appendChild(li);
    });
    return ul;
  }

  // ------- Open/close / focus trap -------
  function focusables(root){
    var sel = ['a[href]','button:not([disabled])','input:not([disabled])','select:not([disabled])','textarea:not([disabled])','[tabindex]:not([tabindex="-1"])'].join(',');
    return qsa(sel, root).filter(function(el){ return !!(el.offsetWidth||el.offsetHeight||el.getClientRects().length); });
  }
  function focusTrap(ev, root){
    var it = focusables(root); if (!it.length) return;
    var f = it[0], l = it[it.length-1];
    if (ev.shiftKey){
      if (document.activeElement===f || !root.contains(document.activeElement)){ ev.preventDefault(); l.focus(); }
    }else{
      if (document.activeElement===l){ ev.preventDefault(); f.focus(); }
    }
  }

  function openDrawer(){
    var dr=qs('#mbl-user-drawer'), bd=qs('#mbl-user-backdrop'), tg=qs('#mbl-user-trigger');
    if (!dr||!bd) return;
    lastFocus = document.activeElement || tg;
    document.documentElement.classList.add('mbl-lock'); document.body.classList.add('mbl-lock');
    bd.removeAttribute('hidden'); bd.classList.add('mbl-open');
    dr.classList.add('mbl-open'); dr.setAttribute('aria-hidden','false');
    if (tg) tg.setAttribute('aria-expanded','true');
    var f = focusables(dr); (f[0]||dr).focus({preventScroll:true});
    open = true;
  }
  function close(){
    var dr=qs('#mbl-user-drawer'), bd=qs('#mbl-user-backdrop'), tg=qs('#mbl-user-trigger');
    if (!dr||!bd) return;
    dr.classList.remove('mbl-open'); dr.setAttribute('aria-hidden','true');
    bd.classList.remove('mbl-open'); bd.setAttribute('hidden','hidden');
    document.documentElement.classList.remove('mbl-lock'); document.body.classList.remove('mbl-lock');
    if (tg) tg.setAttribute('aria-expanded','false');
    var back = lastFocus || tg; if (back && back.focus) back.focus({preventScroll:true});
    open = false;
  }
  function toggle(){ open ? close() : openDrawer(); }

  // ------- Refresh ArenaCoins (scrape) -------
  async function refreshCoins(){
    try{
      var r = await fetch(location.href, { credentials:'same-origin', cache:'no-store' });
      var html = await r.text();
      var doc = new DOMParser().parseFromString(html,'text/html');
      var ac = doc.querySelector('.pill-balance .ac');
      if (ac){
        var v = txt(ac);
        var out = qs('#mbl-ac-val'); if (out) out.textContent = v;
      }
    }catch(_){ /* no-op */ }
  }

  // ------- Init -------
  function init(){
    // esegui solo se c'è header utente (.hdr__right)
    if (!qs('.hdr__right')) return;

    if (isMobile()) buildHeader();

    window.addEventListener('hashchange', close);
    document.addEventListener('click', function(e){
      if (!open) return;
      var dr = qs('#mbl-user-drawer'); if (!dr) return;
      var a = e.target.closest && e.target.closest('a'); if (a && !dr.contains(a)) close();
    });

    if (mm){
      var onChange = function(e){ if (e.matches){ buildHeader(); } else { close(); } };
      if (mm.addEventListener) mm.addEventListener('change', onChange);
      else if (mm.addListener) mm.addListener(onChange);
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
