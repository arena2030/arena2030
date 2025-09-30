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
?>
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

        <!-- Pulsanti (tutti small e ovali, 3 per fila) -->
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

<!-- Espone il token CSRF -->
<script>window.__CSRF = "<?= $CSRF ?>";</script>

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
      if (!val) { alert('Imposta data/ora'); return; }
      const CSRF = window.__CSRF || '';
      const body = new URLSearchParams({
        csrf_token: CSRF,
        id: '<?= (int)$tour['id'] ?>',
        lock_at: val
      }).toString();
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
      const j = await r.json();
      if (j.ok) {
        alert('Lock salvato: ' + j.lock_at);
      } else {
        alert('Errore salvataggio lock: ' + (j.detail || j.error || ''));
      }
    });
  }
  
  // resto del JS (add_event, seal, reopen, ecc.) invariato ...
});
</script>
  
  async function jsonFetch(url,opts){
    const r=await fetch(url,opts||{});
    const raw=await r.text();
    try{ return JSON.parse(raw); } catch(e){ console.error('[RAW]',raw); return {ok:false,error:'bad_json',raw}; }
  }

  async function loadRound(r){
    const j=await jsonFetch(`/api/flash_tournament.php?action=list_events&tid=${encodeURIComponent(code)}&round_no=${r}&debug=1`,{cache:'no-store'});
    const tb=$(`#tblR${r} tbody`); tb.innerHTML='';
    if(!j.ok){ alert('Errore load events R'+r); return; }
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
  }

  async function loadAll(){ for(let r=1;r<=3;r++) await loadRound(r); }
  loadAll();

  // add/aggiorna evento
  document.querySelectorAll('[data-add]').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
      const r=Number(btn.getAttribute('data-add'));
      const home=Number($(`#home_${r}`).value||0);
      const away=Number($(`#away_${r}`).value||0);
      if(!home||!away||home===away){ alert('Seleziona due squadre valide'); return; }
      const fd=new FormData(); fd.set('round_no',String(r)); fd.set('home_team_id',String(home)); fd.set('away_team_id',String(away));
      const j=await jsonFetch(`/api/flash_tournament.php?action=add_event&tid=${encodeURIComponent(code)}&debug=1`,{method:'POST',body:fd});
      if(!j.ok){ alert('Errore add_event: '+(j.error||'')); const d=$('#dbg'); d.style.display='block'; d.textContent=JSON.stringify(j,null,2); return; }
      await loadRound(r);
    });
  });

  // gestione tabella eventi
  document.querySelectorAll('table.table').forEach(tbl=>{
    tbl.addEventListener('click', async (e)=>{
      const b=e.target.closest('button'); if(!b) return;
      // lock toggle
      if(b.hasAttribute('data-lock')){
        const id=b.getAttribute('data-lock'); const r=b.getAttribute('data-round');
        // semplice toggle: se locked, riapri round; altrimenti sigilla
        const fd=new FormData(); fd.set('round_no',r);
        const action = b.textContent.trim()==='Sblocca' ? 'reopen_round' : 'seal_round';
        const j=await jsonFetch(`/api/flash_tournament.php?action=${action}&tid=${encodeURIComponent(code)}&debug=1`,{method:'POST',body:fd});
        if(!j.ok){ alert('Errore lock/unlock: '+(j.error||'')); return; }
        await loadRound(Number(r));
      }
      // salva risultato
      if(b.hasAttribute('data-save-res')){
        const id=b.getAttribute('data-save-res'); const r=b.getAttribute('data-round');
        const sel=document.querySelector(`select[data-res="${id}"]`); const result=sel?sel.value:'UNKNOWN';
        // aggiorna direttamente la tabella eventi
        const fd=new FormData(); fd.set('round_no',r); fd.set('result',result); fd.set('event_id',id);
        // endpoint dedicato non necessario: usiamo SQL rapido via API custom locale
        const j=await jsonFetch(`/api/flash_tournament.php?action=list_events&tid=${encodeURIComponent(code)}&round_no=${r}&debug=1`,{cache:'no-store'});
        // per semplicità aggiorniamo via una rotta inline dedicata (qui sotto)
        const u=await fetch('/admin/_flash_set_result.php', {method:'POST', body:new URLSearchParams({tid:code,event_id:id,result})});
        const raw=await u.text(); try{ JSON.parse(raw); }catch(e){ console.error('[RAW set_result]',raw); }
        await loadRound(Number(r));
      }
    });
  });

  // publish
  const btnPublish=$('#btnPublishTour');
  if(btnPublish) btnPublish.addEventListener('click', async ()=>{
    if(!confirm('Pubblicare il torneo?')) return;
    const j=await jsonFetch(`/api/flash_tournament.php?action=publish&tid=${encodeURIComponent(code)}&debug=1`,{method:'POST'});
    if(!j.ok){ alert('Errore publish: '+(j.error||'')); return; }
    alert('Torneo pubblicato.'); window.location.reload();
  });

  // seal
  const btnSeal=$('#btnSeal');
  if(btnSeal) btnSeal.addEventListener('click', async ()=>{
    const fd=new FormData(); fd.set('round_no',String(currentRound));
    const j=await jsonFetch(`/api/flash_tournament.php?action=seal_round&tid=${encodeURIComponent(code)}&debug=1`,{method:'POST',body:fd});
    if(!j.ok){ alert('Errore sigillo: '+(j.error||'')); return; }
    alert('Round sigillato.'); 
  });

  // reopen
  const btnReopen=$('#btnReopen');
  if(btnReopen) btnReopen.addEventListener('click', async ()=>{
    const fd=new FormData(); fd.set('round_no',String(currentRound));
    const j=await jsonFetch(`/api/flash_tournament.php?action=reopen_round&tid=${encodeURIComponent(code)}&debug=1`,{method:'POST',body:fd});
    if(!j.ok){ alert('Errore riapertura: '+(j.error||'')); return; }
    alert('Round riaperto.');
  });

  // compute
  const btnCalc=$('#btnCalcRound');
  if(btnCalc) btnCalc.addEventListener('click', async ()=>{
    const fd=new FormData(); fd.set('round_no',String(currentRound));
    const j=await jsonFetch(`/api/flash_tournament.php?action=compute_round&tid=${encodeURIComponent(code)}&debug=1`,{method:'POST',body:fd});
    if(!j.ok){ alert('Errore calcolo: '+(j.error||'')); return; }
    alert(`Calcolo OK. Passano: ${j.passed}, Eliminati: ${j.out}.`);
  });

  // publish next
  const btnNext=$('#btnPublishNext');
  if(btnNext) btnNext.addEventListener('click', async ()=>{
    const fd=new FormData(); fd.set('round_no',String(currentRound));
    const j=await jsonFetch(`/api/flash_tournament.php?action=publish_next_round&tid=${encodeURIComponent(code)}&debug=1`,{method:'POST',body:fd});
    if(!j.ok){ alert('Errore pub. next: '+(j.error||'')); return; }
    currentRound = j.current_round || (currentRound+1);
    alert('Round aggiornato a: '+ currentRound);
    window.location.reload();
  });

  // finalize
  const btnFin=$('#btnFinalize');
  if(btnFin) btnFin.addEventListener('click', async ()=>{
    if(!confirm('Finalizzare il torneo?')) return;
    const j=await jsonFetch(`/api/flash_tournament.php?action=finalize_tournament&tid=${encodeURIComponent(code)}&debug=1`,{method:'POST'});
    if(!j.ok){ alert('Errore finalizzazione: '+(j.error||'')+'\n'+(j.detail||'')); return; }
    alert(`Finalizzato (${j.result}). Montepremi: ${j.pool}`);
    window.location.href='/admin/gestisci-tornei.php';
  });
});
</script>
