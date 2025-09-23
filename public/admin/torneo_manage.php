<?php
require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['uid']) || !(($_SESSION['role'] ?? 'USER')==='ADMIN' || (int)($_SESSION['is_admin'] ?? 0)===1)) {
  header('Location: /login.php'); exit;
}

function jsonOut($a){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($a); exit; }
function only_post(){ if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST'){ http_response_code(405); jsonOut(['ok'=>false,'error'=>'method']); } }

function colExists(PDO $pdo, string $table, string $col): bool {
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $q->execute([$table,$col]); return (bool)$q->fetchColumn();
}
function autodetectTourCodeCol(PDO $pdo): string {
  foreach (['tour_code','code','t_code','short_id'] as $c) if (colExists($pdo,'tournaments',$c)) return $c;
  return 'tour_code';
}
function tourByCode(PDO $pdo, $code){
  $code = trim((string)$code); if ($code==='') return false;
  $codeCol = autodetectTourCodeCol($pdo);
  $st=$pdo->prepare("SELECT * FROM tournaments WHERE $codeCol=? LIMIT 1");
  $st->execute([$code]); return $st->fetch(PDO::FETCH_ASSOC);
}
function tourById(PDO $pdo, int $id){
  if ($id<=0) return false;
  $st=$pdo->prepare("SELECT * FROM tournaments WHERE id=? LIMIT 1");
  $st->execute([$id]); return $st->fetch(PDO::FETCH_ASSOC);
}
function getRoundCol(PDO $pdo): ?string {
  foreach (['current_round','round_current','round'] as $c) if (colExists($pdo,'tournaments',$c)) return $c;
  return null;
}

$code = trim($_GET['code'] ?? ($_POST['code'] ?? ''));
$tidParam = (int)($_GET['tid'] ?? $_POST['tid'] ?? 0);
$uiRound = isset($_GET['round']) ? max(1, (int)$_GET['round']) : null;

/* ==== AJAX: eventi ==== */
if (isset($_GET['action']) && in_array($_GET['action'], ['teams_suggest','list_events','add_event','toggle_lock_event','update_result_event','delete_event','set_lock','delete_tournament'], true)) {
  $tour = $tidParam>0 ? tourById($pdo,$tidParam) : tourByCode($pdo,$code);
  if (!$tour) jsonOut(['ok'=>false,'error'=>'tour_not_found']);
  $round = isset($_GET['round']) ? max(1,(int)$_GET['round']) : 1;
  $a=$_GET['action'];

  if ($a==='teams_suggest') {
    $q=trim($_GET['q'] ?? ''); $lim=20;
    if ($q===''){ $st=$pdo->query("SELECT id,name FROM teams ORDER BY name ASC LIMIT $lim"); jsonOut(['ok'=>true,'rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]); }
    $st=$pdo->prepare("SELECT id,name FROM teams WHERE name LIKE ? ORDER BY name ASC LIMIT $lim"); $st->execute(['%'.$q.'%']);
    jsonOut(['ok'=>true,'rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
  }

  if ($a==='list_events') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0'); header('Pragma: no-cache');
    $st=$pdo->prepare("SELECT te.id,te.event_code,te.home_team_id,te.away_team_id,COALESCE(te.is_locked,0) AS is_locked,te.result,te.round,
                              th.name AS home_name, ta.name AS away_name
                       FROM tournament_events te
                       JOIN teams th ON th.id=te.home_team_id
                       JOIN teams ta ON ta.id=te.away_team_id
                       WHERE te.tournament_id=? AND te.round=?
                       ORDER BY te.id DESC");
    $st->execute([$tour['id'], $round]);
    jsonOut(['ok'=>true,'rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
  }

  if ($a==='add_event') {
    only_post();
    $homeId = (int)($_POST['home_team_id'] ?? 0);
$awayId = (int)($_POST['away_team_id'] ?? 0);
    if ($homeId<=0 || $awayId<=0 || $homeId===$awayId) jsonOut(['ok'=>false,'error'=>'teams_invalid']);
    $codeEv=strtoupper(substr(bin2hex(random_bytes(6)),0,6));
    $st=$pdo->prepare("INSERT INTO tournament_events(event_code,tournament_id,home_team_id,away_team_id,round) VALUES (?,?,?,?,?)");
    $st->execute([$codeEv,$tour['id'],$homeId,$awayId,$round]);
    jsonOut(['ok'=>true,'event_code'=>$codeEv]);
  }

  if ($a==='toggle_lock_event') {
    only_post(); $id=(int)$_POST['event_id'];
    $st=$pdo->prepare("UPDATE tournament_events SET is_locked=CASE WHEN COALESCE(is_locked,0)=1 THEN 0 ELSE 1 END WHERE id=? AND tournament_id=?");
    $st->execute([$id,$tour['id']]); jsonOut(['ok'=>true]);
  }

  if ($a==='update_result_event') {
    only_post(); $id=(int)$_POST['event_id']; $result=$_POST['result'] ?? 'UNKNOWN';
    $allowed=['HOME','AWAY','DRAW','VOID','POSTPONED','UNKNOWN','CANCELLED','CANCELED'];
    if(!in_array($result,$allowed,true)) jsonOut(['ok'=>false,'error'=>'result_invalid']);
    $st=$pdo->prepare("UPDATE tournament_events SET result=?, result_set_at=NOW() WHERE id=? AND tournament_id=?");
    $st->execute([$result,$id,$tour['id']]); jsonOut(['ok'=>true]);
  }

  if ($a==='delete_event') {
    only_post(); $id=(int)$_POST['event_id'];
    $st=$pdo->prepare("DELETE FROM tournament_events WHERE id=? AND tournament_id=? LIMIT 1"); $st->execute([$id,$tour['id']]);
    jsonOut(['ok'=>true]);
  }

  if ($a==='set_lock') {
    only_post();
    $val = trim($_POST['lock_at'] ?? '');
    $x   = ($val!=='') ? $val : null;
    $st  = $pdo->prepare("UPDATE tournaments SET lock_at=? WHERE id=?");
    $st->execute([$x,$tour['id']]);
    jsonOut(['ok'=>true,'lock_at'=>$x]);
  }

  if ($a==='delete_tournament') {
    only_post();
    try{
      $pdo->beginTransaction();
      $pdo->prepare("DELETE FROM tournament_picks WHERE tournament_id=?")->execute([$tour['id']]);
      $pdo->prepare("DELETE FROM tournament_events WHERE tournament_id=?")->execute([$tour['id']]);
      $pdo->prepare("DELETE FROM tournament_lives  WHERE tournament_id=?")->execute([$tour['id']]);
      $pdo->prepare("DELETE FROM tournaments      WHERE id=?")->execute([$tour['id']]);
      $pdo->commit();
      jsonOut(['ok'=>true]);
    }catch(Throwable $e){
      if ($pdo->inTransaction()) $pdo->rollBack();
      jsonOut(['ok'=>false,'error'=>'delete_failed','detail'=>$e->getMessage()]);
    }
  }

  http_response_code(400); jsonOut(['ok'=>false,'error'=>'unknown_action']);
}

/* ===== Page view ===== */
$tour = $tidParam>0 ? tourById($pdo,$tidParam) : tourByCode($pdo,$code);
if (!$tour) { echo "<main class='section'><div class='container'><h1>Torneo non trovato</h1></div></main>"; include __DIR__.'/../../partials/footer.php'; exit; }

$page_css='/pages-css/admin-dashboard.css';
include __DIR__.'/../../partials/head.php';
include __DIR__.'/../../partials/header_admin.php';

$roundCol = getRoundCol($pdo);
$tourCurrentRound = $roundCol ? (int)($tour[$roundCol] ?? 1) : 1;

$maxEventRoundRow = $pdo->prepare("SELECT COALESCE(MAX(round),0) AS mr FROM tournament_events WHERE tournament_id=?");
$maxEventRoundRow->execute([$tour['id']]);
$maxEventRound = (int)$maxEventRoundRow->fetchColumn();

$uiRoundVal = (int)($uiRound ?? 0);
$maxRound = max(1, $maxEventRound, $tourCurrentRound, $uiRoundVal);
$currentRound = $uiRoundVal ?: $tourCurrentRound;

$teamsInit=$pdo->query("SELECT id,name FROM teams ORDER BY name ASC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
?>
<main>
<section class="section">
  <div class="container">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
      <h1>Torneo: <?= htmlspecialchars($tour['name']) ?>
        <span class="muted">(<?= htmlspecialchars($tour['tour_code'] ?? $tour['code'] ?? $tour['t_code'] ?? $tour['short_id'] ?? '') ?>)</span>
      </h1>
      <button type="button" class="btn btn--outline btn--sm btn-danger" id="btnDeleteTour">Elimina torneo</button>
    </div>

    <div class="card" style="margin-bottom:16px;">
      <h2 class="card-title">Impostazioni</h2>

      <div class="grid2" style="align-items:end;">
        <div class="field">
          <label class="label">Lock scelte (data/ora)</label>
          <input class="input light" id="lock_at" type="datetime-local" value="<?= !empty($tour['lock_at']) ? date('Y-m-d\TH:i', strtotime($tour['lock_at'])) : '' ?>">
        </div>
        <div class="field">
          <label class="label">&nbsp;</label>
          <button type="button" class="btn btn--outline" id="btnToggleLock"><?= !empty($tour['lock_at']) ? 'Rimuovi lock' : 'Imposta lock' ?></button>
        </div>
      </div>

      <div class="round-row" style="margin-top:8px;">
        <span class="label" style="margin:0 8px 0 0;">Round</span>
        <select id="round_select" class="select light round-select">
          <?php for($i=1;$i<=$maxRound;$i++): ?>
            <option value="<?= $i ?>" <?= $i===$currentRound ? 'selected':'' ?>>Round <?= $i ?></option>
          <?php endfor; ?>
        </select>

        <button type="button" class="btn btn--outline" id="btnSeal">Chiudi scelte</button>
        <button type="button" class="btn btn--outline" id="btnReopen">Riapri scelte</button>
        <button type="button" class="btn btn--primary" id="btnCalcRound">Calcola round</button>
        <button type="button" class="btn btn--outline" id="btnPublishNext">Pubblica Round successivo</button>
      </div>
    </div>

    <div class="card">
      <h2 class="card-title">Eventi</h2>

      <form id="fEv" class="grid2" onsubmit="return false;">
        <div class="field">
          <label class="label">Casa</label>
          <select id="home_team" class="select light team-select">
            <option value="">— Seleziona —</option>
            <?php foreach($teamsInit as $t): ?>
              <option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="label">Trasferta</label>
          <select id="away_team" class="select light team-select">
            <option value="">— Seleziona —</option>
            <?php foreach($teamsInit as $t): ?>
              <option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field" style="grid-column: span 2;">
          <button type="button" class="btn btn--primary" id="btnAddEv">Aggiungi evento</button>
        </div>
      </form>

      <div class="table-wrap" style="margin-top:12px;">
        <table class="table" id="tblEv">
          <thead><tr><th>Codice</th><th>Casa</th><th>Trasferta</th><th>Blocco</th><th>Risultato</th><th>Azioni</th></tr></thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>
</section>
</main>
<?php include __DIR__ . '/../../partials/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const $ = s=>document.querySelector(s);
  const tourCode   = "<?= htmlspecialchars($tour['tour_code'] ?? $tour['code'] ?? $tour['t_code'] ?? $tour['short_id'] ?? '') ?>";
  const currentRound = <?= (int)$currentRound ?>;
  const codeQS = `code=${encodeURIComponent(tourCode)}`;
  const baseUrl = (round)=>`?${codeQS}&round=${encodeURIComponent(round)}`;

  async function loadEvents(round){
    const r = await fetch(`${baseUrl(round)}&action=list_events`, {cache:'no-store'});
    const j = await r.json();
    if(!j.ok){ alert('Errore caricamento'); return; }
    const tb = document.querySelector('#tblEv tbody'); tb.innerHTML='';
    j.rows.forEach(ev=>{
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${ev.event_code}</td>
        <td>${ev.home_name}</td>
        <td>${ev.away_name}</td>
        <td>
          <button type="button" class="btn btn--outline btn--sm" data-lock="${ev.id}">
            ${Number(ev.is_locked)===1 ? 'Sblocca' : 'Blocca'}
          </button>
        </td>
        <td>
          <select class="select light result-select" data-res="${ev.id}">
            <option value="UNKNOWN" ${ev.result==='UNKNOWN'?'selected':''}>—</option>
            <option value="HOME" ${ev.result==='HOME'?'selected':''}>Casa vince</option>
            <option value="AWAY" ${ev.result==='AWAY'?'selected':''}>Trasferta vince</option>
            <option value="DRAW" ${ev.result==='DRAW'?'selected':''}>Pareggio</option>
            <option value="VOID" ${ev.result==='VOID'?'selected':''}>Annullata</option>
            <option value="POSTPONED" ${ev.result==='POSTPONED'?'selected':''}>Rinviata</option>
            <option value="CANCELLED" ${ev.result==='CANCELLED'?'selected':''}>Annullata (cancelled)</option>
          </select>
        </td>
        <td class="actions-cell">
          <button type="button" class="btn btn--outline btn--sm" data-save-res="${ev.id}">Applica</button>
          <button type="button" class="btn btn--outline btn--sm btn-danger" data-del="${ev.id}">Elimina</button>
        </td>
      `;
      tb.appendChild(tr);
    });
  }

  async function apiCore(action, body) {
    const fd = new URLSearchParams(body || {});
    const resp = await fetch(`/api/tournament_core.php?action=${encodeURIComponent(action)}&tid=${encodeURIComponent(tourCode)}`, {
      method:'POST', body: fd, credentials:'same-origin'
    });
    const json = await resp.json().catch(()=>({ok:false,error:'bad_json'}));
    if (!json.ok) throw json;
    return json;
  }

  $('#btnSeal').addEventListener('click', async ()=>{
    const v = Number($('#round_select').value || currentRound);
    try{
      const r = await apiCore('seal_round', {round:String(v)});
      if (r.mode==='pick_lock')      alert(`Sigillate ${r.sealed||0} pick (modalità: sigillo pick).`);
      else if (r.mode==='event_lock')alert(`Bloccati ${r.events_locked||0} eventi (modalità: lock evento).`);
      else if (r.mode==='tour_lock') alert(`Impostato lock_at a ORA (modalità: lock torneo).`);
      else                           alert('Sigillo completato.');
    }catch(e){
      alert('Errore sigillo: ' + (e.detail || e.error || ''));
    }
  });

  $('#btnReopen').addEventListener('click', async ()=>{
    const v = Number($('#round_select').value || currentRound);
    if (!confirm(`Riaprire le scelte del round ${v}?`)) return;
    try{
      const r = await apiCore('reopen_round', {round:String(v)});
      if (r.mode==='pick_lock')       alert(`Riaperto: ${r.reopened||0} pick.`);
      else if (r.mode==='event_lock') alert(`Sbloccati ${r.events_unlocked||0} eventi.`);
      else if (r.mode==='tour_lock')  alert(`Lock_at azzerato. Scelte riaperte.`);
      else                            alert('Riapertura completata.');
    }catch(e){
      alert('Errore riapertura: ' + (e.detail || e.error || ''));
    }
  });

  $('#btnCalcRound').addEventListener('click', async ()=>{
    const v = Number($('#round_select').value || currentRound);
    if (!confirm(`Calcolare il round ${v}?`)) return;
    try{
      const r = await apiCore('compute_round', {round:String(v)});
      alert(`Calcolo completato (modalità sigillo: ${r.sealed_mode}).\nPassano: ${r.passed}, Eliminati: ${r.out}.`);
      if (r.needs_finalize) {
        alert('Attenzione: utenti vivi < 2. Devi FINALIZZARE il torneo.');
      }
      await loadEvents(v);
    }catch(e){
      if (e && e.error==='results_missing') {
        alert('Risultati mancanti per: ' + (e.events ? e.events.join(', ') : ''));
      } else if (e && e.error==='round_already_published') {
        alert('Round già pubblicato: non è possibile ricalcolare.');
      } else if (e && e.error==='duplicate_picks') {
        const msg = (e.detail||[]).map(d=>`Vita #${d.life_id}, utente #${d.user_id}, pick totali=${d.total_picks}, ultima=${d.last_pick_id}`).join('\n');
        alert('Doppie pick sigillate:\n' + msg);
      } else if (e && e.error==='invalid_pick_team') {
        const d = e.detail||{};
        alert(`Pick incoerente: vita #${d.life_id}, utente #${d.user_id}${d.username?(' ('+d.username+')'):''}, squadra=${d.picked_team_name||d.picked_team_id}`);
      } else if (e && (e.error==='seal_backend_missing' || e.error==='lock_not_set' || e.error==='lock_not_reached')) {
        alert('Lock non valido per il calcolo: ' + (e.detail || e.error));
      } else {
        alert('Errore calcolo: ' + (e.detail || e.error || 'sconosciuto'));
      }
    }
  });

  $('#btnPublishNext').addEventListener('click', async ()=>{
    const v = Number($('#round_select').value || currentRound);
    if (!confirm(`Pubblicare il round ${v+1} (chiude il round ${v})?`)) return;
    try{
      const r = await apiCore('publish_next_round', {round:String(v)});
      alert(`Pubblicazione ok. Current round: ${r.current_round ?? (v+1)}`);
      // reset lock input lato UI
      const lockInput = $('#lock_at'); if (lockInput) lockInput.value='';
      const lockBtn = $('#btnToggleLock'); if (lockBtn) lockBtn.textContent = 'Imposta lock';
      const sel = $('#round_select');
      if (sel) {
        const next = v+1;
        if (![...sel.options].some(o=>Number(o.value)===next)) {
          const opt = document.createElement('option');
          opt.value = String(next);
          opt.textContent = `Round ${next}`;
          sel.appendChild(opt);
        }
        sel.value = String(next);
      }
      window.location.href = `?${codeQS}&round=${encodeURIComponent(v+1)}`;
    }catch(e){
      alert('Errore pubblicazione: ' + (e.detail || e.error || ''));
    }
  });

  document.getElementById('tblEv').addEventListener('click', async (e)=>{
    const b=e.target.closest('button'); if(!b) return;
    const sel = $('#round_select');
    const curRound = sel ? Number(sel.value) : currentRound;
    try{
      if(b.hasAttribute('data-lock')){
        const id=b.getAttribute('data-lock');
        const r=await fetch(`${baseUrl(curRound)}&action=toggle_lock_event`,{method:'POST',body:new URLSearchParams({event_id:id})});
        const j=await r.json(); if(!j.ok){ alert('Errore lock evento'); return; } await loadEvents(curRound); return;
      }
      if(b.hasAttribute('data-save-res')){
        const id=b.getAttribute('data-save-res'); const dd=document.querySelector(`select[data-res="${id}"]`); const res=dd?dd.value:'UNKNOWN';
        const r=await fetch(`${baseUrl(curRound)}&action=update_result_event`,{method:'POST',body:new URLSearchParams({event_id:id,result:res})});
        const j=await r.json(); if(!j.ok){ alert('Errore aggiornamento risultato'); return; } await loadEvents(curRound); return;
      }
      if(b.classList.contains('btn-danger') && b.hasAttribute('data-del')){
        const id=b.getAttribute('data-del'); if(!confirm('Eliminare l\'evento?')) return;
        const r=await fetch(`${baseUrl(curRound)}&action=delete_event`,{method:'POST',body:new URLSearchParams({event_id:id})});
        const j=await r.json(); if(!j.ok){ alert('Errore eliminazione'); return; } await loadEvents(curRound); return;
      }
    }catch(err){ console.error(err); alert('Errore imprevisto'); }
  });

  document.getElementById('btnToggleLock').addEventListener('click', async ()=>{
    const val=$('#lock_at').value.trim();
    const r=await fetch(`${baseUrl(currentRound)}&action=set_lock`,{method:'POST',body:new URLSearchParams({lock_at:val})});
    const j=await r.json(); if(!j.ok){ alert('Errore lock torneo'); return; }
    document.getElementById('btnToggleLock').textContent = (val==='') ? 'Imposta lock' : 'Rimuovi lock';
    alert('Lock aggiornato');
  });

  document.getElementById('round_select').addEventListener('change', (e)=>{
    const v = e.target.value;
    window.location.href = `?${codeQS}&round=${encodeURIComponent(v)}`;
  });

  loadEvents(currentRound);

  document.getElementById('btnDeleteTour').addEventListener('click', async ()=>{
    if(!confirm('Eliminare definitivamente il torneo e tutti i suoi eventi?')) return;
    const r=await fetch(`?${codeQS}&action=delete_tournament`,{method:'POST'}); const j=await r.json();
    if(!j.ok){ alert('Errore eliminazione torneo'); return; }
    window.location.href='/admin/crea-tornei.php';
  });
});
</script>
