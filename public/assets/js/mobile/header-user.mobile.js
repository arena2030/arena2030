/*! Mobile Header – USER (guest intatto) */
(function () {
  'use strict';

  // Parti solo se utente presente e viewport mobile
  const userNode = document.querySelector('.hdr__usr');
  if (!userNode) return;
  const isMobile = () => (window.matchMedia ? window.matchMedia('(max-width: 768px)').matches : (window.innerWidth||0) <= 768);
  if (!isMobile()) return;

  const qs=(s,r)=> (r||document).querySelector(s);
  const qsa=(s,r)=> Array.prototype.slice.call((r||document).querySelectorAll(s));
  const txt=(el)=> (el && (el.textContent||'').trim()) || '';

  // Rimuovi residui guest se presenti
  ['#mbl-guestBtn','#mbl-guestDrawer','#mbl-guestBackdrop'].forEach(sel=>{
    const n=qs(sel); if(n && n.parentNode) n.parentNode.removeChild(n);
  });

  // Placeholders per ripristino quando si esce da mobile
  const state = {
    phRic: document.createComment('mbl-ric-ph'),
    phUsr: document.createComment('mbl-usr-ph'),
    moved:false,
    mql:null
  };

  const bar = qs('.hdr__bar');
  const right = qs('.hdr__right');
  if (!bar || !right) return;

  function svg(name){
    if(name==='menu')    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    if(name==='close')   return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    if(name==='refresh') return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 12a8 8 0 1 1-2.34-5.66M20 4v6h-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    if(name==='msg')     return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H8l-4 4V5a2 2 0 0 1 2-2h13a2 2 0 0 1 2 2z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>';
    return '';
  }

  // Trova link utili nell'header utente
  function findRicarica(){
    const all = qsa('.hdr__right a,.hdr__nav a');
    return all.find(a => /ricar/i.test((a.href||'')+txt(a))) || null;
  }
  function findLogout(){
    const all = qsa('.hdr__right a, a');
    return all.find(a => /logout|esci/i.test((a.href||'')+txt(a))) || null;
  }
  function findMsgLink(){
    const cand = qsa('.hdr__right a,.hdr__nav a');
    return cand.find(a=>{
      const t=(a.getAttribute('title')||'')+' '+txt(a)+' '+(a.href||'');
      return /mess/i.test(t);
    }) || null;
  }

  // Costruisci gruppo dx (Ricarica + Avatar + Username) e hamburger
  function mountHeaderGroup(){
    if (qs('#mbl-userGroup')) return;

    const group = document.createElement('div');
    group.id='mbl-userGroup';

    // Sposta Ricarica e .hdr__usr dal loro posto dentro al gruppo (con placeholder)
    const ric = findRicarica();
    if (ric && !state.moved){
      ric.parentNode.insertBefore(state.phRic, ric);
      group.appendChild(ric);
    }
    if (!state.moved){
      userNode.parentNode.insertBefore(state.phUsr, userNode);
      group.appendChild(userNode);
    }

    // Badge avatar se non presente
    if (!userNode.querySelector('.mbl-badge')) {
      const name = txt(userNode) || 'U';
      const ch = name.trim().charAt(0).toUpperCase();
      const badge = document.createElement('span');
      badge.className='mbl-badge';
      badge.textContent = ch || 'U';
      userNode.prepend(badge);
    }

    // Inserisci gruppo e poi hamburger
    bar.appendChild(group);

    if (!qs('#mbl-userBtn', bar)){
      const btn = document.createElement('button');
      btn.id='mbl-userBtn'; btn.type='button';
      btn.setAttribute('aria-label','Apri menu'); btn.setAttribute('aria-controls','mbl-userDrawer'); btn.setAttribute('aria-expanded','false');
      btn.innerHTML = svg('menu');
      bar.appendChild(btn);
    }

    state.moved = true;
  }

  // Drawer + backdrop (unico, idempotente)
  function ensureDrawer(){
    if (qs('#mbl-userDrawer')) return;

    const dr=document.createElement('aside');
    dr.id='mbl-userDrawer'; dr.setAttribute('role','dialog'); dr.setAttribute('aria-modal','true'); dr.setAttribute('aria-hidden','true'); dr.tabIndex=-1;

    const head=document.createElement('div'); head.className='mbl-head';
    const ttl=document.createElement('div'); ttl.className='mbl-title'; ttl.textContent='Menu';
    const cls=document.createElement('button'); cls.type='button'; cls.className='mbl-close'; cls.setAttribute('aria-label','Chiudi menu'); cls.innerHTML=svg('close');
    head.appendChild(ttl); head.appendChild(cls); dr.appendChild(head);

    const sc=document.createElement('div'); sc.className='mbl-scroll'; dr.appendChild(sc);

    const bd=document.createElement('div'); bd.id='mbl-userBackdrop'; bd.setAttribute('hidden','hidden');
    document.body.appendChild(dr); document.body.appendChild(bd);

    // Sezioni del menu
    fillUserSections(sc);

    // Focus trap / open-close
    function getFocusable(root){
      return qsa('a[href],button:not([disabled]),input,select,textarea,[tabindex]:not([tabindex="-1"])',root)
        .filter(el=> !!(el.offsetWidth||el.offsetHeight||el.getClientRects().length));
    }
    function trap(ev){
      const it=getFocusable(dr); if(!it.length) return;
      const first=it[0], last=it[it.length-1];
      if(ev.shiftKey){ if(document.activeElement===first || !dr.contains(document.activeElement)){ev.preventDefault(); last.focus();} }
      else{ if(document.activeElement===last){ev.preventDefault(); first.focus();} }
    }
    function open(){
      document.documentElement.classList.add('mbl-lock'); document.body.classList.add('mbl-lock');
      bd.removeAttribute('hidden'); bd.classList.add('mbl-open'); dr.classList.add('mbl-open'); dr.setAttribute('aria-hidden','false');
      qs('#mbl-userBtn')?.setAttribute('aria-expanded','true');
      (getFocusable(dr)[0]||dr).focus({preventScroll:true});
    }
    function close(){
      dr.classList.remove('mbl-open'); dr.setAttribute('aria-hidden','true');
      bd.classList.remove('mbl-open'); bd.setAttribute('hidden','hidden');
      document.documentElement.classList.remove('mbl-lock'); document.body.classList.remove('mbl-lock');
      qs('#mbl-userBtn')?.setAttribute('aria-expanded','false');
      qs('#mbl-userBtn')?.focus({preventScroll:true});
    }

    // Bind
    qs('#mbl-userBtn')?.addEventListener('click', open);
    cls.addEventListener('click', close);
    bd.addEventListener('click', close);
    dr.addEventListener('keydown', (e)=>{ if(e.key==='Escape') close(); if(e.key==='Tab') trap(e); });
    document.addEventListener('click', (e)=>{ if(bd.hasAttribute('hidden')) return; const a=e.target.closest('a'); if(a && !dr.contains(a)) close(); });
    window.addEventListener('hashchange', close);
  }

  // Helpers per costruire menu
  function gather(sel){ return qsa(sel).filter(a=> a && a.tagName==='A' && (a.href||'').length && txt(a)); }
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

  function readAC(){
    const pill=qs('.pill-balance .ac'); if(pill && !isNaN(parseFloat(pill.textContent))) return parseFloat(pill.textContent);
    const me=qs('#meCoins'); if(me && !isNaN(parseFloat(me.textContent))) return parseFloat(me.textContent);
    return null;
  }
  async function refreshCoins(){
    try{
      const r=await fetch('/premi.php?action=me',{credentials:'same-origin',cache:'no-store'});
      const j=await r.json().catch(()=>null);
      let val=(j && j.ok && j.me && j.me.coins!=null)? parseFloat(j.me.coins) : readAC();
      if(val!=null){
        const out=qs('#mbl-coins-val'); if(out) out.textContent=val.toFixed(2);
        const pill=qs('.pill-balance .ac'); if(pill) pill.textContent=val.toFixed(2);
        const me=qs('#meCoins'); if(me) me.textContent=val.toFixed(2);
      }
    }catch(_){
      const v=readAC(); if(v!=null){ const out=qs('#mbl-coins-val'); if(out) out.textContent=v.toFixed(2); }
    }
  }

  function fillUserSections(sc){
    sc.innerHTML='';

    // ACCOUNT
    const secA=document.createElement('section'); secA.className='mbl-sec';
    const hA=document.createElement('div'); hA.className='mbl-sec__title'; hA.textContent='Account'; secA.appendChild(hA);

    // ArenaCoins + refresh vicino
    const rowC=document.createElement('div'); rowC.className='mbl-kv';
    rowC.innerHTML='<div class="k">ArenaCoins:</div><div class="v" id="mbl-coins-val">—</div><div class="after"></div>';
    const ref=document.createElement('button'); ref.type='button'; ref.className='mbl-ref'; ref.setAttribute('aria-label','Aggiorna ArenaCoins'); ref.innerHTML=svg('refresh');
    rowC.querySelector('.after').appendChild(ref);
    secA.appendChild(rowC);
    const initAC = readAC(); qs('#mbl-coins-val').textContent = (initAC!=null ? initAC.toFixed(2) : '0.00');
    ref.addEventListener('click', refreshCoins);

    // Utente + icona messaggi a destra
    const rowU=document.createElement('div'); rowU.className='mbl-kv';
    const kU=document.createElement('div'); kU.className='k'; kU.textContent='Utente:';
    const vU=document.createElement('div'); vU.className='v'; vU.textContent = txt(userNode);
    const aft=document.createElement('div'); aft.className='after';
    const msg = findMsgLink();
    if(msg){ const m=msg.cloneNode(true); m.innerHTML=svg('msg'); m.className='mbl-ref'; m.setAttribute('aria-label','Messaggi'); aft.appendChild(m); }
    rowU.appendChild(kU); rowU.appendChild(vU); rowU.appendChild(aft);
    secA.appendChild(rowU);

    // CTA Ricarica/Logout stessa altezza
    const row=document.createElement('div'); row.className='mbl-ctaRow';
    const ric = findRicarica(); if(ric){ const p=ric.cloneNode(true); p.classList.add('mbl-cta'); row.appendChild(p); }
    const lo = findLogout(); if(lo){ const g=lo.cloneNode(true); g.classList.add('mbl-ghost'); row.appendChild(g); }
    secA.appendChild(row);
    sc.appendChild(secA);

    // NAV + INFO
    const nav=gather('.subhdr .subhdr__menu a'); section('Navigazione', nav, sc, 'mbl-sec--nav');
    const info=gather('.site-footer .footer-menu a'); section('Info', info, sc, '');
  }

  // Mount e Drawer
  mountHeaderGroup();
  ensureDrawer();

  // Gestione resize: se esco da mobile ripristino
  if (window.matchMedia){
    state.mql = window.matchMedia('(max-width: 768px)');
    const onChange = e=>{
      if(!e.matches){
        // Ritorna i nodi al loro posto originale
        const ric = findRicarica(); if(ric && state.phRic.parentNode){ state.phRic.parentNode.insertBefore(ric, state.phRic); }
        if(state.phUsr.parentNode){ state.phUsr.parentNode.insertBefore(userNode, state.phUsr); }
        const badge = userNode.querySelector('.mbl-badge'); if(badge) badge.remove();
        ['#mbl-userGroup','#mbl-userBtn','#mbl-userDrawer','#mbl-userBackdrop']
          .forEach(sel=>{ const n=qs(sel); if(n&&n.parentNode) n.parentNode.removeChild(n); });
        state.moved=false;
      } else {
        // Rientro mobile
        mountHeaderGroup(); ensureDrawer();
      }
    };
    if (state.mql.addEventListener) state.mql.addEventListener('change', onChange);
    else if (state.mql.addListener) state.mql.addListener(onChange);
  }
})();
