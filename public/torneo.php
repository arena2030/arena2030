<script>
document.addEventListener('DOMContentLoaded', ()=>{
  const $ = s=>document.querySelector(s);
  const $$= (s,p=document)=>[...p.querySelectorAll(s)];

  const qs   = new URLSearchParams(location.search);
  const tid  = Number(qs.get('id')||0) || 0;
  const tcode= qs.get('tid') || '';
  let TID = tid, TCODE = tcode;
  let ROUND=1, BUYIN=0;

  const API_URL = new URL('/api/torneo.php', location.origin);

  function API_GET(params){
    const url = new URL(API_URL);
    if (TID) url.searchParams.set('id', String(TID)); else if (TCODE) url.searchParams.set('tid', TCODE);
    for (const [k,v] of params.entries()) url.searchParams.set(k,v);
    return fetch(url.toString(), { cache:'no-store', credentials:'same-origin' });
  }
  function API_POST(params){
    const url = new URL(API_URL);
    const body = new URLSearchParams(params);
    if (TID && !body.has('id')) body.set('id', String(TID));
    else if (TCODE && !body.has('tid')) body.set('tid', TCODE);
    return fetch(url.toString(), {
      method:'POST',
      headers:{ 'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8', 'Accept':'application/json' },
      body: body.toString(),
      credentials:'same-origin'
    });
  }

  const toast = (msg)=>{ const h=$('#hint'); h.textContent=msg; setTimeout(()=>h.textContent='', 2500); };
  const fmt   = (n)=> Number(n||0).toFixed(2);

  function showModal(id){
    const m=document.getElementById(id); if(!m) return;
    m.removeAttribute('inert'); m.setAttribute('aria-hidden','false');
    const focusable=m.querySelector('button,[href],input,select,textarea,[tabindex]:not([tabindex="-1"])');
    if (focusable && focusable.focus) try{ focusable.focus(); }catch(e){}
  }
  function hideModal(id){
    const m=document.getElementById(id); if(!m) return;
    if (m.contains(document.activeElement)) document.activeElement.blur();
    m.setAttribute('aria-hidden','true'); m.setAttribute('inert','');
  }
  $$('#mdConfirm [data-close], #mdConfirm .modal-backdrop').forEach(el=>el.addEventListener('click', ()=>hideModal('mdConfirm')));
  $$('#mdInfo [data-close], #mdInfo .modal-backdrop').forEach(el=>el.addEventListener('click', ()=>hideModal('mdInfo')));

  function openConfirm(title, html, onConfirm){
    $('#mdTitle').textContent = title;
    $('#mdText').innerHTML    = html;
    const okBtn = $('#mdOk');
    const clone = okBtn.cloneNode(true); okBtn.parentNode.replaceChild(clone, okBtn);
    const ok    = $('#mdOk');
    ok.addEventListener('click', async ()=>{
      ok.disabled=true;
      try{ await onConfirm(); hideModal('mdConfirm'); } finally { ok.disabled=false; }
    }, { once:true });
    showModal('mdConfirm');
  }

  function alertDebug(prefix, raw, j){
    let msg = prefix;
    if (j && typeof j==='object'){
      if (j.error)   msg += `\nerror: ${j.error}`;
      if (j.detail)  msg += `\ndetail: ${j.detail}`;
      if (j.dbg && j.dbg.sql)    msg += `\nsql: ${j.dbg.sql}`;
      if (j.dbg && j.dbg.params) msg += `\nparams: ${JSON.stringify(j.dbg.params)}`;
      if (j.dbg && j.dbg.trace)  msg += `\ntrace: ${j.dbg.trace.split('\n')[0]}…`;
    } else if (raw){
      msg += `\nraw: ${raw.slice(0,240)}`;
    }
    alert(msg);
  }

  async function loadSummary(){
    const p=new URLSearchParams({action:'summary', debug:'1'}); // ⇦ puoi togliere debug=1 quando è tutto ok
    const rsp = await API_GET(p);
    const txt = await rsp.text(); let j; try{ j=JSON.parse(txt);}catch(e){ alertDebug('[SUMMARY] Risposta non JSON:', txt); return; }
    if (!j.ok){ alertDebug('[SUMMARY] errore:', txt, j); return; }

    const t = j.tournament || {};
    TID = t.id || TID; ROUND = t.current_round || 1; BUYIN = t.buyin || 0;

    $('#tTitle').textContent = t.title || 'Torneo';
    $('#tSub').textContent   = [t.league,t.season].filter(Boolean).join(' • ') || '';
    const st = t.state || 'APERTO'; const se=$('#tState'); se.textContent=st; se.className='state '+(st==='APERTO'?'open':(st==='IN CORSO'?'live':'end'));

    $('#kLives').textContent = j.stats?.lives_in_play ?? 0;
    $('#kPool').textContent  = fmt(t.pool_coins ?? 0);
    $('#kLmax').textContent  = (t.lives_max_user==null? 'n/d' : String(t.lives_max_user));

    const lock = t.lock_round || t.lock_r1 || null;
    const kLock = $('#kLock');
    if (lock){ kLock.setAttribute('data-lock', String((new Date(lock)).getTime())); } else { kLock.setAttribute('data-lock','0'); }

    const vbar = $('#vbar'); vbar.innerHTML='';
    const lives = (j.me && j.me.lives) ? j.me.lives : [];
    if (lives.length){
      lives.forEach((lv,idx)=>{
        const d=document.createElement('div'); d.className='life'; d.setAttribute('data-id', String(lv.id));
        d.innerHTML = `<span class="heart"></span><span>Vita ${idx+1}</span>`;
        d.addEventListener('click', ()=>{ $$('.life').forEach(x=>x.classList.remove('active')); d.classList.add('active'); });
        vbar.appendChild(d);
      });
      const first=$('.life'); if(first) first.classList.add('active');
    } else {
      const s=document.createElement('span'); s.className='muted'; s.textContent='Nessuna vita: acquista una vita per iniziare.'; vbar.appendChild(s);
    }

    (function tick(){
      const el=$('#kLock'); const ts=Number(el.getAttribute('data-lock')||0);
      const now=Date.now(); const diff=Math.floor((ts-now)/1000);
      if(!ts){ el.textContent='—'; $('#lockTxt').textContent=''; return; }
      if(diff<=0){ el.textContent='CHIUSO'; $('#lockTxt').textContent='Lock passato'; return; }
      let d=diff, dd=Math.floor(d/86400); d%=86400;
      const hh=String(Math.floor(d/3600)).padStart(2,'0'); d%=3600;
      const mm=String(Math.floor(d/60)).padStart(2,'0'); const ss=String(d%60).padStart(2,'0');
      const s = (dd>0? dd+'g ':'')+hh+':'+mm+':'+ss;
      el.textContent = s; $('#lockTxt').textContent='Lock tra '+s;
      requestAnimationFrame(tick);
    })();

    await Promise.all([loadTrending(), loadEvents()]);
  }

  async function loadTrending(){
    const p=new URLSearchParams({action:'trending', round:String(ROUND), debug:'1'});
    const rsp = await API_GET(p);
    const txt = await rsp.text(); let j; try{ j=JSON.parse(txt);}catch(e){ alertDebug('[TRENDING] non JSON:', txt); return; }
    if (!j.ok && !j.items){ alertDebug('[TRENDING] errore:', txt, j); }
    const box=$('#trend'); box.innerHTML='';
    const items=j.items||[];
    if (!items.length){ box.innerHTML='<div class="muted">Ancora nessuna scelta.</div>'; return; }
    items.forEach(it=>{
      const d=document.createElement('div'); d.className='chip';
      d.innerHTML = `${it.logo? `<img src="${it.logo}" alt="">` : '<span style="width:18px;height:18px;border-radius:50%;background:#1f2937;display:inline-block;"></span>'}
                     <strong>${it.name||('#'+it.team_id)}</strong>
                     <span class="cnt">× ${it.cnt||0}</span>`;
      box.appendChild(d);
    });
  }

  async function loadEvents(){
    const p=new URLSearchParams({action:'events', round:String(ROUND), debug:'1'});
    const rsp = await API_GET(p);
    const txt = await rsp.text(); let j; try{ j=JSON.parse(txt);}catch(e){ alertDebug('[EVENTS] non JSON:', txt); return; }
    if (!j.ok){ alertDebug('[EVENTS] errore:', txt, j); return; }
    const box=$('#events'); box.innerHTML='';
    const evs=j.events||[];
    if (!evs.length){ box.innerHTML='<div class="muted">Nessun evento per questo round.</div>'; return; }
    evs.forEach(ev=>{
      const d=document.createElement('div'); d.className='evt';
      d.innerHTML = `
        <div class="team">${ev.home_logo? `<img src="${ev.home_logo}" alt="">` : ''}<strong>${ev.home_name||('#'+(ev.home_id||'?'))}</strong></div>
        <div class="vs">VS</div>
        <div class="team"><strong>${ev.away_name||('#'+(ev.away_id||'?'))}</strong>${ev.away_logo? `<img src="${ev.away_logo}" alt="">` : ''}</div>
        <div class="flag"></div>
      `;
      d.addEventListener('click', ()=> pickTeamOnEvent(ev, d));
      box.appendChild(d);
    });
  }

  function pickTeamOnEvent(ev, cardEl){
    $('#mdTitle').textContent = 'Conferma scelta';
    $('#mdText').innerHTML    = `
      Scegli la squadra per la tua vita:<br><br>
      <div style="display:flex; gap:8px; align-items:center; justify-content:center;">
        <button class="btn btn--outline" type="button" id="chooseA">${ev.home_name||('#'+ev.home_id)}</button>
        <strong>VS</strong>
        <button class="btn btn--outline" type="button" id="chooseB">${ev.away_name||('#'+ev.away_id)}</button>
      </div>`;
    $('#mdOk').style.display  = 'none';
    showModal('mdConfirm');

    const closeAll = ()=>{ $('#mdOk').style.display=''; hideModal('mdConfirm'); };
    const doPick   = async (teamId, teamName, teamLogo)=>{
      const life = (()=>{ const a=$('.life.active'); return a? Number(a.getAttribute('data-id')): 0; })();
      if (!life){ toast('Seleziona prima una vita'); closeAll(); return; }

      const fd = new URLSearchParams({ action:'pick', life_id:String(life), event_id:String(ev.id), team_id:String(teamId), round:String(ROUND), debug:'1' });
      const rsp = await API_POST(fd);
      const raw = await rsp.text(); let j; try{ j=JSON.parse(raw);}catch(e){ alertDebug('[PICK] non JSON:', raw); closeAll(); return; }
      if (!j.ok){ alertDebug('[PICK]', raw, j); closeAll(); return; }

      cardEl.classList.add('selected');
      const lifeEl = document.querySelector('.life.active');
      if (lifeEl){
        let img = lifeEl.querySelector('img.logo');
        if (!img){ img=document.createElement('img'); img.className='logo'; lifeEl.appendChild(img); }
        img.src = teamLogo || ''; img.alt = teamName || ''; img.title = teamName || '';
        img.style.display = teamLogo ? '' : 'none';
      }
      toast('Scelta salvata');
      closeAll();
      loadTrending();
    };

    $('#chooseA').addEventListener('click', ()=>doPick(ev.home_id, ev.home_name, ev.home_logo), {once:true});
    $('#chooseB').addEventListener('click', ()=>doPick(ev.away_id, ev.away_name, ev.away_logo), {once:true});
    $$('#mdConfirm [data-close], #mdConfirm .modal-backdrop').forEach(el=>el.addEventListener('click', ()=>{ $('#mdOk').style.display=''; }, {once:true}));
  }

  $('#btnBuy').addEventListener('click', ()=>{
    openConfirm(
      'Acquista vita',
      `Confermi l’acquisto di <strong>1 vita</strong> per <strong>${fmt(BUYIN)}</strong> AC?`,
      async ()=>{
        const fd=new URLSearchParams({action:'buy_life', debug:'1'});
        const rsp=await API_POST(fd);
        const txt=await rsp.text(); let j; try{ j=JSON.parse(txt);}catch(e){ alertDebug('[BUY] non JSON:', txt); return; }
        if (!j.ok){ alertDebug('[BUY]', txt, j); return; }
        toast('Vita acquistata');
        document.dispatchEvent(new CustomEvent('refresh-balance'));
        await loadSummary();
      }
    );
  });

  $('#btnUnjoin').addEventListener('click', ()=>{
    openConfirm(
      'Disiscrizione',
      `Confermi la disiscrizione? Ti verranno rimborsati <strong>${fmt(BUYIN)}</strong> AC per ogni vita posseduta.`,
      async ()=>{
        const fd=new URLSearchParams({action:'unjoin', debug:'1'});
        const rsp=await API_POST(fd);
        const txt=await rsp.text(); let j; try{ j=JSON.parse(txt);}catch(e){ alertDebug('[UNJOIN] non JSON:', txt); return; }
        if (!j.ok){ alertDebug('[UNJOIN]', txt, j); return; }
        toast('Disiscrizione completata');
        document.dispatchEvent(new CustomEvent('refresh-balance'));
        location.href='/lobby.php';
      }
    );
  });

  $('#btnInfo').addEventListener('click', async ()=>{
    const p=new URLSearchParams({action:'choices_info', round:String(ROUND), debug:'1'});
    const rsp=await API_GET(p);
    const txt=await rsp.text(); let j; try{ j=JSON.parse(txt);}catch(e){ alertDebug('[CHOICES] non JSON:', txt); return; }
    const box=$('#infoList'); box.innerHTML='';
    const rows=j.rows||[];
    if (!rows.length){ box.innerHTML='<div>Nessuna scelta disponibile.</div>'; }
    else {
      const ul=document.createElement('div'); ul.style.display='grid'; ul.style.gap='6px';
      rows.forEach(row=>{
        const div=document.createElement('div');
        div.textContent = (row.username||'utente') + ' → ' + (row.team_name||('#'+row.team_id));
        ul.appendChild(div);
      });
      box.appendChild(ul);
    }
    showModal('mdInfo');
  });

  loadSummary();
});
</script>
