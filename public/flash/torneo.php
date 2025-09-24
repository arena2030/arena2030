<?php
// /public/flash/torneo.php — VIEW Flash, layout identico al torneo normale
require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }

define('APP_ROOT', dirname(__DIR__, 2));
require_once APP_ROOT . '/partials/csrf.php';
$CSRF = htmlspecialchars(csrf_token(), ENT_QUOTES);

/* ===== Auth ===== */
$uid  = (int)($_SESSION['uid'] ?? 0);
$role = $_SESSION['role'] ?? '';
if ($uid <= 0 || !in_array($role, ['USER','PUNTO','ADMIN'], true)) {
  header('Location: /login.php'); exit;
}

/* ===== Pre-caricamento (solo per mostrare subito il montepremi coerente alla lobby) ===== */
$pre = [
  'found' => false, 'id' => null, 'code' => null, 'name' => null,
  'buyin' => 0.0, 'lives_max_user' => null, 'lock_at' => null,
  'guaranteed_prize' => 0.0, 'buyin_to_prize_pct' => 0.0,
  'pool' => null
];
try{
  $code = isset($_GET['code']) ? trim($_GET['code']) : null;
  $tid  = isset($_GET['id'])   ? (int)$_GET['id']     : 0;
  if ($code || $tid){
    $st = $pdo->prepare("SELECT id, code, name, buyin, lives_max_user, lock_at,
                                COALESCE(guaranteed_prize,0) AS guaranteed_prize,
                                COALESCE(buyin_to_prize_pct,0) AS buyin_to_prize_pct
                         FROM tournament_flash
                         WHERE ".($code ? "code=?" : "id=?")." LIMIT 1");
    $st->execute([$code ?: $tid]);
    if ($row = $st->fetch(PDO::FETCH_ASSOC)){
      $pre['found']=true; $pre['id']=(int)$row['id']; $pre['code']=$row['code'];
      $pre['name']=$row['name']; $pre['buyin']=(float)$row['buyin'];
      $pre['lives_max_user']=$row['lives_max_user']!==null ? (int)$row['lives_max_user'] : null;
      $pre['lock_at']=$row['lock_at']; $pre['guaranteed_prize']=(float)$row['guaranteed_prize'];
      $pre['buyin_to_prize_pct']=(float)$row['buyin_to_prize_pct'];
      // vite totali → pool dinamico come in lobby
      $stc = $pdo->prepare("SELECT COUNT(*) FROM tournament_flash_lives WHERE tournament_id=?");
      $stc->execute([(int)$row['id']]); $livesCnt = (int)$stc->fetchColumn();
      $pct = $pre['buyin_to_prize_pct']; if($pct>0 && $pct<=1) $pct*=100.0; $pct=max(0.0,min(100.0,$pct));
      $poolFrom = round($pre['buyin'] * $livesCnt * ($pct/100.0), 2);
      $pre['pool'] = max($poolFrom, $pre['guaranteed_prize']);
    }
  }
}catch(Throwable $e){ /* fallback: nessun pre-caricamento */ }

$page_css='/pages-css/admin-dashboard.css';
include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/header_utente.php';
?>
<style>
/* ===== Layout & hero (copiato dal torneo normale) ===== */
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

/* 3 KPI (Montepremi, Vite max/utente, Lock round) */
.kpis{ display:grid; grid-template-columns: repeat(3, 1fr); gap:12px; margin-top:12px; }
.kpi{ background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.08); border-radius:14px; padding:12px; text-align:center; }
.kpi .lbl{ font-size:12px; opacity:.9;}
.kpi .val{ font-size:18px; font-weight:900; letter-spacing:.3px; }
.countdown{ font-variant-numeric:tabular-nums; font-weight:900; }

/* Azioni: solo Acquista vita + Disiscrivi */
.actions{ display:flex; justify-content:space-between; align-items:center; gap:12px; margin-top:12px; position:relative; z-index:5; }
.actions-left, .actions-right{ display:flex; gap:8px; align-items:center; }
.actions .btn { pointer-events:auto; }

/* ===== Le mie vite ===== */
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

/* ===== Eventi (3 card Round 1/2/3) ===== */
.events-card{ margin-top:16px; background:#0b1220; border:1px solid #121b2d; border-radius:16px; padding:14px; color:#fff; }
.round-head{ display:flex; align-items:center; gap:12px; margin-bottom:8px;}
.round-head h3{ margin:0; font-size:18px; font-weight:900;}
/* riga evento = ovale a sx + scelte a dx */
.eitem{ display:grid; grid-template-columns: 1fr auto; align-items:center; gap:12px; }
.evt{
  position:relative; display:flex; align-items:center; justify-content:center; gap:12px;
  background:radial-gradient(900px 200px at 50% -100px, rgba(99,102,241,.15), transparent 60%), linear-gradient(125deg,#111827 0%, #0b1120 100%);
  border:1px solid #1f2937; border-radius:9999px; padding:12px 16px;
}
.team{ display:flex; align-items:center; gap:8px; min-width:0;}
.team img{ width:28px; height:28px; border-radius:50%; object-fit:cover; }
.vs{ font-weight:900; opacity:.9; }
.team .pick-dot{ width:10px; height:10px; border-radius:50%; background:transparent; box-shadow:none; display:inline-block; }
.team.picked .pick-dot{ background:#fde047; box-shadow:0 0 10px #fde047, 0 0 20px #fde047; }

/* Scelte a destra */
.choices{ display:flex; gap:8px; }
.choices .btn{ min-width:98px; }
.choices .btn.active{ box-shadow:0 0 0 2px #fde047 inset; }

/* util */
.btn[type="button"]{ cursor:pointer; }
.muted{ color:#9ca3af; font-size:12px; }

/* Modali (copiati) */
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
        <div class="code" id="tCode"><?= $pre['found'] ? '#'.htmlspecialchars($pre['code'],ENT_QUOTES) : '#' ?></div>
        <div class="state" id="tState">APERTO</div>
        <h1 id="tTitle"><?= $pre['found'] ? htmlspecialchars($pre['name'],ENT_QUOTES) : 'Torneo Flash' ?></h1>
        <div class="sub" id="tSub">Flash • 3 round</div>
        <div class="kpis">
          <div class="kpi"><div class="lbl">Montepremi</div><div class="val" id="kPool"><?= $pre['pool']!==null ? number_format($pre['pool'],2,'.','') : '—' ?></div></div>
          <div class="kpi"><div class="lbl">Vite max/utente</div><div class="val" id="kLmax"><?= $pre['lives_max_user']!==null ? (int)$pre['lives_max_user'] : 'n/d' ?></div></div>
          <div class="kpi"><div class="lbl">Lock round</div><div class="val countdown" id="kLock" data-lock="<?= $pre['lock_at']? (int)(strtotime($pre['lock_at'])*1000) : 0 ?>"></div></div>
        </div>
        <div class="actions">
          <div class="actions-left">
            <button class="btn btn--primary btn--sm" type="button" id="btnBuy">Acquista una vita</button>
          </div>
          <div class="actions-right">
            <button class="btn btn--outline btn--sm" type="button" id="btnUnjoin">Disiscrivi</button>
          </div>
        </div>
        <span class="muted" id="hint"></span>
      </div>

      <!-- LE MIE VITE -->
      <div class="vite-card">
        <strong>Le mie vite</strong>
        <div class="vbar" id="vbar"></div>
      </div>

      <!-- EVENTI: Round 1 -->
      <div class="events-card">
        <div class="round-head">
          <h3>Eventi torneo — Round 1</h3>
          <span class="muted" id="lockTxt1"></span>
        </div>
        <div id="eventsR1"></div>
      </div>
      <!-- EVENTI: Round 2 -->
      <div class="events-card">
        <div class="round-head">
          <h3>Eventi torneo — Round 2</h3>
          <span class="muted" id="lockTxt2"></span>
        </div>
        <div id="eventsR2"></div>
      </div>
      <!-- EVENTI: Round 3 -->
      <div class="events-card">
        <div class="round-head">
          <h3>Eventi torneo — Round 3</h3>
          <span class="muted" id="lockTxt3"></span>
        </div>
        <div id="eventsR3"></div>
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

<!-- Modal: avvisi -->
<div class="modal" id="mdAlert" aria-hidden="true">
  <div class="modal-backdrop" data-close></div>
  <div class="modal-card">
    <div class="modal-head"><h3 id="alertTitle">Avviso</h3></div>
    <div class="modal-body"><p id="alertText" class="muted"></p></div>
    <div class="modal-foot"><button class="btn btn--primary" type="button" id="alertOk">Ok</button></div>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
<script src="/js/policy_guard.js"></script>
<script>
document.addEventListener('DOMContentLoaded', ()=>{
  const $  = s=>document.querySelector(s);
  const $$ = (s,p=document)=>[...p.querySelectorAll(s)];

  const qs   = new URLSearchParams(location.search);
  const FID  = Number(qs.get('id')||0) || 0;
  const FCOD = (qs.get('code')||'').toUpperCase();

  const FLASH_API_URL = '/api/flash_torneo.php'; // <-- se il tuo endpoint ha un altro nome, cambia solo questa riga
  const CSRF = '<?= $CSRF ?>';

  // ===== util =====
  const fmt2  = n => Number(n||0).toFixed(2);
  const toast = msg => { const h=$('#hint'); h.textContent=msg; setTimeout(()=>h.textContent='', 2200); };

  function countdownTick(){
    const el=$('#kLock'); const ts=Number(el.getAttribute('data-lock')||0);
    const now=Date.now(); const diff=Math.floor((ts-now)/1000);
    if(!ts){ el.textContent='—'; return; }
    if(diff<=0){ el.textContent='CHIUSO'; return; }
    let d=diff, dd=Math.floor(d/86400); d%=86400;
    const hh=String(Math.floor(d/3600)).padStart(2,'0'); d%=3600;
    const mm=String(Math.floor(d/60)).padStart(2,'0'); const ss=String(d%60).padStart(2,'0');
    el.textContent = (dd>0? dd+'g ':'')+hh+':'+mm+':'+ss;
    requestAnimationFrame(countdownTick);
  }
  countdownTick();

  // ===== API helpers =====
  function API_GET(params){
    const url = new URL(FLASH_API_URL, location.origin);
    for (const [k,v] of params.entries()) url.searchParams.set(k,v);
    if (FID)  url.searchParams.set('id',  String(FID));
    if (FCOD) url.searchParams.set('code',FCOD);
    return fetch(url.toString(), { cache:'no-store', credentials:'same-origin' });
  }
  function API_POST(params){
    const url = new URL(FLASH_API_URL, location.origin);
    const body = new URLSearchParams(params);
    if (FID && !body.has('id'))  body.set('id',  String(FID));
    if (FCOD && !body.has('code')) body.set('code', FCOD);
    body.set('csrf_token', CSRF);
    return fetch(url.toString(), {
      method:'POST',
      headers:{ 'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8', 'Accept':'application/json', 'X-CSRF-Token':CSRF },
      body: body.toString(),
      credentials:'same-origin'
    });
  }

  // ===== Stato pagina =====
  let LIVES = [];          // [{id,status,...}]
  let ACTIVE_LIFE = 0;     // id vita selezionata

  // ===== Summary (hero) =====
  async function loadSummary(){
    const p = new URLSearchParams({ action:'summary' });
    const rsp = await API_GET(p);
    const txt = await rsp.text(); let j;
    try{ j=JSON.parse(txt);}catch(e){ console.error('[summary] non JSON:',txt); return; }
    if (!j || !j.ok) return;

    const t = j.tournament || {};
    $('#tTitle').textContent = t.name || t.title || 'Torneo Flash';
    $('#tCode').textContent  = t.code ? ('#'+t.code) : (t.id?('#'+t.id):'#');
    const st = (t.state||'open').toString().toUpperCase();
    const lab = st.includes('END')||st.includes('CLOSED')||st.includes('FINAL') ? 'CHIUSO' : ( (t.lock_at && Date.now()>=new Date(t.lock_at).getTime()) ? 'IN CORSO' : 'APERTO' );
    const se=$('#tState'); se.textContent=lab; se.className='state '+(lab==='APERTO'?'open':(lab==='IN CORSO'?'live':'end'));

    // pool coerente con lobby (fallback: pre-calcolo lato PHP)
    let pool = (typeof t.pool_coins!=='undefined' && t.pool_coins!==null) ? Number(t.pool_coins) : (<?= $pre['pool']!==null ? json_encode($pre['pool']) : 'null' ?>);
    if ((pool===null || Number.isNaN(pool)) && t.buyin && (t.buyin_to_prize_pct || t.prize_pct) && typeof t.lives_total!=='undefined'){
      const pct = (t.buyin_to_prize_pct || t.prize_pct);
      const P = (pct>0 && pct<=1) ? pct*100 : pct;
      pool = Math.max(Number(t.guaranteed_prize||0), Math.round(Number(t.buyin)*Number(t.lives_total)* (Number(P)/100)*100)/100);
    }
    $('#kPool').textContent = (pool!=null) ? fmt2(pool) : '—';
    $('#kLmax').textContent = (t.lives_max_user!=null) ? String(t.lives_max_user) : 'n/d';
    if (t.lock_at){ $('#kLock').setAttribute('data-lock', String((new Date(t.lock_at)).getTime())); }
  }

  // ===== Le mie vite =====
  async function loadLives(){
    const p=new URLSearchParams({ action:'my_lives' });
    const rsp = await API_GET(p);
    const txt = await rsp.text(); let j; try{ j=JSON.parse(txt);}catch(e){ console.error('[lives] non JSON:',txt); return; }
    LIVES = Array.isArray(j.lives) ? j.lives : [];
    const vbar = $('#vbar'); vbar.innerHTML='';
    if (!LIVES.length){ vbar.innerHTML = '<span class="muted">Nessuna vita: acquista una vita per iniziare.</span>'; ACTIVE_LIFE=0; return; }
    LIVES.forEach((lv,idx)=>{
      const d=document.createElement('div'); d.className='life'; d.setAttribute('data-id', String(lv.id));
      const logo = lv.current_team_logo ? `<img class="logo" src="${lv.current_team_logo}" alt="${lv.current_team_name||''}" title="${lv.current_team_name||''}">` : '';
      const label = lv.current_team_logo ? '' : `<span>Vita ${idx+1}</span>`;
      d.innerHTML = `<span class="heart"></span>${logo}${label}`;
      const s = String(lv.status||lv.state||'').toLowerCase();
      if (['lost','eliminated','dead','out','persa','eliminata'].includes(s) || lv.is_alive===0) d.classList.add('lost');
      d.addEventListener('click', ()=>{
        $$('.life').forEach(x=>x.classList.remove('active')); d.classList.add('active');
        ACTIVE_LIFE = Number(lv.id);
      });
      vbar.appendChild(d);
    });
    // attiva la prima
    const first=$('.life'); if(first){ first.classList.add('active'); ACTIVE_LIFE = Number(first.getAttribute('data-id'))||0; }
  }

  // ===== Eventi per round (render con ovale + tre bottoni a destra) =====
  async function loadRound(round, mountId){
    const box = document.getElementById(mountId); if(!box) return;
    box.innerHTML = '<div class="muted">Caricamento…</div>';
    const p = new URLSearchParams({ action:'events', round:String(round) });
    const rsp = await API_GET(p);
    const txt = await rsp.text(); let j; try{ j=JSON.parse(txt);}catch(e){ console.error('[events] non JSON:',txt); box.innerHTML='<div class="muted">Errore caricamento.</div>'; return; }
    const evs = Array.isArray(j.events) ? j.events : [];
    if(!evs.length){ box.innerHTML='<div class="muted">Nessun evento per questo round.</div>'; return; }
    box.innerHTML='';

    evs.forEach(ev=>{
      // stato pick corrente (accettiamo 1/X/2, home/draw/away, H/D/A)
      const rawPick = (ev.my_pick || ev.choice || '').toString().toLowerCase();
      const wasHome = ['1','h','home','casa'].includes(rawPick);
      const wasDraw = ['x','d','draw','pareggio'].includes(rawPick);
      const wasAway = ['2','a','away','trasferta'].includes(rawPick);

      // wrapper riga
      const wrap=document.createElement('div'); wrap.className='eitem';

      // ovale a sinistra
      const oval=document.createElement('div'); oval.className='evt';
      oval.innerHTML = `
        <div class="team home ${wasHome?'picked':''}">
          <span class="pick-dot"></span>
          ${ev.home_logo? `<img src="${ev.home_logo}" alt="">` : ''}
          <strong>${ev.home_name||('#'+(ev.home_id||'?'))}</strong>
        </div>
        <div class="vs">VS</div>
        <div class="team away ${wasAway?'picked':''}">
          <strong>${ev.away_name||('#'+(ev.away_id||'?'))}</strong>
          ${ev.away_logo? `<img src="${ev.away_logo}" alt="">` : ''}
          <span class="pick-dot"></span>
        </div>
      `;

      // scelte a destra
      const choices=document.createElement('div'); choices.className='choices';
      const bHome = document.createElement('button'); bHome.type='button'; bHome.className='btn btn--outline'+(wasHome?' active':''); bHome.textContent='Casa';
      const bDraw = document.createElement('button'); bDraw.type='button'; bDraw.className='btn btn--outline'+(wasDraw?' active':''); bDraw.textContent='Pareggio';
      const bAway = document.createElement('button'); bAway.type='button'; bAway.className='btn btn--outline'+(wasAway?' active':''); bAway.textContent='Trasferta';
      choices.append(bHome,bDraw,bAway);

      async function doPick(choice){
        // dev'esserci una vita attiva
        if (!ACTIVE_LIFE){
          showAlert('Seleziona una vita', 'Prima seleziona una vita nella sezione <strong>Le mie vite</strong>.');
          return;
        }
        // guard (usa lo stesso endpoint delle policy del torneo classico, oppure quello flash se presente nella tua codebase)
        try{
          const urlGuard = `/api/tournament_core.php?action=policy_guard&what=pick&is_flash=1&code=${encodeURIComponent(FCOD)}&round=${encodeURIComponent(round)}`;
          const g = await fetch(urlGuard,{cache:'no-store',credentials:'same-origin'}).then(r=>r.json());
          if (!g || !g.ok || !g.allowed){ showAlert('Operazione non consentita', (g && g.popup) ? g.popup : 'Non puoi effettuare la scelta in questo momento.'); return; }
        }catch(_){ /* se non esiste, procedo */ }

        const fd = new URLSearchParams({ action:'pick', round:String(round), event_id:String(ev.id), life_id:String(ACTIVE_LIFE), choice:String(choice) });
        const rsp = await API_POST(fd);
        const raw = await rsp.text(); let jj; try{ jj=JSON.parse(raw);}catch(e){ toast('Errore (non JSON)'); console.error('[pick] raw:',raw); return; }
        if (!jj.ok){ showAlert('Errore scelta', jj.detail || jj.error || 'Scelta non registrata'); return; }

        // UI: attiva bottone e puntino
        [bHome,bDraw,bAway].forEach(b=>b.classList.remove('active'));
        if (choice==='home') bHome.classList.add('active');
        if (choice==='draw') bDraw.classList.add('active');
        if (choice==='away') bAway.classList.add('active');
        oval.querySelector('.team.home')?.classList.toggle('picked', choice==='home');
        oval.querySelector('.team.away')?.classList.toggle('picked', choice==='away');

        toast('Scelta registrata');
      }

      bHome.addEventListener('click', ()=>doPick('home'));
      bDraw.addEventListener('click', ()=>doPick('draw'));
      bAway.addEventListener('click', ()=>doPick('away'));

      wrap.appendChild(oval);
      wrap.appendChild(choices);
      box.appendChild(wrap);
    });
  }

  // ===== Modali util =====
  function showAlert(title, html){
    const t = document.getElementById('alertTitle');
    const b = document.getElementById('alertText');
    const m = document.getElementById('mdAlert');
    if (t) t.textContent = title || 'Avviso';
    if (b) b.innerHTML   = html  || '';
    if (m) m.setAttribute('aria-hidden','false');
  }
  function hideAlert(){ document.getElementById('mdAlert')?.setAttribute('aria-hidden','true'); }
  document.getElementById('alertOk')?.addEventListener('click', hideAlert);
  document.querySelector('#mdAlert .modal-backdrop')?.addEventListener('click', hideAlert);

  function openConfirm(title, html, onConfirm){
    $('#mdTitle').textContent = title;
    $('#mdText').innerHTML    = html;
    const okBtn = $('#mdOk');
    const clone = okBtn.cloneNode(true); okBtn.parentNode.replaceChild(clone, okBtn);
    const ok    = $('#mdOk');
    ok.addEventListener('click', async ()=>{
      ok.disabled=true;
      try{ await onConfirm(); document.getElementById('mdConfirm').setAttribute('aria-hidden','true'); } finally { ok.disabled=false; }
    }, { once:true });
    document.getElementById('mdConfirm').setAttribute('aria-hidden','false');
  }

  // ===== Azioni top (buy life / unjoin) =====
  $('#btnBuy').addEventListener('click', async ()=>{
    try{
      const urlGuard = `/api/tournament_core.php?action=policy_guard&what=buy_life&is_flash=1&code=${encodeURIComponent(FCOD)}`;
      const g = await fetch(urlGuard,{cache:'no-store',credentials:'same-origin'}).then(r=>r.json());
      if (!g || !g.ok || !g.allowed){ showAlert('Operazione non consentita', (g && g.popup) ? g.popup : 'Non puoi acquistare vite in questo momento.'); return; }
    }catch(_){ /* se non c'è, continuo */ }
    openConfirm('Acquista vita', 'Confermi l’acquisto di <strong>1 vita</strong>?', async ()=>{
      const fd=new URLSearchParams({action:'buy_life'});
      const rsp=await API_POST(fd);
      const txt=await rsp.text(); let j; try{ j=JSON.parse(txt);}catch(e){ toast('Errore acquisto (non JSON)'); console.error('[buy] raw:',txt); return; }
      if (!j.ok){ showAlert('Errore acquisto', j.detail||j.error||''); return; }
      toast('Vita acquistata');
      document.dispatchEvent(new CustomEvent('refresh-balance'));
      await Promise.all([loadSummary(), loadLives(), loadRound(1,'eventsR1'), loadRound(2,'eventsR2'), loadRound(3,'eventsR3')]);
    });
  });

  $('#btnUnjoin').addEventListener('click', async ()=>{
    try{
      const urlGuard = `/api/tournament_core.php?action=policy_guard&what=unjoin&is_flash=1&code=${encodeURIComponent(FCOD)}`;
      const g = await fetch(urlGuard,{cache:'no-store',credentials:'same-origin'}).then(r=>r.json());
      if (!g || !g.ok || !g.allowed){ showAlert('Operazione non consentita', (g && g.popup) ? g.popup : 'Non puoi disiscriverti in questo momento.'); return; }
    }catch(_){ /* ok */ }
    openConfirm('Disiscrizione', 'Confermi la disiscrizione? Riceverai il rimborso secondo le regole del torneo.', async ()=>{
      const fd=new URLSearchParams({action:'unjoin'});
      const rsp=await API_POST(fd);
      const txt=await rsp.text(); let j; try{ j=JSON.parse(txt);}catch(e){ toast('Errore disiscrizione (non JSON)'); console.error('[unjoin] raw:',txt); return; }
      if (!j.ok){ showAlert('Errore disiscrizione', j.detail||j.error||''); return; }
      toast('Disiscrizione eseguita');
      document.dispatchEvent(new CustomEvent('refresh-balance'));
      location.href='/lobby.php';
    });
  });

  // ===== Boot =====
  (async()=>{
    await loadSummary();
    await loadLives();
    await Promise.all([loadRound(1,'eventsR1'), loadRound(2,'eventsR2'), loadRound(3,'eventsR3')]);
  })();
});
</script>
