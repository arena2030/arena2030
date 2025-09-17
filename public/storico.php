<?php
// /public/storico.php — Storico tornei dell’utente (card + modale picks)
require_once __DIR__ . '/../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }

$uid = (int)($_SESSION['uid'] ?? 0);
$role = $_SESSION['role'] ?? 'USER';
if ($uid <= 0 || !in_array($role, ['USER','PUNTO','ADMIN'], true)) {
  header('Location: /login.php'); exit;
}

/* ===== Helpers ===== */
function json($a){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($a); exit; }
function only_get(){ if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'){ http_response_code(405); json(['ok'=>false,'error'=>'method']); } }
function col_exists(PDO $pdo, string $table, string $col): bool {
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $q->execute([$table,$col]); return (bool)$q->fetchColumn();
}

/* ===== AJAX ===== */
if (isset($_GET['action'])) {
  $a = $_GET['action'];

  // LISTA TORNEI PARTECIPATI
  if ($a === 'list') { only_get();
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per  = min(50, max(1, (int)($_GET['per'] ?? 8)));
    $off  = ($page-1)*$per;
    $q    = trim($_GET['q'] ?? '');
    $flt  = trim($_GET['status'] ?? 'all'); // all|active|closed

    // status col esiste?
    $hasStatus = col_exists($pdo,'tournaments','status');
    $hasUpd    = col_exists($pdo,'tournaments','updated_at');

    // WHERE
    $where = ["tl.user_id=?"];
    $params = [$uid];
    if ($q !== '') { $where[] = "t.name LIKE ?"; $params[] = "%$q%"; }
    if ($hasStatus && in_array($flt, ['active','closed'], true)) { $where[] = "t.status=?"; $params[] = $flt; }
    $WHERE = 'WHERE ' . implode(' AND ', $where);

    // SQL con aggregati sulle vite
    $sql = "SELECT SQL_CALC_FOUND_ROWS
              t.id, t.tour_code, t.name".
              ($hasStatus ? ", t.status" : ", 'n/d' AS status").
              ", t.created_at".
              ($hasUpd ? ", t.updated_at" : ", t.created_at AS updated_at").",
              COUNT(tl.id) AS lives,
              SUM(CASE WHEN tl.status='winner' THEN 1 ELSE 0 END) AS winners,
              SUM(CASE WHEN tl.status='alive'  THEN 1 ELSE 0 END) AS alives,
              SUM(CASE WHEN tl.status='eliminated' THEN 1 ELSE 0 END) AS eliminated,
              MAX(tl.round) AS last_round
            FROM tournaments t
            JOIN tournament_lives tl ON tl.tournament_id = t.id
            $WHERE
            GROUP BY t.id
            ORDER BY t.created_at DESC
            LIMIT $per OFFSET $off";
    $st=$pdo->prepare($sql); $st->execute($params);
    $rows=$st->fetchAll(PDO::FETCH_ASSOC);

    $total = (int)$pdo->query("SELECT FOUND_ROWS()")->fetchColumn();

    json(['ok'=>true,'rows'=>$rows,'total'=>$total,'page'=>$page,'per'=>$per]);
  }

  // DETTAGLIO PICKS PER TORNEO (timeline)
  if ($a === 'picks') { only_get();
    $tid = (int)($_GET['tournament_id'] ?? 0);
    if ($tid<=0) json(['ok'=>false,'error'=>'bad_id']);

    // colonne
    $hasRes = col_exists($pdo,'tournament_events','result');

    // Join picks + teams names
    $sql = "SELECT tp.round, tp.pick,
                   te.id AS event_id, te.start_time".
                   ($hasRes ? ", te.result" : ", NULL AS result").",
                   th.name AS home_name, ta.name AS away_name
            FROM tournament_picks tp
            JOIN tournament_lives tl ON tl.id = tp.life_id
            JOIN tournament_events te ON te.id = tp.event_id
            JOIN teams th ON th.id = te.home_team_id
            JOIN teams ta ON ta.id = te.away_team_id
            WHERE tl.user_id=? AND tp.tournament_id=?
            ORDER BY tp.round ASC, te.start_time ASC, tp.id ASC";
    $st=$pdo->prepare($sql); $st->execute([$uid,$tid]);
    $rows=$st->fetchAll(PDO::FETCH_ASSOC);

    json(['ok'=>true,'rows'=>$rows]);
  }

  http_response_code(400); json(['ok'=>false,'error'=>'unknown_action']);
}

/* ===== VIEW ===== */
$page_css='/pages-css/admin-dashboard.css';
include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/header_utente.php';
?>
<style>
  .hist-wrap{ max-width: 1080px; margin: 0 auto; }
  .filters{ display:flex; justify-content:space-between; gap:12px; align-items:center; margin-bottom:12px; }
  .filters .left{ display:flex; gap:10px; align-items:center; }
  .filters .right{ display:flex; gap:8px; align-items:center; }

  .cards{ display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; }
  @media (max-width: 1100px){ .cards{ grid-template-columns:1fr; } }

  .tour-card{
    background: var(--c-card);
    border:1px solid var(--c-border);
    border-radius:16px;
    overflow:hidden;
    box-shadow:0 10px 30px rgba(0,0,0,.25);
  }
  .tour-hero{
    background: linear-gradient(92deg, #2f80ff 0%, #00c2ff 100%);
    color:#fff; padding:16px; position:relative;
  }
  .tour-code{ position:absolute; left:14px; top:10px; font-weight:800; letter-spacing:.5px; background:rgba(0,0,0,.2); border:1px solid rgba(255,255,255,.25); padding:3px 8px; border-radius:9999px; font-size:12px; }
  .tour-name{ text-align:center; font-weight:900; letter-spacing:.6px; font-size:20px; text-transform:uppercase; }
  .tour-meta{ text-align:center; opacity:.95; font-size:12px; margin-top:2px; }

  .tour-body{ padding:16px; display:grid; grid-template-columns:1fr auto; gap:12px; align-items:center; }
  .pill{ display:inline-flex; align-items:center; gap:6px; padding:6px 10px; border-radius:9999px; border:1px solid var(--c-border); }
  .chip{ display:inline-flex; padding:4px 8px; border-radius:9999px; border:1px solid var(--c-border); font-size:12px; }
  .chip.ok{ border-color:#22c55e; color:#a7f3d0; }
  .chip.off{ border-color:#f87171; color:#fecaca; }
  .chip.info{ border-color:#93c5fd; color:#bfdbfe; }
  .muted-sm{ color:#9aa4b2; font-size:12px; }

  .actions{ display:flex; gap:8px; }
</style>

<main class="section">
  <div class="container">
    <div class="hist-wrap">
      <h1>Storico tornei</h1>

      <div class="filters">
        <div class="left">
          <input type="search" id="q" class="input light" placeholder="Cerca torneo…" style="min-width:260px;">
          <select id="flt" class="select light">
            <option value="all">Tutti</option>
            <option value="active">Attivi</option>
            <option value="closed">Chiusi</option>
          </select>
        </div>
        <div class="right">
          <button class="btn btn--outline btn--sm" id="prev">« Precedente</button>
          <button class="btn btn--outline btn--sm" id="next">Successivo »</button>
        </div>
      </div>

      <div class="cards" id="cards"></div>
      <p id="info" class="muted-sm" style="margin-top:10px;"></p>
    </div>
  </div>
</main>

<?php include __DIR__ . '/../partials/footer.php'; ?>

<!-- Modale picks -->
<div class="modal" id="mdPicks" aria-hidden="true">
  <div class="modal-backdrop" data-close></div>
  <div class="modal-card" style="max-width:980px;">
    <div class="modal-head">
      <h3 id="mdTitle">Dettaglio picks</h3>
      <button class="modal-x" data-close>&times;</button>
    </div>
    <div class="modal-body">
      <div class="table-wrap">
        <table class="table" id="tblPicks">
          <thead>
            <tr>
              <th style="width:100px;">Round</th>
              <th>Evento</th>
              <th style="width:120px;">Pick</th>
              <th style="width:120px;">Esito</th>
            </tr>
          </thead>
          <tbody><!-- js --></tbody>
        </table>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn--primary" data-close>Chiudi</button>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', ()=>{
  const $=s=>document.querySelector(s); const $$=(s,p=document)=>[...p.querySelectorAll(s)];

  let state = { q:'', status:'all', page:1, per:8, total:0 };

  function openM(){ $('#mdPicks').setAttribute('aria-hidden','false'); document.body.classList.add('modal-open'); }
  function closeM(){ $('#mdPicks').setAttribute('aria-hidden','true'); document.body.classList.remove('modal-open'); }
  $$('#mdPicks [data-close], #mdPicks .modal-backdrop').forEach(x=>x.addEventListener('click', closeM));

  function fmtDate(s){ try{ const d=new Date(s); return isNaN(+d)?'-':d.toLocaleString(); }catch(e){ return '-'; } }
  function chipStatus(s){
    if (!s) return '<span class="chip info">n/d</span>';
    const m = String(s).toLowerCase();
    if (m==='active')  return '<span class="chip info">ATTIVO</span>';
    if (m==='closed')  return '<span class="chip ok">CHIUSO</span>';
    if (m==='cancelled')  return '<span class="chip off">ANNULLATO</span>';
    return '<span class="chip">'+s+'</span>';
  }

  async function loadList(){
    const u = new URL(location.href);
    u.searchParams.set('action','list');
    u.searchParams.set('q', state.q);
    u.searchParams.set('status', state.status);
    u.searchParams.set('page', state.page);
    u.searchParams.set('per', state.per);
    const r = await fetch(u, {cache:'no-store'}); const j = await r.json();
    const cards = $('#cards'); cards.innerHTML='';
    if (!j.ok){ cards.innerHTML='<div class="card">Errore caricamento</div>'; return; }
    state.total = j.total;

    if (!j.rows || j.rows.length===0){
      cards.innerHTML='<div class="muted-sm">Nessun torneo trovato.</div>';
      $('#info').textContent = '0 risultati';
      return;
    }

    j.rows.forEach(row=>{
      const livesInfo = `
        <span class="pill"><strong>Vite:</strong> ${row.lives}</span>
        <span class="pill"><strong>Round max:</strong> ${row.last_round || 0}</span>
        <span class="pill"><strong>Vive:</strong> ${row.alives||0}</span>
        <span class="pill"><strong>Vittorie:</strong> ${row.winners||0}</span>
      `;
      const card = document.createElement('div');
      card.className = 'tour-card';
      card.innerHTML = `
        <div class="tour-hero">
          <div class="tour-code">#${row.tour_code || row.id}</div>
          <div class="tour-name">${(row.name||'Senza nome').toUpperCase()}</div>
          <div class="tour-meta">${fmtDate(row.created_at)} — ${fmtDate(row.updated_at)}</div>
        </div>
        <div class="tour-body">
          <div class="meta">${livesInfo}</div>
          <div class="actions">
            ${chipStatus(row.status)}
            <button class="btn btn--primary btn--sm" data-picks="${row.id}" data-name="${row.name||''}">Dettagli</button>
          </div>
        </div>
      `;
      cards.appendChild(card);
    });

    const totalPages = Math.max(1, Math.ceil((state.total || 0) / state.per));
    $('#info').textContent = `Pagina ${state.page} di ${totalPages} — ${state.total} tornei`;
    $('#prev').disabled = (state.page<=1);
    $('#next').disabled = (state.page>=totalPages);
  }

  // Filtro / ricerca / pagine
  $('#q').addEventListener('input', e=>{ state.q=e.target.value.trim(); state.page=1; loadList(); });
  $('#flt').addEventListener('change', e=>{ state.status=e.target.value; state.page=1; loadList(); });
  $('#prev').addEventListener('click', ()=>{ if (state.page>1){ state.page--; loadList(); } });
  $('#next').addEventListener('click', ()=>{ state.page++; loadList(); });

  // Picks modal
  $('#cards').addEventListener('click', async (e)=>{
    const b = e.target.closest('button[data-picks]'); if (!b) return;
    const tid = b.getAttribute('data-picks');
    const name = b.getAttribute('data-name') || '';
    $('#mdTitle').textContent = `Picks — ${name}`;
    const tb = $('#tblPicks tbody');
    tb.innerHTML = '<tr><td colspan="4">Caricamento…</td></tr>';

    const u = new URL(location.href);
    u.searchParams.set('action','picks');
    u.searchParams.set('tournament_id', tid);
    const r = await fetch(u, {cache:'no-store'}); const j = await r.json();

    tb.innerHTML = '';
    if (!j.ok || !j.rows || j.rows.length===0){
      tb.innerHTML = '<tr><td colspan="4">Nessuna pick trovata</td></tr>';
    } else {
      j.rows.forEach(p=>{
        const vs = `${p.home_name || 'Home'} vs ${p.away_name || 'Away'}`;
        const esito = (p.result || '').toUpperCase() || 'N/D';
        const pick  = (p.pick || '').toUpperCase();
        const row = document.createElement('tr');
        row.innerHTML = `
          <td>${p.round}</td>
          <td>${vs}</td>
          <td>${pick}</td>
          <td>${esito}</td>
        `;
        tb.appendChild(row);
      });
    }
    openM();
  });

  // Init
  loadList();
});
</script>
