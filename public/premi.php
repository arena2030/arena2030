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

if ($a==='list_prizes'){ 
  only_get();

  $search = trim($_GET['search'] ?? '');
  $sort   = $_GET['sort'] ?? 'created';
  $dir    = strtolower($_GET['dir'] ?? 'desc')==='asc' ? 'ASC' : 'DESC';

  // ordinamento: created | name | coins
  $order  = $sort==='name'
              ? "p.name $dir"
              : ($sort==='coins'
                  ? "p.amount_coins $dir"
                  : "p.created_at $dir");

  // Mostra TUTTI i premi (abilitati e disabilitati).
  // Filtro opzionale solo per ricerca.
  $par   = [];
  $where = '';
  if ($search !== '') {
    $where = 'WHERE p.name LIKE ?';
    $par[] = "%$search%";
  }

  $sql = "SELECT
            p.id,
            p.prize_code,
            p.name,
            p.description,
            p.amount_coins,
            p.is_enabled,
            p.created_at,
            m.storage_key AS image_key
          FROM prizes p
          LEFT JOIN media m ON m.id = p.image_media_id
          $where
          ORDER BY $order";

  $st = $pdo->prepare($sql);
  $st->execute($par);
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
/* ===== Layout header pagina (coerente con Storico) ===== */
.section{ padding-top:24px; }
.hwrap{ max-width:1100px; margin:0 auto; }
.hwrap h1{ color:#fff; font-size:26px; font-weight:900; letter-spacing:.2px; margin:0 0 12px 0; }

/* ===== Card “dark premium” (come Storico tornei) ===== */
.card{
  position:relative; border-radius:20px; padding:18px 18px 16px;
  background:
    radial-gradient(1000px 300px at 50% -120px, rgba(99,102,241,.10), transparent 60%),
    linear-gradient(135deg,#0e1526 0%, #0b1220 100%);
  border:1px solid rgba(255,255,255,.08);
  color:#fff;
  box-shadow: 0 20px 60px rgba(0,0,0,.35);
  transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease, background .15s ease;
  overflow:hidden;
}
.card::before{
  content:""; position:absolute; left:0; top:0; bottom:0; width:4px;
  background:linear-gradient(180deg,#1e3a8a 0%, #0ea5e9 100%); opacity:.35;
}
.card:hover{ transform: translateY(-2px); box-shadow: 0 26px 80px rgba(0,0,0,.48); border-color:#21324b; }

/* topbar della card */
.topbar{
  display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:12px;
}
.topbar-left{ display:flex; gap:12px; align-items:center; }

/* saldo pill gialla */
.saldo{
  display:inline-flex; align-items:center; gap:8px;
  padding:6px 10px; border-radius:12px;
  border:1px solid rgba(253,224,71,.35);
  background:rgba(253,224,71,.08);
  color:#fde047; font-weight:900; letter-spacing:.3px;
}
.saldo .ac{ opacity:.9; font-weight:800; }

/* search */
.searchbox{
  height:36px; padding:0 12px; min-width:260px;
  border-radius:10px; background:#0f172a; border:1px solid #1f2937; color:#fff;
}

/* ===== Tabella dark dentro la card ===== */
.table-wrap{ overflow:auto; border-radius:12px; }
.table{ width:100%; border-collapse:separate; border-spacing:0; }
.table thead th{
  text-align:left; font-weight:900; font-size:12px; letter-spacing:.3px;
  color:#9fb7ff; padding:10px 12px; background:#0f172a; border-bottom:1px solid #1e293b;
}
.table thead th.sortable{ cursor:pointer; user-select:none; }
.table thead th .arrow{ opacity:.5; font-size:10px; }
.table tbody td{
  padding:12px; border-bottom:1px solid #122036; color:#e5e7eb; font-size:14px;
  background:linear-gradient(0deg, rgba(255,255,255,.02), rgba(255,255,255,.02));
}
.table tbody tr:hover td{ background:rgba(255,255,255,.025); }
.table tbody tr:last-child td{ border-bottom:0; }

/* thumb immagine — media */
.img-thumb{
  width:56px; height:56px; object-fit:cover;
  border-radius:10px; border:1px solid #223152; background:#0d1326; display:block;
}

/* pill di stato */
.pill{
  display:inline-flex; align-items:center; gap:6px;
  padding:4px 10px; border-radius:9999px; font-size:12px; font-weight:800; line-height:1;
  border:1px solid #334465; background:#0f172a; color:#cbd5e1;
}
.pill.ok{ border-color:rgba(52,211,153,.45); color:#d1fae5; background:rgba(6,78,59,.25); }
.pill.off{ border-color:rgba(239,68,68,.45); color:#fecaca; background:rgba(68,16,16,.25); }

/* Bottone Richiedi – solo nella pagina Premi */
.pr-page .table button.btn.btn--primary.btn--sm{
  height:34px;
  padding:0 14px;
  border-radius:9999px; /* ovale */
  font-weight:800;
  border:1px solid #3b82f6;
  background:#2563eb;
  color:#fff;
}
.pr-page .table button.btn.btn--primary.btn--sm:hover{ filter:brightness(1.05); }

/* variante “grigia” non acquistabile (non disabilito il click, lo intercetto da JS) */
.pr-page .table button.btn--disabled{
  height:34px;
  padding:0 14px;
  border-radius:9999px;
  font-weight:800;
  border:1px solid #374151;
  background:#1f2937;
  color:#9ca3af;
  cursor:not-allowed;
}

.muted{ color:#9ca3af; font-size:12px; }
.muted-sm{ color:#9ca3af; font-size:12px; }

/* modal (rimangono i tuoi) */
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
                <th>Codice</th>
                <th>Foto</th>
                <th class="sortable" data-sort="name">Nome <span class="arrow">↕</span></th>
                <th>Descrizione</th>
                <th>Stato</th>
                <th class="sortable" data-sort="coins">Arena Coins <span class="arrow">↕</span></th>
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
  // URL assoluto e inequivocabile all’endpoint della stessa pagina
  const u = new URL('/premi.php', location.origin);
  u.searchParams.set('action', 'list_prizes');
  u.searchParams.set('sort',  sort);
  u.searchParams.set('dir',   dir);
  if (search) u.searchParams.set('search', search);
  // cache-buster
  u.searchParams.set('_', Date.now().toString());

  const tb = document.querySelector('#tblPrizes tbody');
  if (!tb) return;

  tb.innerHTML = '<tr><td colspan="7">Caricamento…</td></tr>';

  try{
    const r = await fetch(u.toString(), { cache:'no-store', credentials:'same-origin' });

    let j;
    try {
      j = await r.json();
    } catch(parseErr){
      const txt = await r.text().catch(()=> '');
      console.error('[loadPrizes] parse error:', parseErr, txt);
      tb.innerHTML = '<tr><td colspan="7">Errore caricamento (risposta non valida)</td></tr>';
      return;
    }

    const rows = (j && j.ok && Array.isArray(j.rows)) ? j.rows : [];
    tb.innerHTML = '';

    if (rows.length === 0){
      console.warn('[loadPrizes] 0 righe ricevute:', j);
      tb.innerHTML = '<tr><td colspan="7">Nessun premio disponibile</td></tr>';
      return;
    }

    rows.forEach(row=>{
      const cost     = Number(row.amount_coins || 0);
      const enabled  = (row.is_enabled == 1);
      const can      = enabled && (Number(meCoins) >= cost);
      const reason   = !enabled ? 'Premio non richiedibile'
                                : (Number(meCoins) < cost ? 'Arena Coins insufficienti' : '');

      const imgHTML = row.image_key
        ? `<img class="img-thumb" src="${CDN_BASE ? (CDN_BASE + '/' + row.image_key) : ''}" alt="">`
        : '<div class="img-thumb" style="background:#0d1326;"></div>';

      const btnClass = can ? 'btn btn--primary btn--sm' : 'btn btn--disabled';
      const btnAttrs = `data-req="${row.id}" data-name="${row.name || ''}" data-coins="${cost}" data-can="${can?1:0}" data-reason="${reason}" title="${reason}"`;

      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td><code>${row.prize_code || '-'}</code></td>
        <td>${imgHTML}</td>
        <td>${row.name || '-'}</td>
        <td>${row.description ? row.description : ''}</td>
        <td>${enabled ? '<span class="pill ok">Abilitato</span>' : '<span class="pill off">Disabilitato</span>'}</td>
        <td>${cost.toFixed(2)}</td>
        <td style="text-align:right;">
          <button class="${btnClass}" ${btnAttrs}>Richiedi</button>
        </td>
      `;
      tb.appendChild(tr);
    });

  } catch(err){
    console.error('[loadPrizes] fetch error:', err);
    tb.innerHTML = '<tr><td colspan="7">Errore caricamento</td></tr>';
  }
}

  // sort + search
  $('#tblPrizes thead').addEventListener('click', (e)=>{
    const th=e.target.closest('[data-sort]'); if(!th) return; const s=th.getAttribute('data-sort');
    if (sort===s) dir=(dir==='asc'?'desc':'asc'); else{ sort=s; dir='asc'; } loadPrizes();
  });
  $('#qPrize').addEventListener('input', e=>{ search=e.target.value.trim(); loadPrizes(); });

  // open wizard (o messaggio se non acquistabile)
$('#tblPrizes').addEventListener('click', (e)=>{
  const b = e.target.closest('button[data-req]'); if(!b) return;

  // Ricontrollo LIVE: se il saldo è stato aggiornato dopo il render, non fidarti di data-can
  const cost = Number(b.getAttribute('data-coins') || 0);
  const enabled = (b.getAttribute('data-reason') !== 'Premio non richiedibile'); // o leggi dallo stato riga
  const canNow = enabled && (Number(meCoins) >= cost);

  if (!canNow){
    const why = !enabled ? 'Premio non richiedibile' : 'Arena Coins insufficienti';
    alert(why);
    return;
  }

  const id  = b.getAttribute('data-req');
  const nm  = b.getAttribute('data-name');
  const ac  = b.getAttribute('data-coins');
  $('#r_prize_id').value = id;
  $('#r_prize_name').value = nm;
  $('#r_prize_coins').value = ac;
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

// init: prima saldo, poi lista (evita race)
// init: chiamate separate (robuste) + rete di sicurezza
loadMe();
loadPrizes();

// rete di sicurezza: se per qualunque motivo loadMe() ritardasse,
// tra 1.5s ricarico comunque la lista (non rompe nulla)
setTimeout(()=>{
  // se la tabella è ancora vuota, rilancio
  const tb = document.querySelector('#tblPrizes tbody');
  if (tb && tb.children.length === 0) {
    loadPrizes();
  }
}, 1500);

// ▼▼▼ AGGIUNGI QUESTA RIGA DI CHIUSURA ▼▼▼
});  // chiude document.addEventListener('DOMContentLoaded', ()=>{ 
</script>
