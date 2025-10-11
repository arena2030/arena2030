/*!
 * Arena – Mobile Layer (Right Drawer)
 * - Drawer a destra con hamburger
 * - Guest: CTA Registrati/Login + Navigazione + Info (niente “Account”)
 * - Logged: Account con ArenaCoins (letto dal pill header), Refresh accanto al saldo, Ricarica (primario), Logout (ghost)
 * - Menu costruito dal DOM (no hardcode)
 * - Accessibilità: dialog, focus-trap, aria-expanded, Esc
 * - Scroll-lock durante apertura
 */
(function () {
  'use strict';

  // ---------------- Utils ----------------
  var MQL = '(max-width: 768px)';
  var isMobile = function(){ return (window.matchMedia ? window.matchMedia(MQL).matches : (window.innerWidth||0) <= 768); };
  var qs  = function(s,r){ return (r||document).querySelector(s); };
  var qsa = function(s,r){ return Array.prototype.slice.call((r||document).querySelectorAll(s)); };
  var ric = window.requestIdleCallback || function(cb){ return setTimeout(cb,0); };

  function svg(name){
    if (name==='hamb')   return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    if (name==='close')  return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    if (name==='refresh')return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4v6h6M20 20v-6h-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/><path d="M20 10a8 8 0 0 0-14-4M4 14a8 8 0 0 0 14 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/></svg>';
    if (name==='bell')   return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22a2.5 2.5 0 0 0 2.5-2.5h-5A2.5 2.5 0 0 0 12 22Zm7-6V11a7 7 0 1 0-14 0v5l-2 2h18l-2-2Z" fill="currentColor"/></svg>';
    return '';
  }
  function txt(el){ return (el && (el.textContent||'').trim()) || ''; }
  function parseCoins(str){
    if (!str) return null;
    // Accetta "14.00 AC", "14,00", "14.00 Coins"
    var s = String(str).replace(/[^\d.,-]/g,'').replace(/\.(?=\d{3}\b)/g,''); // togli puntini migliaia
    if (s.indexOf(',')>-1 && s.indexOf('.')>-1) s = s.replace('.', '').replace(',', '.');
    else if (s.indexOf(',')>-1) s = s.replace(',', '.');
    var n = parseFloat(s);
    return isNaN(n) ? null : n;
  }
  function numToAC(n){ return (typeof n==='number' ? n : parseFloat(n||0)).toFixed(2); }

  // ---------------- State ----------------
  var state = {
    built: false,
    open:  false,
    lastActive: null,
    coinsObserver: null
  };

  // ---------------- Build once ----------------
  function buildOnce(){
    if (state.built || !isMobile()) return;

    var bar = qs('.hdr__bar'); if (!bar) return;

    // Trigger hamburger a destra (append)
    if (!qs('#mbl-trigger', bar)){
      var t = document.createElement('button');
      t.id='mbl-trigger';
      t.type='button';
      t.className='mbl-iconbtn';
      t.setAttribute('aria-label','Apri menu');
      t.setAttribute('aria-controls','mbl-drawer');
      t.setAttribute('aria-expanded','false');
      t.innerHTML = svg('hamb');
      bar.appendChild(t);
    }

    // Drawer + backdrop
    if (!qs('#mbl-drawer')){
      var dr = document.createElement('aside');
      dr.id='mbl-drawer';
      dr.setAttribute('role','dialog');
      dr.setAttribute('aria-modal','true');
      dr.setAttribute('aria-hidden','true');
      dr.tabIndex = -1;

      // Head
      var hd = document.createElement('div');
      hd.className='mbl-head';
      var title = document.createElement('div');
      title.className='mbl-title';
      title.textContent='Menu';
      var close = document.createElement('button');
      close.type='button';
      close.className='mbl-close';
      close.setAttribute('aria-label','Chiudi menu');
      close.innerHTML = svg('close'); // “X” chiara e visibile
      hd.appendChild(title);
      hd.appendChild(close);
      dr.appendChild(hd);

      // Body scroll + foot
      var sc = document.createElement('div'); sc.className='mbl-scroll'; dr.appendChild(sc);
      var ft = document.createElement('div'); ft.className='mbl-foot';   dr.appendChild(ft);

      document.body.appendChild(dr);

      var bd = document.createElement('div');
      bd.id='mbl-backdrop';
      bd.setAttribute('hidden','hidden');
      document.body.appendChild(bd);

      // Bind
      close.addEventListener('click', closeDrawer);
      bd.addEventListener('click', closeDrawer);
      dr.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape') { ev.preventDefault(); closeDrawer(); }
        if (ev.key === 'Tab')    { trapFocus(ev, dr); }
      });

      // Riempie sezioni in idle la prima volta
      ric(function(){ fillDrawer(sc, ft); });
    }

    var trig = qs('#mbl-trigger');
    if (trig && !trig._bound){
      trig.addEventListener('click', toggleDrawer);
      trig._bound = true;
    }

    state.built = true;
  }

  // ---------------- Drawer content ----------------
  function gather(selector){
    return qsa(selector).filter(function(a){
      return a && a.tagName === 'A' && (a.href||'').length>0 && txt(a) !== '';
    });
  }
  function makeList(links){
    var ul = document.createElement('ul'); ul.className='mbl-list';
    links.forEach(function(a){
      try{
        var li = document.createElement('li');
        var cp = a.cloneNode(true);
        ['id','onclick','onmousedown','onmouseup','onmouseover','onmouseout'].forEach(function(k){ cp.removeAttribute(k); });
        cp.addEventListener('click', function(){ closeDrawer(); });
        li.appendChild(cp);
        ul.appendChild(li);
      }catch(_){}
    });
    return ul;
  }
  function section(title, links, extra){
    if (!links || !links.length) return null;
    var s = document.createElement('section'); s.className='mbl-sec'+(extra?(' '+extra):'');
    var h = document.createElement('div'); h.className='mbl-sec__title'; h.textContent=title; s.appendChild(h);
    s.appendChild(makeList(links));
    return s;
  }
  function addKV(container, label, value, suffixNode){
    var row = document.createElement('div'); row.className='mbl-kv';
    var k = document.createElement('div'); k.className='k'; k.textContent = label;
    var v = document.createElement('div'); v.className='v'; v.textContent = value;
    row.appendChild(k); row.appendChild(v);
    if (suffixNode){ var s=document.createElement('div'); s.className='kv-suffix'; s.appendChild(suffixNode); row.appendChild(s); }
    container.appendChild(row);
    return {row:row, v:v};
  }
  function buildCtaRow(parent, primaryA, secondaryA){
    var box = document.createElement('div'); box.className='mbl-ctaRow';
    if (primaryA){
      var p = primaryA.cloneNode(true);
      p.classList.add('btn-primary'); p.removeAttribute('id');
      p.addEventListener('click', function(){ closeDrawer(); });
      box.appendChild(p);
    }
    if (secondaryA){
      var s = secondaryA.cloneNode(true);
      s.classList.add('btn-ghost'); s.removeAttribute('id');
      s.addEventListener('click', function(){ closeDrawer(); });
      box.appendChild(s);
    }
    parent.appendChild(box);
  }

  function isLoggedNow(){
    // Heuristics robuste: pill saldo o utente presente o link Logout presente
    return !!(qs('.pill-balance .ac') || qs('.hdr__usr') || qs('.hdr__right a[href*="logout"]'));
  }

  function readCoinsFromHeader(){
    var ac = qs('.pill-balance .ac');
    var n  = parseCoins(ac ? ac.textContent : '');
    return (n==null ? null : n);
  }

  function fillDrawer(scrollContainer, footContainer){
    if (!scrollContainer || !footContainer) return;
    scrollContainer.innerHTML=''; footContainer.innerHTML='';

    var navLinks  = gather('.subhdr .subhdr__menu a');          // Navigazione
    var footLinks = gather('.site-footer .footer-menu a');      // Info footer
    var isLogged  = isLoggedNow();

    // ====== GUEST ======
    if (!isLogged){
      // CTA Registrati (primaria) + Login (ghost) dalla barra ospite
      var guestActions = gather('.hdr__nav a');
      var regA   = guestActions.find(function(a){ return /registr/i.test(a.href) || /registr/i.test(txt(a)); }) || null;
      var loginA = guestActions.find(function(a){ return /login|acced/i.test(a.href) || /acced/i.test(txt(a)); }) || null;

      var secW = document.createElement('section'); secW.className='mbl-sec';
      var hW = document.createElement('div'); hW.className='mbl-sec__title'; hW.textContent='Benvenuto'; secW.appendChild(hW);
      buildCtaRow(secW, regA, loginA);
      scrollContainer.appendChild(secW);
    }

    // ====== LOGGED ======
    if (isLogged){
      var secA = document.createElement('section'); secA.className='mbl-sec mbl-sec--account';

      var hA = document.createElement('div'); hA.className='mbl-sec__title'; hA.textContent='Account';
      secA.appendChild(hA);

      // ArenaCoins con refresh accanto
      var refreshBtn = document.createElement('button');
      refreshBtn.type='button';
      refreshBtn.className='mbl-iconbtn mbl-refresh';
      refreshBtn.setAttribute('aria-label','Aggiorna ArenaCoins');
      refreshBtn.innerHTML = svg('refresh');

      var coins = readCoinsFromHeader();
      var coinsRow = addKV(secA, 'ArenaCoins:', (coins==null ? '—' : numToAC(coins)), refreshBtn);

      // Username con avatar “pallino” se presente in header
      var usrName = txt(qs('.hdr__usr')) || '—';
      var userRow = document.createElement('div'); userRow.className='mbl-userline';
      var avatar  = document.createElement('span'); avatar.className='mbl-avatar';
      // Se in header c'è un'icona/avatar, clonala; altrimenti lettera iniziale
      var headerAvatar = qs('.hdr__right .avatar, .hdr__usr .avatar, .hdr__right .usr-avatar');
      if (headerAvatar) { avatar.appendChild(headerAvatar.cloneNode(true)); }
      else { avatar.textContent = usrName ? usrName.trim().charAt(0).toUpperCase() : 'U'; }
      var uname = document.createElement('span'); uname.className='mbl-uname'; uname.textContent = usrName;
      userRow.appendChild(avatar); userRow.appendChild(uname);
      secA.appendChild(userRow);
      // Tap su avatar o nome → stessa pagina profilo/immagine del desktop (se c'è)
      var profileA = gather('.hdr__right a').find(function(a){ return /dati-utente|profile|avatar|utente/i.test(a.href); }) || null;
      if (profileA){
        userRow.classList.add('clickable');
        userRow.addEventListener('click', function(){ location.href = profileA.href; });
      }

      // CTA: Ricarica (primaria) + Logout (ghost)
      var rightLinks = gather('.hdr__right a');
      var topUpA = rightLinks.find(function(a){ return /ricar/i.test(a.href) || /ricar/i.test(txt(a)); }) || null;
      var logoutA = rightLinks.find(function(a){ return /logout|esci/i.test(a.href) || /logout|esci/i.test(txt(a)); }) || null;
      buildCtaRow(secA, topUpA, logoutA);

      // (IMPORTANTE) Non aggiungiamo più alcuna “rotellina” separata in basso
      // per evitare doppioni: refresh sta SOLO accanto ad ArenaCoins.

      // Bind refresh: emette evento app e risincronizza dal pill header
      refreshBtn.addEventListener('click', function(){
        try{ document.dispatchEvent(new CustomEvent('refresh-balance')); }catch(_){}
        // Poll breve (max ~2s) sul pill header per aggiornare il drawer
        var tries = 0;
        var iv = setInterval(function(){
          var n = readCoinsFromHeader();
          if (n!=null){
            coinsRow.v.textContent = numToAC(n);
          }
          if (++tries >= 10) clearInterval(iv);
        }, 200);
      });

      // live sync mentre il drawer è aperto
      var pill = qs('.pill-balance .ac');
      if (pill){
        if (state.coinsObserver) { try{ state.coinsObserver.disconnect(); }catch(_){ } }
        state.coinsObserver = new MutationObserver(function(){
          var n = readCoinsFromHeader();
          if (n!=null) coinsRow.v.textContent = numToAC(n);
        });
        state.coinsObserver.observe(pill, {characterData:true, childList:true, subtree:true});
      }

      scrollContainer.appendChild(secA);
    }

    // Navigazione
    if (navLinks.length){
      var secN = section('Navigazione', navLinks, 'mbl-sec--nav');
      scrollContainer.appendChild(secN);
    }

    // (Facoltativo) Messaggi se presente un link in header
    var msgLink = (function(){
      var all = gather('.hdr__right a, .hdr__nav a');
      return all.find(function(a){ return /messaggi|messages|inbox/i.test(a.href) || /messagg/i.test(txt(a)); }) || null;
    })();
    if (msgLink){
      var secM = document.createElement('section'); secM.className='mbl-sec';
      var hM = document.createElement('div'); hM.className='mbl-sec__title'; hM.textContent='Messaggi'; secM.appendChild(hM);
      var ul = document.createElement('ul'); ul.className='mbl-list';
      var li = document.createElement('li');
      var a  = msgLink.cloneNode(true);
      a.classList.add('with-icon'); a.insertAdjacentHTML('afterbegin', '<span class="icon">'+svg('bell')+'</span>');
      a.addEventListener('click', function(){ closeDrawer(); });
      li.appendChild(a); ul.appendChild(li);
      secM.appendChild(ul);
      scrollContainer.appendChild(secM);
    }

    // Info / footer (in basso, fuori dallo scroll non ha senso: qui lo mettiamo nello scroll, ma separato visivamente)
    if (footLinks.length){
      var title = document.createElement('div'); title.className='mbl-sec__title'; title.textContent='Info';
      footContainer.appendChild(title);
      footContainer.appendChild(makeList(footLinks));
    }
  }

  // ---------------- Open / Close ----------------
  function getFocusable(root){
    var sel = ['a[href]','button:not([disabled])','input:not([disabled])','select:not([disabled])','textarea:not([disabled])','[tabindex]:not([tabindex="-1"])'].join(',');
    return qsa(sel, root).filter(function(el){ return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length); });
  }
  function trapFocus(ev, root){
    var items = getFocusable(root); if (!items.length) return;
    var first = items[0], last = items[items.length-1];
    if (ev.shiftKey){
      if (document.activeElement === first || !root.contains(document.activeElement)){ ev.preventDefault(); last.focus(); }
    } else {
      if (document.activeElement === last){ ev.preventDefault(); first.focus(); }
    }
  }
  function openDrawer(){
    var dr=qs('#mbl-drawer'), bd=qs('#mbl-backdrop'), tg=qs('#mbl-trigger'); if(!dr||!bd) return;

    // Aggiorna le sezioni OGNI volta che apro (così guest/logged/coins sono sempre corretti)
    var sc = qs('.mbl-scroll', dr), ft = qs('.mbl-foot', dr);
    fillDrawer(sc, ft);

    state.lastActive = document.activeElement || tg;
    document.documentElement.classList.add('mbl-lock'); document.body.classList.add('mbl-lock');
    bd.removeAttribute('hidden'); bd.classList.add('mbl-open');
    dr.classList.add('mbl-open'); dr.setAttribute('aria-hidden','false');
    if (tg) tg.setAttribute('aria-expanded','true');
    var f = getFocusable(dr); (f[0]||dr).focus({preventScroll:true});
    state.open = true;
  }
  function closeDrawer(){
    var dr=qs('#mbl-drawer'), bd=qs('#mbl-backdrop'), tg=qs('#mbl-trigger'); if(!dr||!bd) return;
    dr.classList.remove('mbl-open'); dr.setAttribute('aria-hidden','true');
    bd.classList.remove('mbl-open'); bd.setAttribute('hidden','hidden');
    document.documentElement.classList.remove('mbl-lock'); document.body.classList.remove('mbl-lock');
    if (tg) tg.setAttribute('aria-expanded','false');
    if (state.coinsObserver){ try{ state.coinsObserver.disconnect(); }catch(_){ } state.coinsObserver=null; }
    var back = state.lastActive || tg; if(back && back.focus) back.focus({preventScroll:true});
    state.open = false;
  }
  function toggleDrawer(){ state.open ? closeDrawer() : openDrawer(); }

  // ---------------- Init ----------------
  function init(){
    if (isMobile()) buildOnce();

    if (window.matchMedia){
      var mql = window.matchMedia(MQL);
      var onChange = function(e){ if (e.matches){ buildOnce(); } else { closeDrawer(); } };
      if (mql.addEventListener) mql.addEventListener('change', onChange);
      else if (mql.addListener)  mql.addListener(onChange);
    }

    // Chiudi se click fuori da drawer su un link esterno
    document.addEventListener('click', function(ev){
      if (!state.open) return;
      var dr = qs('#mbl-drawer'); if (!dr) return;
      var a = ev.target.closest && ev.target.closest('a'); if (a && !dr.contains(a)) closeDrawer();
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
