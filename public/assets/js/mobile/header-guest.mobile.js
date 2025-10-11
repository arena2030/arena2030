// Header Guest - Mobile hamburger (drawer a destra)
(function(){
  'use strict';

  var isMobile = function(){ return window.matchMedia ? window.matchMedia('(max-width: 768px)').matches : (window.innerWidth||0)<=768; };
  var qs  = function(s,r){ return (r||document).querySelector(s); };
  var qsa = function(s,r){ return Array.prototype.slice.call((r||document).querySelectorAll(s)); };

  function svg(name){
    if(name==='menu') return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 6h18M3 12h18M3 18h18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    if(name==='close')return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    return '';
  }

  function buildOnce(){
    if (!isMobile()) return;
    var bar = qs('.hdr__bar'); if (!bar) return;

    // Pulsante hamburger (se non c'è)
    if (!qs('#mbl-guestHamburger', bar)){
      var btn = document.createElement('button');
      btn.id='mbl-guestHamburger'; btn.type='button';
      btn.className='mbl-guest-btn'; btn.setAttribute('aria-label','Apri menu');
      btn.setAttribute('aria-controls','mbl-guestDrawer'); btn.setAttribute('aria-expanded','false');
      btn.innerHTML = svg('menu');
      // Mettilo a destra della barra
      bar.appendChild(btn);
    }

    // Drawer + backdrop (se non ci sono)
    if (!qs('#mbl-guestDrawer')){
      var dr = document.createElement('aside');
      dr.id='mbl-guestDrawer'; dr.setAttribute('role','dialog'); dr.setAttribute('aria-modal','true');
      dr.setAttribute('aria-hidden','true'); dr.tabIndex=-1;

      var head = document.createElement('div'); head.className='mbl-head';
      var title = document.createElement('div'); title.className='mbl-title'; title.textContent='Menu';
      var close = document.createElement('button'); close.type='button'; close.className='mbl-close'; close.setAttribute('aria-label','Chiudi menu'); close.innerHTML=svg('close');
      head.appendChild(title); head.appendChild(close);
      dr.appendChild(head);

      var sc = document.createElement('div'); sc.className='mbl-scroll';
      dr.appendChild(sc);

      // --- CONTENUTO MINIMO (guest): CTA + Navigazione + Info ---
      var secW = document.createElement('section'); secW.className='mbl-sec';
      var hW   = document.createElement('div'); hW.className='mbl-sec__title'; hW.textContent='Benvenuto';
      secW.appendChild(hW);
      var row  = document.createElement('div'); row.className='mbl-ctaRow';
      // Prende i link "Registrati / Login" dalla .hdr__nav (se esistono)
      var accLinks = qsa('.hdr__nav a');
      var reg = accLinks.find(a=>/registr/i.test(a.href) || /registr/i.test(a.textContent||'')) || null;
      var log = accLinks.find(a=>/login|acced/i.test(a.href) || /acced/i.test(a.textContent||'')) || null;
      if (reg){ var r=reg.cloneNode(true); r.classList.add('mbl-cta'); row.appendChild(r); }
      if (log){ var l=log.cloneNode(true); l.classList.add('mbl-ghost'); row.appendChild(l); }
      secW.appendChild(row);
      sc.appendChild(secW);

      // Navigazione dal subheader
      var navLinks = qsa('.subhdr .subhdr__menu a');
      if (navLinks.length){
        var secN=document.createElement('section'); secN.className='mbl-sec';
        var hN=document.createElement('div'); hN.className='mbl-sec__title'; hN.textContent='Navigazione';
        secN.appendChild(hN);
        var ul=document.createElement('ul'); ul.className='mbl-list';
        navLinks.forEach(a=>{ var li=document.createElement('li'); var cp=a.cloneNode(true); li.appendChild(cp); ul.appendChild(li); });
        secN.appendChild(ul); sc.appendChild(secN);
      }

      // Info dal footer
      var footLinks = qsa('.site-footer .footer-menu a');
      if (footLinks.length){
        var secI=document.createElement('section'); secI.className='mbl-sec';
        var hI=document.createElement('div'); hI.className='mbl-sec__title'; hI.textContent='Info';
        secI.appendChild(hI);
        var ulI=document.createElement('ul'); ulI.className='mbl-list';
        footLinks.forEach(a=>{ var li=document.createElement('li'); var cp=a.cloneNode(true); li.appendChild(cp); ulI.appendChild(li); });
        secI.appendChild(ulI); sc.appendChild(secI);
      }

      document.body.appendChild(dr);

      var bd=document.createElement('div'); bd.id='mbl-guestBackdrop'; bd.setAttribute('hidden','hidden');
      document.body.appendChild(bd);

      // Bind base
      close.addEventListener('click', closeDrawer);
      bd.addEventListener('click', closeDrawer);

      // Focus trap
      dr.addEventListener('keydown', function(ev){
        if (ev.key==='Escape'){ ev.preventDefault(); closeDrawer(); return; }
        if (ev.key!=='Tab') return;
        var f = getFocusable(dr); if (!f.length) return;
        var first=f[0], last=f[f.length-1];
        if (ev.shiftKey){
          if (document.activeElement===first || !dr.contains(document.activeElement)){ ev.preventDefault(); last.focus(); }
        } else {
          if (document.activeElement===last){ ev.preventDefault(); first.focus(); }
        }
      });
    }

    // Bind trigger
    var trigger = qs('#mbl-guestHamburger'); if (!trigger || trigger._bound) return;
    trigger.addEventListener('click', toggleDrawer);
    trigger._bound = true;
  }

  function getFocusable(root){
    var sel=['a[href]','button:not([disabled])','input:not([disabled])','select:not([disabled])','textarea:not([disabled])','[tabindex]:not([tabindex="-1"])'].join(',');
    return qsa(sel, root).filter(function(el){ return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length); });
  }

  function openDrawer(){
    var dr=qs('#mbl-guestDrawer'), bd=qs('#mbl-guestBackdrop'), tg=qs('#mbl-guestHamburger'); if(!dr||!bd) return;
    document.documentElement.classList.add('mbl-lock'); document.body.classList.add('mbl-lock');
    bd.removeAttribute('hidden'); bd.classList.add('mbl-open');
    dr.classList.add('mbl-open'); dr.setAttribute('aria-hidden','false');
    if (tg){ tg.setAttribute('aria-expanded','true'); tg.innerHTML = svg('close'); }
    var f=getFocusable(dr); (f[0]||dr).focus({preventScroll:true});
  }
  function closeDrawer(){
    var dr=qs('#mbl-guestDrawer'), bd=qs('#mbl-guestBackdrop'), tg=qs('#mbl-guestHamburger'); if(!dr||!bd) return;
    dr.classList.remove('mbl-open'); dr.setAttribute('aria-hidden','true');
    bd.classList.remove('mbl-open'); bd.setAttribute('hidden','hidden');
    document.documentElement.classList.remove('mbl-lock'); document.body.classList.remove('mbl-lock');
    if (tg){ tg.setAttribute('aria-expanded','false'); tg.innerHTML = svg('menu'); tg.focus({preventScroll:true}); }
  }
  function toggleDrawer(){ var dr=qs('#mbl-guestDrawer'); if(!dr) return; (dr.classList.contains('mbl-open')?closeDrawer:openDrawer)(); }

  function init(){
    if (!isMobile()) return;
    buildOnce();

    // Re‑bind quando cambia il breakpoint
    if (window.matchMedia){
      var mql=window.matchMedia('(max-width: 768px)');
      var onChange = function(e){ if (e.matches){ buildOnce(); } else { closeDrawer(); } };
      if (mql.addEventListener) mql.addEventListener('change', onChange);
      else if (mql.addListener) mql.addListener(onChange);
    }
  }

  if (document.readyState==='loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
