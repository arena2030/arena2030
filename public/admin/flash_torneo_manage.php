<?php
require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }
$role = strtoupper((string)($_SESSION['role'] ?? 'USER'));
if (empty($_SESSION['uid']) || (!in_array($role,['ADMIN','PUNTO']) && (int)($_SESSION['is_admin'] ?? 0)!==1)) {
  header('Location: /login.php'); exit;
}
$code = trim($_GET['code'] ?? '');
if ($code===''){ echo '<main class="section"><div class="container"><h1>Codice mancante</h1></div></main>'; exit; }
$page_css='/pages-css/flash.css';
include __DIR__.'/../../partials/head.php';
include __DIR__.'/../../partials/header_admin.php';

$st=$pdo->prepare("SELECT * FROM flash_tournaments WHERE code=? LIMIT 1"); $st->execute([$code]);
$tour=$st->fetch(PDO::FETCH_ASSOC);
if (!$tour){ echo '<main class="section"><div class="container"><h1>Torneo Flash non trovato</h1></div></main>'; include __DIR__.'/../../partials/footer.php'; exit; }

$totalRounds=(int)$tour['total_rounds']; $curRound=(int)$tour['current_round']; $status=strtolower($tour['status']);
?>
<main class="section">
  <div class="container card">
    <div style="display:flex;justify-content:space-between;align-items:center;">
      <h1><?= htmlspecialchars($tour['name']).' ('.htmlspecialchars($tour['code']).')' ?></h1>
      <a href="/flash/torneo.php?code=<?= urlencode($tour['code']) ?>" class="btn btn--outline btn--sm" target="_blank">Apri pagina utente</a>
    </div>

    <div class="mt-4 flash-toolbar">
      <div>
        <div class="label">Stato</div>
        <div class="muted"><?= htmlspecialchars($status) ?></div>
      </div>
      <div>
        <div class="label">Round</div>
        <select id="roundSel" class="select light">
          <?php for($i=1;$i<=$totalRounds;$i++): ?>
          <option value="<?= $i ?>" <?= $i===$curRound?'selected':'' ?>>Round <?= $i ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="btn-grid-3">
        <?php if ($status==='pending'): ?>
          <button id="btnPublish" class="btn btn--primary btn--sm">Pubblica torneo</button>
        <?php endif; ?>
        <?php if ($status==='published' || $status==='locked'): ?>
          <button id="btnSeal" class="btn btn--primary btn--sm">Chiudi scelte</button>
          <button id="btnReopen" class="btn btn--primary btn--sm">Riapri scelte</button>
          <button id="btnCompute" class="btn btn--primary btn--sm">Calcola round</button>
          <button id="btnNext" class="btn btn--primary btn--sm">Pubblica round successivo</button>
          <button id="btnFinalize" class="btn btn--primary btn--sm">Finalizza torneo</button>
        <?php endif; ?>
      </div>
    </div>

    <hr class="mt-4" />

    <h2>Eventi round <span id="hRound"><?= $curRound ?></span></h2>
    <div class="grid2">
      <div class="field">
        <label class="label">Casa</label>
        <input class="input light" id="home" placeholder="ID squadra (teams.id)">
      </div>
      <div class="field">
        <label class="label">Trasferta</label>
        <input class="input light" id="away" placeholder="ID squadra (teams.id)">
      </div>
    </div>
    <button id="btnAdd" class="btn btn--primary btn--sm mt-4">Aggiungi evento</button>

    <div class="table-wrap mt-4">
      <table class="table" id="tbl">
        <thead><tr><th>Code</th><th>Casa</th><th>Trasferta</th><th>Lock</th><th>Risultato</th></tr></thead>
        <tbody></tbody>
      </table>
    </div>

    <pre id="debug" class="flash-debug mt-4 hidden"></pre>
  </div>
</main>
<script>
const tourCode = <?= json_encode($tour['code']) ?>;
const dbgEl = document.getElementById('debug');

function showErr(where, raw, json){
  dbgEl.classList.remove('hidden');
  dbgEl.textContent = `[${where}] ERRORE:\n` + (json ? JSON.stringify(json,null,2) : raw);
}

async function jfetch(url, opts){
  const resp = await fetch(url, opts || {});
  const raw = await resp.text();
  try { return JSON.parse(raw); }
  catch(e){ showErr('jfetch', raw, null); return {ok:false,error:'bad_json',raw}; }
}

async function loadEv(){
  const round = Number(document.getElementById('roundSel').value);
  document.getElementById('hRound').textContent = round;
  const j = await jfetch(`/api/flash_tournament.php?action=list_events&tid=${encodeURIComponent(tourCode)}&round_no=${round}&debug=1`);
  if (!j.ok){ showErr('list_events', j.raw || '', j); return; }
  const tb = document.querySelector('#tbl tbody'); tb.innerHTML='';
  for (const r of j.rows){
    const tr = document.createElement('tr');
    tr.innerHTML = `<td>${r.event_code}</td><td>${r.home_name}</td><td>${r.away_name}</td>
      <td>${Number(r.is_locked)===1?'🔒':'—'}</td>
      <td>${r.result}</td>`;
    tb.appendChild(tr);
  }
}

document.getElementById('roundSel').addEventListener('change', loadEv);

document.getElementById('btnAdd').addEventListener('click', async ()=>{
  const round = Number(document.getElementById('roundSel').value);
  const home = Number(document.getElementById('home').value || 0);
  const away = Number(document.getElementById('away').value || 0);
  const fd = new URLSearchParams({round_no:String(round), home_team_id:String(home), away_team_id:String(away), debug:'1'});
  const j = await jfetch(`/api/flash_tournament.php?action=add_event&tid=${encodeURIComponent(tourCode)}`, {method:'POST', body:fd});
  if (!j.ok){ showErr('add_event', j.raw || '', j); return; }
  await loadEv();
});

const actPost = (action, extra)=> jfetch(`/api/flash_tournament.php?action=${action}&tid=${encodeURIComponent(tourCode)}`, {method:'POST', body: new URLSearchParams({...extra, debug:'1'})});

const btnPublish = document.getElementById('btnPublish');
if (btnPublish) btnPublish.addEventListener('click', async ()=>{ const j=await actPost('publish',{}); if(!j.ok){showErr('publish','',j);return;} alert('Pubblicato'); location.reload(); });

const btnSeal = document.getElementById('btnSeal');
if (btnSeal) btnSeal.addEventListener('click', async ()=>{ const r=Number(roundSel.value); const j=await actPost('seal_round',{round_no:String(r)}); if(!j.ok){showErr('seal_round','',j);return;} alert('Sigillato'); });

const btnReopen = document.getElementById('btnReopen');
if (btnReopen) btnReopen.addEventListener('click', async ()=>{ const r=Number(roundSel.value); const j=await actPost('reopen_round',{round_no:String(r)}); if(!j.ok){showErr('reopen_round','',j);return;} alert('Riaperto'); });

const btnCompute = document.getElementById('btnCompute');
if (btnCompute) btnCompute.addEventListener('click', async ()=>{ const r=Number(roundSel.value); const j=await actPost('compute_round',{round_no:String(r)}); if(!j.ok){showErr('compute_round','',j);return;} alert(`Calcolo ok. Passano ${j.passed}, out ${j.out}`); });

const btnNext = document.getElementById('btnNext');
if (btnNext) btnNext.addEventListener('click', async ()=>{ const r=Number(roundSel.value); const j=await actPost('publish_next_round',{round_no:String(r)}); if(!j.ok){showErr('publish_next_round','',j);return;} alert('Round successivo pubblicato'); location.reload(); });

const btnFinalize = document.getElementById('btnFinalize');
if (btnFinalize) btnFinalize.addEventListener('click', async ()=>{ const j=await actPost('finalize_tournament',{}); if(!j.ok){showErr('finalize_tournament','',j);return;} alert('Finalizzato'); location.href='/admin/gestisci-tornei.php'; });

loadEv();
</script>
<?php include __DIR__.'/../../partials/footer.php'; ?>
