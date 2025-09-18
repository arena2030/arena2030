<?php
// /public/torneo.php — VIEW sola interfaccia (usa /public/api/torneo.php per le azioni)
require_once __DIR__ . '/../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }

/* ===== DEBUG overlay anti-schermata-bianca (on-demand con ?debug=1) ===== */
$__DBG = (isset($_GET['debug']) && $_GET['debug']=='1');
if ($__DBG) { ini_set('display_errors','1'); error_reporting(E_ALL); }
register_shutdown_function(function() use($__DBG){
  if (!$__DBG) return;
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
    http_response_code(500);
    $msg = htmlspecialchars($e['message'] ?? '', ENT_QUOTES, 'UTF-8');
    $file= htmlspecialchars($e['file'] ?? '', ENT_QUOTES, 'UTF-8');
    $line= (int)($e['line'] ?? 0);
    echo "<div style=\"position:fixed;inset:0;z-index:999999;background:#0b1020;color:#f5d97b;font:14px/1.4 monospace;padding:16px;overflow:auto\">
            <div style=\"font-weight:800;margin-bottom:8px;\">[FATAL] torneo.php</div>
            <div><strong>message:</strong> {$msg}</div>
            <div><strong>file:</strong> {$file}</div>
            <div><strong>line:</strong> {$line}</div>
            <div style=\"margin-top:10px;color:#9fb7ff\">(disattiva rimuovendo &debug=1 dalla URL)</div>
          </div>";
  }
});

/* ===== Auth ===== */
$uid  = (int)($_SESSION['uid'] ?? 0);
$role = $_SESSION['role'] ?? '';
if ($uid <= 0 || !in_array($role, ['USER','PUNTO','ADMIN'], true)) {
  header('Location: /login.php'); exit;
}

$page_css='/pages-css/admin-dashboard.css';
include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/header_utente.php';
?>
<style>
/* ===== Layout & hero ===== */
.twrap{ max-width:1100px; margin:0 auto; }
.hero{
  position:relative; background:linear-gradient(135deg,#1e3a8a 0%, #0f172a 100%);
  border:1px solid rgba(255,255,255,.1); border-radius:20px; padding:18px 18px 14px;
  color:#fff; box-shadow:0 18px 60px rgba(0,0,0,.35);
}
.hero h1{ margin:0 0 4px; font-size:22px; font-weight:900; letter-spacing:.3px; }
.hero .sub{ opacity:.9; font-size:13px; }
.state{ position:absolute; top:12px; right:12px; font-size:12px; font-weight:800; letter-spacing:.4px;
  padding:4px 10px; border-radius:9999px; border:1px solid rgba(255,255,255,.25); background:rgba(0,0,0,.2); pointer-events:none; }
.state.open{ border-color: rgba(52,211,153,.45); color:#d1fae5; }
.state.live{ border-color: rgba(250,204,21,.55); color:#fef9c3; }
.state.end{  border-color: rgba(239,68,68,.45); color:#fee2e2; }
.kpis{ display:grid; grid-template-columns: repeat(4, 1fr); gap:12px; margin-top:12px; }
.kpi{ background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.08); border-radius:14px; padding:12px; text-align:center; }
.kpi .lbl{ font-size:12px; opacity:.9;}
.kpi .val{ font-size:18px; font-weight:900; letter-spacing:.3px; }
.countdown{ font-variant-numeric:tabular-nums; font-weight:900; }

/* ===== Azioni ===== */
.actions{ display:flex; justify-content:space-between; align-items:center; gap:12px; margin-top:12px; position:relative; z-index:5; }
.actions-left, .actions-right{ display:flex; gap:8px; align-items:center; }
.actions .btn { pointer-events:auto; }

/* ===== Vite ===== */
.vite-card{ margin-top:16px; background:#0b1220; border:1px solid #121b2d; border-radius:16px; padding:14px; color:#fff; }
.vbar{ display:flex; align-items:center; flex-wrap:wrap; gap:10px; margin-top:10px;}
.life{
  position:relative; display:flex; align-items:center; gap:6px; padding:6px 10px; border-radius:9999px;
  background:linear-gradient(135deg,#13203a 0%,#0c1528 100%); border:1px solid #1f2b46;
  cursor:pointer;
}
.life.active{ box-shadow:0 0 0 2px #2563eb inset; }
.life img.logo{ width:18px; height:18px; object-fit:cover; border-radius:50%; border:1px solid rgba(255,255,255,.35); }
.heart{ width:18px; height:18px; display:inline-block; background:url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" fill="%23FF3B3B" viewBox="0 0 24 24"><path d="M12 21s-8-6.438-8-11a5 5 0 0 1 9-3 5 5 0 0 1 9 3c0 4.562-8 11-8 11z"/></svg>') no-repeat center/contain; }
.life.lost .heart{ filter:grayscale(1) opacity(.5); }

/* ===== Gettonate ===== */
.trend-card{ margin-top:16px; background:#0b1220; border:1px solid #121b2d; border-radius:16px; padding:14px; color:#fff; }
.trend-title{ font-weight:800; }
.trend-chips{ display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; }
.chip{ display:flex; align-items:center; gap:8px; padding:6px 10px; border-radius:9999px; background:#0f172a; border:1px solid #14203a; }
.chip img{ width:18px; height:18px; border-radius:50%; object-fit:cover; }
.chip .cnt{ opacity:.8; font-size:12px; }

/* ===== Eventi ===== */
.events-card{ margin-top:16px; background:#0b1220; border:1px solid #121b2d; border-radius:16px; padding:14px; color:#fff; }
.round-head{ display:flex; align-items:center; gap:12px; margin-bottom:8px;}
.round-head h3{ margin:0; font-size:18px; font-weight:900;}
.egrid{ display:grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap:12px; }
@media (max-width:820px){ .egrid{ grid-template-columns: 1fr; } }
.evt{
  position:relative; display:flex; align-items:center; justify-content:center; gap:12px;
  background:radial-gradient(900px 200px at 50% -100px, rgba(99,102,241,.15), transparent 60%), linear-gradient(125deg,#111827 0%, #0b1120 100%);
  border:1px solid #1f2937; border-radius:9999px; padding:12px 16px; cursor:pointer;
  transition: transform .12s ease, box-shadow .12s ease;
}
.evt:hover{ transform:translateY(-1px); box-shadow:0 12px 30px rgba(0,0,0,.35);}
.team{ display:flex; align-items:center; gap:8px; min-width:0;}
.team img{ width:28px; height:28px; border-radius:50%; object-fit:cover; }
.vs{ font-weight:900; opacity:.9; }
.flag{ position:absolute; right:10px; top:-6px; width:20px; height:20px; border-radius:50%; background:#fde047; display:none; animation: pulse 1s infinite; }
@keyframes pulse{ 0%{transform:scale(.9)} 50%{transform:scale(1.1)} 100%{transform:scale(.9)} }
.evt.selected .flag{ display:block; }

/* ===== Bottoni ===== */
.btn[type="button"]{ cursor:pointer; }
.muted{ color:#9ca3af; font-size:12px; }

/* ===== Modali ===== */
.modal[aria-hidden="true"]{ display:none; } .modal{ position:fixed; inset:0; z-index:85;}
.modal-backdrop{ position:absolute; inset:0; background:rgba(0,0,0,.55); }
.modal-card{ position:relative; z-index:86; width:min(520px,94vw); margin:12vh auto 0;
  background:var(--c-bg); border:1px solid var(--c-border); border-radius:16px; overflow:hidden; box-shadow:0 18px 50px rgba(0,0,0,.5); color:#fff;}
.modal-head{ padding:12px 16px; border-bottom:1px solid var(--c-border); display:flex; align-items:center; gap:8px;}
.modal-body{ padding:16px;}
.modal-foot{ padding:12px 16px; border-top:1px solid var(--c-border); display:flex; justify-content:flex-end; gap:8px;}
</style>

<main class="section">
  <div class="container">
    <div class="twrap">
      <!-- HERO -->
      <div class="hero">
        <div class="code" id="tCode">#</div>
        <div class="state" id="tState">APERTO</div>
        <h1 id="tTitle">Torneo</h1>
        <div class="sub" id="tSub">Lega • Stagione</div>
        <div class="kpis">
          <div class="kpi"><div class="lbl">Vite in gioco</div><div class="val" id="kLives">0</div></div>
          <div class="kpi"><div class="lbl">Montepremi (AC)</div><div class="val" id="kPool">0.00</div></div>
          <div class="kpi"><div class="lbl">Vite max/utente</div><div class="val" id="kLmax">n/d</div></div>
          <div class="kpi"><div class="lbl">Lock round</div><div class="val countdown" id="kLock" data-lock=""></div></div>
        </div>
        <div class="actions">
          <div class="actions-left">
            <button class="btn btn--primary btn--sm" type="button" id="btnBuy">Acquista una vita</button>
            <button class="btn btn--ghost btn--sm" type="button" id="btnInfo">Infoscelte</button>
          </div>
          <div class="actions-right">
            <button class="btn btn--outline btn--sm" type="button" id="btnUnjoin">Disiscrivi</button>
          </div>
        </div>
        <span class="muted" id="hint"></span>
      </div>

      <!-- VITE -->
      <div class="vite-card">
        <strong>Le mie vite</strong>
        <div class="vbar" id="vbar"></div>
      </div>

      <!-- GETTONATE -->
      <div class="trend-card">
        <div class="trend-title">Gli utenti hanno scelto</div>
        <div class="trend-chips" id="trend"></div>
      </div>

      <!-- EVENTI -->
      <div class="events-card">
        <div class="round-head">
          <h3>Eventi torneo — Round <span id="rNow2">1</span></h3>
          <span class="muted" id="lockTxt"></span>
        </div>
        <div class="egrid" id="events"></div>
      </div>
    </div>
  </div>
</main>

<!-- Modal: conferme -->
<div class="modal" id="mdConfirm" aria-hidden="true">
  <div class="modal-backdrop" data-close></div>
  <div class="modal-card">
    <div class="modal-head"><h3 id="mdTitle">Conferma</h3></div>
    <div class="modal-body"><p id="mdText"></p></div>
    <div class="modal-foot">
      <button class="btn btn--outline" type="button" data-close>Annulla</button>
      <button class="btn btn--primary" type="button" id="mdOk">Conferma</button>
    </div>
  </div>
</div>

<!-- Modal: infoscelte -->
<div class="modal" id="mdInfo" aria-hidden="true">
  <div class="modal-backdrop" data-close></div>
  <div class="modal-card">
    <div class="modal-head"><h3>Trasparenza scelte</h3></div>
    <div class="modal-body"><div id="infoList" class="muted">Caricamento…</div></div>
    <div class="modal-foot"><button class="btn btn--primary" type="button" data-close>Chiudi</button></div>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', ()=>{
  const $  = s=>document.querySelector(s);
  const $$ = (s,p=document)=>[...p.querySelectorAll(s)];

  // === Torneo target ===
  const qs   = new URLSearchParams(location.search);
  const tid  = Number(qs.get('id')||0) || 0;
  const tcode= qs.get('tid') || '';
  let TID = tid, TCODE = tcode;
  let ROUND=1, BUYIN=0;

  // === Endpoint API assoluto ===
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

  // === UI util ===
  const toast = (msg)=>{ const h=$('#hint'); h.textContent=msg; setTimeout(()=>h.textContent='', 2500); };
  const fmt   = (n)=> Number(n||0).toFixed(2);

  // ===== Helpers modali: show/hide con blur focus + inert
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

  // ===== SUMMARY =====
  async function loadSummary(){
    const p=new URLSearchParams({action:'summary'});
    const rsp = await API_GET(p);
    const txt = await rsp.text(); let j; try{ j=JSON.parse(txt);}catch(e){ console.error('[SUMMARY] non JSON:', txt); toast('Errore torneo'); return; }
    if (!j.ok){ toast('Torneo non trovato'); return; }

    const t = j.tournament || {};
    TID = t.id || TID; ROUND = t.current_round || 1; BUYIN = t.buyin || 0;

    $('#tTitle').textContent = t.title || 'Torneo';
    $('#tCode').textContent = (t.code ? ('#'+t.code) : (t.id?('#'+t.id):''));
    $('#tSub').textContent   = [t.league,t.season].filter(Boolean).join(' • ') || '';
    const st = t.state || 'APERTO'; const se=$('#tState'); se.textContent=st; se.className='state '+(st==='APERTO'?'open':(st==='IN CORSO'?'live':'end'));

    $('#kLives').textContent = j.stats?.lives_in_play ?? 0;
    $('#kPool').textContent  = fmt(t.pool_coins ?? 0);
    $('#kLmax').textContent  = (t.lives_max_user==null? 'n/d' : String(t.lives_max_user));
    $('#rNow2').textContent  = String(ROUND);

    const lock = t.lock_round || t.lock_r1 || null;
    const kLock = $('#kLock');
    if (lock){ kLock.setAttribute('data-lock', String((new Date(lock)).getTime())); } else { kLock.setAttribute('data-lock','0'); }

    // render vite
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

    // lock ticker
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

  // ===== TRENDING =====
  async function loadTrending(){
    const p=new URLSearchParams({action:'trending', round:String(ROUND)});
    const rsp = await API_GET(p);
    const txt = await rsp.text(); let j; try{ j=JSON.parse(txt);}catch(e){ console.error('[TREND] non JSON:', txt); return; }
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

  // ===== EVENTI =====
  async function loadEvents(){
    const p=new URLSearchParams({action:'events', round:String(ROUND)});
    const rsp = await API_GET(p);
    const txt = await rsp.text(); let j; try{ j=JSON.parse(txt);}catch(e){ console.error('[EVENTS] non JSON:', txt); return; }
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

  // ===== PICK su evento =====
  function pickTeamOnEvent(ev, cardEl){
    const html = `
      Scegli la squadra per la tua vita:<br><br>
      <div style="display:flex; gap:8px; align-items:center; justify-content:center;">
        <button class="btn btn--outline" type="button" id="chooseA">${ev.home_name||('#'+ev.home_id)}</button>
        <strong>VS</strong>
        <button class="btn btn--outline" type="button" id="chooseB">${ev.away_name||('#'+ev.away_id)}</button>
      </div>
    `;
    $('#mdTitle').textContent = 'Conferma scelta';
    $('#mdText').innerHTML    = html;
    $('#mdOk').style.display  = 'none';
    showModal('mdConfirm');

    const closeAll = ()=>{ $('#mdOk').style.display=''; hideModal('mdConfirm'); };

    const doPick = async (teamId, teamName, teamLogo)=>{
      const life = (()=>{ const a=$('.life.active'); return a? Number(a.getAttribute('data-id')): 0; })();
      if (!life){ toast('Seleziona prima una vita'); closeAll(); return; }

      const fd = new URLSearchParams({ action:'pick', life_id:String(life), event_id:String(ev.id), team_id:String(teamId), round:String(ROUND) });
      const rsp = await API_POST(fd);
      const raw = await rsp.text(); let j; try{ j=JSON.parse(raw);}catch(e){ toast('Errore (non JSON)'); console.error('[PICK] raw:', raw); closeAll(); return; }
      if (!j.ok){ toast(j.detail || j.error || 'Errore scelta'); closeAll(); return; }

      // feedback
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

    const A = ()=> doPick(ev.home_id, ev.home_name, ev.home_logo);
    const B = ()=> doPick(ev.away_id, ev.away_name, ev.away_logo);

    $('#chooseA').addEventListener('click', A, {once:true});
    $('#chooseB').addEventListener('click', B, {once:true});
    $$('#mdConfirm [data-close], #mdConfirm .modal-backdrop').forEach(el=>el.addEventListener('click', ()=>{ $('#mdOk').style.display=''; }, {once:true}));
  }

  // ===== BUY LIFE =====
  $('#btnBuy').addEventListener('click', ()=>{
    openConfirm(
      'Acquista vita',
      `Confermi l’acquisto di <strong>1 vita</strong> per <strong>${fmt(BUYIN)}</strong> AC?`,
      async ()=>{
        const fd=new URLSearchParams({action:'buy_life'});
        const rsp=await API_POST(fd);
        const txt=await rsp.text(); let j; try{ j=JSON.parse(txt);}catch(e){ toast('Errore acquisto (non JSON)'); console.error('[BUY] raw:',txt); return; }
        if (!j.ok){ toast(j.detail || j.error || 'Errore acquisto'); return; }
        toast('Vita acquistata');
        document.dispatchEvent(new CustomEvent('refresh-balance'));
        await loadSummary();
      }
    );
  });

  // ===== UNJOIN =====
  $('#btnUnjoin').addEventListener('click', ()=>{
    openConfirm(
      'Disiscrizione',
      `Confermi la disiscrizione? Ti verranno rimborsati <strong>${fmt(BUYIN)}</strong> AC per ogni vita posseduta.`,
      async ()=>{
        const fd=new URLSearchParams({action:'unjoin'});
        const rsp=await API_POST(fd);
        const txt=await rsp.text(); let j; try{ j=JSON.parse(txt);}catch(e){ toast('Errore disiscrizione (non JSON)'); console.error('[UNJOIN] raw:',txt); return; }
        if (!j.ok){ toast(j.detail || j.error || 'Errore disiscrizione'); return; }
        toast('Disiscrizione completata');
        document.dispatchEvent(new CustomEvent('refresh-balance'));
        location.href='/lobby.php';
      }
    );
  });

  // ===== INFO SCELTE =====
  $('#btnInfo').addEventListener('click', async ()=>{
    const p=new URLSearchParams({action:'choices_info', round:String(ROUND)});
    const rsp=await API_GET(p);
    const txt=await rsp.text(); let j; try{ j=JSON.parse(txt);}catch(e){ console.error('[INFO] non JSON:', txt); return; }
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

  // Init
  loadSummary();
});
</script>
