<?php
require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['uid']) || !(($_SESSION['role'] ?? 'USER')==='ADMIN' || (int)($_SESSION['is_admin'] ?? 0)===1)) {
  header('Location: /login.php'); exit;
}

/* ===== Helpers JSON/POST + generatori codici ===== */
function json($a){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($a); exit; }
function only_post(){ if ($_SERVER['REQUEST_METHOD']!=='POST'){ http_response_code(405); json(['ok'=>false,'error'=>'method']); } }
function genCode($len=6){
  $max = 36**$len - 1;
  $n = random_int(0, $max);
  $b36 = strtoupper(base_convert($n,10,36));
  return str_pad($b36, $len, '0', STR_PAD_LEFT);
}
function getFreeCode(PDO $pdo, $table, $col='tour_code'){
  for($i=0;$i<10;$i++){
    $c = genCode(6);
    $st = $pdo->prepare("SELECT 1 FROM {$table} WHERE {$col}=? LIMIT 1");
    $st->execute([$c]);
    if (!$st->fetch()) return $c;
  }
  throw new RuntimeException('Codice univoco non disponibile');
}

/* ===== Endpoints AJAX interni a questa pagina ===== */
if (isset($_GET['action'])) {
  $a = $_GET['action'];

  // CREATE tournament
  if ($a==='create') {
    only_post();
    $name = trim($_POST['name'] ?? '');
    $buyin = (float)($_POST['buyin'] ?? 0);
    $seats_infinite = (int)($_POST['seats_infinite'] ?? 0);
    $seats_max = $seats_infinite ? null : (int)($_POST['seats_max'] ?? 0);
    $lives_max_user = (int)($_POST['lives_max_user'] ?? 1);
    $guaranteed_prize = strlen(trim($_POST['guaranteed_prize'] ?? '')) ? (float)$_POST['guaranteed_prize'] : null;
    $buyin_to_prize_pct = (float)($_POST['buyin_to_prize_pct'] ?? 0);
    $rake_pct = (float)($_POST['rake_pct'] ?? 0);

    if ($name==='' || $buyin<=0 || $lives_max_user<1) {
      json(['ok'=>false,'error'=>'required']);
    }

    try{
      $code = getFreeCode($pdo, 'tournaments', 'tour_code');
      $st = $pdo->prepare("INSERT INTO tournaments
        (tour_code,name,buyin,seats_max,seats_infinite,lives_max_user,guaranteed_prize,buyin_to_prize_pct,rake_pct)
        VALUES (?,?,?,?,?,?,?,?,?)");
      $st->execute([$code,$name,$buyin,$seats_max,$seats_infinite,$lives_max_user,$guaranteed_prize,$buyin_to_prize_pct,$rake_pct]);
      json(['ok'=>true,'code'=>$code]);
    }catch(Throwable $e){
      json(['ok'=>false,'error'=>'db','detail'=>$e->getMessage()]);
    }
  }

  // LIST pending tournaments
  if ($a==='list_pending') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    $st = $pdo->query("SELECT id,tour_code,name,buyin,seats_max,seats_infinite,lives_max_user,guaranteed_prize,buyin_to_prize_pct,rake_pct,created_at
                       FROM tournaments WHERE status='pending' ORDER BY created_at DESC");
    json(['ok'=>true,'rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
  }

  http_response_code(400); json(['ok'=>false,'error'=>'unknown_action']);
}

/* ===== View ===== */
$page_css = '/pages-css/admin-dashboard.css';
include __DIR__ . '/../../partials/head.php';
include __DIR__ . '/../../partials/header_admin.php';
?>
<main>
  <section class="section">
    <div class="container">
      <h1>Crea tornei</h1>

      <div class="card" style="max-width:860px;">
        <h2 class="card-title">Nuovo torneo</h2>
        <form id="fNew" class="grid2" onsubmit="return false;">
          <div class="field">
            <label class="label">Nome torneo *</label>
            <input class="input light" id="name" required>
          </div>
          <div class="field">
            <label class="label">Costo vita (buy-in) *</label>
            <input class="input light" id="buyin" type="number" step="0.01" min="0" required>
          </div>

          <div class="field">
            <label class="label">Posti disponibili</label>
            <input class="input light" id="seats_max" type="number" step="1" min="0" placeholder="es. 128">
          </div>
          <div class="field" style="display:flex;align-items:center;gap:8px;">
            <input id="seats_inf" type="checkbox">
            <label for="seats_inf" class="label">Infiniti posti</label>
          </div>

          <div class="field">
            <label class="label">Vite max acquistabili/utente *</label>
            <input class="input light" id="lives_max_user" type="number" step="1" min="1" required value="1">
          </div>
          <div class="field">
            <label class="label">Montepremi garantito (opz.)</label>
            <input class="input light" id="guaranteed_prize" type="number" step="0.01" min="0" placeholder="es. 1000.00">
          </div>

          <div class="field">
            <label class="label">% buy-in → montepremi</label>
            <input class="input light" id="buyin_to_prize_pct" type="number" step="0.01" min="0" max="100" placeholder="es. 90.00">
          </div>
          <div class="field">
            <label class="label">% rake sito</label>
            <input class="input light" id="rake_pct" type="number" step="0.01" min="0" max="100" placeholder="es. 10.00">
          </div>

          <div class="field" style="grid-column: span 2;">
            <button class="btn btn--primary" id="btnCreate">Crea torneo</button>
          </div>
        </form>
      </div>

      <div class="card" style="margin-top:16px;">
        <h2 class="card-title">Tornei in pending</h2>
        <div class="table-wrap">
          <table class="table" id="tbl">
            <thead>
              <tr>
                <th>Codice</th>
                <th>Nome</th>
                <th>Buy-in</th>
                <th>Posti</th>
                <th>Lives max</th>
                <th>Garantito</th>
                <th>%→prize / Rake%</th>
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
const $=s=>document.querySelector(s);

function seatsLabel(row){
  return row.seats_infinite==1 ? '∞' : (row.seats_max ?? '-');
}

async function loadPending(){
  const r = await fetch('?action=list_pending', { cache:'no-store',
    headers:{'Cache-Control':'no-cache, no-store, max-age=0','Pragma':'no-cache'} });
  const j = await r.json();
  if(!j.ok){ alert('Errore caricamento'); return; }
  const tb = document.querySelector('#tbl tbody');
  tb.innerHTML = '';
  j.rows.forEach(row=>{
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${row.tour_code}</td>
      <td>${row.name}</td>
      <td>${Number(row.buyin).toFixed(2)}</td>
      <td>${seatsLabel(row)}</td>
      <td>${row.lives_max_user}</td>
      <td>${row.guaranteed_prize ? Number(row.guaranteed_prize).toFixed(2) : '-'}</td>
      <td>${Number(row.buyin_to_prize_pct).toFixed(2)} / ${Number(row.rake_pct).toFixed(2)}</td>
      <td>
        <a class="btn btn--outline btn--sm" href="/admin/torneo_manage.php?code=${row.tour_code}">Apri</a>
      </td>
    `;
    tb.appendChild(tr);
  });
}

$('#btnCreate').addEventListener('click', async ()=>{
  const name = $('#name').value.trim();
  const buyin = $('#buyin').value.trim();
  const seats_infinite = $('#seats_inf').checked ? 1 : 0;
  const seats_max = $('#seats_max').value.trim();
  const lives_max_user = $('#lives_max_user').value.trim();
  const guaranteed_prize = $('#guaranteed_prize').value.trim();
  const buyin_to_prize_pct = $('#buyin_to_prize_pct').value.trim();
  const rake_pct = $('#rake_pct').value.trim();

  if(!name || !buyin || !lives_max_user){ alert('Compila i campi obbligatori'); return; }
  const fd = new URLSearchParams();
  fd.append('name', name);
  fd.append('buyin', buyin);
  fd.append('seats_infinite', seats_infinite);
  if(!seats_infinite && seats_max) fd.append('seats_max', seats_max);
  fd.append('lives_max_user', lives_max_user);
  if(guaranteed_prize) fd.append('guaranteed_prize', guaranteed_prize);
  if(buyin_to_prize_pct) fd.append('buyin_to_prize_pct', buyin_to_prize_pct);
  if(rake_pct) fd.append('rake_pct', rake_pct);

  const r = await fetch('?action=create', { method:'POST', body: fd });
  const j = await r.json();
  if(!j.ok){ alert('Errore: ' + (j.error||'')); return; }

  // reset form e ricarica lista
  document.getElementById('fNew').reset();
  $('#seats_inf').checked = false;
  await loadPending();
  alert('Torneo creato. Codice: ' + j.code);
});

loadPending();
</script>
