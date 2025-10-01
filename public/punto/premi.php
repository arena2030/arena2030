<?php
// collegati alla connessione DB e avvio sessione
require_once __DIR__ . '/../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/partials/csrf.php';
$CSRF = htmlspecialchars(csrf_token(), ENT_QUOTES);

// Accesso: consenti USER o PUNTO (se vuoi solo PUNTO, sostituisci la condizione)
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

    // Mostra TUTTI i premi (abilitati e disabilitati). Filtro opzionale solo per ricerca.
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

// ⇢ Header PUNTO (se usi un altro header, cambia questa riga)
include __DIR__ . '/../partials/header_punto.php';

// CDN per immagini
$CDN_BASE = rtrim(getenv('CDN_BASE') ?: getenv('S3_CDN_BASE') ?: '', '/');
?>
<style>
/* ===== Layout header pagina (coerente con Storico) ===== */
.section{ padding-top:24px; }
.hwrap{ max_width:1100px; margin:0 auto; }
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

.muted{ color:#9ca3af; font-size:12px; }
.muted-sm{ color:#9ca3af; font-size:12px; }

/* modal */
.modal[aria-hidden="true"]{ display:none; } .modal{ position:fixed; inset:0; z-index:60; }
.modal-open{ overflow:hidden; }
.modal-backdrop{ position:absolute; inset:0; background:rgba(0,0,0,.5); }
.modal-card{ position:relative; z-index:61; width:min(760px,96vw); background:var(--c-bg); border:1px solid var(--c-border); border-radius:16px; margin:6vh auto 0; padding:0; box-shadow:0 16px 48px rgba(0,0,0,.5); max-height:86vh; display:flex; flex-direction:column; }
.modal-head{ display:flex; align-items:center; gap:10px; padding:12px 16px; border-bottom:1px solid var(--c-border); }
.modal-x{ margin-left:auto; background:transparent; border:0; color:#fff; font-size:24px; cursor:pointer; }
.modal-body{ padding:16px; overflow:auto; }
.modal-foot{ display:flex; justify-content:flex-end; gap:8px; padding:12px 16px; border-top:1px solid var(--c-border); }

/* griglie form */
.grid2{ display:grid; grid-template-columns:1fr 1fr; gap:16px; } 
@media (max-width:860px){ .grid2{ grid-template-columns:1fr; } }

/* input */
.field .label{ display:block; margin-bottom:6px; font-weight:800; font-size:12px; color:#9fb7ff; }
.input.light, .select.light{
  width:100%; height:38px; padding:0 12px; border-radius:10px;
  background:#0f172a; border:1px solid #1f2937; color:#fff;
}

/* bottoni tavola */
.pr-page .table button.btn.btn--primary.btn--sm{
  height:34px; padding:0 14px; border-radius:9999px; font-weight:800;
  border:1px solid #3b82f6; background:#2563eb; color:#fff;
}
.pr-page .table button.btn.btn--primary.btn--sm:hover{ filter:brightness(1.05); }
.pr-page .table button.btn--disabled{
  height:34px; padding:0 14px; border-radius:9999px; font-weight:800;
  border:1px solid #374151; background:#1f2937; color:#9ca3af; cursor:not-allowed;
}

/* step wizard */
.step[aria-hidden="true"]{ display:none !important; }
.step{ display:block; }
.step:not(.active){ display:none; }

/* separatore sottile */
.hr{ height:1px; background:#142036; margin:10px 0; }

/* badge riepilogo */
.badge{ display:inline-block; padding:2px 8px; border:1px solid #24324d; border-radius:9999px; font-size:12px; color:#cbd5e1; }

/* (riutilizzabile) checkbox rotondo */
.check{ display:inline-flex; align-items:center; gap:8px; cursor:pointer; user-select:none; }
.check input[type="checkbox"]{
  appearance:none; width:18px; height:18px; border-radius:50%;
  border:2px solid #334155; background:#0f172a; outline:none; position:relative;
  transition:.15s border-color ease;
}
.check input[type="checkbox"]:checked{
  border-color:#fde047; background:#fde047; box-shadow:0 0 10px rgba(253,224,71,.35);
}
.hidden{ display:none !important; }
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

      <!-- Wizard richiesta premio (SOLO SPEDIZIONE per PUNTO) -->
      <div class="modal" id="mdReq" aria-hidden="true">
        <div class="modal-backdrop" data-close></div>
        <div class="modal-card">
          <div class="modal-head">
            <h3>Richiedi premio</h3>
            <button class="modal-x" data-close>&times;</button>
          </div>
          <div class="modal-body">
            <form id="fReq" novalidate>
              <input type="hidden" id="r_prize_id">
              <input type="hidden" id="r_prize_name">
              <input type="hidden" id="r_prize_coins">

              <!-- STEP 1 — Spedizione -->
              <section class="step active" data-step="1">
                <div class="badge">Indirizzo di spedizione</div>
                <div class="grid2" style="margin-top:10px;">
                  <div class="field"><label class="label">Stato *</label><input class="input light" id="ship_stato" required></div>
                  <div class="field"><label class="label">Città *</label><input class="input light" id="ship_citta" required></div>
                  <div class="field"><label class="label">Comune *</label><input class="input light" id="ship_comune" required></div>
                  <div class="field"><label class="label">Provincia *</label><input class="input light" id="ship_provincia" required></div>
                  <div class="field" style="grid-column:span 2;"><label class="label">Via *</label><input class="input light" id="ship_via" required></div>
                  <div class="field"><label class="label">Civico *</label><input class="input light" id="ship_civico" required></div>
                  <div class="field"><label class="label">CAP *</label><input class="input light" id="ship_cap" required></div>
                </div>
              </section>

              <!-- STEP 2 — Riepilogo -->
              <section class="step" data-step="2">
                <div class="card" style="padding:12px;">
                  <div><strong>Premio:</strong> <span id="rv_name"></span></div>
                  <div><strong>Costo:</strong> <span id="rv_coins"></span> <span class="muted">AC</span></div>
                  <div class="hr"></div>
                  <div><strong>Spedizione:</strong></div>
                  <div id="rv_ship" class="muted-sm"></div>
                </div>
              </section>
            </form>
          </div>
          <!-- Footer wizard -->
          <div class="modal-foot">
            <div style="display:flex; gap:8px;">
              <button class="btn btn--outline" type="button" data-close>Annulla</button>
              <button class="btn btn--primary" type="button" id="r_next">Avanti</button>
              <button class="btn btn--primary hidden" type="button" id="r_send">Richiedi</button>
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
  const $  = s => document.querySelector(s);
  const $$ = (s,p=document)=>[...p.querySelectorAll(s)];
  const CDN_BASE = <?= json_encode($CDN_BASE) ?>;
  const CSRF     = '<?= $CSRF ?>';

  let meCoins = 0.00, sort='created', dir='desc', search='';

  /* ===== Modali: open/close con gestione focus ===== */
  const isInside = (el, root) => !!(el && root && (el===root || root.contains(el)));
  let lastOpener = null;

  function openM(sel){
    const m = $(sel); if(!m) return;
    document.body.classList.add('modal-open');
    m.setAttribute('aria-hidden','false');
    const focusable = m.querySelector('[data-close], button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
    (focusable || m).focus({preventScroll:true});
    m._opener = lastOpener || null;
  }
  function closeM(sel){
    const m = $(sel); if(!m) return;
    if (isInside(document.activeElement, m)) document.activeElement.blur();
    m.setAttribute('aria-hidden','true');
    document.body.classList.remove('modal-open');
    const target = m._opener && document.contains(m._opener) ? m._opener : document.body;
    if (target && target.focus) target.focus({preventScroll:true});
    m._opener = null; lastOpener = null;
  }
  document.addEventListener('click', (e)=>{
    const btn = e.target.closest('[data-close]'); if (!btn) return;
    e.preventDefault();
    const modal = btn.closest('.modal'); if (modal) closeM('#' + modal.id);
  });
  document.addEventListener('click', (e)=>{
    const bd = e.target.closest('.modal-backdrop'); if (!bd) return;
    e.preventDefault();
    const modal = bd.closest('.modal'); if (modal) closeM('#' + modal.id);
  });
  document.addEventListener('keydown', (e)=>{
    if (e.key !== 'Escape') return;
    const open = document.querySelector('.modal[aria-hidden="false"]');
    if (open) closeM('#' + open.id);
  });

  /* ===== API ===== */
  async function loadMe(){
    try{
      const r = await fetch('?action=me', { cache:'no-store' });
      let j; try { j = await r.json(); }
      catch(e){ console.error('[loadMe] non-JSON', e); return; }
      if (j.ok && j.me){
        meCoins = Number(j.me.coins||0);
        $('#meCoins').textContent = meCoins.toFixed(2);
      }
    }catch(err){ console.error('[loadMe] fetch error', err); }
  }

  async function loadPrizes(){
    const u = new URL('/punto/premi.php', location.origin);
    u.searchParams.set('action','list_prizes');
    u.searchParams.set('sort',  sort);
    u.searchParams.set('dir',   dir);
    if (search) u.searchParams.set('search', search);
    u.searchParams.set('_', Date.now().toString());

    const tb = $('#tblPrizes tbody'); if (!tb) return;
    tb.innerHTML = '<tr><td colspan="7">Caricamento…</td></tr>';

    try{
      const r = await fetch(u.toString(), { cache:'no-store', credentials:'same-origin' });
      let j; try { j = await r.json(); }
      catch(parseErr){
        const txt = await r.text().catch(()=> '');
        console.error('[loadPrizes] parse error:', parseErr, txt);
        tb.innerHTML = '<tr><td colspan="7">Errore caricamento (risposta non valida)</td></tr>';
        return;
      }

      const rows = (j && j.ok && Array.isArray(j.rows)) ? j.rows : [];
      tb.innerHTML = '';
      if (rows.length === 0){
        tb.innerHTML = '<tr><td colspan="7">Nessun premio disponibile</td></tr>';
        return;
      }

      rows.forEach(row=>{
        const cost     = Number(row.amount_coins || 0);
        const enabled  = (row.is_enabled == 1);
        const can      = enabled && (Number(meCoins) >= cost);
        const reason   = !enabled ? 'Premio non richiedibile'
                                  : (Number(meCoins) < cost ? 'Arena Coins insufficienti' : '');

        let imgHTML = '<div class="img-thumb" style="background:#0d1326;"></div>';
        if (row.image_key && CDN_BASE) {
          const src = CDN_BASE + '/' + row.image_key;
          imgHTML = `<img class="img-thumb" src="${src}" alt="">`;
        }

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
            <button type="button" class="${btnClass}" ${btnAttrs}>Richiedi</button>
          </td>
        `;
        tb.appendChild(tr);
      });

    } catch(err){
      console.error('[loadPrizes] fetch error:', err);
      tb.innerHTML = '<tr><td colspan="7">Errore caricamento</td></tr>';
    }
  }

  /* ===== Interazioni tabella ===== */
  const thead = document.querySelector('#tblPrizes thead');
  if (thead) thead.addEventListener('click', (e)=>{
    const th=e.target.closest('[data-sort]'); if(!th) return;
    const s=th.getAttribute('data-sort');
    if (sort===s) dir=(dir==='asc'?'desc':'asc'); else{ sort=s; dir='asc'; }
    loadPrizes();
  });

  const qInput = $('#qPrize');
  if (qInput) qInput.addEventListener('input', e=>{
    search=e.target.value.trim(); loadPrizes();
  });

  /* ===== Apertura wizard (delega) ===== */
  document.addEventListener('click', (e)=>{
    const b = e.target.closest('button[data-req]'); if(!b) return;
    const table = $('#tblPrizes'); if (!table || !table.contains(b)) return;

    // ricontrollo live
    const cost = Number(b.getAttribute('data-coins') || 0);
    const enabled = (b.getAttribute('data-reason') !== 'Premio non richiedibile');
    const canNow = enabled && (Number(meCoins) >= cost);
    if (!canNow){ alert(!enabled ? 'Premio non richiedibile' : 'Arena Coins insufficienti'); return; }

    lastOpener = b;

    // bind dati premio
    $('#r_prize_id').value   = b.getAttribute('data-req');
    $('#r_prize_name').value = b.getAttribute('data-name');
    $('#r_prize_coins').value= b.getAttribute('data-coins');

    // reset wizard (solo spedizione)
    $$('#fReq input').forEach(i=>{ if (!['hidden'].includes(i.type)) i.value=''; });

    // step 1 on
    const steps = $$('#fReq .step');
    steps.forEach((s,i)=>s.classList.toggle('active', i===0));
    $('#r_next').classList.remove('hidden');
    $('#r_send').classList.add('hidden');

    openM('#mdReq');
  });

  /* ===== NEXT ===== */
  const btnNext = $('#r_next');
  if (btnNext) btnNext.addEventListener('click', ()=>{
    const steps = $$('#fReq .step');

    // Step 1 -> 2 : valida spedizione
    if (steps[0]?.classList.contains('active')){
      const need=['ship_stato','ship_citta','ship_comune','ship_provincia','ship_via','ship_civico','ship_cap'];
      for (const id of need){ const el=$('#'+id); if (!el?.value.trim()){ el?.reportValidity?.(); return; } }

      // Riepilogo
      $('#rv_name').textContent  = $('#r_prize_name').value;
      $('#rv_coins').textContent = Number($('#r_prize_coins').value||0).toFixed(2);
      const shipHTML = `
        ${$('#ship_via').value} ${$('#ship_civico').value}<br>
        ${$('#ship_cap').value} ${$('#ship_citta').value} (${ $('#ship_provincia').value })<br>
        ${$('#ship_comune').value} — ${$('#ship_stato').value}
      `;
      $('#rv_ship').innerHTML = shipHTML;

      steps[0].classList.remove('active'); steps[1]?.classList.add('active');
      btnNext.classList.add('hidden'); $('#r_send').classList.remove('hidden');
    }
  });

  /* ===== INVIO ===== */
  const btnSend = $('#r_send');
  if (btnSend) btnSend.addEventListener('click', async ()=>{
    const btn = btnSend; btn.disabled=true; btn.textContent='Invio…';
    try{
      const data = new URLSearchParams({
        prize_id: String(Number($('#r_prize_id').value||0)),
        // spedizione -> prize_requests
        ship_same_as_res: '0',
        ship_stato:     $('#ship_stato').value.trim(),
        ship_citta:     $('#ship_citta').value.trim(),
        ship_comune:    $('#ship_comune').value.trim(),
        ship_provincia: $('#ship_provincia').value.trim(),
        ship_via:       $('#ship_via').value.trim(),
        ship_civico:    $('#ship_civico').value.trim(),
        ship_cap:       $('#ship_cap').value.trim()
      });
      data.set('csrf_token', CSRF);

      const r = await fetch('/api/prize_request.php?action=request', {
        method:'POST',
        body:data,
        credentials:'same-origin',
        headers:{ 'Accept':'application/json', 'X-CSRF-Token': CSRF }
      });

      let j=null, raw='';
      try { j = await r.json(); } catch(_) { try{ raw = await r.text(); }catch(__){} }

      if (!j || j.ok!==true){
        let msg='Errore richiesta premio';
        if (j && j.detail) msg += ': '+j.detail;
        else if (raw) msg += ': '+raw.slice(0,300);
        alert(msg);
        return;
      }

      closeM('#mdReq');
      openM('#mdOk');
      await loadMe();
      await loadPrizes();

    }catch(e){
      alert('Errore invio: ' + (e && e.message ? e.message : ''));
    }finally{
      btn.disabled=false; btn.textContent='Richiedi';
    }
  });

  // init
  (async ()=>{
    await loadMe();
    await loadPrizes();
  })();

});
</script>
