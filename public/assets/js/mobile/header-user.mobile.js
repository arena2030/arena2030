/*! Header User – Mobile layer (drawer a destra, robusto) */
(function(){
  'use strict';

  // ---------- Utils ----------
  var MQL = '(max-width: 768px)';
  var ric = window.requestIdleCallback || function(cb){ return setTimeout(cb,0); };
  function isMobile(){ return (window.matchMedia ? window.matchMedia(MQL).matches : (window.innerWidth||0) <= 768); }
  function qs(s, r){ return (r||document).querySelector(s); }
  function qsa(s, r){ return Array.prototype.slice.call((r||document).querySelectorAll(s)); }
  function txt(el){ return (el && (el.textContent||'').trim()) || ''; }
  function on(el, ev, fn){ if(el) el.addEventListener(ev, fn, {passive:false}); }

  function svg(name){
    if(name==='menu')    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    if(name==='x')       return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    if(name==='refresh') return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4v6h6M20 20v-6h-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/><path d="M20 9A8 8 0 0 0 4 10" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/></svg>';
    if(name==='mail')    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7h18v10H3z" fill="none" stroke="currentColor" stroke-width="2"/><path d="M3 7l9 6 9-6" fill="none" stroke="currentColor" stroke-width="2"/></svg>';
    return '';
  }

  // ---------- Stato ----------
  var state = { built:false, open:false, lastFocus:null, acVal:null, msgHref:null };

  // ---------- Build ----------
  function build(){
    if(state.built || !isMobile()) return;

    // Se non è loggato (manca .hdr__usr), non facciamo nulla
    var usr = qs('.hdr__usr');
    if(!usr) return;

    // Rimuovi layer guest (evita doppio hamburger)
    ['#mbl-guestBtn','#mbl-guestDrawer','#mbl-guestBackdrop'].forEach(function(sel){
      var n = qs(sel); if(n && n.parentNode) n.parentNode.removeChild(n);
    });

    // Inserisci hamburger rotondo
    var bar = qs('.hdr__bar');
    var right = qs('.hdr__right', bar) || bar;
    var btn = qs('#mbl-userBtn', right);
    if(!btn){
      btn = document.createElement('button');
      btn.id = 'mbl-userBtn'; btn.type='button';
      btn.setAttribute('aria-label','Apri menu'); btn.setAttribute('aria-controls','mbl-userDrawer'); btn.setAttribute('aria-expanded','false');
      btn.innerHTML = svg('menu');
      right.appendChild(btn);
      on(btn,'click',toggle);
      on(btn,'keydown',function(e){ if(e.key==='Enter'||e.key===' ') { e.preventDefault(); toggle(); } });
    }

    // Drawer + backdrop (con [hidden] per chiusura “vera”)
    if(!qs('#mbl-userDrawer')){
      var dr = document.createElement('aside');
      dr.id='mbl-userDrawer'; dr.setAttribute('role','dialog'); dr.setAttribute('aria-modal','true'); dr.setAttribute('aria-hidden','true'); dr.tabIndex=-1;

      var head = document.createElement('div'); head.className='mbl-head';
      var ttl = document.createElement('div'); ttl.className='mbl-title'; ttl.textContent='Menu';
      var close = document.createElement('button'); close.className='mbl-close'; close.type='button'; close.innerHTML = svg('x');
      head.appendChild(ttl); head.appendChild(close);
      dr.appendChild(head);

      var sc = document.createElement('div'); sc.className='mbl-scroll'; dr.appendChild(sc);
      document.body.appendChild(dr);

      var bd = document.createElement('div'); bd.id='mbl-userBackdrop'; bd.setAttribute('hidden','');
      document.body.appendChild(bd);

      ric(function(){ try{ fill(sc); }catch(e){ console.error('[mbl-user] fill error',e); } });

      on(close,'click',closeDrawer);
      on(bd,'click',closeDrawer);
      on(dr,'keydown',function(e){
        if(e.key==='Escape'){ e.preventDefault(); closeDrawer(); }
        if(e.key==='Tab'){ trapFocus(e, dr); }
      });
    }

    // Sincronizza subito il valore delle AC dalla pillola header (se presente)
    syncFromHeader();

    state.built = true;
  }

  // ---------- Sezioni ----------
  function fill(sc){
    sc.innerHTML = '';

    // Account
    var acc = document.createElement('section'); acc.className='mbl-sec';
    var h = document.createElement('div'); h.className='mbl-sec__title'; h.textContent='Account';
    acc.appendChild(h);

    // AC + refresh
    var acLine = document.createElement('div'); acLine.className='mbl-ac-line';
    var acK = document.createElement('div'); acK.className='k'; acK.textContent='ArenaCoins:';
    var acV = document.createElement('div'); acV.className='v'; acV.id='mbl-acVal'; acV.textContent = formatAC(state.acVal);
    var acR = document.createElement('button'); acR.className='mbl-ac-refresh'; acR.type='button'; acR.setAttribute('aria-label','Aggiorna ArenaCoins'); acR.innerHTML = svg('refresh');
    on(acR,'click',refreshAC);
    acLine.appendChild(acK); acLine.appendChild(acV); acLine.appendChild(acR);
    acc.appendChild(acLine);

    // Utente: avatar + nome + messaggi a dx
    var userLine = document.createElement('div'); userLine.className='mbl-user-line';
    var uK = document.createElement('div'); uK.className='k'; uK.textContent='Utente:';
    var wrap = document.createElement('div'); wrap.className='userwrap';

    var usr = qs('.hdr__usr');
    if(usr){
      var avatar = usr.querySelector('img, .avatar, .usr-avatar') || usr.firstElementChild;
      if(avatar) wrap.appendChild(avatar.cloneNode(true));
      var nameNode = usr.querySelector('.username, .usr-name, .name');
      var nameSpan = document.createElement('span'); nameSpan.className='username'; nameSpan.textContent = nameNode ? txt(nameNode) : txt(usr);
      wrap.appendChild(nameSpan);
      var profileLink = usr.closest('a') || usr.querySelector('a');
      if(profileLink){ on(wrap,'click',function(){ location.href = profileLink.href; }); wrap.style.cursor='pointer'; }
    }

    var msgBtn = document.createElement('button'); msgBtn.className='mbl-msg-btn'; msgBtn.type='button'; msgBtn.setAttribute('aria-label','Messaggi'); msgBtn.innerHTML = svg('mail');
    state.msgHref = detectMessagesHref() || '/messaggi.php';
    on(msgBtn, 'click', function(){ location.href = state.msgHref; });

    userLine.appendChild(uK); userLine.appendChild(wrap); userLine.appendChild(msgBtn);
    acc.appendChild(userLine);

    // CTA ricarica + logout
    var actions = collectUserActions();
    if(actions.ricarica || actions.logout){
      var row = document.createElement('div'); row.className='mbl-ctaRow';
      if(actions.ricarica){ var r = actions.ricarica.cloneNode(true); r.classList.add('mbl-cta'); r.removeAttribute('id'); on(r,'click',closeDrawer); row.appendChild(r); }
      if(actions.logout){ var l = actions.logout.cloneNode(true); l.classList.add('mbl-ghost'); l.removeAttribute('id'); on(l,'click',closeDrawer); row.appendChild(l); }
      acc.appendChild(row);
    }
    sc.appendChild(acc);

    // Navigazione dal sub-header
    var nav = qsa('.subhdr .subhdr__menu a');
    if(nav.length){
      var sn = document.createElement('section'); sn.className='mbl-sec';
      var hn = document.createElement('div'); hn.className='mbl-sec__title'; hn.textContent='Navigazione';
      sn.appendChild(hn); sn.appendChild(makeList(nav));
      sc.appendChild(sn);
    }

    // Info dal footer
    var info = qsa('.site-footer .footer-menu a');
    if(info.length){
      var si = document.createElement('section'); si.className='mbl-sec';
      var hi = document.createElement('div'); hi.className='mbl-sec__title'; hi.textContent='Info';
      si.appendChild(hi); si.appendChild(makeList(info));
      sc.appendChild(si);
    }
  }

  function makeList(links){
    var ul = document.createElement('ul'); ul.className='mbl-list';
    links.forEach(function(a){
      if(!a || a.tagName!=='A') return;
      var li = document.createElement('li');
      var cp = a.cloneNode(true); cp.removeAttribute('id');
      on(cp,'click',closeDrawer);
      li.appendChild(cp); ul.appendChild(li);
    });
    return ul;
  }

  function collectUserActions(){
    var out = { ricarica:null, logout:null, others:[] };
    qsa('.hdr__right a').forEach(function(a){
      var t = txt(a).toLowerCase();
      if(/ricar/i.test(t) && !out.ricarica) out.ricarica = a;
      else if(/logout|esci/i.test(t) && !out.logout) out.logout = a;
      else out.others.push(a);
    });
    return out;
  }

  function detectMessagesHref(){
    var a = qsa('.hdr__right a').find(function(x){
      var t = txt(x).toLowerCase();
      return /messag|messagg|msg|inbox|mail/.test(t) || /messag|msg|inbox|mail/.test((x.getAttribute('href')||'').toLowerCase());
    });
    return a ? a.href : null;
  }

  // ---------- ArenaCoins ----------
  function parseAC(s){
    if(s==null) return null;
    var n = String(s).replace(/[^\d.,-]/g,'').replace(',','.');
    var f = parseFloat(n);
    return isFinite(f) ? f : null;
  }
  function formatAC(n){ return Number(n==null?0:n).toFixed(2); }
  function readHeaderAC(){
    var el = qs('.pill-balance .ac') || qs('#meCoins');
    return el ? parseAC(txt(el)) : null;
  }
  function writeHeaderAC(v){
    var el = qs('.pill-balance .ac'); if(el) el.textContent = formatAC(v);
    var me = qs('#meCoins'); if(me) me.textContent = formatAC(v);
  }
  function syncFromHeader(){
    var v = readHeaderAC();
    if(v!=null){ state.acVal = v; var box = qs('#mbl-acVal'); if(box) box.textContent = formatAC(v); }
  }
  async function refreshAC(){
    try{
      var r = await fetch('/premi.php?action=me', {credentials:'same-origin', cache:'no-store'});
      var j = await r.json().catch(function(){ return null; });
      var val = (j && j.ok && j.me && typeof j.me.coins!=='undefined') ? parseAC(j.me.coins) : null;
      if(val==null) val = readHeaderAC();
      if(val!=null){
        state.acVal = val;
        writeHeaderAC(val);
        var box = qs('#mbl-acVal'); if(box) box.textContent = formatAC(val);
      }
    }catch(_){ syncFromHeader(); }
  }

  // ---------- Apertura/chiusura ----------
  function getFocusable(root){
    var sel = ['a[href]','button:not([disabled])','input:not([disabled])','select:not([disabled])','textarea:not([disabled])','[tabindex]:not([tabindex="-1"])'].join(',');
    return qsa(sel, root).filter(function(el){ return !!(el.offsetWidth||el.offsetHeight||el.getClientRects().length); });
  }
  function trapFocus(e, root){
    var items = getFocusable(root); if(!items.length) return;
    var first = items[0], last = items[items.length-1];
    if(e.shiftKey){
      if(document.activeElement===first || !root.contains(document.activeElement)){ e.preventDefault(); last.focus(); }
    }else{
      if(document.activeElement===last){ e.preventDefault(); first.focus(); }
    }
  }

  function openDrawer(){
    var dr=qs('#mbl-userDrawer'), bd=qs('#mbl-userBackdrop'), bt=qs('#mbl-userBtn'); if(!dr||!bd) return;
    state.lastFocus = document.activeElement || bt;
    document.documentElement.classList.add('mbl-lock'); document.body.classList.add('mbl-lock');
    bd.removeAttribute('hidden'); bd.classList.add('mbl-open');
    dr.classList.add('mbl-open'); dr.setAttribute('aria-hidden','false');
    if(bt) bt.setAttribute('aria-expanded','true');
    var foc = getFocusable(dr); (foc[0]||dr).focus({preventScroll:true});
    state.open = true;
  }
  function closeDrawer(){
    var dr=qs('#mbl-userDrawer'), bd=qs('#mbl-userBackdrop'), bt=qs('#mbl-userBtn'); if(!dr||!bd) return;
    dr.classList.remove('mbl-open'); dr.setAttribute('aria-hidden','true');
    bd.classList.remove('mbl-open'); bd.setAttribute('hidden','');
    document.documentElement.classList.remove('mbl-lock'); document.body.classList.remove('mbl-lock');
    if(bt) bt.setAttribute('aria-expanded','false');
    var back = state.lastFocus || bt; if(back && back.focus) back.focus({preventScroll:true});
    state.open = false;
  }
  function toggle(){ state.open ? closeDrawer() : openDrawer(); }

  // ---------- Init ----------
  function init(){
    if(!isMobile()) return;
    build();

    if(window.matchMedia){
      var mq = window.matchMedia(MQL);
      var handler = function(e){ if(e.matches) build(); else closeDrawer(); };
      if(mq.addEventListener) mq.addEventListener('change', handler); else mq.addListener(handler);
    }

    on(window,'hashchange',closeDrawer);
    on(document,'click',function(ev){
      if(!state.open) return;
      var dr = qs('#mbl-userDrawer'); if(!dr) return;
      var a = ev.target && ev.target.closest ? ev.target.closest('a') : null;
      if(a && !dr.contains(a)) closeDrawer();
    });
  }

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
