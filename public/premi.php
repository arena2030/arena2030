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
/* ====== PREMI (scoped) ====== */
.pr-page {}

/* Card principale in stile “Storico tornei” (scoped) */
.pr-page .card{
  position:relative;
  border-radius:20px;
  padding:18px 18px 16px;
  background:
    radial-gradient(1000px 300px at 50% -120px, rgba(99,102,241,.10), transparent 60%),
    linear-gradient(135deg,#0e1526 0%, #0b1220 100%);
  border:1px solid rgba(255,255,255,.08);
  color:#fff;
  box-shadow: 0 20px 60px rgba(0,0,0,.35);
  transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease, background .15s ease;
  overflow:hidden;
}
.pr-page .card::before{
  content:""; position:absolute; left:0; top:0; bottom:0; width:4px;
  background:linear-gradient(180deg,#1e3a8a 0%, #0ea5e9 100%); opacity:.35;
}
.pr-page .card:hover{
  transform: translateY(-2px);
  box-shadow: 0 26px 80px rgba(0,0,0,.48);
  border-color:#21324b;
}

/* Topbar */
.pr-page .topbar{
  display:flex; justify-content:space-between; gap:10px; align-items:center; margin-bottom:12px;
}

/* pillola saldo (opzionale, resta semplice se non vuoi la pillola) */
.pr-page .saldo-pill{
  display:inline-flex; align-items:center; gap:8px;
  padding:6px 10px; border-radius:12px;
  background:rgba(253,224,71,.10);
  border:1px solid rgba(253,224,71,.35);
  color:#fde047; font-weight:900; letter-spacing:.3px;
}

/* search a destra */
.pr-page .searchbox{ min-width:260px; }

/* Tavola dark */
.pr-page .table-wrap{ border-radius:12px; overflow:hidden; }
.pr-page table.table{
  width:100%;
  border-collapse:separate; border-spacing:0;
  color:#e5e7eb; background:#0d1426;
  border:1px solid #1f2a44; border-radius:12px; overflow:hidden;
}
.pr-page .table thead th{
  background:#0f1b34; color:#cbd5e1;
  padding:12px; font-weight:800; text-align:left;
  border-bottom:1px solid #1f2a44;
}
.pr-page .table tbody td{
  padding:12px; border-bottom:1px dashed #1c2743; vertical-align:middle;
}
.pr-page .table tbody tr:hover{ background:#0b162b; }

/* miniature */
.pr-page .img-thumb{
  width:56px; height:56px; object-fit:cover; border-radius:8px;
  border:1px solid #1f2a44; background:#111827;
}

/* stato */
.pr-page .muted-sm{ color:#9aa6bd; font-size:12px; }

/* Modal */
.pr-page .modal[aria-hidden="true"]{ display:none; }
.pr-page .modal{ position:fixed; inset:0; z-index:60; }
.pr-page .modal-open{ overflow:hidden; }
.pr-page .modal-backdrop{ position:absolute; inset:0; background:rgba(0,0,0,.5); }
.pr-page .modal-card{
  position:relative; z-index:61; width:min(760px,96vw);
  background:linear-gradient(135deg,#0e1526 0%, #0b1220 100%);
  border:1px solid #1f2a44; border-radius:16px; margin:6vh auto 0; padding:0;
  box-shadow:0 16px 48px rgba(0,0,0,.5); max-height:86vh; display:flex; flex-direction:column; color:#e5edf7;
}
.pr-page .modal-head{ display:flex; align-items:center; gap:10px; padding:12px 16px; border-bottom:1px solid #1f2a44; }
.pr-page .modal-head h3{ margin:0; font-weight:900; }
.pr-page .modal-x{ margin-left:auto; background:transparent; border:0; color:#fff; font-size:24px; cursor:pointer; }
.pr-page .modal-body{ padding:16px; overflow:auto; }
.pr-page .modal-foot{ display:flex; justify-content:flex-end; gap:8px; padding:12px 16px; border-top:1px solid #1f2a44; }

/* Griglia form spedizione */
.pr-page .grid2{ display:grid; grid-template-columns:1fr 1fr; gap:16px; }
@media (max-width:860px){ .pr-page .grid2{ grid-template-columns:1fr; } }

/* Campi chiari come in resto del sito */
.pr-page .label{ display:block; margin-bottom:6px; font-size:12px; color:#9fb7ff; font-weight:700; }
.pr-page .input.light{
  background:#0c1628; border:1px solid #1e2a44; color:#e5edf7;
  border-radius:10px; height:38px; padding:0 12px;
}
.pr-page .input.light:focus{ outline:none; border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,.18); }

/* --- Importante: NON tocco .btn / .btn--primary globali --- */
/* Per eventuali bottoni interni specifici ai premi, scopa con .pr-page .table ecc. */
</style>

<main class="pr-page">
  <section class="section">
    <div class="container hwrap">
      <h1>Premi</h1>

      <div class="card">
        <div class="topbar">
          <div class="topbar-left">
            <span class="saldo"><span id="meCoins">0.00</span> <span class="ac">AC</span></span>
          </div>
          <div style="display:flex; gap:8px; align-items:center;">
            <input type="search" class="searchbox" id="qPrize" placeholder="Cerca premio…">
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
                <th style="text-align:right;">Azione</th>
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
      const img = row.image_key ? `<img class="img-thumb" src="${CDN_BASE ? (CDN_BASE+'/'+row.image_key) : ''}" alt="">` : '<div class="img-thumb" style="background:#0d1326;"></div>';
      const tr=document.createElement('tr');
      tr.innerHTML = `
        <td>${new Date(row.created_at).toLocaleString()}</td>
        <td>${img}</td>
        <td>${row.name}</td>
        <td><code>${row.prize_code}</code></td>
        <td>${Number(row.amount_coins).toFixed(2)}</td>
        <td>${row.is_enabled==1?'Abilitato':'Disabilitato'}</td>
        <td style="text-align:right;">
          <button class="btn btn--primary btn--sm" data-req="${row.id}" data-name="${row.name}" data-coins="${row.amount_coins}" ${disabled} title="${hint}">Richiedi</button>
        </td>
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
