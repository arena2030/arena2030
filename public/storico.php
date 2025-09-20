<?php
// /public/storico.php — Storico tornei (UI)
// VIEW: sola interfaccia. Usa /public/api/storico.php (preferita) e fallback su /public/api/torneo.php.
require_once __DIR__ . '/../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }

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
/* ===== Layout ===== */
.section{ padding-top:24px; }
.hwrap{ max-width:1100px; margin:0 auto; }

.hhead{
  display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:14px;
}
.hhead h1{ margin:0; color:#fff; font-size:26px; font-weight:900; letter-spacing:.2px; }
.hctrls{ display:flex; gap:8px; align-items:center; }
.hctrls .inp{ height:36px; padding:0 12px; border-radius:10px; background:#0f172a; border:1px solid #1f2937; color:#fff; }
.hctrls .btn{ height:36px; }

.grid{
  display:grid; gap:14px;
  grid-template-columns: repeat(2, minmax(0,1fr));
}
@media (max-width:880px){ .grid{ grid-template-columns: 1fr; } }

/* ===== Card torneo ===== */
.card{
  position:relative; padding:14px; border-radius:16px;
  background:linear-gradient(135deg,#0f172a 0%, #0b1220 100%);
  border:1px solid #1f2937; color:#fff;
  box-shadow: 0 16px 50px rgba(0,0,0,.35);
}
.codeTag{
  position:absolute; left:12px; top:10px;
  background:#172554; border:1px solid #1e3a8a; color:#9fb7ff;
  border-radius:10px; padding:4px 8px; font-size:12px; font-weight:800;
}
.ctitle{ margin:0 0 8px 0; font-size:18px; font-weight:900; padding-left:84px; }
.cmeta{
  display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-top:2px; padding-left:84px;
}
.badge{
  display:inline-flex; align-items:center; gap:6px;
  padding:6px 10px; border-radius:9999px;
  background:#0b162b; border:1px solid #1e293b; font-weight:700; font-size:12px;
}
.badge .lbl{ opacity:.85; font-weight:600; }
.badge .val{ font-weight:900; letter-spacing:.2px; }

.cactions{
  position:absolute; right:12px; top:10px; display:flex; gap:8px; align-items:center;
}
.stpill{
  padding:4px 8px; border-radius:9999px; border:1px solid #233046; font-size:12px; font-weight:800; color:#cbd5e1;
}
.stpill.live{ border-color:#fde04755; color:#fde047; }
.stpill.closed{ border-color:#ef444455; color:#fecaca; }
.stpill.published{ border-color:#34d39955; color:#d1fae5; }

.winRow{
  display:flex; align-items:center; justify-content:space-between; gap:10px; margin-top:10px;
  padding-top:10px; border-top:1px dashed #263246;
}
.winLbl{ font-size:12px; opacity:.85; }
.winName{
  font-weight:900; color:#fde047; letter-spacing:.2px;
  text-shadow:0 0 10px rgba(253,224,71,.25);
  min-height:20px;
}
.winName.fade{ animation: fadeSwap 2.8s ease-in-out infinite; }
@keyframes fadeSwap { 0%{opacity:0;} 10%{opacity:1;} 45%{opacity:1;} 55%{opacity:0;} 100%{opacity:0;} }

/* ===== Paginazione lista ===== */
.listPager{ margin-top:14px; display:flex; justify-content:flex-end; gap:8px; }
.listPager .btn{ min-width:110px; }

/* ===== Modal dettagli ===== */
.modal[aria-hidden="true"]{ display:none; } .modal{ position:fixed; inset:0; z-index:85; }
.modal-backdrop{ position:absolute; inset:0; background:rgba(0,0,0,.55); }
.modal-card{ position:relative; z-index:86; width:min(980px,96vw); margin:8vh auto 0;
  background:var(--c-bg); border:1px solid var(--c-border); border-radius:16px; overflow:hidden; color:#fff; }
.modal-head{ padding:12px 16px; border-bottom:1px solid var(--c-border); display:flex; align-items:center; gap:8px; }
.modal-head h3{ margin:0; font-weight:900; }
.modal-x{ margin-left:auto; background:transparent; border:0; color:#fff; font-size:24px; cursor:pointer; }
.modal-body{ padding:16px; }
.modal-foot{ padding:12px 16px; border-top:1px solid var(--c-border); display:flex; justify-content:flex-end; gap:8px; }

/* Round pager */
.rpager{ display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:12px; }
.rpager .where{ font-weight:800; }
.rpager .ar{ display:flex; gap:8px; }

/* Risultati eventi */
.resBox{ background:#0b1220; border:1px solid #121b2d; border-radius:14px; padding:12px; }
.resHead{ font-weight:900; margin-bottom:8px; opacity:.9; }
.event{ display:grid; grid-template-columns: 1fr 42px 1fr; gap:12px; align-items:center; padding:10px 8px; border-bottom:1px dashed #1f2937; }
.event:last-child{ border-bottom:0; }
.team{
  display:flex; align-items:center; gap:8px; min-width:0; background:#0c1628; border:1px solid #1e2a44;
  padding:6px 8px; border-radius:12px;
}
.team img{ width:26px; height:26px; border-radius:50%; object-fit:cover; }
.team strong{ white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.score{ text-align:center; font-weight:900; }
.team.win{
  border-color:rgba(253,224,71,.65);
  box-shadow:0 0 0 1px rgba(253,224,71,.35) inset, 0 0 18px rgba(253,224,71,.15);
}

/* Scelte utenti */
.choices{ margin-top:12px; }
.choicesHead{ font-weight:900; margin-bottom:8px; opacity:.9; }
.choiceGrid{ display:grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap:12px; }
@media (max-width:780px){ .choiceGrid{ grid-template-columns: 1fr; } }
.cgroup{ background:#0b1220; border:1px solid #121b2d; border-radius:14px; padding:10px; }
.cgt{ display:flex; align-items:center; gap:8px; margin-bottom:8px; font-weight:800; }
.cgt img{ width:20px; height:20px; border-radius:50%; }
.uAvs{ display:flex; gap:4px; flex-wrap:wrap; }
.uAv{
  width:26px; height:26px; border-radius:50%; overflow:hidden; display:inline-flex; align-items:center; justify-content:center;
  background:#111827; border:1px solid #1f2937; font-size:12px; font-weight:900; color:#cbd5e1;
}
.uAv img{ width:100%; height:100%; object-fit:cover; }

/* Bottoni base riuso */
.btn[type="button"]{ cursor:pointer; }
</style>

<main class="section">
  <div class="container">
    <div class="hwrap">
      <div class="hhead">
        <h1>Storico tornei</h1>
        <div class="hctrls">
          <input class="inp" type="search" id="q" placeholder="Cerca torneo…">
          <button class="btn btn--outline btn--sm" type="button" id="btnPrevLst">« Precedente</button>
          <button class="btn btn--outline btn--sm" type="button" id="btnNextLst">Successivo »</button>
        </div>
      </div>

      <div id="grid" class="grid"></div>
      <div class="listPager">
        <span class="muted" id="lstInfo"></span>
      </div>
    </div>
  </div>
</main>

<!-- Modal Dettagli -->
<div class="modal" id="mdDet" aria-hidden="true">
  <div class="modal-backdrop" data-close></div>
  <div class="modal-card">
    <div class="modal-head">
      <h3 id="mdTitle">Dettagli torneo</h3>
      <button class="modal-x" data-close>&times;</button>
    </div>
    <div class="modal-body">
      <div class="rpager">
        <div class="where">Round <span id="rIdx">1</span> di <span id="rTot">1</span></div>
        <div class="ar">
          <button class="btn btn--outline btn--sm" type="button" id="rPrev">«</button>
          <button class="btn btn--outline btn--sm" type="button" id="rNext">»</button>
        </div>
      </div>

      <div class="resBox">
        <div class="resHead">Risultati</div>
        <div id="events"></div>
      </div>

      <div class="choices">
        <div class="choicesHead">Scelte utenti</div>
        <div id="choices" class="choiceGrid"></div>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn--primary" data-close>Chiudi</button>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', ()=>{
  const $  = s=>document.querySelector(s);
  const $$ = (s,p=document)=>[...p.querySelectorAll(s)];

  /* ====== Endpoints preferiti + fallback ====== */
  const PREFERRED_API = '/api/storico.php';
  const ALT_API = '/api/torneo.php'; // fallback per events/choices_info

  /* ====== Stato lista ====== */
  const PER_PAGE = 6;
  let page = 1, pages = 1, total = 0, query = '';

  /* ====== Stato dettagli ====== */
  let curTid = null, curCode = null, roundsTot = 1, roundNow = 1;

  /* ====== Utils ====== */
  const fmt = n => Number(n||0).toFixed(2);
  const fmtDate = s => { if(!s) return '-'; const d = new Date(s); return isNaN(+d) ? s : d.toLocaleString(); };
  const clamp = (x,a,b)=> Math.min(Math.max(x,a),b);

  function showModal(m){ const el=$(m); if(!el) return; el.setAttribute('aria-hidden','false'); }
  function hideModal(m){ const el=$(m); if(!el) return; el.setAttribute('aria-hidden','true'); }
  $$('#mdDet [data-close], #mdDet .modal-backdrop').forEach(x=>x.addEventListener('click', ()=>hideModal('#mdDet')));

  /* ====== Fetch helper con fallback ====== */
async function apiList(page=1, limit=PER_PAGE, q=''){
  // preferita
  try{
    const u = new URL(PREFERRED_API, location.origin);
    u.searchParams.set('action','list');
    u.searchParams.set('page', String(page));
    u.searchParams.set('limit', String(limit));
    if (q) u.searchParams.set('q', q);

    const rsp = await fetch(u, {cache:'no-store', credentials:'same-origin'});
    const txt = await rsp.text();
    try{
      const j = JSON.parse(txt);
      if (j && j.ok) return j;
      return { ok:false, detail: j && j.detail ? j.detail : ('non JSON o errore: ' + txt.slice(0,200)) };
    }catch(e){
      return { ok:false, detail: 'risposta non JSON: ' + txt.slice(0,200) };
    }
  }catch(_){}

  // fallback /api/torneo.php?action=history (se esiste)
  try{
    const u = new URL(ALT_API, location.origin);
    u.searchParams.set('action','history');
    u.searchParams.set('page', String(page));
    u.searchParams.set('limit', String(limit));
    if (q) u.searchParams.set('q', q);

    const rsp = await fetch(u, {cache:'no-store', credentials:'same-origin'});
    const txt = await rsp.text();
    try{
      const j = JSON.parse(txt);
      if (j && j.ok) return j;
      return { ok:false, detail: j && j.detail ? j.detail : ('non JSON o errore (fallback): ' + txt.slice(0,200)) };
    }catch(e){
      return { ok:false, detail: 'risposta non JSON (fallback): ' + txt.slice(0,200) };
    }
  }catch(_){}

  return { ok:false, detail:'nessuna risposta valida dall’API' };
}

  async function apiRoundDetails(idOrCode, round){
    // preferita
    try{
      const u = new URL(PREFERRED_API, location.origin);
      u.searchParams.set('action','round');
      if (typeof idOrCode === 'number') u.searchParams.set('id', String(idOrCode));
      else u.searchParams.set('tid', idOrCode);
      u.searchParams.set('round', String(round));
      const j = await fetch(u, {cache:'no-store', credentials:'same-origin'}).then(r=>r.json());
      if (j && j.ok) return j;
    }catch(_){}
    // fallback 1: events da torneo.php
    try{
      const u = new URL(ALT_API, location.origin);
      u.searchParams.set('action','events');
      u.searchParams.set('round', String(round));
      if (typeof idOrCode === 'number') u.searchParams.set('id', String(idOrCode));
      else u.searchParams.set('tid', idOrCode);
      const j = await fetch(u, {cache:'no-store', credentials:'same-origin'}).then(r=>r.json());
      if (j && j.ok) return j;
    }catch(_){}
    return { ok:false };
  }

  async function apiRoundChoices(idOrCode, round){
    // preferita
    try{
      const u = new URL(PREFERRED_API, location.origin);
      u.searchParams.set('action','choices');
      if (typeof idOrCode === 'number') u.searchParams.set('id', String(idOrCode));
      else u.searchParams.set('tid', idOrCode);
      u.searchParams.set('round', String(round));
      const j = await fetch(u, {cache:'no-store', credentials:'same-origin'}).then(r=>r.json());
      if (j && j.ok) return j;
    }catch(_){}
    // fallback choices_info
    try{
      const u = new URL(ALT_API, location.origin);
      u.searchParams.set('action','choices_info');
      u.searchParams.set('round', String(round));
      if (typeof idOrCode === 'number') u.searchParams.set('id', String(idOrCode));
      else u.searchParams.set('tid', idOrCode);
      const j = await fetch(u, {cache:'no-store', credentials:'same-origin'}).then(r=>r.json());
      if (j && j.ok) return j;
    }catch(_){}
    return { ok:false };
  }

  /* ====== Render lista ====== */
  async function loadList(){
    const grid = $('#grid'); grid.innerHTML = '<div class="card">Caricamento…</div>';
    const j = await apiList(page, PER_PAGE, query);
if (!j || !j.ok){
  const msg = (j && j.detail) ? ('Errore caricamento storico: ' + j.detail) : 'Errore caricamento storico.';
  grid.innerHTML = `<div class="card">${msg}</div>`;
  $('#lstInfo').textContent = '';
  return;
}
    const items = Array.isArray(j.items||j.rows) ? (j.items || j.rows) : [];
    total = Number(j.total || items.length);
    pages = Number(j.pages || Math.max(1, Math.ceil(total / (j.limit || PER_PAGE))));
    page  = clamp(Number(j.page || page), 1, pages);

    if (!items.length){
      grid.innerHTML = '<div class="card">Nessun torneo trovato.</div>';
      $('#lstInfo').textContent = '—';
      return;
    }

    grid.innerHTML = '';
    items.forEach(it=>{
      // fields robusti
      const id     = Number(it.id || it.tournament_id || 0);
      const code   = (it.code || it.t_code || it.short_id || '').toString().toUpperCase();
      const title  = it.title || it.name || 'Torneo';
      const state  = (it.state || it.status || 'CHIUSO').toString().toUpperCase();
      const ltot   = Number(it.lives_total ?? it.lives_bought ?? it.buyins ?? 0);
      const lmax   = Number(it.round_max ?? it.best_round ?? it.current_round ?? 0);
      const alive  = Number(it.lives_alive ?? it.lives_in_play ?? 0);
      const wins   = Number(it.wins ?? it.winners_count ?? (Array.isArray(it.winners)? it.winners.length : 0));
      const winners= Array.isArray(it.winners) ? it.winners : (Array.isArray(it.wins_list)? it.wins_list : []);

      const stClass = state.includes('PUB') ? 'published' : (state.includes('LIVE')||state.includes('CORSO')?'live':'closed');

      const card = document.createElement('div');
      card.className = 'card';
      card.innerHTML = `
        <div class="codeTag">${code?('#'+code):('#'+id)}</div>
        <div class="cactions">
          <span class="stpill ${stClass}">${state}</span>
          <button class="btn btn--outline btn--sm" type="button">Dettagli</button>
        </div>
        <h3 class="ctitle">${title}</h3>
        <div class="cmeta">
          <div class="badge"><span class="lbl">Vite</span><span class="val">${ltot}</span></div>
          <div class="badge"><span class="lbl">Round max</span><span class="val">${lmax}</span></div>
          <div class="badge"><span class="lbl">Vive</span><span class="val">${alive}</span></div>
          <div class="badge"><span class="lbl">Vittorie</span><span class="val">${wins}</span></div>
        </div>
        <div class="winRow">
          <div class="winLbl">Vincitore</div>
          <div class="winName ${winners.length>1?'fade':''}" data-winners='${JSON.stringify(winners||[]).replace(/'/g,"&#39;")}'>—</div>
        </div>
      `;

      // rotazione nomi vincitori
      const wn = card.querySelector('.winName');
      const list = winners && winners.length ? winners : [];
      if (list.length === 0){
        wn.textContent = wins>1 ? 'Più vincitori' : (wins===1?'—':'—');
      } else if (list.length === 1){
        wn.textContent = list[0].username || list[0].name || '—';
      } else {
        let idx = 0;
        const update = ()=>{
          const w = list[idx % list.length];
          wn.textContent = (w.username || w.name || '—');
          idx++;
        };
        update();
        setInterval(update, 2600);
      }

      // dettagli
      const btn = card.querySelector('.btn');
      btn.addEventListener('click', ()=>{
        curTid  = id || null;
        curCode = code || null;
        roundsTot = Math.max(1, lmax || 1);
        roundNow  = 1;
        $('#mdTitle').textContent = `${title} — ${code?('#'+code):('#'+id)}`;
        $('#rTot').textContent = String(roundsTot);
        $('#rIdx').textContent = String(roundNow);
        showModal('#mdDet');
        loadRound();
      });

      grid.appendChild(card);
    });

    // footer info
    const per = Number(j.limit || PER_PAGE);
    const from = total===0 ? 0 : ((page-1)*per + 1);
    const to   = total===0 ? 0 : Math.min((page-1)*per + items.length, total);
    $('#lstInfo').textContent = `Pagina ${page} / ${pages} — Mostrati ${from}–${to} di ${total}`;
    $('#btnPrevLst').disabled = (page<=1);
    $('#btnNextLst').disabled = (page>=pages);
  }

  // lista: pager/search
  $('#btnPrevLst').addEventListener('click', ()=>{ if(page>1){ page--; loadList(); }});
  $('#btnNextLst').addEventListener('click', ()=>{ if(page<pages){ page++; loadList(); }});
  $('#q').addEventListener('input', (e)=>{ query = (e.target.value||'').trim(); page=1; loadList(); });

  /* ====== Dettagli: round loader ====== */
  async function loadRound(){
    $('#rIdx').textContent = String(roundNow);
    const evBox = $('#events'); evBox.innerHTML = 'Caricamento…';
    const chBox = $('#choices'); chBox.innerHTML = '';

    // Events
    const idOrCode = (curCode && curCode.length) ? curCode : (curTid || 0);
    const evs = await apiRoundDetails(idOrCode, roundNow);

    if (!evs || !evs.ok){
      evBox.innerHTML = '<div class="muted">Dati eventi non disponibili per questo round.</div>';
    } else {
      const arr = Array.isArray(evs.events) ? evs.events : [];
      if (!arr.length){
        evBox.innerHTML = '<div class="muted">Nessun evento per questo round.</div>';
      } else {
        evBox.innerHTML = '';
        arr.forEach(e=>{
          const hs = (e.home_score ?? e.h_score ?? e.home_goals ?? null);
          const as = (e.away_score ?? e.a_score ?? e.away_goals ?? null);
          const sc = (hs!=null && as!=null) ? `${hs} - ${as}` : (e.status_text || '—');
          const winId = Number(e.winner_team_id ?? (hs!=null && as!=null ? (hs>as? e.home_id : hs<as? e.away_id : 0) : 0));

          const row = document.createElement('div');
          row.className = 'event';
          row.innerHTML = `
            <div class="team ${winId && Number(e.home_id)===winId?'win':''}">
              ${e.home_logo? `<img src="${e.home_logo}" alt="">` : ''}
              <strong>${e.home_name || ('#'+e.home_id)}</strong>
            </div>
            <div class="score">${sc}</div>
            <div class="team ${winId && Number(e.away_id)===winId?'win':''}">
              ${e.away_logo? `<img src="${e.away_logo}" alt="">` : ''}
              <strong>${e.away_name || ('#'+e.away_id)}</strong>
            </div>
          `;
          evBox.appendChild(row);
        });
      }
    }

    // Choices
    const ch = await apiRoundChoices(idOrCode, roundNow);
    if (!ch || !ch.ok){
      chBox.innerHTML = '<div class="cgroup"><div class="muted">Scelte non disponibili.</div></div>';
    } else {
      const rows = Array.isArray(ch.rows||ch.choices) ? (ch.rows || ch.choices) : [];
      if (!rows.length){
        chBox.innerHTML = '<div class="cgroup"><div class="muted">Nessuna scelta effettuata in questo round.</div></div>';
      } else {
        // group by team_id
        const groups = new Map();
        rows.forEach(r=>{
          const tid = Number(r.team_id || r.pick_team_id || 0);
          const key = String(tid);
          if (!groups.has(key)) groups.set(key, {
            team_id: tid,
            team_name: r.team_name || r.pick_team_name || ('#'+tid),
            team_logo: r.team_logo || r.pick_team_logo || '',
            users: []
          });
          const u = groups.get(key);
          u.users.push({
            username: r.username || r.user || 'utente',
            avatar: r.avatar || r.user_avatar || ''
          });
        });

        chBox.innerHTML = '';
        groups.forEach(g=>{
          const card = document.createElement('div');
          card.className = 'cgroup';
          const initials = name => (name||'')[0] ? (name[0].toUpperCase()) : '?';
          const us = g.users.map(u=>`
            <span class="uAv" title="${u.username}">
              ${u.avatar ? `<img src="${u.avatar}" alt="${u.username}">` : initials(u.username)}
            </span>`).join('');
          card.innerHTML = `
            <div class="cgt">
              ${g.team_logo ? `<img src="${g.team_logo}" alt="">` : ''}
              <div>${g.team_name}</div>
              <div class="muted" style="margin-left:auto;">× ${g.users.length}</div>
            </div>
            <div class="uAvs">${us}</div>
          `;
          chBox.appendChild(card);
        });
      }
    }

    // pager buttons
    $('#rPrev').disabled = (roundNow<=1);
    $('#rNext').disabled = (roundNow>=roundsTot);
  }

  $('#rPrev').addEventListener('click', ()=>{ if(roundNow>1){ roundNow--; loadRound(); } });
  $('#rNext').addEventListener('click', ()=>{ if(roundNow<roundsTot){ roundNow++; loadRound(); } });

  // Avvio
  loadList();
});
</script>
