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

/* ====================== AJAX ======================= */
if (isset($_GET['action'])) {
  $a = $_GET['action'];

  // Suggerimenti squadre (per datalist)
  if ($a==='teams_suggest') {
    $q = trim($_GET['q'] ?? '');
    $lim = 20;
    if ($q==='') {
      $st = $pdo->query("SELECT id,name FROM teams ORDER BY name ASC LIMIT $lim");
      json(['ok'=>true,'rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
    } else {
      $st = $pdo->prepare("SELECT id,name FROM teams WHERE name LIKE ? ORDER BY name ASC LIMIT $lim");
      $st->execute(['%'.$q.'%']);
      json(['ok'=>true,'rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
    }
  }

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

  // ADD evento (accetta id oppure name e lo risolve)
  if ($a==='add_event') {
    only_post();
    $homeId = (int)($_POST['home_team_id'] ?? 0);
    $awayId = (int)($_POST['away_team_id'] ?? 0);
    $homeName = trim($_POST['home_team_name'] ?? '');
    $awayName = trim($_POST['away_team_name'] ?? '');

    // se non arrivan gli id, risolvo dai nomi (match esatto o primo like)
    if ($homeId<=0 && $homeName!=='') {
      $s = $pdo->prepare("SELECT id FROM teams WHERE name=? LIMIT 1"); $s->execute([$homeName]); $homeId = (int)$s->fetchColumn();
      if ($homeId<=0) { $s=$pdo->prepare("SELECT id FROM teams WHERE name LIKE ? ORDER BY name ASC LIMIT 1"); $s->execute(['%'.$homeName.'%']); $homeId=(int)$s->fetchColumn(); }
    }
    if ($awayId<=0 && $awayName!=='') {
      $s = $pdo->prepare("SELECT id FROM teams WHERE name=? LIMIT 1"); $s->execute([$awayName]); $awayId = (int)$s->fetchColumn();
      if ($awayId<=0) { $s=$pdo->prepare("SELECT id FROM teams WHERE name LIKE ? ORDER BY name ASC LIMIT 1"); $s->execute(['%'.$awayName.'%']); $awayId=(int)$s->fetchColumn(); }
    }

    if ($homeId<=0 || $awayId<=0 || $homeId===$awayId) json(['ok'=>false,'error'=>'teams_invalid']);

    try{
      $evcode = getFreeCode($pdo,'tournament_events','event_code');
      $st = $pdo->prepare("INSERT INTO tournament_events(event_code,tournament_id,home_team_id,away_team_id) VALUES (?,?,?,?)");
      $st->execute([$evcode,$tour['id'],$homeId,$awayId]);
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

  // SET/CLEAR global lock
  if ($a==='set_lock') {
    only_post();
    $lock_at = trim($_POST['lock_at'] ?? '');
    $value = $lock_at!=='' ? $lock_at : null;
    $st = $pdo->prepare("UPDATE tournaments SET lock_at=? WHERE id=?");
    $st->execute([$value,$tour['id']]);
    json(['ok'=>true,'lock_at'=>$value]);
  }

  // DELETE tournament
  if ($a==='delete_tournament') {
    only_post();
    try{
      $st = $pdo->prepare("DELETE FROM tournaments WHERE id=? LIMIT 1");
      $st->execute([$tour['id']]); // ON DELETE CASCADE sugli eventi
      json(['ok'=>true]);
    }catch(Throwable $e){ json(['ok'=>false,'error'=>'db','detail'=>$e->getMessage()]); }
  }

  http_response_code(400); json(['ok'=>false,'error'=>'unknown_action']);
}
/* ================== /AJAX ================== */

$page_css = '/pages-css/admin-dashboard.css';
include __DIR__ . '/../../partials/head.php';
include __DIR__ . '/../../partials/header_admin.php';

/* Teams per primo popolamento datalist (fallback) */
$teamsInit = $pdo->query("SELECT id,name FROM teams ORDER BY name ASC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
?>
<main>
  <section class="section">
    <div class="container">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
        <h1>Torneo: <?= htmlspecialchars($tour['name']) ?> <span class="muted">(<?= htmlspecialchars($tour['tour_code']) ?>)</span></h1>
        <button class="btn btn--outline btn--sm btn-danger" id="btnDeleteTour">Elimina torneo</button>
      </div>

      <div class="card" style="margin-bottom:16px;">
        <h2 class="card-title">Impostazioni</h2>
        <div class="grid2" style="align-items:end;">
          <div class="field">
            <label class="label">Lock scelte (data/ora)</label>
            <input class="input light" id="lock_at" type="datetime-local" value="<?= $tour['lock_at'] ? date('Y-m-d\TH:i', strtotime($tour['lock_at'])) : '' ?>">
          </div>
          <div class="field">
            <label class="label">&nbsp;</label>
            <?php $hasLock = !empty($tour['lock_at']); ?>
            <button class="btn btn--outline" id="btnToggleLock"><?= $hasLock ? 'Rimuovi lock' : 'Imposta lock' ?></button>
          </div>
        </div>
        <div style="margin-top:8px;">
          <button class="btn btn--primary" id="btnPublish">Pubblica torneo</button>
        </div>
      </div>

      <div class="card">
        <h2 class="card-title">Eventi</h2>

        <!-- Ricerca con datalist + campo testo -->
        <form id="fEv" class="grid2" onsubmit="return false;">
          <div class="field">
            <label class="label">Casa (scrivi e scegli)</label>
            <input class="input light" id="home_name" list="teamsListHome" placeholder="digita...">
            <datalist id="teamsListHome">
              <?php foreach($teamsInit as $t): ?>
                <option value="<?= htmlspecialchars($t['name']) ?>" data-id="<?= (int)$t['id'] ?>"></option>
              <?php endforeach; ?>
            </datalist>
            <input type="hidden" id="home_team_id">
          </div>

          <div class="field">
            <label class="label">Trasferta (scrivi e scegli)</label>
            <input class="input light" id="away_name" list="teamsListAway" placeholder="digita...">
            <datalist id="teamsListAway">
              <?php foreach($teamsInit as $t): ?>
                <option value="<?= htmlspecialchars($t['name']) ?>" data-id="<?= (int)$t['id'] ?>"></option>
              <?php endforeach; ?>
            </datalist>
            <input type="hidden" id="away_team_id">
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

/* ====== EVENTI ====== */
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
          ${ev.is_locked==1 ? 'Sblocca' : 'Blocca'}
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

/* ====== DATALIST SUGGEST ====== */
function bindDatalist(inputId, listId, hiddenId){
  const input = $(inputId), list = $(listId), hidden = $(hiddenId);

  input.addEventListener('input', async ()=>{
    hidden.value = ''; // reset id finché non selezioni
    const q = input.value.trim();
    if (q.length < 2) return;
    const r = await fetch('?action=teams_suggest&q='+encodeURIComponent(q), {cache:'no-store'});
    const j = await r.json(); if(!j.ok) return;
    list.innerHTML='';
    j.rows.forEach(t=>{
      const opt = document.createElement('option');
      opt.value = t.name;
      opt.dataset.id = t.id;
      list.appendChild(opt);
    });
  });

  // quando l'utente conferma un valore, se coincide con un option -> prendo l'id
  input.addEventListener('change', ()=>{
    const val = input.value.trim();
    const opt = [...list.options].find(o=>o.value===val);
    hidden.value = opt ? opt.dataset.id : '';
  });
}

bindDatalist('#home_name','#teamsListHome','#home_team_id');
bindDatalist('#away_name','#teamsListAway','#away_team_id');

/* ====== ADD EVENT ====== */
$('#btnAddEv').addEventListener('click', async ()=>{
  const homeId = $('#home_team_id').value.trim();
  const awayId = $('#away_team_id').value.trim();
  const homeName = $('#home_name').value.trim();
  const awayName = $('#away_name').value.trim();

  if ((!homeId && homeName==='') || (!awayId && awayName==='')) {
    alert('Seleziona o digita i nomi delle squadre'); return;
  }
  const fd = new URLSearchParams();
  if (homeId) fd.append('home_team_id', homeId); else fd.append('home_team_name', homeName);
  if (awayId) fd.append('away_team_id', awayId); else fd.append('away_team_name', awayName);

  const r = await fetch('?action=add_event', { method:'POST', body: fd });
  const j = await r.json();
  if(!j.ok){ alert('Errore: ' + (j.error||'')); return; }

  // reset form
  $('#fEv').reset();
  $('#home_team_id').value='';
  $('#away_team_id').value='';
  await loadEvents();
});

/* ====== lock/result/delete evento ====== */
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

/* ====== LOCK torneo: bottone unico ====== */
const btnToggleLock = $('#btnToggleLock');
btnToggleLock.addEventListener('click', async ()=>{
  const input = $('#lock_at');
  const val = input.value.trim();
  // se c'è una data -> imposto; se vuota -> rimuovo
  const r = await fetch('?action=set_lock', { method:'POST', body:new URLSearchParams({lock_at:val}) });
  const j = await r.json();
  if(!j.ok){ alert('Errore lock torneo'); return; }
  // aggiorna label bottone
  btnToggleLock.textContent = (val==='') ? 'Imposta lock' : 'Rimuovi lock';
  alert('Lock aggiornato');
});

/* ====== DELETE torneo ====== */
$('#btnDeleteTour').addEventListener('click', async ()=>{
  if (!confirm('Eliminare definitivamente il torneo e tutti i suoi eventi?')) return;
  const r = await fetch('?action=delete_tournament', { method:'POST' });
  const j = await r.json();
  if (!j.ok){ alert('Errore eliminazione torneo'); return; }
  window.location.href = '/admin/crea-tornei.php';
});

/* ====== PUBLISH ====== */
$('#btnPublish').addEventListener('click', async ()=>{
  if(!confirm('Pubblicare il torneo?')) return;
  const r = await fetch('?action=publish', { method:'POST' });
  const j = await r.json(); if(!j.ok){alert('Errore publish'); return;}
  alert('Torneo pubblicato');
  window.location.href = '/admin/crea-tornei.php';
});

/* init */
loadEvents();
</script>
