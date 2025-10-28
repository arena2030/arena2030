/*! Mobile Header– GUEST */
(function(){
  'use strict';
  // Non partire se user presente
  if (document.querySelector('.hdr__usr')) return;

  if (window.matchMedia && !window.matchMedia('(max-width: 768px)').matches) return;

  const qs=(s,r)=> (r||document).querySelector(s);
  const qsa=(s,r)=> Array.prototype.slice.call((r||document).querySelectorAll(s));
  const txt=(el)=> (el && (el.textContent||'').trim()) || '';

  // SVG inline
  function svg(name){
    if(name==='menu')   return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    if(name==='close')  return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    return '';
  }

  // Inietta trigger a destra
  const bar = qs('.hdr__bar'); if(!bar) return;
  if (!qs('#mbl-guestBtn', bar)){
    const btn=document.createElement('button');
    btn.id='mbl-guestBtn'; btn.className='mbl-guest-btn';
    btn.type='button'; btn.setAttribute('aria-label','Apri menu');
    btn.setAttribute('aria-controls','mbl-guestDrawer'); btn.setAttribute('aria-expanded','false');
    btn.innerHTML=svg('menu');
    bar.appendChild(btn);
  }

  // Drawer + backdrop
  let drawer=qs('#mbl-guestDrawer'), backdrop=qs('#mbl-guestBackdrop');
  if(!drawer){
    drawer=document.createElement('aside');
    drawer.id='mbl-guestDrawer'; drawer.setAttribute('role','dialog'); drawer.setAttribute('aria-modal','true'); drawer.setAttribute('aria-hidden','true'); drawer.tabIndex=-1;

    // head
    const head=document.createElement('div'); head.className='mbl-head';
    const title=document.createElement('div'); title.className='mbl-title'; title.textContent='Menu';
    const x=document.createElement('button'); x.type='button'; x.className='mbl-close'; x.setAttribute('aria-label','Chiudi menu'); x.innerHTML=svg('close');
    head.appendChild(title); head.appendChild(x);
    drawer.appendChild(head);

    const scroll=document.createElement('div'); scroll.className='mbl-scroll'; drawer.appendChild(scroll);
    fillGuest(scroll);

    backdrop=document.createElement('div'); backdrop.id='mbl-guestBackdrop'; backdrop.setAttribute('hidden','hidden');
    document.body.appendChild(drawer); document.body.appendChild(backdrop);

    // events
    function getFocusable(root){
      return qsa('a[href],button:not([disabled]),input,select,textarea,[tabindex]:not([tabindex="-1"])',root)
        .filter(el=> !!(el.offsetWidth||el.offsetHeight||el.getClientRects().length));
    }
    function trap(ev){
      const items=getFocusable(drawer); if(!items.length) return;
      const first=items[0], last=items[items.length-1];
      if(ev.shiftKey){
        if(document.activeElement===first || !drawer.contains(document.activeElement)){ev.preventDefault(); last.focus();}
      }else{
        if(document.activeElement===last){ev.preventDefault(); first.focus();}
      }
    }
    function open(){
      document.documentElement.classList.add('mbl-lock'); document.body.classList.add('mbl-lock');
      backdrop.removeAttribute('hidden'); backdrop.classList.add('mbl-open');
      drawer && drawer.removeAttribute('hidden');
      drawer.classList.add('mbl-open'); drawer.setAttribute('aria-hidden','false');
      qs('#mbl-guestBtn')?.setAttribute('aria-expanded','true');
      (getFocusable(drawer)[0]||drawer).focus({preventScroll:true});
    }
    function close(){
      drawer.classList.remove('mbl-open'); drawer.setAttribute('aria-hidden','true');
      backdrop.classList.remove('mbl-open'); backdrop.setAttribute('hidden','hidden');
      document.documentElement.classList.remove('mbl-lock'); document.body.classList.remove('mbl-lock');
      qs('#mbl-guestBtn')?.setAttribute('aria-expanded','false');
      qs('#mbl-guestBtn')?.focus({preventScroll:true});
    }

    // bind
    qs('#mbl-guestBtn')?.addEventListener('click', open);
    x.addEventListener('click', close);
    backdrop.addEventListener('click', close);
    drawer.addEventListener('keydown', (e)=>{ if(e.key==='Escape') close(); if(e.key==='Tab') trap(e); });
    document.addEventListener('click', (e)=>{ if(backdrop.hasAttribute('hidden')) return; const a=e.target.closest('a'); if(a && !drawer.contains(a)) close(); });
    window.addEventListener('hashchange', close);
  }

  // Build sections
  function gather(sel){ return qsa(sel).filter(a=> a.tagName==='A' && (a.href||'').length && txt(a)); }
  function makeList(links){
    const ul=document.createElement('ul'); ul.className='mbl-list';
    links.forEach(a=>{ const li=document.createElement('li'); const cp=a.cloneNode(true); cp.removeAttribute('id'); li.appendChild(cp); ul.appendChild(li); });
    return ul;
  }
  function section(title, links, sc){
    if(!links || !links.length) return;
    const wrap=document.createElement('section'); wrap.className='mbl-sec';
    const h=document.createElement('div'); h.className='mbl-sec__title'; h.textContent=title; wrap.appendChild(h);
    wrap.appendChild(makeList(links)); sc.appendChild(wrap);
  }
  function fillGuest(sc){
    sc.innerHTML='';
    // CTA Registrati/Login
    const acc = gather('.hdr__nav a');
    const registrati=acc.find(a=> /registr/i.test(a.href)||/registr/i.test(txt(a)));
    const login=acc.find(a=> /login|acced/i.test(a.href)||/acced/i.test(txt(a)));
    if(registrati||login){
      const sec=document.createElement('section'); sec.className='mbl-sec';
      const h=document.createElement('div'); h.className='mbl-sec__title'; h.textContent='Benvenuto'; sec.appendChild(h);
      const row=document.createElement('div'); row.className='mbl-ctaRow';
      if(registrati){ const p=registrati.cloneNode(true); p.classList.add('mbl-cta'); row.appendChild(p); }
      if(login){ const g=login.cloneNode(true); g.classList.add('mbl-ghost'); row.appendChild(g); }
      sec.appendChild(row); sc.appendChild(sec);
    }
    // NAV ↴ Info ↴
    section('Navigazione', gather('.subhdr .subhdr__menu a'), sc);
    section('Info',         gather('.site-footer .footer-menu a'), sc);
  }
})();
