<?php
require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['uid']) || !(($_SESSION['role'] ?? 'USER')==='ADMIN' || (int)($_SESSION['is_admin'] ?? 0)===1)) {
  header('Location: /login.php'); exit;
}

function json($a){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($a); exit; }
function only_post(){ if ($_SERVER['REQUEST_METHOD']!=='POST'){ http_response_code(405); json(['ok'=>false,'error'=>'method']); } }
function tourByCode(PDO $pdo, $code){
  $st = $pdo->prepare("SELECT * FROM tournaments WHERE tour_code=? LIMIT 1");
  $st->execute([$code]);
  return $st->fetch(PDO::FETCH_ASSOC);
}
function genCode($len=6){ $n=random_int(0, 36**$len -1); $b36=strtoupper(base_convert($n,10,36)); return str_pad($b36,$len,'0',STR_PAD_LEFT); }
function getFreeCode(PDO $pdo, $table, $col){ for($i=0;$i<10;$i++){ $c=genCode(6); $st=$pdo->prepare("SELECT 1 FROM {$table} WHERE {$col}=? LIMIT 1"); $st->execute([$c]); if(!$st->fetch()) return $c; } throw new RuntimeException('code'); }

$code = trim($_GET['code'] ?? '');
$tour = $code ? tourByCode($pdo, $code) : null;
if (!$tour) { echo "Torneo non trovato"; exit; }

if (isset($_GET['action'])) {
  $a = $_GET['action'];

  // LIST eventi
  if ($a==='list_events') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    $st = $pdo->prepare("SELECT te.id, te.event_code, te.home_team_id, te.away_team_id, te.is_locked, te.result,
                                th.name AS home_name, ta.name AS away_name
                         FROM tournament_events te
                         JOIN teams th ON th.id=te.home_team_id
                         JOIN teams ta ON ta.id=te.away_team_id
                         WHERE te.tournament_id=?
                         ORDER BY te.id DESC");
    $st->execute([$tour['id']]);
    json(['ok'=>true,'rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
  }

  // ADD evento
  if ($a==='add_event') {
    only_post();
    $home = (int)($_POST['home_team_id'] ?? 0);
    $away = (int)($_POST['away_team_id'] ?? 0);
    if ($home<=0 || $away<=0 || $home===$away) json(['ok'=>false,'error'=>'teams_invalid']);
    try{
      $evcode = getFreeCode($pdo,'tournament_events','event_code');
      $st = $pdo->prepare("INSERT INTO tournament_events(event_code,tournament_id,home_team_id,away_team_id) VALUES (?,?,?,?)");
      $st->execute([$evcode,$tour['id'],$home,$away]);
      json(['ok'=>true]);
    }catch(Throwable $e){ json(['ok'=>false,'error'=>'db','detail'=>$e->getMessage()]); }
  }

  // TOGGLE lock evento
  if ($a==='toggle_lock_event') {
    only_post();
    $id = (int)($_POST['event_id'] ?? 0);
    $st = $pdo->prepare("UPDATE tournament_events SET is_locked = CASE WHEN is_locked=1 THEN 0 ELSE 1 END WHERE id=? AND tournament_id=?");
    $st->execute([$id,$tour['id']]);
    json(['ok'=>true]);
  }

  // UPDATE risultato evento
  if ($a==='update_result_event') {
    only_post();
    $id = (int)($_POST['event_id'] ?? 0);
    $result = $_POST['result'] ?? 'UNKNOWN';
    $allowed = ['HOME','AWAY','DRAW','VOID','POSTPONED','UNKNOWN'];
    if (!in_array($result,$allowed,true)) json(['ok'=>false,'error'=>'result_invalid']);
    $st = $pdo->prepare("UPDATE tournament_events SET result=?, result_set_at=NOW() WHERE id=? AND tournament_id=?");
    $st->execute([$result,$id,$tour['id']]);
    json(['ok'=>true]);
  }

  // DELETE evento
  if ($a==='delete_event') {
    only_post();
    $id = (int)($_POST['event_id'] ?? 0);
    $st = $pdo->prepare("DELETE FROM tournament_events WHERE id=? AND tournament_id=? LIMIT 1");
    $st->execute([$id,$tour['id']]);
    json(['ok'=>true]);
  }

  // SET global lock datetime
  if ($a==='set_lock') {
    only_post();
    $lock_at = trim($_POST['lock_at'] ?? '');
    $value = $lock_at!=='' ? $lock_at : null;
    $st = $pdo->prepare("UPDATE tournaments SET lock_at=? WHERE id=?");
    $st->execute([$value,$tour['id']]);
    json(['ok'=>true]);
  }

  // PUBLISH torneo
  if ($a==='publish') {
    only_post();
    $st = $pdo->prepare("UPDATE tournaments SET status='published' WHERE id=?");
    $st->execute([$tour['id']]);
    json(['ok'=>true]);
  }

  http_response_code(400); json(['ok'=>false,'error'=>'unknown_action']);
}

$page_css = '/pages-css/admin-dashboard.css';
include __DIR__ . '/../../partials/head.php';
include __DIR__ . '/../../partials/header_admin.php';

/* Teams per select */
$teams = $pdo->query("SELECT id,name FROM teams ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<main>
  <section class="section">
    <div class="container">
      <h1>Torneo: <?= htmlspecialchars($tour['name']) ?> <span class="muted">(<?= htmlspecialchars($tour['tour_code']) ?>)</span></h1>

      <div class="card" style="margin-bottom:16px;">
        <h2 class="card-title">Impostazioni</h2>
        <div class="grid2">
          <div class="field">
            <label class="label">Lock scelte (data/ora)</label>
            <input class="input light" id="lock_at" type="datetime-local" value="<?= $tour['lock_at'] ? date('Y-m-d\TH:i', strtotime($tour['lock_at'])) : '' ?>">
          </div>
          <div class="field" style="display:flex;align-items:flex-end;gap:8px;">
            <button class="btn btn--outline btn--sm" id="btnSetLock">Imposta lock</button>
            <button class="btn btn--outline btn--sm" id="btnClearLock">Rimuovi lock</button>
          </div>
        </div>
        <div style="margin-top:8px;">
          <button class="btn btn--primary" id="btnPublish">Pubblica torneo</button>
        </div>
      </div>

      <div class="card">
        <h2 class="card-title">Eventi</h2>

        <form id="fEv" class="grid2" onsubmit="return false;">
          <div class="field">
            <label class="label">Casa</label>
            <select id="home_team" class="select light" required>
              <option value="">—</option>
              <?php foreach($teams as $t): ?>
                <option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label class="label">Trasferta</label>
            <select id="away_team" class="select light" required>
              <option value="">—</option>
              <?php foreach($teams as $t): ?>
                <option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field" style="grid-column: span 2;">
            <button class="btn btn--primary" id="btnAddEv">Aggiungi evento</button>
          </div>
        </form>

        <div class="table-wrap" style="margin-top:12px;">
          <table class="table" id="tblEv">
            <thead>
              <tr>
                <th>Codice</th>
                <th>Casa</th>
                <th>Trasferta</th>
                <th>Blocco</th>
                <th>Risultato</th>
                <th>Azioni</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>

      </div>
    </div>
  </section>
</main>
<?php include __DIR__ . '/../../partials/footer.php'; ?>

<script>
const $ = s=>document.querySelector(s);
const tourCode = "<?= htmlspecialchars($tour['tour_code']) ?>";

async function loadEvents(){
  const r = await fetch('?action=list_events', { cache:'no-store',
    headers:{'Cache-Control':'no-cache, no-store, max-age=0','Pragma':'no-cache'}});
  const j = await r.json();
  if(!j.ok){ alert('Errore caricamento'); return; }
  const tb = $('#tblEv tbody'); tb.innerHTML='';
  j.rows.forEach(ev=>{
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${ev.event_code}</td>
      <td>${ev.home_name}</td>
      <td>${ev.away_name}</td>
      <td>
        <button type="button" class="btn btn--outline btn--sm" data-lock="${ev.id}">
          ${ev.is_locked==1 ? 'Sbloccato?' : 'Blocca'}
        </button>
      </td>
      <td>
        <select class="select light" data-res="${ev.id}">
          <option value="UNKNOWN" ${ev.result==='UNKNOWN'?'selected':''}>—</option>
          <option value="HOME" ${ev.result==='HOME'?'selected':''}>Casa vince</option>
          <option value="AWAY" ${ev.result==='AWAY'?'selected':''}>Trasferta vince</option>
          <option value="DRAW" ${ev.result==='DRAW'?'selected':''}>Pareggio</option>
          <option value="VOID" ${ev.result==='VOID'?'selected':''}>Annullata</option>
          <option value="POSTPONED" ${ev.result==='POSTPONED'?'selected':''}>Rinviata</option>
        </select>
        <button type="button" class="btn btn--outline btn--sm" data-save-res="${ev.id}">Applica</button>
      </td>
      <td>
        <button type="button" class="btn btn--outline btn--sm btn-danger" data-del="${ev.id}">Elimina</button>
      </td>
    `;
    tb.appendChild(tr);
  });
}

// add event
$('#btnAddEv').addEventListener('click', async ()=>{
  const home = $('#home_team').value, away = $('#away_team').value;
  if(!home || !away || home===away){ alert('Seleziona squadre valide'); return; }
  const fd = new URLSearchParams({home_team_id:home, away_team_id:away});
  const r = await fetch('?action=add_event', { method:'POST', body: fd });
  const j = await r.json();
  if(!j.ok){ alert('Errore: ' + (j.error||'')); return; }
  $('#fEv').reset();
  await loadEvents();
});

// lock toggle / delete / set result
$('#tblEv').addEventListener('click', async (e)=>{
  const b = e.target.closest('button'); if(!b) return;
  if (b.hasAttribute('data-lock')) {
    const id = b.getAttribute('data-lock');
    const r = await fetch('?action=toggle_lock_event', { method:'POST', body:new URLSearchParams({event_id:id}) });
    const j = await r.json(); if(!j.ok){alert('Errore lock'); return;}
    await loadEvents();
  }
  if (b.hasAttribute('data-save-res')) {
    const id = b.getAttribute('data-save-res');
    const sel = document.querySelector(`select[data-res="${id}"]`);
    const res = sel ? sel.value : 'UNKNOWN';
    const r = await fetch('?action=update_result_event', { method:'POST', body:new URLSearchParams({event_id:id,result:res}) });
    const j = await r.json(); if(!j.ok){alert('Errore risultato'); return;}
    await loadEvents();
  }
  if (b.classList.contains('btn-danger') && b.hasAttribute('data-del')) {
    const id = b.getAttribute('data-del');
    if (!confirm('Eliminare l\'evento?')) return;
    const r = await fetch('?action=delete_event', { method:'POST', body:new URLSearchParams({event_id:id}) });
    const j = await r.json(); if(!j.ok){alert('Errore eliminazione'); return;}
    await loadEvents();
  }
});

// lock datetime
$('#btnSetLock').addEventListener('click', async ()=>{
  const val = $('#lock_at').value.trim();
  const r = await fetch('?action=set_lock', { method:'POST', body:new URLSearchParams({lock_at:val}) });
  const j = await r.json(); if(!j.ok){alert('Errore lock torneo'); return;}
  alert('Lock aggiornato');
});
$('#btnClearLock').addEventListener('click', async ()=>{
  const r = await fetch('?action=set_lock', { method:'POST', body:new URLSearchParams({lock_at:''}) });
  const j = await r.json(); if(!j.ok){alert('Errore lock torneo'); return;}
  $('#lock_at').value = '';
});

// publish
$('#btnPublish').addEventListener('click', async ()=>{
  if(!confirm('Pubblicare il torneo?')) return;
  const r = await fetch('?action=publish', { method:'POST' });
  const j = await r.json(); if(!j.ok){alert('Errore publish'); return;}
  alert('Torneo pubblicato');
  window.location.href = '/admin/crea-tornei.php'; // o /admin/tornei.php se usi questo file come landing
});

loadEvents();
</script>
