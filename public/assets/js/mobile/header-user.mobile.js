/*! Header User - Mobile (robusto, no conflitti con guest) */
(function(){
  'use strict';

  // ---------- Utils
  var qs  = function(s, r){ return (r||document).querySelector(s); };
  var qsa = function(s, r){ return Array.prototype.slice.call((r||document).querySelectorAll(s)); };
  var mm  = window.matchMedia ? window.matchMedia('(max-width:768px)') : { matches: (window.innerWidth||0)<=768, addEventListener: function(){} };

  function txt(n){ return (n && (n.textContent||'').trim()) || ''; }
  function isLogged(){ return !!qs('.hdr__usr'); }
  function once(fn){ var ran=false; return function(){ if(ran) return; ran=true; return fn.apply(this, arguments); }; }

  // ---------- SVG icone (inline)
  function icon(name){
    if(name==='menu'){
      return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    }
    if(name==='x'){
      return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    }
    if(name==='refresh'){
      return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4v6h6M20 20v-6h-6" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round"/><path d="M20 10a8 8 0 0 0-14-4M4 14a8 8 0 0 0 14 4" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round"/></svg>';
    }
    if(name==='msg'){
      return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>';
    }
    return '';
  }

  // ---------- Stato drawer
  var Drawer = (function(){
    var open = false, lastActive = null;

    function focusable(root){
      var sel = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
      return qsa(sel, root).filter(function(el){ return !!(el.offsetWidth||el.offsetHeight||el.getClientRects().length); });
    }

    function trapTab(ev, root){
      if(ev.key!=='Tab') return;
      var items = focusable(root); if(!items.length) return;
      var first = items[0], last = items[items.length-1];
      if(ev.shiftKey){
        if(document.activeElement===first || !root.contains(document.activeElement)){ ev.preventDefault(); last.focus(); }
      }else{
        if(document.activeElement===last){ ev.preventDefault(); first.focus(); }
      }
    }

    function ensure(){
      var dr = qs('#mbl-userDrawer');
      if(dr) return dr;

      // Remove guest artifacts if present
      var gb = qs('#mbl-guestBtn');      if(gb && gb.parentNode) gb.parentNode.removeChild(gb);
      var gd = qs('#mbl-guestDrawer');   if(gd && gd.parentNode) gd.parentNode.removeChild(gd);
      var gk = qs('#mbl-guestBackdrop'); if(gk && gk.parentNode) gk.parentNode.removeChild(gk);

      // Backdrop
      var bd = qs('#mbl-userBackdrop');
      if(!bd){
        bd = document.createElement('div');
        bd.id='mbl-userBackdrop';
        bd.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.45);backdrop-filter:saturate(120%) blur(1px);opacity:0;pointer-events:none;transition:opacity .2s ease;z-index:150;';
        document.body.appendChild(bd);
        bd.addEventListener('click', close);
      }

      // Drawer (right)
      dr = document.createElement('aside');
      dr.id='mbl-userDrawer';
      dr.setAttribute('role','dialog'); dr.setAttribute('aria-modal','true'); dr.setAttribute('aria-hidden','true');
      dr.style.cssText='position:fixed;top:0;right:0;height:100dvh;width:320px;max-width:92vw;transform:translateX(105%);transition:transform .24s ease;background:var(--c-bg,#0b1220);color:var(--c-text,#fff);border-left:1px solid var(--c-border,rgba(255,255,255,.08));z-index:151;display:flex;flex-direction:column;';

      var head = document.createElement('div');
      head.style.cssText='display:flex;align-items:center;gap:10px;padding:14px;border-bottom:1px solid var(--c-border,rgba(255,255,255,.08));';
      head.innerHTML = '<div style="font-weight:900;font-size:16px;">Menu</div>';
      var x = document.createElement('button');
      x.type='button';
      x.setAttribute('aria-label','Chiudi menu');
      x.innerHTML = icon('x');
      x.style.cssText = 'margin-left:auto;width:40px;height:40px;border-radius:12px;border:1px solid var(--c-border,rgba(255,255,255,.12));background:var(--c-bg-2,#0f172a);color:var(--c-text,#fff);display:inline-flex;align-items:center;justify-content:center;';
      x.addEventListener('click', close);
      head.appendChild(x);
      dr.appendChild(head);

      var sc = document.createElement('div'); sc.className='mbl-scroll'; sc.style.cssText='flex:1;overflow:auto;';
      dr.appendChild(sc);

      document.body.appendChild(dr);

      // Riempie le sezioni una volta (idempotente)
      fill(sc);

      // keydown (Esc + trap tab)
      dr.addEventListener('keydown', function(e){
        if(e.key==='Escape'){ e.preventDefault(); close(); }
        else trapTab(e, dr);
      });

      return dr;
    }

    function openDrawer(){
      var dr = ensure(), bd = qs('#mbl-userBackdrop'), btn = qs('#mbl-userBtn');
      lastActive = document.activeElement || btn;
      document.documentElement.classList.add('mbl-lock'); document.body.classList.add('mbl-lock');
      bd.style.opacity='1'; bd.style.pointerEvents='auto';
      dr.style.transform='translateX(0)';
      dr.setAttribute('aria-hidden','false');
      if(btn) btn.setAttribute('aria-expanded','true');
      var f = focusable(dr); (f[0]||dr).focus({preventScroll:true});
      open = true;
    }

    function close(){
      var dr = qs('#mbl-userDrawer'), bd = qs('#mbl-userBackdrop'), btn = qs('#mbl-userBtn');
      if(!dr || !bd) return;
      dr.style.transform='translateX(105%)';
      dr.setAttribute('aria-hidden','true');
      bd.style.opacity='0'; bd.style.pointerEvents='none';
      document.documentElement.classList.remove('mbl-lock'); document.body.classList.remove('mbl-lock');
      if(btn) btn.setAttribute('aria-expanded','false');
      if(lastActive && lastActive.focus) lastActive.focus({preventScroll:true});
      open=false;
    }

    function toggle(){ open ? close() : openDrawer(); }

    // ---- Riempi contenuti (robusto, no NPE)
    function fill(root){
      try{
        root.innerHTML='';

        var secA = document.createElement('section'); secA.style.cssText='padding:12px;';
        var tA = document.createElement('div'); tA.textContent='Account'; tA.style.cssText='font-size:12px;font-weight:900;letter-spacing:.3px;color:var(--c-muted,#9fb7ff);text-transform:uppercase;padding:2px 6px 8px;';
        secA.appendChild(tA);

        // Riga saldo
        var rSaldo = document.createElement('div');
        rSaldo.style.cssText='display:flex;align-items:center;gap:10px;padding:6px 6px;';
        var k1 = document.createElement('div'); k1.textContent='ArenaCoins:'; k1.style.cssText='min-width:110px;color:var(--c-muted,#9fb7ff);font-weight:900;';
        var v1 = document.createElement('div'); v1.id='mbl-ac-val'; v1.style.cssText='font-weight:800;';
        var rf = document.createElement('button'); rf.id='mbl-ac-refresh'; rf.type='button'; rf.setAttribute('aria-label','Aggiorna saldo'); rf.innerHTML=icon('refresh');
        rf.style.cssText='margin-left:6px;width:28px;height:28px;border-radius:10px;border:1px solid var(--c-border,rgba(255,255,255,.12));background:var(--c-bg-2,#0f172a);color:var(--c-text,#fff);display:inline-flex;align-items:center;justify-content:center;';
        rSaldo.appendChild(k1); rSaldo.appendChild(v1); rSaldo.appendChild(rf);
        secA.appendChild(rSaldo);

        // Riga utente + messaggi a destra
        var rUser = document.createElement('div');
        rUser.style.cssText='display:flex;align-items:center;gap:10px;padding:6px 6px;';
        var k2=document.createElement('div'); k2.textContent='Utente:'; k2.style.cssText=k1.style.cssText;
        var ubox=document.createElement('div'); ubox.style.cssText='display:flex;align-items:center;gap:8px;font-weight:800;';
        var badge=document.createElement('span'); badge.className='mbl-badge'; badge.style.cssText='display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:9999px;border:1px solid var(--c-border,rgba(255,255,255,.12));background:var(--c-bg-2,#0f172a);';
        var uname=document.createElement('span'); uname.id='mbl-usr-name';
        ubox.appendChild(badge); ubox.appendChild(uname);
        var msgA = findMsgLink(); // copia link "Messaggi"
        var msgBtn=null;
        if(msgA){
          msgBtn = msgA.cloneNode(true);
          msgBtn.removeAttribute('id'); msgBtn.textContent='';
          msgBtn.setAttribute('aria-label','Messaggi');
          msgBtn.innerHTML = icon('msg');
          msgBtn.style.cssText='margin-left:auto;width:32px;height:32px;border-radius:12px;border:1px solid var(--c-border,rgba(255,255,255,.12));display:inline-flex;align-items:center;justify-content:center;';
        }
        rUser.appendChild(k2); rUser.appendChild(ubox); if(msgBtn) rUser.appendChild(msgBtn);
        secA.appendChild(rUser);

        // CTA (Ricarica + Logout)
        var row = document.createElement('div'); row.style.cssText='display:flex;gap:10px;flex-wrap:wrap;padding:8px 6px 0;';
        var ricarica = findRicarica();
        var logout   = findLogout();
        if(ricarica){
          var p = ricarica.cloneNode(true); p.removeAttribute('id');
          p.style.cssText='display:inline-flex;align-items:center;justify-content:center;height:40px;padding:0 16px;border-radius:9999px;font-weight:800;border:1px solid color-mix(in lab,var(--c-primary,#3b82f6) 85%,#000);background:var(--c-primary,#3b82f6);color:#fff;text-decoration:none;';
          row.appendChild(p);
        }
        if(logout){
          var g = logout.cloneNode(true); g.removeAttribute('id');
          g.style.cssText='display:inline-flex;align-items:center;justify-content:center;height:40px;padding:0 16px;border-radius:9999px;font-weight:800;border:1px solid var(--c-border,#1f2937);background:transparent;color:var(--c-text,#e5e7eb);text-decoration:none;';
          row.appendChild(g);
        }
        secA.appendChild(row);

        root.appendChild(secA);

        // NAVIGAZIONE (dal sub-header)
        var navLinks = collect('.subhdr .subhdr__menu a');
        if(navLinks.length){
          root.appendChild(section('Navigazione', navLinks, true));
        }

        // INFO (footer)
        var infoLinks = collect('.site-footer .footer-menu a');
        if(infoLinks.length){
          root.appendChild(section('Info', infoLinks, false));
        }

        // popolamento dati
        hydrateAccount();

        // refresh saldo
        rf.addEventListener('click', function(e){ e.preventDefault(); e.stopPropagation(); hydrateAccount(true); });
      }catch(err){
        console.error('[mbl-user] fill error', err);
      }
    }

    function section(title, links, strong){
      var sec=document.createElement('section'); sec.style.cssText='padding:12px;';
      var h=document.createElement('div'); h.textContent=title; h.style.cssText='font-size:12px;font-weight:900;letter-spacing:.3px;color:var(--c-muted,#9fb7ff);text-transform:uppercase;padding:2px 6px 8px;';
      sec.appendChild(h);
      var ul=document.createElement('ul'); ul.style.cssText='list-style:none;margin:0;padding:0;';
      links.forEach(function(a){
        var li=document.createElement('li');
        var cp=a.cloneNode(true); cp.removeAttribute('id');
        cp.style.cssText='display:block;padding:12px 8px;border-radius:8px;text-decoration:none;color:var(--c-text,#e5e7eb);'+(strong?'font-weight:800;':'');
        li.appendChild(cp); ul.appendChild(li);
        cp.addEventListener('click', function(){ close(); });
      });
      sec.appendChild(ul);
      return sec;
    }

    function collect(sel){
      return qsa(sel).filter(function(a){ return a && a.tagName==='A' && (a.href||'').length>0 && txt(a)!==''; });
    }
    function findRicarica(){
      var all=qsa('.hdr__right a,.hdr__nav a'); return all.find(a=>/ricar/i.test(((a.href||'')+txt(a))))||null;
    }
    function findLogout(){
      var all=qsa('.hdr__right a,.hdr__nav a'); return all.find(a=>/logout|esci/i.test(((a.href||'')+txt(a))))||null;
    }
    function findMsgLink(){
      var all=qsa('.hdr__right a,.hdr__nav a'); return all.find(a=>/messagg/i.test(((a.href||'')+txt(a))))||null;
    }

    function hydrateAccount(force){
      try{
        // username + avatar
        var usrEl = qs('.hdr__usr'); var name = txt(usrEl);
        var nm = qs('#mbl-usr-name'); if(nm) nm.textContent = name || '';
        var b  = qs('#mbl-userDrawer .mbl-badge');
        if(b){ b.textContent = (name||'U').trim().charAt(0).toUpperCase(); }

        // ArenaCoins: preferisci qualsiasi badge già presente nel DOM desktop
        var acDom = qs('.pill-balance .ac, .saldo .ac, .badge-ac, [data-ac]');
        var val = acDom ? String(txt(acDom)).replace(/[^\d.,-]/g,'').replace(',', '.') : '';
        if(!val && !force){
          // niente nel DOM → non forzare 0
          val = qs('#mbl-ac-val') ? qs('#mbl-ac-val').textContent : '';
        }
        var out = qs('#mbl-ac-val'); if(out) out.textContent = val || '—';

        // eventuale fetch leggero (solo se force)
        if(force){
          // Se hai una endpoint globale per saldo, usala qui:
          // fetch('/api/me.php?action=coins',{credentials:'same-origin'}).then(r=>r.json()).then(j=>{ if(j&&j.coins!=null){ out.textContent=Number(j.coins).toFixed(2); }});
          // Fallback: rileggi dal DOM (es. pagina ha aggiornato pillola in header)
          var again = qs('.pill-balance .ac, .saldo .ac, .badge-ac, [data-ac]');
          if(again){ out.textContent = String(txt(again)).replace(/[^\d.,-]/g,'').replace(',', '.') || out.textContent; }
        }
      }catch(e){ /* no-op */ }
    }

    return { ensure: ensure, open: openDrawer, close: close, toggle: toggle };
  })();

  // ---------- Header (ricarica · avatar · username · hamburger)
  function mountHeader(){
    var bar = qs('.hdr__bar'); if(!bar) return;

    // pulizia: mostra solo ricarica + usr; nascondi altro
    qsa('.hdr__right > *').forEach(function(n){ n.style.display='none'; });

    var usr = qs('.hdr__usr');
    if(usr){ usr.style.display='inline-flex'; usr.style.alignItems='center'; usr.style.gap='8px'; }

    // avatar fallback se non presente
    if(usr && !usr.querySelector('img,[class*="avatar"],.mbl-badge')){
      var ch=(txt(usr).trim().charAt(0)||'U').toUpperCase();
      var badge=document.createElement('span');
      badge.className='mbl-badge';
      badge.style.cssText='display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:9999px;border:1px solid var(--c-border,rgba(255,255,255,.12));background:var(--c-bg-2,#0f172a);';
      badge.textContent=ch;
      usr.prepend(badge);
    }

    // sposta “Ricarica” (se esiste) prima della usr
    var ricarica = (function(){
      var all=qsa('.hdr__right a,.hdr__nav a'); return all.find(a=>/ricar/i.test(((a.href||'')+txt(a))))||null;
    })();
    if(ricarica){ ricarica.style.display='inline-flex'; ricarica.style.marginRight='8px'; bar.appendChild(ricarica); }

    // append username visibile
    if(usr) bar.appendChild(usr);

    // hamburger (round)
    var btn = qs('#mbl-userBtn');
    if(!btn){
      btn = document.createElement('button');
      btn.id='mbl-userBtn'; btn.type='button';
      btn.setAttribute('aria-label','Apri menu'); btn.setAttribute('aria-controls','mbl-userDrawer'); btn.setAttribute('aria-expanded','false');
      btn.innerHTML = icon('menu');
      btn.style.cssText='display:inline-flex;align-items:center;justify-content:center;width:42px;height:42px;border-radius:12px;border:1px solid var(--c-border,#1e293b);background:var(--c-bg-2,#0f172a);color:var(--c-text,#fff);margin-left:8px;z-index:150;';
      bar.appendChild(btn);
    }

    // Bind “a prova di overlay”
    var safeOpen = function(ev){
      try{ ev && ev.preventDefault && ev.preventDefault(); ev && ev.stopPropagation && ev.stopPropagation(); ev && ev.stopImmediatePropagation && ev.stopImmediatePropagation(); }catch(_){}
      Drawer.ensure(); Drawer.toggle();
    };
    // listener diretti
    btn.addEventListener('click',    safeOpen, {passive:false});
    btn.addEventListener('pointerup',safeOpen, {passive:false});
    btn.addEventListener('touchend', safeOpen, {passive:false});
    btn.addEventListener('keydown',  function(e){ if(e.key==='Enter'||e.key===' '){ safeOpen(e); } }, {passive:false});
    // delega in cattura (se click intercettato su SVG interno o overlay)
    document.addEventListener('click', function(e){
      if(e.target && e.target.closest && e.target.closest('#mbl-userBtn')) safeOpen(e);
    }, true);
  }

  // ---------- Bootstrap
  function boot(){
    if(!mm.matches) return;           // solo mobile
    if(!isLogged()) return;           // solo user loggato
    // rimuovi eventuale guest per sicurezza
    var gb = qs('#mbl-guestBtn');      if(gb && gb.parentNode) gb.parentNode.removeChild(gb);
    var gd = qs('#mbl-guestDrawer');   if(gd && gd.parentNode) gd.parentNode.removeChild(gd);
    var gk = qs('#mbl-guestBackdrop'); if(gk && gk.parentNode) gk.parentNode.removeChild(gk);
    mountHeader();
  }

  if(document.readyState==='loading'){ document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
  if(mm.addEventListener){ mm.addEventListener('change', function(e){ if(e.matches) boot(); }); }

})();
