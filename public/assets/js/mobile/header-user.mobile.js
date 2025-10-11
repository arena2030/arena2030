/*! Header User - Mobile layer (menu a destra, icone in header)
   - Non tocca il desktop
   - Legge il DOM esistente (hdr__bar / hdr__right / subhdr / footer)
   - Robusto: se un elemento non esiste, salta senza errori
*/
(function(){
  'use strict';

  // ===== Utils =====
  var isMobile = function(){
    return (window.matchMedia ? window.matchMedia('(max-width: 768px)').matches
                              : (window.innerWidth||0) <= 768);
  };
  var qs  = function(s, r){ return (r||document).querySelector(s); };
  var qsa = function(s, r){ return Array.prototype.slice.call((r||document).querySelectorAll(s)); };
  var txt = function(el){ return (el && (el.textContent||'').trim()) || ''; };

  // SVG inline
  function svg(name){
    if (name==='burger')
      return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 6h18M3 12h18M3 18h18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    if (name==='close')
      return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    if (name==='msg')
      return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 6h16a2 2 0 012 2v7a2 2 0 01-2 2H9l-5 4V8a2 2 0 012-2z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>';
    if (name==='refresh')
      return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 4v6h6M20 20v-6h-6M6.3 17.7A8 8 0 0018 14M6 10a8 8 0 0111.7-3.7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    return '';
  }

  // ===== Costruzione header (icone) =====
  function enhanceHeaderRight(){
    var right = qs('.hdr__right'); if (!right) return false;

    // 1) Messaggi: cerca link "messaggi"
    var msg = qsa('a', right).find(function(a){
      var t = txt(a).toLowerCase();
      var h = (a.getAttribute('href')||'').toLowerCase();
      return /messagg|message/.test(t) || /messagg|message/.test(h) || a.classList.contains('hdr__msg');
    });
    if (!msg){
      // fallback (se non esiste, non lo creiamo forzatamente per evitare URL sbagliati)
    }else{
      msg.classList.add('mbl-msg');
      // Render icona rotonda, aria-label e titolo
      msg.setAttribute('aria-label','Messaggi'); msg.setAttribute('title','Messaggi');
      msg.innerHTML = svg('msg');
    }

    // 2) Ricarica: marca per ordine (non cambiamo stile tema)
    var ricarica = qsa('a,button', right).find(function(el){
      var t = txt(el).toLowerCase();
      var h = (el.getAttribute('href')||'').toLowerCase();
      return /ricar/.test(t) || /ricar/.test(h);
    });
    if (ricarica){ ricarica.classList.add('mbl-ricarica'); }

    // 3) Utente: avatar+username
    var usr = qs('.hdr__usr'); if (usr){ usr.classList.add('mbl-usr'); }

    // 4) Hamburger (in coda al cluster destro)
    if (!qs('#mbl-userTrigger', right)){
      var t = document.createElement('button');
      t.id = 'mbl-userTrigger'; t.type='button';
      t.setAttribute('aria-label','Apri menu'); t.setAttribute('aria-controls','mbl-userDrawer');
      t.setAttribute('aria-expanded','false');
      t.innerHTML = svg('burger');
      right.appendChild(t);
      t.addEventListener('click', toggleDrawer);
      t.addEventListener('keydown', function(ev){
        if (ev.key==='Enter' || ev.key===' ') { ev.preventDefault(); toggleDrawer(); }
      });
    }
    return true;
  }

  // ===== Drawer =====
  function buildDrawer(){
    if (qs('#mbl-userDrawer')) return;

    var dr = document.createElement('aside');
    dr.id='mbl-userDrawer'; dr.setAttribute('role','dialog'); dr.setAttribute('aria-modal','true');
    dr.setAttribute('aria-hidden','true'); dr.tabIndex = -1;

    // Head
    var head = document.createElement('div'); head.className='mbl-head';
    var ttl  = document.createElement('div'); ttl.className='mbl-title'; ttl.textContent='Menu';
    var close= document.createElement('button'); close.type='button'; close.className='mbl-close';
    close.setAttribute('aria-label','Chiudi menu'); close.innerHTML = svg('close');
    head.appendChild(ttl); head.appendChild(close); dr.appendChild(head);

    // Body scrollable + foot (info footer)
    var sc   = document.createElement('div'); sc.className='mbl-scroll';
    var foot = document.createElement('div'); foot.className='mbl-foot';
    dr.appendChild(sc); dr.appendChild(foot);

    document.body.appendChild(dr);

    var bd = document.createElement('div'); bd.id='mbl-userBackdrop'; bd.setAttribute('hidden','hidden');
    document.body.appendChild(bd);

    // Fill contenuti
    try { fillDrawer(sc, foot); } catch(_){}

    // Bind
    close.addEventListener('click', closeDrawer);
    bd.addEventListener('click', closeDrawer);
    dr.addEventListener('keydown', function(ev){
      if (ev.key==='Escape'){ ev.preventDefault(); closeDrawer(); return; }
      if (ev.key==='Tab'){ trapFocus(ev, dr); }
    });
  }

  function gather(sel){
    return qsa(sel).filter(function(a){ return a && a.tagName==='A' && (a.href||'').length && txt(a)!==''; });
  }

  function makeList(links){
    var ul = document.createElement('ul'); ul.className='mbl-list';
    links.forEach(function(a){
      var li = document.createElement('li');
      var cp = a.cloneNode(true);
      // pulizia attributi inline
      ['id','onclick','onmousedown','onmouseup','onmouseover','onmouseout'].forEach(function(k){ cp.removeAttribute(k); });
      cp.addEventListener('click', function(){ closeDrawer(); });
      li.appendChild(cp); ul.appendChild(li);
    });
    return ul;
  }

  function kv(container, k, v){
    var row = document.createElement('div'); row.className='mbl-kv';
    row.innerHTML = '<div class="k">'+k+'</div><div class="v">'+v+'</div>';
    container.appendChild(row);
  }

  function ctaRow(parent, primaryA, secondaryA){
    if (!primaryA && !secondaryA) return;
    var row = document.createElement('div'); row.className='mbl-ctaRow';
    if (primaryA){
      var p = primaryA.cloneNode(true); p.classList.add('mbl-cta'); p.removeAttribute('id');
      p.addEventListener('click', closeDrawer); row.appendChild(p);
    }
    if (secondaryA){
      var s = secondaryA.cloneNode(true); s.classList.add('mbl-ghost'); s.removeAttribute('id');
      s.addEventListener('click', closeDrawer); row.appendChild(s);
    }
    parent.appendChild(row);
  }

  function fillDrawer(sc, foot){
    sc.innerHTML = ''; foot.innerHTML='';

    // Account (Coins + Utente), CTA ricarica/logout
    var accSec = document.createElement('section'); accSec.className='mbl-sec';
    var hAcc   = document.createElement('div'); hAcc.className='mbl-sec__title'; hAcc.textContent='Account';
    var boxAcc = document.createElement('div'); boxAcc.className='mbl-account';

    // valore coins dalla pillola in header, se esiste
    var coinsEl = qs('.pill-balance .ac'); var coinsTxt = coinsEl ? txt(coinsEl) : '—';
    kv(boxAcc, 'ArenaCoins:', coinsTxt);

    var usr = qs('.hdr__usr'); if (usr){ kv(boxAcc, 'Utente:', txt(usr)); }

    accSec.appendChild(hAcc); accSec.appendChild(boxAcc);

    // CTA: Ricarica (primaria) + Logout (ghost), se esistono tra i link dell’header
    var headerLinks = gather('.hdr__right a');
    var ricaricaA   = headerLinks.find(function(a){ return /ricar/.test((a.href||'').toLowerCase()) || /ricar/.test(txt(a).toLowerCase()); }) || null;
    var logoutA     = headerLinks.find(function(a){ return /logout|esci/.test((a.href||'').toLowerCase()) || /logout|esci/.test(txt(a).toLowerCase()); }) || null;
    ctaRow(accSec, ricaricaA, logoutA);
    sc.appendChild(accSec);

    // NAV: dal subheader
    var navLinks = gather('.subhdr .subhdr__menu a');
    if (navLinks.length){
      var navSec = document.createElement('section'); navSec.className='mbl-sec mbl-sec--nav';
      var h = document.createElement('div'); h.className='mbl-sec__title'; h.textContent='Navigazione';
      navSec.appendChild(h); navSec.appendChild(makeList(navLinks));
      sc.appendChild(navSec);
    }

    // INFO: dal footer (in basso fisso)
    var infoLinks = gather('.site-footer .footer-menu a');
    if (infoLinks.length){
      var title = document.createElement('div'); title.className='mbl-sec__title'; title.textContent='Info';
      foot.appendChild(title);
      foot.appendChild(makeList(infoLinks));
    }

    // Refresh live dei coins (se la pagina emette eventi custom)
    try{
      document.addEventListener('refresh-balance', function(){
        var cEl = qs('.pill-balance .ac'); var val = cEl ? txt(cEl) : null;
        if (val){
          var kvVal = qsa('.mbl-kv .k', accSec).find(function(k){ return txt(k)==='ArenaCoins:'; });
          if (kvVal){ var v = kvVal.nextElementSibling; if (v) v.textContent = val; }
        }
      });
      // bottone refresh accanto ai coins
      var coinsRow = qsa('.mbl-kv', accSec).find(function(r){ return txt(r.firstElementChild)==='ArenaCoins:'; });
      if (coinsRow){
        var btn = document.createElement('button');
        btn.type='button'; btn.setAttribute('aria-label','Aggiorna saldo'); btn.style.marginLeft='8px';
        btn.style.width='36px'; btn.style.height='36px'; btn.style.borderRadius='10px';
        btn.style.border='1px solid var(--c-border,#1e293b)'; btn.style.background='var(--c-bg-2,#0f172a)'; btn.style.color='var(--c-text,#fff)';
        btn.innerHTML = svg('refresh');
        btn.addEventListener('click', function(){
          // se il progetto già aggiorna la pillola via fetch, emettiamo l’evento standard usato altrove
          var ev = new CustomEvent('refresh-balance'); document.dispatchEvent(ev);
        });
        coinsRow.appendChild(btn);
      }
    }catch(_){}
  }

  // ===== Apertura/chiusura, focus-trap e scroll lock =====
  var state={ open:false, lastActive:null };
  function getFocusable(root){
    var sel = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
    return qsa(sel, root).filter(function(el){ return !!(el.offsetWidth||el.offsetHeight||el.getClientRects().length); });
  }
  function trapFocus(ev, root){
    var items = getFocusable(root); if (!items.length) return;
    var first=items[0], last=items[items.length-1];
    if (ev.shiftKey){
      if (document.activeElement===first || !root.contains(document.activeElement)){ ev.preventDefault(); last.focus(); }
    }else{
      if (document.activeElement===last){ ev.preventDefault(); first.focus(); }
    }
  }
  function openDrawer(){
    var dr=qs('#mbl-userDrawer'), bd=qs('#mbl-userBackdrop'), tg=qs('#mbl-userTrigger'); if(!dr||!bd) return;
    state.lastActive = document.activeElement || tg;
    document.documentElement.classList.add('mbl-lock'); document.body.classList.add('mbl-lock');
    bd.removeAttribute('hidden'); bd.classList.add('mbl-open'); dr.classList.add('mbl-open'); dr.setAttribute('aria-hidden','false');
    if (tg) tg.setAttribute('aria-expanded','true');
    var f = getFocusable(dr); (f[0]||dr).focus({preventScroll:true});
    state.open = true;
  }
  function closeDrawer(){
    var dr=qs('#mbl-userDrawer'), bd=qs('#mbl-userBackdrop'), tg=qs('#mbl-userTrigger'); if(!dr||!bd) return;
    dr.classList.remove('mbl-open'); dr.setAttribute('aria-hidden','true');
    bd.classList.remove('mbl-open'); bd.setAttribute('hidden','hidden');
    document.documentElement.classList.remove('mbl-lock'); document.body.classList.remove('mbl-lock');
    if (tg) tg.setAttribute('aria-expanded','false');
    var back = state.lastActive || tg; if (back && back.focus) back.focus({preventScroll:true});
    state.open = false;
  }
  function toggleDrawer(){ state.open ? closeDrawer() : openDrawer(); }

  // ===== Init =====
  function init(){
    if (!isMobile()) return;
    // Lato utente: agisci solo se trovi l’header utente
    var hasUserHeader = !!qs('.hdr__right'); if (!hasUserHeader) return;

    var ok = enhanceHeaderRight(); if (!ok) return;
    buildDrawer();

    // Chiudi quando si naviga fuori dal drawer
    window.addEventListener('hashchange', closeDrawer);
    document.addEventListener('click', function(ev){
      if (!state.open) return;
      var dr = qs('#mbl-userDrawer'); if (!dr) return;
      var a = ev.target.closest && ev.target.closest('a'); if (a && !dr.contains(a)) closeDrawer();
    });

    // Reagisci a cambio viewport
    if (window.matchMedia){
      var mql = window.matchMedia('(max-width: 768px)');
      var onChange = function(e){ if (!e.matches) closeDrawer(); };
      if (mql.addEventListener) mql.addEventListener('change', onChange);
      else if (mql.addListener) mql.addListener(onChange);
    }
  }

  if (document.readyState==='loading'){ document.addEventListener('DOMContentLoaded', init); }
  else { init(); }
})();
