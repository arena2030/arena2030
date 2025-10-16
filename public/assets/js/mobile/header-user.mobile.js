/*! Header User – Mobile shell + drawer (riusa logiche desktop) */
(function(){
  'use strict';

  if (window.__ARENA_MBL_USER__) return;
  window.__ARENA_MBL_USER__ = true;

  const isMobile = () => (window.matchMedia ? window.matchMedia('(max-width:768px)').matches : (window.innerWidth||0)<=768);
  const qs  = (s,r)=> (r||document).querySelector(s);
  const qsa = (s,r)=> Array.prototype.slice.call((r||document).querySelectorAll(s));
  const txt = (el)=> (el && (el.textContent||'').trim()) || '';

  if (!isMobile()) return;

  /* -------- helpers HTML sorgente (desktop) -------- */
  const hdr  = qs('header.hdr');                // nascosto in mobile (display:none)
  const brand= qsa('.hdr__brand')[0] || null;   // clone logo
  const user = qs('.hdr__usr') || null;         // username
  const pill = qs('.pill-balance .ac, [data-balance-amount]') || null; // saldo
  const avatImg = qs('#avatarImg');
  const avatInit= qs('#avatarInitial');

  const ricarica = findLink(/ricar/i);
  const logout   = findLink(/logout|esci/i);
  const msgLink  = findLink(/mess/i); // link/widget messaggi dal desktop

  function findLink(re){
    const all = qsa('a');
    return all.find(a => re.test(((a.href||'')+txt(a)+(a.title||'')))) || null;
  }

  function coinText(){ return pill ? (pill.textContent||'0').trim() : '0.00'; }
  function dispatchRefresh(){ document.dispatchEvent(new CustomEvent('refresh-balance')); }

  /* -------- svg -------- */
  function svg(n){
    if(n==='menu')    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    if(n==='x')       return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    if(n==='refresh') return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 12a8 8 0 1 1-2.34-5.66M20 4v6h-6" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round"/></svg>';
    if(n==='msg')     return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H8l-4 3V5a2 2 0 0 1 2-2h13a2 2 0 0 1 2 2v10Z" stroke="currentColor" stroke-width="2" fill="none" stroke-linejoin="round"/></svg>';
    return '';
  }

  /* -------- azioni che riusano la logica desktop -------- */
  function openAvatarModal(){
    const b = qs('#btnAvatar'); if (!b) return;
    b.dispatchEvent(new MouseEvent('click', {bubbles:true}));
  }
  function openMessages(e){
    if (e){ e.preventDefault(); e.stopPropagation(); }
    if (!msgLink) return;
    // inoltro il click sul vero link/widget messaggi del desktop (stesse funzioni/ popup)
    msgLink.dispatchEvent(new MouseEvent('click', {bubbles:true, cancelable:true}));
  }

  /* -------- shell header (logo + msg + avatar + username + hamburger) -------- */
  function buildShell(){
    if (qs('#mblu-bar')) return;
    const bar = document.createElement('div'); bar.id='mblu-bar';

    // brand sx (clono il contenuto del tuo .hdr__brand)
    const aBrand = document.createElement('a'); aBrand.id='mblu-brand'; aBrand.href='/lobby.php';
    if (brand){ aBrand.innerHTML = brand.innerHTML; }
    else { aBrand.innerHTML = '<img src="/assets/logo_arena.png" alt="ARENA">'; }
    bar.appendChild(aBrand);

    // dx
    const right = document.createElement('div'); right.id='mblu-right';

    // (NUOVO) Messaggi prima dell'avatar – solo se esiste il link desktop
    if (msgLink){
      const mb = document.createElement('button');
      mb.id = 'mblu-msgBtn'; mb.type = 'button';
      mb.setAttribute('aria-label','Messaggi');
      // uso l'icona inline; se vuoi puoi anche mb.innerHTML = msgLink.innerHTML;
      mb.innerHTML = svg('msg');
      mb.addEventListener('click', openMessages, {passive:false});
      right.appendChild(mb);
    }

    // chip user (avatar + username) – opzionale se disponibili
    if (user){
      const chip = document.createElement('div'); chip.id='mblu-usrChip';

      const av = document.createElement('span'); av.id='mblu-usrAv';
      if (avatImg && avatImg.getAttribute('src')){
        av.innerHTML = `<img alt="Avatar" src="${avatImg.getAttribute('src')}">`;
      } else {
        const initial = (txt(user).charAt(0) || 'U').toUpperCase();
        av.textContent = initial;
      }
      av.addEventListener('click', openAvatarModal, {passive:true});
      chip.appendChild(av);

      const name = document.createElement('span'); name.id='mblu-usrName'; name.textContent = txt(user);
      chip.appendChild(name);

      right.appendChild(chip);
    }

    // hamburger rotondo
    const hb = document.createElement('button'); hb.id='mblu-btn'; hb.type='button';
    hb.setAttribute('aria-label','Apri menu'); hb.setAttribute('aria-controls','mblu-drawer'); hb.setAttribute('aria-expanded','false');
    hb.innerHTML = svg('menu');
    right.appendChild(hb);

    bar.appendChild(right);
    document.body.prepend(bar);

    // overlay + drawer
    ensureDrawer();

    // binding a prova di overlay
    const openEv = (e)=>{ e.preventDefault(); e.stopPropagation(); toggleDrawer(); };
    document.addEventListener('pointerdown', (e)=>{ if (e.target.closest && e.target.closest('#mblu-btn')) openEv(e); }, true);
    hb.addEventListener('click', openEv, {passive:false});
  }

  /* -------- drawer -------- */
  function ensureDrawer(){
    if (qs('#mblu-drawer')) return;

    const bd = document.createElement('div'); bd.id='mblu-backdrop'; bd.setAttribute('hidden','hidden');
    document.body.appendChild(bd);

    const dr = document.createElement('aside'); dr.id='mblu-drawer';
    dr.setAttribute('role','dialog'); dr.setAttribute('aria-modal','true'); dr.setAttribute('aria-hidden','true'); dr.tabIndex = -1;

    // head
    const head = document.createElement('div'); head.className='mbl-head';
    const ttl  = document.createElement('div'); ttl.className='mbl-title'; ttl.textContent='Menu';
    const cls  = document.createElement('button'); cls.className='mbl-close'; cls.type='button'; cls.setAttribute('aria-label','Chiudi'); cls.innerHTML = svg('x');
    head.appendChild(ttl); head.appendChild(cls); dr.appendChild(head);

    const sc = document.createElement('div'); sc.className='mbl-scroll'; dr.appendChild(sc);
    document.body.appendChild(dr);

    fillDrawer(sc);

    // chiusure
    const close = closeDrawer;
    cls.addEventListener('click', close, {passive:true});
    bd .addEventListener('click', close, {passive:true});
    dr .addEventListener('keydown', (e)=>{ if(e.key==='Escape') close(); if(e.key==='Tab') trapFocus(e, dr); });
    document.addEventListener('click', (e)=>{ if(bd.hasAttribute('hidden')) return; const a=e.target.closest&&e.target.closest('a'); if(a && !dr.contains(a)) close(); }, {passive:true});
    window.addEventListener('hashchange', close, {passive:true});
  }

  function fillDrawer(sc){
    sc.innerHTML='';

    // ACCOUNT
    const secA = section('Account');
    // saldo + refresh vicino
    const rowC = kv('ArenaCoins:', `<span id="mblu-ac" class="v">${coinText()}</span><button id="mblu-refresh" type="button" aria-label="Aggiorna">${svg('refresh')}</button>`);
    secA._wrap.appendChild(rowC);

    // utente (+ eventuale messaggi nel drawer: lasciato invariato)
    const uname = txt(user) || 'Utente';
    const initial = uname.charAt(0).toUpperCase();
    const msgBtn = msgLink ? `<a id="mblu-msg" href="${msgLink.getAttribute('href')||'#'}" aria-label="Messaggi" class="msg">${svg('msg')}</a>` : '';
    const uHTML = `
      <span style="display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:9999px;border:1px solid var(--c-border,#20314b);background:var(--c-bg-2,#0f172a);font-weight:900;margin-right:8px;">${initial}</span>
      <span>${uname}</span>
      ${msgBtn}
    `;
    secA._wrap.appendChild(kv('Utente:', uHTML));

    // CTA: ricarica + logout
    const row = document.createElement('div'); row.className='mbl-ctaRow';
    if (ricarica){ const p = ricarica.cloneNode(true); p.classList.add('mbl-cta'); p.addEventListener('click', closeDrawer, {passive:true}); row.appendChild(p); }
    if (logout)  { const g = logout.cloneNode(true);   g.classList.add('mbl-ghost'); g.addEventListener('click', closeDrawer, {passive:true}); row.appendChild(g); }
    secA._wrap.appendChild(row);
    sc.appendChild(secA);

    // NAVIGAZIONE (dal subheader desktop)
    const navLinks = qsa('.subhdr .subhdr__menu a');
    if (navLinks.length){
      const secN = listSection('Navigazione', navLinks);
      sc.appendChild(secN);
    }

    // INFO (dal footer desktop)
    const infoLinks = qsa('.site-footer .footer-menu a');
    if (infoLinks.length){
      const secI = listSection('Info', infoLinks);
      sc.appendChild(secI);
    }

    // refresh saldo → riusa la logica desktop
    qs('#mblu-refresh')?.addEventListener('click', async (e)=>{
      e.preventDefault();
      dispatchRefresh();
      setTimeout(()=>{ const v = coinText(); const ac = qs('#mblu-ac'); if(ac) ac.textContent = v; }, 300);
    });

    // messaggi nel drawer (se presente) → chiudo drawer e lascio al widget desktop
    qs('#mblu-msg')?.addEventListener('click', ()=> closeDrawer(), {passive:true});
  }

  function section(title){
    const s = document.createElement('section'); s.className='mbl-sec';
    const h = document.createElement('div'); h.className='mbl-sec__title'; h.textContent=title;
    const w = document.createElement('div'); s.appendChild(h); s.appendChild(w); s._wrap = w; return s;
  }
  function kv(k, innerHTML){
    const row = document.createElement('div'); row.className='mbl-kv';
    const kk = document.createElement('div'); kk.className='k'; kk.textContent=k;
    const vv = document.createElement('div'); vv.className='v'; vv.innerHTML = innerHTML;
    row.appendChild(kk); row.appendChild(vv); return row;
  }
  function listSection(title, links){
    const s = document.createElement('section'); s.className='mbl-sec';
    const h = document.createElement('div'); h.className='mbl-sec__title'; h.textContent=title; s.appendChild(h);
    const ul = document.createElement('ul'); ul.className='mbl-list'; s.appendChild(ul);
    links.forEach(a=>{ const li=document.createElement('li'); const cp=a.cloneNode(true); cp.removeAttribute('id'); cp.addEventListener('click', closeDrawer, {passive:true}); li.appendChild(cp); ul.appendChild(li); });
    return s;
  }

  /* -------- apertura/chiusura -------- */
  let _open=false, _last=null;
  function getFocusable(root){
    const sel='a[href],button:not([disabled]),input,select,textarea,[tabindex]:not([tabindex="-1"])';
    return qsa(sel, root).filter(el=> !!(el.offsetWidth||el.offsetHeight||el.getClientRects().length));
  }
  function trapFocus(ev, root){
    if (ev.key!=='Tab') return;
    const it=getFocusable(root); if(!it.length) return;
    const f=it[0], l=it[it.length-1];
    if (ev.shiftKey){ if (document.activeElement===f || !root.contains(document.activeElement)){ ev.preventDefault(); l.focus(); } }
    else { if (document.activeElement===l){ ev.preventDefault(); f.focus(); } }
  }
  function openDrawer(){
    ensureDrawer();
    const dr=qs('#mblu-drawer'), bd=qs('#mblu-backdrop'), btn=qs('#mblu-btn');
    _last=document.activeElement || btn;
    document.documentElement.classList.add('mbl-lock'); document.body.classList.add('mbl-lock');
    bd.removeAttribute('hidden'); bd.classList.add('mbl-open');
    dr.classList.add('mbl-open'); dr.setAttribute('aria-hidden','false');
    btn && btn.setAttribute('aria-expanded','true');
    const f=getFocusable(dr); (f[0]||dr).focus({preventScroll:true});
    _open=true;
  }
  function closeDrawer(){
    const dr=qs('#mblu-drawer'), bd=qs('#mblu-backdrop'), btn=qs('#mblu-btn');
    if(!dr||!bd) return;
    dr.classList.remove('mbl-open'); dr.setAttribute('aria-hidden','true');
    bd.classList.remove('mbl-open'); bd.setAttribute('hidden','hidden');
    document.documentElement.classList.remove('mbl-lock'); document.body.classList.remove('mbl-lock');
    btn && btn.setAttribute('aria-expanded','false');
    _last && _last.focus && _last.focus({preventScroll:true});
    _open=false;
  }
  function toggleDrawer(){ _open ? closeDrawer() : openDrawer(); }

  /* -------- bootstrap -------- */
  function boot(){
    if (!isMobile()) return;
    buildShell();
    ensureDrawer();
  }

  if (document.readyState === 'loading'){ document.addEventListener('DOMContentLoaded', boot); }
  else { boot(); }

})();
