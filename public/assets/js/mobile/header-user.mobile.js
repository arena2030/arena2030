/*! Mobile Header – USER */
(function(){
  'use strict';
  // Parti solo se esiste user
  if (!document.querySelector('.hdr__usr')) return;
  if (window.matchMedia && !window.matchMedia('(max-width: 768px)').matches) return;

  // Rimuovi eventuale layer guest residuo
  ['#mbl-guestHamburger','#mbl-guestBtn','#mbl-guestDrawer','#mbl-guestBackdrop']
    .forEach(sel=>{ const n=document.querySelector(sel); if(n&&n.parentNode) n.parentNode.removeChild(n); });

  const qs=(s,r)=> (r||document).querySelector(s);
  const qsa=(s,r)=> Array.prototype.slice.call((r||document).querySelectorAll(s));
  const txt=(el)=> (el && (el.textContent||'').trim()) || '';

  function svg(name){
    if(name==='menu')    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    if(name==='close')   return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    if(name==='refresh') return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 12a8 8 0 1 1-2.34-5.66M20 4v6h-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    if(name==='msg')     return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H8l-4 4V5a2 2 0 0 1 2-2h13a2 2 0 0 1 2 2z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>';
    return '';
  }

  const bar = qs('.hdr__bar'); if(!bar) return;

  // Trigger hamburger (rotondo, a destra)
  if(!qs('#mbl-userBtn', bar)){
    const btn=document.createElement('button');
    btn.id='mbl-userBtn'; btn.type='button'; btn.setAttribute('aria-label','Apri menu');
    btn.setAttribute('aria-controls','mbl-userDrawer'); btn.setAttribute('aria-expanded','false');
    btn.innerHTML=svg('menu'); bar.appendChild(btn);
  }

  // Drawer + backdrop
  let drawer=qs('#mbl-userDrawer'), backdrop=qs('#mbl-userBackdrop');
  if(!drawer){
    drawer=document.createElement('aside');
    drawer.id='mbl-userDrawer'; drawer.setAttribute('role','dialog'); drawer.setAttribute('aria-modal','true'); drawer.setAttribute('aria-hidden','true'); drawer.tabIndex=-1;

    const head=document.createElement('div'); head.className='mbl-head';
    const title=document.createElement('div'); title.className='mbl-title'; title.textContent='Menu';
    const x=document.createElement('button'); x.type='button'; x.className='mbl-close'; x.setAttribute('aria-label','Chiudi menu'); x.innerHTML=svg('close');
    head.appendChild(title); head.appendChild(x); drawer.appendChild(head);

    const sc=document.createElement('div'); sc.className='mbl-scroll'; drawer.appendChild(sc);
    backdrop=document.createElement('div'); backdrop.id='mbl-userBackdrop'; backdrop.setAttribute('hidden','hidden');
    document.body.appendChild(drawer); document.body.appendChild(backdrop);

    fillUser(sc);

    // focus trap & open/close
    function getFocusable(root){
      return qsa('a[href],button:not([disabled]),input,select,textarea,[tabindex]:not([tabindex="-1"])',root)
        .filter(el=> !!(el.offsetWidth||el.offsetHeight||el.getClientRects().length));
    }
    function trap(ev){
      const it=getFocusable(drawer); if(!it.length) return;
      const first=it[0], last=it[it.length-1];
      if(ev.shiftKey){ if(document.activeElement===first || !drawer.contains(document.activeElement)){ev.preventDefault(); last.focus();} }
      else{ if(document.activeElement===last){ev.preventDefault(); first.focus();} }
    }
    function open(){
      document.documentElement.classList.add('mbl-lock'); document.body.classList.add('mbl-lock');
      backdrop.removeAttribute('hidden'); backdrop.classList.add('mbl-open');
      drawer.classList.add('mbl-open'); drawer.setAttribute('aria-hidden','false');
      qs('#mbl-userBtn')?.setAttribute('aria-expanded','true');
      (getFocusable(drawer)[0]||drawer).focus({preventScroll:true});
    }
    function close(){
      drawer.classList.remove('mbl-open'); drawer.setAttribute('aria-hidden','true');
      backdrop.classList.remove('mbl-open'); backdrop.setAttribute('hidden','hidden');
      document.documentElement.classList.remove('mbl-lock'); document.body.classList.remove('mbl-lock');
      qs('#mbl-userBtn')?.setAttribute('aria-expanded','false');
      qs('#mbl-userBtn')?.focus({preventScroll:true});
    }

    qs('#mbl-userBtn')?.addEventListener('click', open);
    x.addEventListener('click', close);
    backdrop.addEventListener('click', close);
    drawer.addEventListener('keydown', (e)=>{ if(e.key==='Escape') close(); if(e.key==='Tab') trap(e); });
    document.addEventListener('click', (e)=>{ if(backdrop.hasAttribute('hidden')) return; const a=e.target.closest('a'); if(a && !drawer.contains(a)) close(); });
    window.addEventListener('hashchange', close);
  }

  // Helpers raccolta link
  function gather(sel){ return qsa(sel).filter(a=> a.tagName==='A' && (a.href||'').length && txt(a)); }
  function makeList(links){
    const ul=document.createElement('ul'); ul.className='mbl-list';
    links.forEach(a=>{ const li=document.createElement('li'); const cp=a.cloneNode(true); cp.removeAttribute('id'); li.appendChild(cp); ul.appendChild(li); });
    return ul;
  }
  function section(title, links, sc, extraClass){
    if(!links || !links.length) return;
    const wrap=document.createElement('section'); wrap.className='mbl-sec'+(extraClass?(' '+extraClass):'');
    const h=document.createElement('div'); h.className='mbl-sec__title'; h.textContent=title; wrap.appendChild(h);
    wrap.appendChild(makeList(links)); sc.appendChild(wrap);
  }

  function readACFromDOM(){
    const pill=qs('.pill-balance .ac'); if(pill && !isNaN(parseFloat(pill.textContent))) return parseFloat(pill.textContent);
    const me=qs('#meCoins'); if(me && !isNaN(parseFloat(me.textContent))) return parseFloat(me.textContent);
    return null;
  }

  async function refreshCoins(){
    try{
      const r=await fetch('/premi.php?action=me',{credentials:'same-origin',cache:'no-store'});
      const j=await r.json().catch(()=>null);
      let val=(j && j.ok && j.me && j.me.coins!=null)? parseFloat(j.me.coins) : readACFromDOM();
      if(val!=null){
        const out=qs('#mbl-coins-val'); if(out) out.textContent=val.toFixed(2);
        const pill=qs('.pill-balance .ac'); if(pill) pill.textContent=val.toFixed(2);
        const me=qs('#meCoins'); if(me) me.textContent=val.toFixed(2);
      }
    }catch(_){
      const v=readACFromDOM();
      if(v!=null){ const out=qs('#mbl-coins-val'); if(out) out.textContent=v.toFixed(2); }
    }
  }

  function findMsgLink(){
    const cand = [].concat(
      gather('.hdr__right a'),
      gather('.hdr__nav a')
    );
    return cand.find(a=>{
      const t=(a.getAttribute('title')||'')+' '+txt(a)+' '+(a.href||'');
      return /mess/i.test(t);
    }) || null;
  }

  // Costruzione CONTE NUTO
  function fillUser(sc){
    sc.innerHTML='';

    // ACCOUNT
    const secA=document.createElement('section'); secA.className='mbl-sec';
    const hA=document.createElement('div'); hA.className='mbl-sec__title'; hA.textContent='Account'; secA.appendChild(hA);

    // ArenaCoins (valore + refresh vicino)
    const rowCoins=document.createElement('div'); rowCoins.className='mbl-kv';
    rowCoins.innerHTML='<div class="k">ArenaCoins:</div><div class="v" id="mbl-coins-val">—</div><div class="after"></div>';
    const after=rowCoins.querySelector('.after');
    const ref=document.createElement('button'); ref.type='button'; ref.className='mbl-ref'; ref.setAttribute('aria-label','Aggiorna ArenaCoins'); ref.innerHTML=svg('refresh');
    after.appendChild(ref);
    secA.appendChild(rowCoins);

    const acInit=readACFromDOM(); qs('#mbl-coins-val').textContent = (acInit!=null?acInit.toFixed(2):'0.00');
    ref.addEventListener('click', refreshCoins);

    // Utente: avatar + username + (icona messaggi a destra)
    const rowUser=document.createElement('div'); rowUser.className='mbl-kv';
    const kU=document.createElement('div'); kU.className='k'; kU.textContent='Utente:';
    const vU=document.createElement('div'); vU.className='v';
    const usr=qs('.hdr__usr'); vU.textContent = usr ? txt(usr) : (txt(qs('.hdr__right'))||'');
    const afterU=document.createElement('div'); afterU.className='after';
    // icona messaggi
    const msgA=findMsgLink();
    if(msgA){ const m=msgA.cloneNode(true); m.innerHTML=svg('msg'); m.setAttribute('aria-label','Messaggi'); m.className='mbl-ref'; afterU.appendChild(m); }
    rowUser.appendChild(kU); rowUser.appendChild(vU); rowUser.appendChild(afterU);
    secA.appendChild(rowUser);

    // CTA ricarica/logout
    const row=document.createElement('div'); row.className='mbl-ctaRow';
    const ricarica = gather('.hdr__right a').find(a=> /ricar/i.test((a.href||'')+txt(a))) || null;
    const logout   = gather('.hdr__right a').find(a=> /logout|esci/i.test((a.href||'')+txt(a))) || gather('a').find(a=>/logout|esci/i.test((a.href||'')+txt(a))) || null;
    if(ricarica){ const p=ricarica.cloneNode(true); p.classList.add('mbl-cta'); row.appendChild(p); }
    if(logout){ const g=logout.cloneNode(true); g.classList.add('mbl-ghost'); row.appendChild(g); }
    secA.appendChild(row);
    sc.appendChild(secA);

    // NAV + INFO
    const nav=gather('.subhdr .subhdr__menu a'); if(nav.length){ const s=document.createElement('section'); s.className='mbl-sec mbl-sec--nav'; const h=document.createElement('div'); h.className='mbl-sec__title'; h.textContent='Navigazione'; s.appendChild(h); s.appendChild(makeList(nav)); sc.appendChild(s); }
    const info=gather('.site-footer .footer-menu a'); if(info.length){ const s2=document.createElement('section'); s2.className='mbl-sec'; const h2=document.createElement('div'); h2.className='mbl-sec__title'; h2.textContent='Info'; s2.appendChild(h2); s2.appendChild(makeList(info)); sc.appendChild(s2); }
  }
})();
