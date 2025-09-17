<?php
require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['uid']) || !(($_SESSION['role'] ?? 'USER')==='ADMIN' || (int)($_SESSION['is_admin'] ?? 0)===1)) {
  header('Location: /login.php'); exit;
}

/* Helpers */
function json($a){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($a); exit; }
function only_post(){ if ($_SERVER['REQUEST_METHOD']!=='POST'){ http_response_code(405); json(['ok'=>false,'error'=>'method']); } }
function genCode($len=6){ $n=random_int(0,36**$len-1); $b36=strtoupper(base_convert($n,10,36)); return str_pad($b36,$len,'0',STR_PAD_LEFT); }
function getFreeCode(PDO $pdo,$table,$col='tour_code'){ for($i=0;$i<12;$i++){ $c=genCode(6); $st=$pdo->prepare("SELECT 1 FROM {$table} WHERE {$col}=? LIMIT 1"); $st->execute([$c]); if(!$st->fetch()) return $c; } throw new RuntimeException('code'); }

/* Endpoints AJAX */
if (isset($_GET['action'])) {
  $a=$_GET['action'];

  if ($a==='create') {
    only_post();
    $name = trim($_POST['name'] ?? '');
    $buyin= (float)($_POST['buyin'] ?? 0);
    $seats_inf = (int)($_POST['seats_infinite'] ?? 0);
    $seats_max = $seats_inf ? null : (int)($_POST['seats_max'] ?? 0);
    $lives_max= (int)($_POST['lives_max_user'] ?? 1);
    $gprize   = strlen(trim($_POST['guaranteed_prize'] ?? '')) ? (float)$_POST['guaranteed_prize'] : null;
    $pct2prize= (float)($_POST['buyin_to_prize_pct'] ?? 0);
    $rake_pct = (float)($_POST['rake_pct'] ?? 0);

    if ($name==='' || $buyin<=0 || $lives_max<1) json(['ok'=>false,'error'=>'required']);
    try{
      $code = getFreeCode($pdo,'tournaments','tour_code');
      $st = $pdo->prepare("INSERT INTO tournaments
        (tour_code,name,buyin,seats_max,seats_infinite,lives_max_user,guaranteed_prize,buyin_to_prize_pct,rake_pct)
        VALUES (?,?,?,?,?,?,?,?,?)");
      $st->execute([$code,$name,$buyin,$seats_max,$seats_inf,$lives_max,$gprize,$pct2prize,$rake_pct]);
      json(['ok'=>true,'code'=>$code]);
    }catch(Throwable $e){ json(['ok'=>false,'error'=>'db','detail'=>$e->getMessage()]); }
  }

  if ($a==='list_pending') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0'); header('Pragma: no-cache');
    $st=$pdo->query("SELECT id,tour_code,name,buyin,seats_max,seats_infinite,lives_max_user,guaranteed_prize,buyin_to_prize_pct,rake_pct,created_at
                     FROM tournaments WHERE status='pending' ORDER BY created_at DESC");
    json(['ok'=>true,'rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
  }

  http_response_code(400); json(['ok'=>false,'error'=>'unknown_action']);
}

/* View */
$page_css = '/pages-css/admin-dashboard.css';
include __DIR__ . '/../../partials/head.php';
include __DIR__ . '/../../partials/header_admin.php';
?>
<main>
  <section class="section">
    <div class="container">
      <h1>Crea tornei</h1>

      <!-- link rapidi -->
      <div class="card" style="max-width:640px; margin-bottom:16px;">
        <p class="muted">Seleziona una funzione</p>
        <div style="display:flex; gap:12px; margin-top:12px;">
          <a class="btn btn--primary" href="/admin/teams.php">Gestisci squadre</a>
          <button type="button" class="btn btn--outline" id="btnOpenWizard">Crea torneo</button>
        </div>
      </div>

      <!-- pending -->
      <div class="card" style="margin-top:16px;">
        <h2 class="card-title">Tornei in pending</h2>
        <div class="table-wrap">
          <table class="table" id="tbl">
            <thead>
            <tr>
              <th>Codice</th><th>Nome</th><th>Buy-in</th><th>Posti</th>
              <th>Lives max</th><th>Garantito</th><th>%→prize / Rake%</th><th>Azioni</th>
            </tr>
            </thead><tbody></tbody>
          </table>
        </div>
      </div>

      <!-- wizard modal -->
      <div class="modal" id="wizard" aria-hidden="true">
        <div class="modal-backdrop" data-close></div>
        <div class="modal-card" style="max-width:760px;">
          <div class="modal-head">
            <h3>Nuovo torneo</h3>
            <div class="steps-dots"><span class="dot active" data-dot="1"></span><span class="dot" data-dot="2"></span><span class="dot" data-dot="3"></span></div>
            <button class="modal-x" data-close>&times;</button>
          </div>
          <div class="modal-body scroller">
            <form id="wForm">
              <section class="step active" data-step="1">
                <div class="grid2">
                  <div class="field"><label class="label">Nome torneo *</label><input class="input light" id="w_name" required></div>
                  <div class="field"><label class="label">Costo vita (buy-in) *</label><input class="input light" id="w_buyin" type="number" step="0.01" min="0" required></div>
                </div>
              </section>
              <section class="step" data-step="2">
                <div class="grid2">
                  <div class="field"><label class="label">Posti disponibili</label><input class="input light" id="w_seats_max" type="number" step="1" min="0" placeholder="es. 128"></div>
                  <div class="field">
                    <label class="label">Posti</label>
                    <div class="chip-toggle" id="chipInf"><input id="w_seats_inf" type="checkbox" hidden><span class="chip">Infiniti posti</span></div>
                  </div>
                </div>
              </section>
              <section class="step" data-step="3">
                <div class="grid2">
                  <div class="field"><label class="label">Vite max / utente *</label><input class="input light" id="w_lives_max_user" type="number" step="1" min="1" required value="1"></div>
                  <div class="field"><label class="label">Montepremi garantito (opz.)</label><input class="input light" id="w_guaranteed_prize" type="number" step="0.01" min="0"></div>
                  <div class="field"><label class="label">% buy-in → montepremi</label><input class="input light" id="w_buyin_to_prize_pct" type="number" step="0.01" min="0" max="100"></div>
                  <div class="field"><label class="label">% rake sito</label><input class="input light" id="w_rake_pct" type="number" step="0.01" min="0" max="100"></div>
                </div>
              </section>
            </form>
          </div>
          <div class="modal-foot">
            <button type="button" class="btn btn--outline" id="wPrev">Indietro</button>
            <button type="button" class="btn btn--primary" id="wNext">Avanti</button>
            <button type="button" class="btn btn--primary hidden" id="wCreate">Crea torneo</button>
          </div>
        </div>
      </div>
    </div>
  </section>
</main>
<?php include __DIR__ . '/../../partials/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const $=s=>document.querySelector(s);

  async function loadPending(){
    const r = await fetch('?action=list_pending', { cache:'no-store',
      headers:{'Cache-Control':'no-cache, no-store, max-age=0','Pragma':'no-cache'}});
    const j = await r.json();
    if(!j.ok){ alert('Errore caricamento'); return; }
    const tb = document.querySelector('#tbl tbody'); tb.innerHTML='';
    j.rows.forEach(row=>{
      const tr=document.createElement('tr');
      const seats = row.seats_infinite==1 ? '∞' : (row.seats_max ?? '-');
      const g = row.guaranteed_prize ? Number(row.guaranteed_prize).toFixed(2) : '-';
      tr.innerHTML = `
        <td>${row.tour_code}</td>
        <td>${row.name}</td>
        <td>${Number(row.buyin).toFixed(2)}</td>
        <td>${seats}</td>
        <td>${row.lives_max_user}</td>
        <td>${g}</td>
        <td>${Number(row.buyin_to_prize_pct).toFixed(2)} / ${Number(row.rake_pct).toFixed(2)}</td>
        <td><a class="btn btn--outline btn--sm" href="/admin/torneo_manage.php?code=${row.tour_code}">Apri</a></td>
      `;
      tb.appendChild(tr);
    });
  }

  // wizard
  const modal = $('#wizard');
  const steps = ()=>[...document.querySelectorAll('#wizard .step')];
  const dots  = ()=>[...document.querySelectorAll('#wizard .steps-dots .dot')];
  let idx=0;

  function openWizard(){ modal.setAttribute('aria-hidden','false'); document.body.classList.add('modal-open'); setStep(0); }
  function closeWizard(){ modal.setAttribute('aria-hidden','true'); document.body.classList.remove('modal-open'); }
  function setStep(i){
    idx=Math.max(0,Math.min(i,steps().length-1));
    steps().forEach((s,k)=>s.classList.toggle('active',k===idx));
    dots().forEach((d,k)=>d.classList.toggle('active',k<=idx));
    $('#wPrev').classList.toggle('hidden',idx===0);
    $('#wNext').classList.toggle('hidden',idx===steps().length-1);
    $('#wCreate').classList.toggle('hidden',idx!==steps().length-1);
    document.querySelector('#wizard .modal-body.scroller').scrollTop=0;
  }

  document.querySelectorAll('[data-close]').forEach(b=>b.addEventListener('click', closeWizard));
  $('#btnOpenWizard').addEventListener('click', openWizard);
  $('#wPrev').addEventListener('click', ()=>setStep(idx-1));
  $('#wNext').addEventListener('click', ()=>{
    const cur=steps()[idx]; const invalid=cur.querySelector(':invalid');
    if(invalid){ invalid.reportValidity(); return; }
    setStep(idx+1);
  });

  // chip toggle "infiniti posti"
  document.querySelector('#chipInf .chip').addEventListener('click', ()=>{
    const cb = document.querySelector('#w_seats_inf');
    cb.checked = !cb.checked;
    document.querySelector('#chipInf .chip').classList.toggle('active', cb.checked);
  });

  $('#wCreate').addEventListener('click', async ()=>{
    try{
      if (!$('#w_name').value.trim() || !$('#w_buyin').value.trim() || !$('#w_lives_max_user').value.trim()){
        alert('Compila i campi obbligatori'); return;
      }
      const fd=new URLSearchParams();
      fd.append('name',$('#w_name').value.trim());
      fd.append('buyin',$('#w_buyin').value.trim());
      fd.append('seats_infinite',$('#w_seats_inf').checked ? '1':'0');
      const sm=$('#w_seats_max').value.trim(); if(!$('#w_seats_inf').checked && sm) fd.append('seats_max',sm);
      fd.append('lives_max_user',$('#w_lives_max_user').value.trim());
      const gp=$('#w_guaranteed_prize').value.trim(); if(gp) fd.append('guaranteed_prize',gp);
      const p=$('#w_buyin_to_prize_pct').value.trim(); if(p) fd.append('buyin_to_prize_pct',p);
      const rk=$('#w_rake_pct').value.trim(); if(rk) fd.append('rake_pct',rk);

      const b=$('#wCreate'); b.disabled=true;
      const r=await fetch('?action=create',{method:'POST',body:fd});
      const j=await r.json();
      b.disabled=false;

      if(!j.ok){ alert('Errore: ' + (j.error||'')); return; }
      closeWizard(); await loadPending(); alert('Torneo creato. Codice: '+j.code);
    }catch(err){ alert('Errore imprevisto'); console.error(err); }
  });

  loadPending();
});
</script>

<style>
/* chip toggle */
.chip-toggle{display:inline-block;cursor:pointer}
.chip-toggle .chip{display:inline-block;padding:6px 12px;border-radius:9999px;border:1px solid var(--c-border);background:transparent;color:var(--c-muted);font-size:14px;transition:.2s}
.chip-toggle input:checked + .chip, .chip-toggle .chip.active{border-color:#27ae60;color:#a7e3bf;background:rgba(39,174,96,.15)}
</style>
