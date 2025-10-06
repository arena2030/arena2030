<?php
require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['uid']) || !(($_SESSION['role'] ?? 'USER')==='ADMIN' || (int)($_SESSION['is_admin'] ?? 0)===1)) {
  header('Location: /login.php'); exit;
}

/* Helpers */
function jsonOut($a){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($a); exit; }
function only_post(){ if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST'){ http_response_code(405); jsonOut(['ok'=>false,'error'=>'method']); } }

function tourByCode(PDO $pdo, string $code){
  $st=$pdo->prepare("SELECT * FROM tournament_flash WHERE code=? LIMIT 1");
  $st->execute([$code]); return $st->fetch(PDO::FETCH_ASSOC);
}
function teamList(PDO $pdo): array {
  return $pdo->query("SELECT id,name FROM teams ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
}

/* ===== Page view ===== */
$code = trim($_GET['code'] ?? '');
$tour = $code ? tourByCode($pdo,$code) : null;
if (!$tour) { echo "<main class='section'><div class='container'><h1>Torneo Flash non trovato</h1></div></main>"; include __DIR__ . '/../../partials/footer.php'; exit; }

$page_css='/pages-css/flash.css';
include __DIR__ . '/../../partials/head.php';
include __DIR__ . '/../../partials/header_admin.php';

// 🔐 inizializza token CSRF
require_once __DIR__ . '/../../partials/csrf.php';
$CSRF = htmlspecialchars(csrf_token(), ENT_QUOTES);

$teamsInit = teamList($pdo);
$isPending   = (strtolower((string)$tour['status'])==='pending');
$isPublished = (strtolower((string)$tour['status'])==='published' || strtolower((string)$tour['status'])==='locked');
$currentRound = (int)($tour['current_round'] ?? 1);

/* Debug UI flag (appendi ?debug=1 all'URL per attivare la dock) */
$DEBUG_UI = isset($_GET['debug']) && $_GET['debug'] === '1';
?>
<style>
  /* ======= Look “card eleganti” + azioni inline — nessun cambio logico ======= */
  .section{ padding-top:24px; }
  .container{ max-width:1100px; margin:0 auto; }
  h1{ color:#fff; font-size:26px; font-weight:900; letter-spacing:.2px; margin:0 0 12px; }
  .muted{ color:#9ca3af; font-weight:500; }

  /* Card principale */
  .card{
    position:relative; border-radius:20px; padding:18px;
    background:
      radial-gradient(1000px 300px at 50% -120px, rgba(99,102,241,.10), transparent 60%),
      linear-gradient(135deg,#0e1526 0%, #0b1220 100%);
    border:1px solid rgba(255,255,255,.08);
    color:#fff;
    box-shadow: 0 20px 60px rgba(0,0,0,.35);
    transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease, background .15s ease;
    overflow:hidden; margin-bottom:16px;
  }
  .card::before{
    content:""; position:absolute; left:0; top:0; bottom:0; width:4px;
    background:linear-gradient(180deg,#1e3a8a 0%, #0ea5e9 100%); opacity:.35;
  }
  .card:hover{ transform: translateY(-2px); box-shadow: 0 26px 80px rgba(0,0,0,.48); border-color:#21324b; }
  .card-title{ margin:0 0 10px; font-size:18px; font-weight:800; color:#dbeafe; letter-spacing:.3px; }

  /* Sotto-card dei round */
  .subcard{
    margin-top:12px; border-radius:14px; padding:14px;
    background:linear-gradient(135deg,#0b1220 0%, #0b1322 100%);
    border:1px solid rgba(255,255,255,.06);
  }
  .subcard h3{ margin:0 0 10px; color:#e5e7eb; font-size:16px; font-weight:800; }

  /* Griglie & campi */
  .grid2{ display:grid; grid-template-columns:1fr 1fr; gap:16px; }
  @media (max-width:860px){ .grid2{ grid-template-columns:1fr; } }
  .field{ display:flex; flex-direction:column; gap:6px; }
  .label{ color:#9fb7ff; font-size:12px; font-weight:800; letter-spacing:.3px; }

  /* Inputs/Select coerenti */
  .input.light, .select.light{
    width:100%; height:38px; padding:0 12px; border-radius:10px;
    background:#0f172a; border:1px solid #1f2937; color:#fff; appearance:none;
  }
  .input.light:focus, .select.light:focus{
    outline:0; border-color:#334155; box-shadow:0 0 0 3px rgba(59,130,246,.15);
  }

  /* === Impostazioni & Azioni — tutti i pulsanti su UNA riga === */
  .container > .card:first-of-type > .grid2{
    display:flex; align-items:flex-end; gap:12px; flex-wrap:nowrap; overflow:auto;
    scrollbar-width:thin;
  }
  .container > .card:first-of-type > .grid2 .field:first-child{
    flex:1 1 auto; min-width:280px;
  }
  .container > .card:first-of-type > .grid2 .field:first-child .input.light{ margin-bottom:6px; }

  /* Barra azioni in riga (stesso stile dei tasti PRIMARI + small) */
  .btn-grid-3{
    display:flex; align-items:center; gap:8px; flex:0 0 auto; flex-wrap:nowrap;
  }
  .btn-grid-3 .btn{ height:36px; line-height:36px; padding:0 14px; border-radius:9999px; }
  /* Forzo il look “primario” su tutti i bottoni del gruppo e su Salva lock */
  .btn-grid-3 .btn,
  .btn-grid-3 .btn.btn--danger,
  #btnSaveLock{
    background: var(--btn-primary-bg, #1d4ed8) !important;
    border-color: var(--btn-primary-bg, #1d4ed8) !important;
    color:#fff !important;
  }
  #btnSaveLock{
    height:36px; line-height:36px; padding:0 14px; border-radius:9999px; margin-top:2px;
  }

  /* Tabella elegante (per i 3 round) */
  .table-wrap{ overflow:auto; border-radius:12px; }
  .table{ width:100%; border-collapse:separate; border-spacing:0; }
  .table thead th{
    text-align:left; font-weight:900; font-size:12px; letter-spacing:.3px;
    color:#9fb7ff; padding:10px 12px; background:#0f172a; border-bottom:1px solid #1e293b;
  }
  .table tbody td{
    padding:12px; border-bottom:1px solid #122036; color:#e5e7eb; font-size:14px;
    background:linear-gradient(0deg, rgba(255,255,255,.02), rgba(255,255,255,.02));
    vertical-align:middle;
  }
  .table tbody tr:hover td{ background:rgba(255,255,255,.025); }
  .table tbody tr:last-child td{ border-bottom:0; }

  /* Celle azioni e select risultati */
  .table td .btn{ height:32px; line-height:32px; border-radius:9999px; padding:0 12px; }
  .result-select{ min-width:160px; }

  /* ===== Debug dock (attivabile con ?debug=1) ===== */
  .debug-dock{
    position:fixed; inset:auto 6px 6px 6px; height:30vh; background:#0b1220; color:#e5e7eb;
    border:1px solid #1f2937; border-radius:10px; z-index:9999; display:none; box-shadow:0 8px 30px rgba(0,0,0,.5);
  }
  .debug-dock.active{ display:block; }
  .debug-head{
    display:flex; align-items:center; justify-content:space-between; gap:8px;
    padding:6px 10px; background:#0f172a; border-bottom:1px solid #1f2937; border-radius:10px 10px 0 0;
  }
  .debug-body{ height:calc(30vh - 40px); overflow:auto; padding:8px 10px; font:12px/1.35 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; white-space:pre-wrap; }
  .debug-btn{ border:1px solid #334155; background:#0b1220; color:#e5e7eb; border-radius:9999px; padding:4px 10px; font-size:12px; }
  .debug-toggle{
    position:fixed; right:10px; bottom:10px; z-index:10000; border:1px solid #334155; background:#0b1220; color:#e5e7eb; border-radius:9999px; padding:6px 12px; font-size:12px;
    display:<?php echo $DEBUG_UI ? 'none' : 'none'; ?>; /* nascosta se non usata */
  }
</style>

<main>
<section class="section">
  <div class="container">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
      <h1>Torneo Flash: <?= htmlspecialchars($tour['name']) ?>
        <span class="muted">(<?= htmlspecialchars($tour['code']) ?>)</span>
      </h1>
      <a class="btn btn--outline btn--sm" href="/admin/crea-tornei.php">Torna alla lista</a>
    </div>

    <div class="card">
      <h2 class="card-title">Impostazioni & Azioni</h2>

      <div class="grid2" style="align-items:end;">
        <div class="field">
          <label class="label">Lock scelte (data/ora)</label>
          <input class="input light" id="lock_at" type="datetime-local" value="<?= !empty($tour['lock_at']) ? date('Y-m-d\TH:i', strtotime($tour['lock_at'])) : '' ?>">
          <button type="button" class="btn btn--sm" id="btnSaveLock">Salva lock</button>
        </div>

        <!-- Pulsanti (tutti small e ovali, su un'unica riga) -->
        <div class="field btn-grid-3">
          <?php if ($isPending): ?>
            <button type="button" class="btn btn--primary btn--sm" id="btnPublishTour">Pubblica torneo</button>
          <?php endif; ?>
          <?php if ($isPublished): ?>
            <button type="button" class="btn btn--primary btn--sm" id="btnSeal">Chiudi scelte (R<?= (int)$currentRound ?>)</button>
            <button type="button" class="btn btn--primary btn--sm" id="btnReopen">Riapri scelte (R<?= (int)$currentRound ?>)</button>
            <button type="button" class="btn btn--primary btn--sm" id="btnCalcRound">Calcola round (R<?= (int)$currentRound ?>)</button>
            <button type="button" class="btn btn--primary btn--sm" id="btnPublishNext">Pubblica R<?= (int)$currentRound+1 ?></button>
            <button type="button" class="btn btn--danger btn--sm" id="btnFinalize">Finalizza torneo</button>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="card">
      <h2 class="card-title">Eventi Round 1–3</h2>
      <p class="muted">Inserisci un (1) evento per ogni round (Casa vs Trasferta). Il sistema consente solo 1 evento/round.</p>

      <?php for($r=1;$r<=3;$r++): ?>
      <div class="subcard">
        <h3>Round <?= $r ?></h3>
        <div class="grid2">
          <div class="field">
            <label class="label">Casa</label>
            <select class="select light" id="home_<?= $r ?>">
              <option value="">— Seleziona —</option>
              <?php foreach($teamsInit as $t): ?>
                <option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label class="label">Trasferta</label>
            <select class="select light" id="away_<?= $r ?>">
              <option value="">— Seleziona —</option>
              <?php foreach($teamsInit as $t): ?>
                <option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field" style="grid-column: span 2;">
            <button type="button" class="btn btn--outline btn--sm" data-add="<?= $r ?>">Aggiungi/aggiorna evento Round <?= $r ?></button>
          </div>
        </div>

        <div class="table-wrap" style="margin-top:8px;">
          <table class="table" id="tblR<?= $r ?>">
            <thead><tr><th>Codice</th><th>Casa</th><th>Trasferta</th><th>Blocco</th><th>Risultato</th><th>Azioni</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
      <?php endfor; ?>

      <pre id="dbg" class="debug" style="display:none"></pre>
    </div>

  </div>
</section>
</main>
<?php include __DIR__ . '/../../partials/footer.php'; ?>

<!-- Espone il token CSRF & flag debug -->
<script>
  window.__CSRF = "<?= $CSRF ?>";
  window.__DEBUG_UI = <?= $DEBUG_UI ? 'true' : 'false' ?>;
</script>

<!-- ========== DEBUG DOCK (UI) ========== -->
<?php if ($DEBUG_UI): ?>
<div class="debug-dock active" id="debugDock">
  <div class="debug-head">
    <strong>Debug Console</strong>
    <div>
      <button class="debug-btn" id="dbg-clear">Pulisci</button>
      <button class="debug-btn" id="dbg-close">Chiudi</button>
    </div>
  </div>
  <div class="debug-body" id="dbg-body"></div>
</div>
<?php endif; ?>

<script>
/* ===== Handlers base + logger visivo ===== */
(function(){
  const enabled = !!window.__DEBUG_UI;
  const out = enabled ? document.getElementById('dbg-body') : null;

  function toStr(obj){
    try { return (typeof obj==='string') ? obj : JSON.stringify(obj, (k,v)=>v instanceof Error ? {message:v.message, stack:v.stack} : v, 2); }
    catch(e){ return String(obj); }
  }
  function stamp(){
    const d = new Date();
    return d.toLocaleTimeString('it-IT') + '.' + String(d.getMilliseconds()).padStart(3,'0');
  }
  function append(prefix, payload, level='log'){
    if (!enabled) { console[level](`[${prefix}]`, payload); return; }
    const line = document.createElement('div');
    line.innerHTML = `<div><b>[${stamp()}] ${prefix}</b></div><pre>${toStr(payload)}</pre>`;
    out.appendChild(line);
    out.scrollTop = out.scrollHeight;
    console[level](`[${prefix}]`, payload);
  }
  window.__DBG__ = append;

  if (enabled){
    document.getElementById('dbg-clear').addEventListener('click', ()=>{ out.innerHTML=''; });
    document.getElementById('dbg-close').addEventListener('click', ()=>{ document.getElementById('debugDock').classList.remove('active'); });
  }

  // Errori globali
  window.addEventListener('error', (e)=>{
    append('window.error', {message:e.message, file:e.filename, line:e.lineno, col:e.colno, stack:e.error?.stack}, 'error');
  });
  window.addEventListener('unhandledrejection', (e)=>{
    append('promise.rejection', {reason: (e.reason?.stack || e.reason)}, 'error');
  });
})();
</script>

<!-- ===== Script #1: init lock/save (resto invariato) ===== -->
<script>
document.addEventListener('DOMContentLoaded', ()=>{
  const $=s=>document.querySelector(s);
  const code="<?= htmlspecialchars($tour['code']) ?>";
  const isPublished = <?= $isPublished?'true':'false' ?>;
  let currentRound = <?= (int)$currentRound ?>;

  // === Salva lock data/ora ===
  const btnSaveLock = document.getElementById('btnSaveLock');
  if (btnSaveLock) {
    btnSaveLock.addEventListener('click', async () => {
      const inp = document.getElementById('lock_at');
      const val = inp?.value || '';
      if (!val) { alert('Imposta data/ora'); __DBG__('lock.save.missing', {val}); return; }
      const CSRF = window.__CSRF || '';
      const body = new URLSearchParams({
        csrf_token: CSRF,
        id: '<?= (int)$tour['id'] ?>',
        lock_at: val
      }).toString();
      try{
        __DBG__('lock.save.req', {url:'/api/flash_tournament.php?action=set_lock', body});
        const r = await fetch('/api/flash_tournament.php?action=set_lock', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
            'X-CSRF-Token': CSRF,
            'Accept': 'application/json'
          },
          credentials: 'same-origin',
          body
        });
        const raw = await r.text();
        __DBG__('lock.save.resp.raw', {status:r.status, raw});
        let j; try{ j=JSON.parse(raw); }catch(e){ __DBG__('lock.save.json.error', {err:e?.message, raw}, 'error'); alert('Risposta non JSON (lock)'); return; }
        if (j.ok) { alert('Lock salvato: ' + j.lock_at); __DBG__('lock.save.ok', j); }
        else { alert('Errore salvataggio lock: ' + (j.detail || j.error || '')); __DBG__('lock.save.fail', j, 'error'); }
      }catch(e){
        __DBG__('lock.save.fetch.error', {error: e?.message || e}, 'error');
        alert('Errore rete salvataggio lock');
      }
    });
  }
});
</script>

<!-- ===== Script #2: JS operativo (variabili globali + debug approfondito) ===== -->
<script>
/* Alias comodo e variabili GLOBALI richieste anche fuori dal DOMContentLoaded */
const $  = (s, p=document)=>p.querySelector(s);
const code = "<?= htmlspecialchars($tour['code']) ?>";
let currentRound = <?= (int)$currentRound ?>;

/* Utils con debug estremo */
async function jsonFetch(url,opts){
  const started = Date.now();
  __DBG__('fetch.req', {url, opts: sanitizeOpts(opts)});
  try{
    const resp = await fetch(url, opts||{});
    const raw  = await resp.text();
    const ms   = Date.now() - started;
    __DBG__('fetch.resp.raw', {url, status:resp.status, ms, rawSnippet: raw.length>2000 ? (raw.slice(0,2000)+'…') : raw});
    let j;
    try{ j = JSON.parse(raw); }
    catch(e){
      __DBG__('fetch.json.error', {url, status:resp.status, error:e?.message, rawSnippet: raw.slice(0,3000)}, 'error');
      return {ok:false, error:'bad_json', status:resp.status, raw};
    }
    return j;
  }catch(e){
    __DBG__('fetch.network.error', {url, error:e?.message || e}, 'error');
    return {ok:false, error:'network', detail: String(e)};
  }
}
function sanitizeOpts(opts){
  if (!opts) return {};
  const o = {...opts};
  if (o.body instanceof FormData) { const obj={}; o.body.forEach((v,k)=>obj[k]=v); o.body = obj; }
  if (typeof o.body === 'string' && o.body.length>2000) o.body = o.body.slice(0,2000)+'…';
  return o;
}

/* Render tabelle eventi per round */
async function loadRound(r){
  __DBG__('round.load.start', {round:r});
  const j=await jsonFetch(`/api/flash_tournament.php?action=list_events&tid=${encodeURIComponent(code)}&round_no=${r}&debug=1`,{cache:'no-store'});
  const tb=document.querySelector(`#tblR${r} tbody`); tb.innerHTML='';
  if(!j.ok){ alert('Errore load events R'+r); __DBG__('round.load.fail', {round:r, payload:j}, 'error'); return; }
  __DBG__('round.load.rows', {round:r, count:(j.rows||[]).length});
  j.rows.forEach(ev=>{
    const tr=document.createElement('tr');
    tr.innerHTML=`
      <td>${ev.event_code}</td>
      <td>${ev.home_name}</td>
      <td>${ev.away_name}</td>
      <td>
        <button type="button" class="btn btn--outline btn--sm" data-lock="${ev.id}" data-round="${r}">
          ${Number(ev.is_locked)===1?'Sblocca':'Blocca'}
        </button>
      </td>
      <td>
        <select class="select light result-select" data-res="${ev.id}">
          <option value="UNKNOWN" ${ev.result==='UNKNOWN'?'selected':''}>—</option>
          <option value="HOME" ${ev.result==='HOME'?'selected':''}>Casa</option>
          <option value="AWAY" ${ev.result==='AWAY'?'selected':''}>Trasferta</option>
          <option value="DRAW" ${ev.result==='DRAW'?'selected':''}>Pareggio</option>
          <option value="POSTPONED" ${ev.result==='POSTPONED'?'selected':''}>Rinviata</option>
          <option value="CANCELLED" ${ev.result==='CANCELLED'?'selected':''}>Annullata</option>
        </select>
      </td>
      <td>
        <button type="button" class="btn btn--outline btn--sm" data-save-res="${ev.id}" data-round="${r}">Applica</button>
      </td>
    `;
    tb.appendChild(tr);
  });
  __DBG__('round.load.done', {round:r});
}

async function loadAll(){ for(let r=1;r<=3;r++) await loadRound(r); }
loadAll();

/* add/aggiorna evento */
document.querySelectorAll('[data-add]').forEach(btn=>{
  btn.addEventListener('click', async ()=>{
    const r=Number(btn.getAttribute('data-add'));
    const home=Number((document.querySelector(`#home_${r}`)?.value)||0);
    const away=Number((document.querySelector(`#away_${r}`)?.value)||0);
    __DBG__('event.add.click', {round:r, home, away});
    if(!home||!away||home===away){ alert('Seleziona due squadre valide'); __DBG__('event.add.invalid', {round:r, home, away}, 'error'); return; }
    const fd=new FormData(); fd.set('round_no',String(r)); fd.set('home_team_id',String(home)); fd.set('away_team_id',String(away));
    const j=await jsonFetch(`/api/flash_tournament.php?action=add_event&tid=${encodeURIComponent(code)}&debug=1`,{method:'POST',body:fd});
    if(!j.ok){
      alert('Errore add_event: '+(j.error||''));
      const d=document.querySelector('#dbg'); d && (d.style.display='block', d.textContent=JSON.stringify(j,null,2));
      __DBG__('event.add.fail', j, 'error');
      return;
    }
    __DBG__('event.add.ok', j);
    await loadRound(r);
  });
});

/* gestione tabella eventi */
document.querySelectorAll('table.table').forEach(tbl=>{
  tbl.addEventListener('click', async (e)=>{
    const b=e.target.closest('button'); if(!b) return;

    // lock toggle
    if(b.hasAttribute('data-lock')){
      const r=b.getAttribute('data-round');
      const action = b.textContent.trim()==='Sblocca' ? 'reopen_round' : 'seal_round';
      const fd=new FormData(); fd.set('round_no',r);
      __DBG__('event.lock.toggle.req', {round:r, action});
      const j=await jsonFetch(`/api/flash_tournament.php?action=${action}&tid=${encodeURIComponent(code)}&debug=1`,{method:'POST',body:fd});
      if(!j.ok){ alert('Errore lock/unlock: '+(j.error||'')); __DBG__('event.lock.toggle.fail', j, 'error'); return; }
      __DBG__('event.lock.toggle.ok', j);
      await loadRound(Number(r));
    }

    // salva risultato
    if(b.hasAttribute('data-save-res')){
      const id=b.getAttribute('data-save-res'); const r=b.getAttribute('data-round');
      const sel=document.querySelector(`select[data-res="${id}"]`); const result=sel?sel.value:'UNKNOWN';
      __DBG__('event.result.save.req', {round:r, event_id:id, result});
      try{
        const u=await fetch('/admin/_flash_set_result.php', {method:'POST', body:new URLSearchParams({tid:code,event_id:id,result})});
        const raw=await u.text();
        __DBG__('event.result.save.raw', {status:u.status, rawSnippet: raw.length>1200 ? raw.slice(0,1200)+'…' : raw});
        try{ JSON.parse(raw); }catch(e){ __DBG__('event.result.save.json.error', {error:e?.message, rawSnippet: raw.slice(0,1200)}, 'error'); }
      }catch(e){
        __DBG__('event.result.save.net.error', {error:e?.message || e}, 'error');
        alert('Errore rete salvataggio risultato');
      }
      await loadRound(Number(r));
    }
  });
});

/* Azioni globali */
const btnPublish=document.querySelector('#btnPublishTour');
if(btnPublish) btnPublish.addEventListener('click', async ()=>{
  if(!confirm('Pubblicare il torneo?')) return;
  __DBG__('tour.publish.req', {tid:code});
  const j=await jsonFetch(`/api/flash_tournament.php?action=publish&tid=${encodeURIComponent(code)}&debug=1`,{method:'POST'});
  if(!j.ok){ alert('Errore publish: '+(j.error||'')); __DBG__('tour.publish.fail', j, 'error'); return; }
  __DBG__('tour.publish.ok', j);
  alert('Torneo pubblicato.'); window.location.reload();
});

const btnSeal=document.querySelector('#btnSeal');
if(btnSeal) btnSeal.addEventListener('click', async ()=>{
  const fd=new FormData(); fd.set('round_no',String(currentRound));
  __DBG__('round.seal.req', {round:currentRound});
  const j=await jsonFetch(`/api/flash_tournament.php?action=seal_round&tid=${encodeURIComponent(code)}&debug=1`,{method:'POST',body:fd});
  if(!j.ok){ alert('Errore sigillo: '+(j.error||'')); __DBG__('round.seal.fail', j, 'error'); return; }
  __DBG__('round.seal.ok', j);
  alert('Round sigillato.');
});

const btnReopen=document.querySelector('#btnReopen');
if(btnReopen) btnReopen.addEventListener('click', async ()=>{
  const fd=new FormData(); fd.set('round_no',String(currentRound));
  __DBG__('round.reopen.req', {round:currentRound});
  const j=await jsonFetch(`/api/flash_tournament.php?action=reopen_round&tid=${encodeURIComponent(code)}&debug=1`,{method:'POST',body:fd});
  if(!j.ok){ alert('Errore riapertura: '+(j.error||'')); __DBG__('round.reopen.fail', j, 'error'); return; }
  __DBG__('round.reopen.ok', j);
  alert('Round riaperto.');
});

const btnCalc=document.querySelector('#btnCalcRound');
if(btnCalc) btnCalc.addEventListener('click', async ()=>{
  const fd=new FormData(); fd.set('round_no',String(currentRound));
  __DBG__('round.compute.req', {round:currentRound});
  const j=await jsonFetch(`/api/flash_tournament.php?action=compute_round&tid=${encodeURIComponent(code)}&debug=1`,{method:'POST',body:fd});
  if(!j.ok){ alert('Errore calcolo: '+(j.error||'')); __DBG__('round.compute.fail', j, 'error'); return; }
  __DBG__('round.compute.ok', j);
  alert(`Calcolo OK. Passano: ${j.passed}, Eliminati: ${j.out}.`);
});

const btnNext=document.querySelector('#btnPublishNext');
if(btnNext) btnNext.addEventListener('click', async ()=>{
  const fd=new FormData(); fd.set('round_no',String(currentRound));
  __DBG__('round.publishNext.req', {round:currentRound});
  const j=await jsonFetch(`/api/flash_tournament.php?action=publish_next_round&tid=${encodeURIComponent(code)}&debug=1`,{method:'POST',body:fd});
  if(!j.ok){ alert('Errore pub. next: '+(j.error||'')); __DBG__('round.publishNext.fail', j, 'error'); return; }
  currentRound = j.current_round || (currentRound+1);
  __DBG__('round.publishNext.ok', j);
  alert('Round aggiornato a: '+ currentRound);
  window.location.reload();
});

const btnFin=document.querySelector('#btnFinalize');
if(btnFin) btnFin.addEventListener('click', async ()=>{
  if(!confirm('Finalizzare il torneo?')) return;
  __DBG__('tour.finalize.req', {tid:code});
  const j=await jsonFetch(`/api/flash_tournament.php?action=finalize_tournament&tid=${encodeURIComponent(code)}&debug=1`,{method:'POST'});
  if(!j.ok){ alert('Errore finalizzazione: '+(j.error||'')+'\n'+(j.detail||'')); __DBG__('tour.finalize.fail', j, 'error'); return; }
  __DBG__('tour.finalize.ok', j);
  alert(`Finalizzato (${j.result}). Montepremi: ${j.pool}`);
  window.location.href='/admin/gestisci-tornei.php';
});
</script>
