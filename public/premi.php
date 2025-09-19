<?php
// collegati alla connessione DB e avvio sessione
require_once __DIR__ . '/../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/partials/csrf.php';
$CSRF = htmlspecialchars(csrf_token(), ENT_QUOTES);

if (empty($_SESSION['uid']) || (($_SESSION['role'] ?? 'USER')!=='USER' && ($_SESSION['role'] ?? '')!=='PUNTO')) {
  header('Location: /login.php'); exit;
}

function json($a){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($a); exit; }
function only_get(){ if (($_SERVER['REQUEST_METHOD'] ?? '')!=='GET'){ http_response_code(405); json(['ok'=>false,'error'=>'method']); } }

if (isset($_GET['action'])){
  $a = $_GET['action'];

  if ($a==='me'){ only_get();
    $uid=(int)$_SESSION['uid'];
    $st=$pdo->prepare("SELECT username, COALESCE(coins,0) coins FROM users WHERE id=?");
    $st->execute([$uid]); $me=$st->fetch(PDO::FETCH_ASSOC) ?: ['username'=>'','coins'=>0];
    json(['ok'=>true,'me'=>$me]);
  }

  if ($a==='list_prizes'){ only_get();
    $search = trim($_GET['search'] ?? '');
    $sort   = $_GET['sort'] ?? 'created';
    $dir    = strtolower($_GET['dir'] ?? 'desc')==='asc'?'ASC':'DESC';
    $order  = $sort==='name' ? "p.name $dir" : ($sort==='coins' ? "p.amount_coins $dir" : "p.created_at $dir");
    $w = ['p.is_listed=1']; $p=[];
    if ($search!==''){ $w[]='p.name LIKE ?'; $p[]="%$search%"; }
    $where='WHERE '.implode(' AND ',$w);
    $sql="SELECT p.id,p.prize_code,p.name,p.description,p.amount_coins,p.is_enabled,p.created_at,
                 m.storage_key AS image_key
          FROM prizes p
          LEFT JOIN media m ON m.id=p.image_media_id
          $where
          ORDER BY $order";
    $st=$pdo->prepare($sql); $st->execute($p);
    json(['ok'=>true,'rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
  }

  http_response_code(400); json(['ok'=>false,'error'=>'unknown_action']);
}

$page_css='/pages-css/admin-dashboard.css';
include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/header_utente.php';
// CDN per immagini
$CDN_BASE = rtrim(getenv('CDN_BASE') ?: getenv('S3_CDN_BASE') ?: '', '/');
?>
<style>
  .pr-page .card{ margin-bottom:16px; }
  .topbar{ display:flex; justify-content:space-between; gap:10px; align-items:center; margin-bottom:12px; }
  .searchbox{ min-width:260px; }
  .table th.sortable{ cursor:pointer; user-select:none; }
  .table th.sortable .arrow{ opacity:.5; font-size:10px; }
  .img-thumb{ width:56px; height:56px; object-fit:cover; border-radius:8px; border:1px solid var(--c-border); }
  .muted-sm{ color:#aaa; font-size:12px; }
  .modal[aria-hidden="true"]{ display:none; } .modal{ position:fixed; inset:0; z-index:60; }
  .modal-open{ overflow:hidden; }
  .modal-backdrop{ position:absolute; inset:0; background:rgba(0,0,0,.5); }
  .modal-card{ position:relative; z-index:61; width:min(760px,96vw); background:var(--c-bg); border:1px solid var(--c-border); border-radius:16px; margin:6vh auto 0; padding:0; box-shadow:0 16px 48px rgba(0,0,0,.5); max-height:86vh; display:flex; flex-direction:column; }
  .modal-head{ display:flex; align-items:center; gap:10px; padding:12px 16px; border-bottom:1px solid var(--c-border); }
  .modal-x{ margin-left:auto; background:transparent; border:0; color:#fff; font-size:24px; cursor:pointer; }
  .modal-body{ padding:16px; overflow:auto; }
  .modal-foot{ display:flex; justify-content:flex-end; gap:8px; padding:12px 16px; border-top:1px solid var(--c-border); }
  .grid2{ display:grid; grid-template-columns:1fr 1fr; gap:16px; } @media (max-width:860px){ .grid2{ grid-template-columns:1fr; } }
</style>

<main class="pr-page">
  <section class="section">
    <div class="container">
      <h1>Premi</h1>

      <div class="card">
        <div class="topbar">
          <div style="display:flex; gap:16px; align-items:center;">
            <div>Saldo: <strong id="meCoins">0.00</strong> <span class="muted">AC</span></div>
          </div>
          <div style="display:flex; gap:8px; align-items:center;">
            <input type="search" class="input light searchbox" id="qPrize" placeholder="Cerca premio…">
          </div>
        </div>
        <div class="table-wrap">
          <table class="table" id="tblPrizes">
            <thead>
              <tr>
                <th class="sortable" data-sort="created">Creato il <span class="arrow">↕</span></th>
                <th>Foto</th>
                <th class="sortable" data-sort="name">Nome <span class="arrow">↕</span></th>
                <th>Codice</th>
                <th class="sortable" data-sort="coins">Arena Coins <span class="arrow">↕</span></th>
                <th>Stato</th>
                <th>Azione</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      </div>

      <!-- Wizard richiesta premio -->
      <div class="modal" id="mdReq" aria-hidden="true">
        <div class="modal-backdrop" data-close></div>
        <div class="modal-card">
          <div class="modal-head">
            <h3>Richiedi premio</h3>
            <button class="modal-x" data-close>&times;</button>
          </div>
          <div class="modal-body">
            <form id="fReq" novalidate>
              <input type="hidden" id="r_prize_id"><input type="hidden" id="r_prize_name"><input type="hidden" id="r_prize_coins">
              <section class="step active" data-step="1">
                <div class="grid2">
                  <div class="field"><label class="label">Stato *</label><input class="input light" id="ship_stato" required></div>
                  <div class="field"><label class="label">Città *</label><input class="input light" id="ship_citta" required></div>
                  <div class="field"><label class="label">Comune *</label><input class="input light" id="ship_comune" required></div>
                  <div class="field"><label class="label">Provincia *</label><input class="input light" id="ship_provincia" required></div>
                  <div class="field" style="grid-column:span 2;"><label class="label">Via *</label><input class="input light" id="ship_via" required></div>
                  <div class="field"><label class="label">Civico *</label><input class="input light" id="ship_civico" required></div>
                  <div class="field"><label class="label">CAP *</label><input class="input light" id="ship_cap" required></div>
                </div>
              </section>
              <section class="step" data-step="2">
                <div class="card" style="padding:12px;">
                  <div><strong>Premio:</strong> <span id="rv_name"></span></div>
                  <div><strong>Costo:</strong> <span id="rv_coins"></span> <span class="muted">AC</span></div>
                  <hr style="border-color:var(--c-border)">
                  <div><strong>Spedizione:</strong></div>
                  <div id="rv_addr" class="muted-sm"></div>
                </div>
              </section>
            </form>
          </div>
          <div class="modal-foot">
            <button class="btn btn--outline" id="r_prev">Indietro</button>
            <div style="display:flex; gap:8px;">
              <button class="btn btn--outline" data-close>Annulla</button>
              <button class="btn btn--primary" id="r_next">Avanti</button>
              <button class="btn btn--primary hidden" id="r_send">Richiedi</button>
            </div>
          </div>
        </div>
      </div>

      <!-- Dialog OK -->
      <div class="modal" id="mdOk" aria-hidden="true">
        <div class="modal-backdrop" data-close></div>
        <div class="modal-card" style="max-width:560px;">
          <div class="modal-head">
            <h3>Premio richiesto!</h3>
            <button class="modal-x" data-close>&times;</button>
          </div>
          <div class="modal-body">
            <p>Riceverai aggiornamenti nella sezione <strong>Messaggi</strong> del tuo account.</p>
          </div>
          <div class="modal-foot">
            <button class="btn btn--primary" data-close>Chiudi</button>
          </div>
        </div>
      </div>

    </div>
  </section>
</main>
<?php
include __DIR__ . '/../partials/footer.php';
?>
<script>
document.addEventListener('DOMContentLoaded', ()=>{
  const $=s=>document.querySelector(s); const $$=(s,p=document)=>[...p.querySelectorAll(s)];
  const CDN_BASE = <?= json_encode($CDN_BASE) ?>;

  let meCoins = 0.00, sort='created', dir='desc', search='';

  function openM(id){ $(id).setAttribute('aria-hidden','false'); document.body.classList.add('modal-open'); }
  function closeM(id){ $(id).setAttribute('aria-hidden','true'); document.body.classList.remove('modal-open'); }

  async function loadMe(){
    const r=await fetch('?action=me',{cache:'no-store'}); const j=await r.json();
    if (j.ok && j.me){ meCoins = Number(j.me.coins||0); $('#meCoins').textContent = meCoins.toFixed(2); }
  }

  async function loadPrizes(){
    const u=new URL('?action=list_prizes', location.href); u.searchParams.set('sort',sort); u.searchParams.set('dir',dir);
    if (search) u.searchParams.set('search',search);
    const r=await fetch(u); const j=await r.json();
    const tb=$('#tblPrizes tbody'); tb.innerHTML='';
    if (!j.ok){ tb.innerHTML='<tr><td colspan="7">Errore</td></tr>'; return; }
    (j.rows||[]).forEach(row=>{
      const can = (row.is_enabled==1) && (meCoins >= Number(row.amount_coins||0));
      const disabled = can ? '' : 'disabled';
      const hint = row.is_enabled!=1 ? 'Non richiedibile' : (meCoins<row.amount_coins ? 'Arena Coins insufficienti' : '');
      const img = row.image_key ? `<img class="img-thumb" src="${CDN_BASE ? (CDN_BASE+'/'+row.image_key) : ''}" alt="">` : '<div class="img-thumb" style="background:#222;"></div>';
      const tr=document.createElement('tr');
      tr.innerHTML = `
        <td>${new Date(row.created_at).toLocaleString()}</td>
        <td>${img}</td>
        <td>${row.name}</td>
        <td><code>${row.prize_code}</code></td>
        <td>${Number(row.amount_coins).toFixed(2)}</td>
        <td>${row.is_enabled==1?'Abilitato':'Disabilitato'}</td>
        <td><button class="btn btn--primary btn--sm" data-req="${row.id}" data-name="${row.name}" data-coins="${row.amount_coins}" ${disabled} title="${hint}">Richiedi</button></td>
      `;
      tb.appendChild(tr);
    });
  }

  // sort + search
  $('#tblPrizes thead').addEventListener('click', (e)=>{
    const th=e.target.closest('[data-sort]'); if(!th) return; const s=th.getAttribute('data-sort');
    if (sort===s) dir=(dir==='asc'?'desc':'asc'); else{ sort=s; dir='asc'; } loadPrizes();
  });
  $('#qPrize').addEventListener('input', e=>{ search=e.target.value.trim(); loadPrizes(); });

  // open wizard
  $('#tblPrizes').addEventListener('click', (e)=>{
    const b=e.target.closest('button[data-req]'); if(!b) return;
    const id=b.getAttribute('data-req'); const nm=b.getAttribute('data-name'); const ac=b.getAttribute('data-coins');
    $('#r_prize_id').value=id; $('#r_prize_name').value=nm; $('#r_prize_coins').value=ac;
    $$('.step').forEach((s,i)=>s.classList.toggle('active', i===0));
    $('#r_next').classList.remove('hidden'); $('#r_send').classList.add('hidden');
    openM('#mdReq');
  });

  // wizard nav
  $('#r_prev').addEventListener('click', ()=>{
    const s=$$('.step'); s[1].classList.remove('active'); s[0].classList.add('active');
    $('#r_next').classList.remove('hidden'); $('#r_send').classList.add('hidden');
  });
  $('#r_next').addEventListener('click', ()=>{
    const need=['ship_stato','ship_citta','ship_comune','ship_provincia','ship_via','ship_civico','ship_cap'];
    for (const id of need){ const el=$('#'+id); if (!el.value.trim()){ el.reportValidity?.(); return; } }
    $('#rv_name').textContent = $('#r_prize_name').value;
    $('#rv_coins').textContent = Number($('#r_prize_coins').value||0).toFixed(2);
    const rv = `${$('#ship_via').value} ${$('#ship_civico').value}<br>${$('#ship_cap').value} ${$('#ship_citta').value} (${ $('#ship_provincia').value })<br>${$('#ship_comune').value} — ${$('#ship_stato').value}`;
    $('#rv_addr').innerHTML = rv;
    const s=$$('.step'); s[0].classList.remove('active'); s[1].classList.add('active');
    $('#r_next').classList.add('hidden'); $('#r_send').classList.remove('hidden');
  });

  // send request (scala subito i coins)
  $('#r_send').addEventListener('click', async ()=>{
    const data = new URLSearchParams({
      prize_id: $('#r_prize_id').value,
      ship_stato: $('#ship_stato').value.trim(),
      ship_citta: $('#ship_citta').value.trim(),
      ship_comune: $('#ship_comune').value.trim(),
      ship_provincia: $('#ship_provincia').value.trim(),
      ship_via: $('#ship_via').value.trim(),
      ship_civico: $('#ship_civico').value.trim(),
      ship_cap: $('#ship_cap').value.trim()
    });
    // 🔒 CSRF
data.set('csrf_token','<?= $CSRF ?>');

const r = await fetch('/api/prize_request.php?action=request',{
  method:'POST',
  body:data,
  credentials:'same-origin',
  headers:{ 'Accept':'application/json', 'X-CSRF-Token':'<?= $CSRF ?>' }
});
    const j = await r.json();
    if (!j.ok){
      let msg = 'Errore';
      if (j.error==='insufficient_coins') msg='Arena Coins insufficienti';
      else if (j.error==='prize_disabled') msg='Premio non richiedibile';
      else if (j.error==='prize_not_found') msg='Premio non trovato';
      alert(msg); return;
    }
    closeM('#mdReq');
    openM('#mdOk');
  });

  // init
  loadMe(); loadPrizes();
});
</script>
