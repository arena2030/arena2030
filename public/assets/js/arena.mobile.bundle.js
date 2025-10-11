/*! Arena Mobile Layer (Right Drawer, full-site mobile polish) — single-file bundle, no deps
   - Drawer a destra, header mobile pulito (logo sx, avatar+username+hamburger dx)
   - Menu costruito dal DOM (subhdr/nav, azioni account, footer)
   - ArenaCoins con refresh accanto; avatar cliccabili (stesso comportamento del desktop)
   - Icona “Messaggi” nel menu; “Movimenti” apre lo stesso pop‑up del desktop (event bridging)
   - Accessibilità: role="dialog", aria-modal, focus trap, ESC; scroll-lock
   - Responsive fix globali + page enhancers (torneo/flash: azioni sotto la card; premi: lista mobile)
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
    if (name==='close')   return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    if (name==='menu')    return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 6h18M3 12h18M3 18h18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    if (name==='refresh') return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 4v6h6M20 20v-6h-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/><path d="M20 14a8 8 0 0 1-14.9 3M4 10a8 8 0 0 1 14.9-3" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/></svg>';
    if (name==='msg')     return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 5h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H8l-4 3V7a2 2 0 0 1 2-2z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M22 7l-10 7L2 7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    return '';
  }

  // -----------------------------------------------------
  // CSS inline (mobile + fix responsive globali). Desktop invariato.
  // -----------------------------------------------------
  function injectStyle(){
    if (qs('#mbl-style')) return;
    var css = `
/* ===== Arena Mobile Layer ===== */
#mbl-trigger{ display:none; -webkit-tap-highlight-color:transparent; }
#mbl-backdrop{
  position:fixed; inset:0; background:rgba(0,0,0,.45);
  backdrop-filter:saturate(120%) blur(1px);
  opacity:0; pointer-events:none; transition:opacity .2s ease; z-index:70;
}
#mbl-backdrop.mbl-open{ opacity:1; pointer-events:auto; }

/* Drawer a destra; scorre tutto (testata sticky) */
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
  background:var(--c-bg-2,#0f172a); color:#fff; /* forza contrasto */
  display:inline-flex; align-items:center; justify-content:center;
}
#mbl-drawer .mbl-close svg{ width:20px; height:20px; display:block; }

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
#mbl-drawer .mbl-sec--nav .mbl-list a{ font-weight:800; } /* nav più marcata */
.mbl-ico{ width:18px; height:18px; display:inline-flex; align-items:center; justify-content:center; opacity:.95; }
.mbl-ico svg{ width:18px; height:18px; display:block; }
.mbl-t{ flex:1 1 auto; }

/* CTA row (allineate e stessa altezza) */
#mbl-drawer .mbl-ctaRow{ display:flex; gap:8px; padding:8px 6px 0; flex-wrap:wrap; }
#mbl-drawer .mbl-cta, #mbl-drawer .mbl-ghost{
  display:inline-flex; align-items:center; justify-content:center;
  height:40px !important; padding:0 16px !important; line-height:1 !important;
  border-radius:9999px; font-weight:800; text-decoration:none; white-space:nowrap;
}
#mbl-drawer .mbl-cta{
  background: var(--c-primary, #3b82f6) !important; color:#fff !important;
  border:1px solid var(--c-border,#1f2937) !important;
}
#mbl-drawer .mbl-ghost{
  background: transparent !important; color: var(--c-text,#e5e7eb) !important;
  border:1px solid var(--c-border,#1f2937) !important;
}

/* Dati account */
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

/* Avatar inline nel valore Utente */
#mbl-drawer .mbl-kv .v .mbl-av{ width:22px; height:22px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; background:var(--c-bg-2,#0f172a); color:#fff; font-size:11px; font-weight:900; }
#mbl-drawer .mbl-kv .v img{ width:22px; height:22px; border-radius:50%; display:inline-block; }

/* ===== Header mobile pulito ===== */
@media (max-width:768px){
  /* Nasconde sub-header e footer */
  .subhdr, .site-footer { display:none !important; }

  /* pulizia header */
  .hdr__nav { display:none !important; }           /* guest actions nel drawer */
  .hdr__bar{ display:flex; align-items:center; }
  .hdr__right{ margin-left:auto !important; display:flex !important; align-items:center; gap:8px; }
  .hdr__right > *:not(.hdr__usr){ display:none !important; } /* user: mostra solo blocco utente */

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

  /* hamburger subito dopo l'utente */
  .hdr__bar > #mbl-trigger{
    display:inline-flex; align-items:center; justify-content:center;
    width:40px; height:40px; margin-left:8px; order:999;
    border-radius:10px; border:1px solid var(--c-border,#1e293b);
    background:var(--c-bg-2,#0f172a); color:var(--c-text,#fff);
  }
  #mbl-trigger svg{ width:20px; height:20px; }

  /* ===== Polish responsive globale ===== */
  .container{ padding-left:14px; padding-right:14px; }
  h1{ font-size:clamp(22px, 7vw, 34px); line-height:1.15; }
  h2{ font-size:clamp(18px, 5.6vw, 26px); line-height:1.2; }
  .card{ border-radius:16px; }
  .btn, .btn--primary, .btn.btn--primary{ min-height:44px; }
  .grid{ display:grid; gap:12px; }
  .grid > *{ min-width:0; }

  /* Tabelle: overflow auto se non già gestite */
  .mbl-tablewrap{ overflow:auto; -webkit-overflow-scrolling:touch; border-radius:12px; border:1px solid var(--c-border,rgba(255,255,255,.12)); }
  .mbl-scrollhint{ text-align:right; font-size:11px; color:var(--c-muted,#9fb7ff); padding:4px 6px 0; }

  /* Modali */
  .modal .modal-card{ width:min(96vw, 620px) !important; max-height:86vh !important; }

  /* Torneo/Flash: azioni scommessa sotto la card + griglia 3 col */
  .mbl-bet-actions{ display:grid; grid-template-columns:1fr 1fr 1fr; gap:8px; margin-top:10px; }
  .mbl-bet-actions .btn{ min-height:44px; }
  .mbl-matchcard *{ white-space:normal !important; } /* evita testi accalcati */

  /* Premi: lista mobile (se presente) */
  .mbl-prizes{ display:flex; flex-direction:column; gap:10px; }
  .mbl-prize{ display:flex; align-items:center; gap:12px; padding:10px; border:1px solid var(--c-border,rgba(255,255,255,.12)); border-radius:14px; background:rgba(255,255,255,.02); }
  .mbl-prize img{ width:48px; height:48px; border-radius:10px; object-fit:cover; border:1px solid var(--c-border,rgba(255,255,255,.12)); }
  .mbl-prize .t{ flex:1 1 auto; }
  .mbl-prize .t .nm{ font-weight:800; }
  .mbl-prize .t .ds{ opacity:.8; font-size:13px; }
  .mbl-prize .act .btn{ min-height:38px; }
}

/* ===== Desktop: layer invisibile ===== */
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
    var img = qs('.hdr__usr img, .hdr__usr .avatar');
    if (img){
      var c = img.cloneNode(true);
      c.style.width  = small ? '22px' : '28px';
      c.style.height = small ? '22px' : '28px';
      c.style.borderRadius = '9999px';
      return c;
    }
    var n = document.createElement('span');
    n.className='mbl-av';
    n.textContent = (readUsername()||'?').charAt(0).toUpperCase();
    return n;
  }

  // Bridge click avatar → stesso comportamento del desktop (apertura pagina/impostazioni avatar)
  function bindAvatarClicks(){
    var usr = qs('.hdr__usr'); if (!usr) return;
    var anchor = usr.querySelector('a'); // link reale del desktop
    var href = anchor ? anchor.getAttribute('href') : '/dati-utente.php';

    // header: se clicchi l'avatar o il blocco utente → segue l'ancora originale
    if (!usr._mblClickBound){
      usr.addEventListener('click', function(e){
        // se si è cliccato un bottone/menù del desktop, lascia stare
        if (e.target && e.target.closest('#mbl-trigger')) return;
        if (anchor){ e.preventDefault(); try{ anchor.click(); }catch(_){ window.location.href = href; } }
        else { window.location.href = href; }
      });
      usr._mblClickBound = true;
    }

    // nel drawer: i KV “Utente” diventano link cliccabili
    var kv = qs('#mbl-drawer .mbl-account'); if (kv && !kv._mblUsrLinkDone){
      var v = kv.querySelector('.mbl-kv .v'); if (v){
        var wrap = document.createElement('a');
        wrap.href = href; wrap.style.display='inline-flex'; wrap.style.alignItems='center'; wrap.style.gap='8px';
        while (v.firstChild){ wrap.appendChild(v.firstChild); }
        v.appendChild(wrap);
      }
      kv._mblUsrLinkDone = true;
    }
  }

  // -----------------------------------------------------
  // Header user: avatar fallback se manca
  // -----------------------------------------------------
  function patchHeaderUser(){
    var usrWrap = qs('.hdr__bar .hdr__usr'); if (!usrWrap || usrWrap._mblDone) return;
    var target = usrWrap.firstElementChild && usrWrap.firstElementChild.tagName==='A' ? usrWrap.firstElementChild : usrWrap;
    if (!target.querySelector('img, .avatar, .mbl-av')) target.insertBefore(createAvatarNode(false), target.firstChild);
    usrWrap._mblDone = true;
    bindAvatarClicks();
  }

  // -----------------------------------------------------
  // Filtri link "rumore" (es. ↻ senza testo)
  // -----------------------------------------------------
  function isNoiseLink(a){
    var t = (txt(a) || '').trim();
    var href = (a.getAttribute('href')||'').toLowerCase();
    if (!t) return true;
    if (t.length <= 2 && !/[a-z0-9]/i.test(t)) return true;           // solo simboli
    if (/refresh|aggiorn|reload/.test(t.toLowerCase())) return true;  // link tecnici
    if (/refresh|aggiorn|reload/.test(href)) return true;
    return false;
  }

  // -----------------------------------------------------
  // Event bridging: apri lo stesso pop-up “Movimenti” del desktop
  // -----------------------------------------------------
  function openMovimentiPopup(){
    var selectors = [
      '.hdr__right a', '.hdr__nav a',
      '[data-open="movimenti"]', 'button[data-open="movimenti"]',
      'a[href*="movimenti"]'
    ];
    var cand = null;
    for (var s=0; s<selectors.length && !cand; s++){
      var list = qsa(selectors[s]);
      for (var i=0; i<list.length; i++){
        var el = list[i];
        var L  = txt(el).toLowerCase();
        var H  = (el.getAttribute('href')||'').toLowerCase();
        if (/moviment/.test(L) || /moviment/.test(H) || el.getAttribute('data-open')==='movimenti'){
          cand = el; break;
        }
      }
    }
    if (cand){
      try { cand.click(); } catch(_){
        try { cand.dispatchEvent(new MouseEvent('click',{bubbles:true,cancelable:true,view:window})); } catch(__){}
      }
      setTimeout(function(){
        if (!document.querySelector('.modal[aria-hidden="false"], .modal.show, .dialog[open]')){
          var href = cand.getAttribute('href') || '/movimenti.php';
          if (!/moviment/.test(href)) href = '/movimenti.php';
          window.location.href = href;
        }
      }, 400);
      return;
    }
    window.location.href = '/movimenti.php';
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
        var label = txt(a);
        var labelLower = label.toLowerCase();

        var li = document.createElement('li');
        var cp = a.cloneNode(true);
        cp.removeAttribute('id'); ['onclick','onmousedown','onmouseup','onmouseover','onmouseout'].forEach(function(k){ cp.removeAttribute(k); });

        // Decorazione: icona Messaggi
        if (/messagg/.test(labelLower)){
          cp.innerHTML = '';
          var ico = document.createElement('span'); ico.className='mbl-ico'; ico.innerHTML = svg('msg');
          var t = document.createElement('span'); t.className='mbl-t'; t.textContent = label;
          cp.appendChild(ico); cp.appendChild(t);
        }

        // “Movimenti”: apri pop-up desktop
        if (/moviment/.test(labelLower)){
          cp.addEventListener('click', function(e){ e.preventDefault(); closeDrawer(); ric(openMovimentiPopup); });
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

    // Dati base
    var userEl = qs('.hdr__usr');
    var balEl  = qs('.pill-balance .ac');

    // Link di base
    var navLinks  = gather('.subhdr .subhdr__menu a');             // Navigazione
    var isLogged  = !!userEl || !!qs('.hdr__right');
    var accLinks  = isLogged ? gather('.hdr__right a') : gather('.hdr__nav a');  // Azioni account
    var footLinks = gather('.site-footer .footer-menu a');         // Info/footer

    // Guest: CTA Registrati + Accedi
    if (!isLogged){
      var registrati = accLinks.find(function(a){ return /registr/i.test(a.href) || /registr/i.test(txt(a)); }) || null;
      var accedi     = accLinks.find(function(a){ return /login|acced/i.test(a.href) || /acced/i.test(txt(a)); }) || null;

      if (registrati || accedi){
        var secW = document.createElement('section'); secW.className='mbl-sec';
        var hW = document.createElement('div'); hW.className='mbl-sec__title'; hW.textContent='Benvenuto'; secW.appendChild(hW);
        var row = document.createElement('div'); row.className='mbl-ctaRow';
        if (registrati){ var r = registrati.cloneNode(true); r.classList.add('mbl-cta'); r.classList.remove('btn--disabled'); r.addEventListener('click', closeDrawer); row.appendChild(r); }
        if (accedi){     var g = accedi.cloneNode(true);     g.classList.add('mbl-ghost'); g.addEventListener('click', closeDrawer); row.appendChild(g); }
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
        if (ricaricaA){ var r = ricaricaA.cloneNode(true); r.classList.add('mbl-cta'); r.classList.remove('btn--disabled'); r.addEventListener('click', closeDrawer); row.appendChild(r); }
        if (logoutA){   var g = logoutA.cloneNode(true);     g.classList.add('mbl-ghost'); g.addEventListener('click', closeDrawer); row.appendChild(g); }
        secA.appendChild(row);
      }

      // Altre azioni (Messaggi, Dati utente, ecc.)
      var others = accLinks.filter(function(a){ return a !== ricaricaA && a !== logoutA; });
      if (others.length){ secA.appendChild(makeList(others)); }
      body.appendChild(secA);
    }

    // NAVIGAZIONE (dal sub-header)
    if (navLinks.length){
      var secN = section('Navigazione', navLinks, 'mbl-sec--nav');
      body.appendChild(secN);
    }

    // INFO (footer del sito) — scorre insieme
    if (footLinks.length){
      var secF = section('Info', footLinks, '');
      body.appendChild(secF);
    }

    setupBalanceSync(); // keep in sync
    bindAvatarClicks(); // avatar nel drawer cliccabile
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
  // Page enhancers (mobile-only, best-effort, no-op se markup diverso)
  // -----------------------------------------------------
  function reflowBetRows(){
    if (!isMobile()) return;
    // Trova blocchi che contengono i 3 bottoni Casa/Pareggio/Trasferta e li sposta sotto alla card
    var btnSel = '.btn, button';
    var labelsRe = /(casa|home|pareggio|draw|x|trasferta|away)/i;

    qsa('.card, [class*="match"], [class*="evento"], [class*="event"]').forEach(function(card){
      if (card._mblBetDone) return;
      var btns = qsa(btnSel, card).filter(function(b){ return labelsRe.test(txt(b)); });
      if (btns.length >= 3){
        var wrap = document.createElement('div'); wrap.className = 'mbl-bet-actions';
        btns.slice(0,3).forEach(function(b){ wrap.appendChild(b); });
        card.appendChild(wrap);
        card.classList.add('mbl-matchcard');
        card._mblBetDone = true;
      }
    });
  }

  function enhancePrizesTable(){
    if (!isMobile()) return;
    var tbl = qs('#tblPrizes'); if (!tbl || tbl._mblListDone) return;
    var tb = tbl.querySelector('tbody'); if (!tb) return;

    var list = document.createElement('div'); list.className='mbl-prizes';

    qsa('tr', tb).forEach(function(tr){
      var tds = qsa('td', tr); if (tds.length < 6) return;
      var code = txt(tds[0]);
      var img  = tds[1].querySelector('img');
      var name = txt(tds[2]);
      var desc = txt(tds[3]);
      var btn  = tds[6] ? tds[6].querySelector('button, a') : null;

      var row = document.createElement('div'); row.className='mbl-prize';
      if (img){ var im = img.cloneNode(true); im.removeAttribute('width'); im.removeAttribute('height'); row.appendChild(im); }

      var t = document.createElement('div'); t.className='t';
      var nm = document.createElement('div'); nm.className='nm'; nm.textContent = name || code || '—';
      var ds = document.createElement('div'); ds.className='ds'; ds.textContent = desc || '';
      t.appendChild(nm); t.appendChild(ds); row.appendChild(t);

      var act = document.createElement('div'); act.className='act';
      if (btn){ var bc = btn.cloneNode(true); bc.addEventListener('click', function(){ closeDrawer(); }); act.appendChild(bc); }
      row.appendChild(act);

      list.appendChild(row);
    });

    tbl.parentNode.insertBefore(list, tbl.nextSibling);
    tbl._mblListDone = true;
  }

  function runPageEnhancers(){
    reflowBetRows();
    enhancePrizesTable();
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
      bar.appendChild(t);
    }

    // Drawer + backdrop
    if (!qs('#mbl-drawer')){
      var dr = document.createElement('aside');
      dr.id='mbl-drawer'; dr.setAttribute('role','dialog'); dr.setAttribute('aria-modal','true'); dr.setAttribute('aria-hidden','true'); dr.tabIndex=-1;

      // Head
      var head = document.createElement('div'); head.className='mbl-head';
      var brand = document.createElement('div'); brand.className='mbl-brand';
      var logo = qs('.hdr__logo') || qs('.hdr .logo'); if (logo){ brand.appendChild(logo.cloneNode(true)); }
      var ttl = document.createElement('div'); ttl.className='mbl-title'; ttl.textContent='Menu';
      brand.appendChild(ttl); head.appendChild(brand);

      var btnX = document.createElement('button'); btnX.type='button'; btnX.className='mbl-close'; btnX.setAttribute('aria-label','Chiudi menu');
      btnX.innerHTML = svg('close'); head.appendChild(btnX);

      dr.appendChild(head);

      var body = document.createElement('div'); body.className='mbl-body'; dr.appendChild(body);
      document.body.appendChild(dr);

      var bd = document.createElement('div'); bd.id='mbl-backdrop'; bd.setAttribute('hidden','hidden'); document.body.appendChild(bd);

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

    // Responsive extra: wrap tabelle “nude” + page enhancers
    wrapNakedTables();
    runPageEnhancers();

    state.built = true;
  }

  // -----------------------------------------------------
  // Wrap tabelle senza wrapper per scroll orizzontale
  // -----------------------------------------------------
  function wrapNakedTables(){
    if (!isMobile()) return;
    qsa('table').forEach(function(t){
      if (t.closest('.table-wrap, .mbl-tablewrap')) return;
      var w = document.createElement('div'); w.className='mbl-tablewrap';
      var hint = document.createElement('div'); hint.className='mbl-scrollhint'; hint.textContent='Scorri →';
      var parent = t.parentNode; if (!parent) return;
      parent.insertBefore(w, t); w.appendChild(t); parent.insertBefore(hint, w.nextSibling);
    });
  }

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

    // Alcuni contenuti cambiano dinamicamente → tenta reflow
    document.addEventListener('refresh-balance', updateBalanceFromDOM, { passive:true });
    window.addEventListener('load', function(){ ric(runPageEnhancers); }, { passive:true });
  }

  if (document.readyState === 'loading'){ document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
