<?php
require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['uid']) || (($_SESSION['role'] ?? '')!=='PUNTO')) { header('Location: /login.php'); exit; }

function json($a){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($a); exit; }
function only_get(){ if ($_SERVER['REQUEST_METHOD']!=='GET'){ http_response_code(405); json(['ok'=>false,'error'=>'method']); } }
function getSortClause(array $white, string $sort=null, string $dir=null, string $default='u.username ASC'){
  $s = $white[$sort ?? ''] ?? $default; $d = strtolower($dir)==='desc' ? 'DESC' : 'ASC'; return "$s $d";
}
function userNameCols(PDO $pdo): array {
  static $cols = null;
  if ($cols===null){
    $arr = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users'")->fetchAll(PDO::FETCH_COLUMN);
    $fn = null; foreach (['nome','first_name','name'] as $c){ if (in_array($c,$arr,true)) { $fn=$c; break; } }
    $ln = null; foreach (['cognome','last_name','surname'] as $c){ if (in_array($c,$arr,true)) { $ln=$c; break; } }
    $cols = [$fn,$ln];
  }
  return $cols;
}
function columnExists(PDO $pdo, string $table, string $col): bool {
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $q->execute([$table,$col]); return (bool)$q->fetchColumn();
}
function myPointCode(PDO $pdo, int $uid): ?string {
  $st=$pdo->prepare("SELECT point_code FROM points WHERE user_id=? LIMIT 1");
  $st->execute([$uid]); $pc=$st->fetchColumn();
  return $pc ?: null;
}

/* ===== AJAX ===== */
if (isset($_GET['action'])) {
  $a = $_GET['action'];

  if ($a==='list_users'){ only_get();
    $uid = (int)($_SESSION['uid']);
    $pointUsername = $_SESSION['username'] ?? '';
    if ($pointUsername==='') {
      $getU = $pdo->prepare("SELECT username FROM users WHERE id=?");
      $getU->execute([$uid]);
      $pointUsername = (string)($getU->fetchColumn() ?: '');
    }
    $pc = myPointCode($pdo, $uid);

    $search = trim($_GET['search'] ?? '');
    $sort   = $_GET['sort'] ?? 'username';
    $dir    = $_GET['dir']  ?? 'asc';
    $page   = max(1,(int)($_GET['page'] ?? 1));
    $per    = min(50, max(1,(int)($_GET['per'] ?? 10)));
    $off    = ($page-1)*$per;

    [$fn,$ln] = userNameCols($pdo);
    $hasUserCode = columnExists($pdo,'users','user_code');

    $order = getSortClause([
      'id'       => $hasUserCode ? 'u.user_code' : 'u.id',
      'username' => 'u.username',
      'nome'     => $fn ? "u.`$fn`" : "u.username",
      'cognome'  => $ln ? "u.`$ln`" : "u.username",
      'coins'    => 'u.coins',
      'status'   => 'u.is_active'
    ], $sort, $dir, $hasUserCode ? 'u.user_code ASC' : 'u.id ASC');

    $conds = []; $paramsNet = [];
    if (columnExists($pdo,'users','presenter_code')) {
      if ($pc)             { $conds[] = "u.presenter_code = ?"; $paramsNet[] = $pc; }
      if ($pointUsername)  { $conds[] = "u.presenter_code = ?"; $paramsNet[] = $pointUsername; }
    }
    if (columnExists($pdo,'users','presenter')) {
      $conds[] = "u.presenter = ?";       $paramsNet[] = $uid;
      if ($pointUsername) { $conds[] = "u.presenter = ?"; $paramsNet[] = $pointUsername; }
    }
    if (columnExists($pdo,'users','point_user_id'))  { $conds[] = "u.point_user_id = ?";  $paramsNet[] = $uid; }
    if ($pc && columnExists($pdo,'users','point_code'))      { $conds[] = "u.point_code = ?";      $paramsNet[] = $pc; }
    if ($pointUsername && columnExists($pdo,'users','point_username')) { $conds[] = "u.point_username = ?"; $paramsNet[] = $pointUsername; }

    if (!$conds) { json(['ok'=>true,'rows'=>[],'total'=>0,'page'=>1,'pages'=>0,'point_username'=>$pointUsername]); }

    $whereNet = '(' . implode(' OR ', $conds) . ')';
    $w = ["u.role <> 'PUNTO'", $whereNet];
    $p = $paramsNet;
    if ($search!==''){ $w[]="u.username LIKE ?"; $p[]="%$search%"; }
    $where = 'WHERE ' . implode(' AND ', $w);

    $sql = "SELECT u.id"
         . ($hasUserCode ? ", u.user_code AS user_code" : "")
         . ", u.username, u.is_active, COALESCE(u.coins,0) as coins"
         . ($fn ? ", u.`$fn` AS nome" : ", NULL AS nome")
         . ($ln ? ", u.`$ln` AS cognome" : ", NULL AS cognome")
         . " FROM users u $where ORDER BY $order LIMIT $per OFFSET $off";

    $st = $pdo->prepare($sql); $st->execute($p); $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $ct = $pdo->prepare("SELECT COUNT(*) FROM users u $where");
    $ct->execute($p); $total = (int)$ct->fetchColumn(); $pages = (int)ceil($total/$per);

    json(['ok'=>true,'rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>$pages,'point_username'=>$pointUsername]);
  }

  if ($a==='user_movements'){ only_get();
    $uid = (int)($_GET['user_id'] ?? 0);
    if ($uid<=0) json(['ok'=>false,'error'=>'bad_id']);
    $hasTS = columnExists($pdo,'points_balance_log','created_at');
    $sql = $hasTS
      ? "SELECT id, delta, reason, created_at FROM points_balance_log WHERE user_id=? ORDER BY id DESC LIMIT 50"
      : "SELECT id, delta, reason, NULL AS created_at FROM points_balance_log WHERE user_id=? ORDER BY id DESC LIMIT 50";
    $st=$pdo->prepare($sql); $st->execute([$uid]); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    json(['ok'=>true,'rows'=>$rows]);
  }

  http_response_code(400); json(['ok'=>false,'error'=>'unknown_action']);
}

/* ===== VIEW ===== */
$page_css='/pages-css/admin-dashboard.css';
include __DIR__ . '/../../partials/head.php';
include __DIR__ . '/../../partials/header_punto.php';
?>
<style>
  /* ===== Stili allineati alla pagina “Premi” (identici) ===== */

  /* Layout titolo pagina */
  .section{ padding-top:24px; }
  .container{ max-width:1100px; margin:0 auto; }
  h1{ color:#fff; font-size:26px; font-weight:900; letter-spacing:.2px; margin:0 0 12px; }

  /* Card scura premium */
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
    margin-bottom:16px;
  }
  .card::before{
    content:""; position:absolute; left:0; top:0; bottom:0; width:4px;
    background:linear-gradient(180deg,#1e3a8a 0%, #0ea5e9 100%); opacity:.35;
  }
  .card:hover{ transform: translateY(-2px); box-shadow: 0 26px 80px rgba(0,0,0,.48); border-color:#21324b; }

  /* Topbar card */
  .topbar{ display:flex; justify-content:space-between; gap:10px; align-items:center; margin-bottom:12px; }
  .searchbox{
    height:36px; padding:0 12px; min-width:260px;
    border-radius:10px; background:#0f172a; border:1px solid #1f2937; color:#fff;
  }

  /* Tabella scura come premi */
  .table-wrap{ overflow:auto; border-radius:12px; }
  .table{ width:100%; border-collapse:separate; border-spacing:0; }
  .table thead th{
    text-align:left; font-weight:900; font-size:12px; letter-spacing:.3px;
    color:#9fb7ff; padding:10px 12px;
    background:#0f172a; border-bottom:1px solid #1e293b;
  }
  .table thead th.sortable{ cursor:pointer; user-select:none; }
  .table thead th .arrow{ opacity:.5; font-size:10px; }
  .table tbody td{
    padding:12px; border-bottom:1px solid #122036; color:#e5e7eb; font-size:14px;
    background:linear-gradient(0deg, rgba(255,255,255,.02), rgba(255,255,255,.02));
  }
  .table tbody tr:hover td{ background:rgba(255,255,255,.025); }
  .table tbody tr:last-child td{ border-bottom:0; }

  /* Badge/scritte secondarie */
  .muted{ color:#9ca3af; font-size:12px; }

  /* Modale (coerente) */
  .modal[aria-hidden="true"]{ display:none; } .modal{ position:fixed; inset:0; z-index:60; }
  .modal-open{ overflow:hidden; }
  .modal-backdrop{ position:absolute; inset:0; background:rgba(0,0,0,.5); }
  .modal-card{ position:relative; z-index:61; width:min(760px,96vw); background:var(--c-bg); border:1px solid var(--c-border); border-radius:16px; margin:6vh auto 0; padding:0; box-shadow:0 16px 48px rgba(0,0,0,.5); max-height:86vh; display:flex; flex-direction:column; }
  .modal-head{ display:flex; align-items:center; gap:10px; padding:12px 16px; border-bottom:1px solid var(--c-border); }
  .modal-x{ margin-left:auto; background:transparent; border:0; color:#fff; font-size:24px; cursor:pointer; }
  .modal-body{ padding:16px; overflow:auto; }
  .modal-foot{ display:flex; justify-content:flex-end; gap:8px; padding:12px 16px; border-top:1px solid var(--c-border); }

  /* Input in card/modale (match premi) */
  .input.light{
    width:100%; height:38px; padding:0 12px; border-radius:10px;
    background:#0f172a; border:1px solid #1f2937; color:#fff;
  }
</style>

<main class="pt-page">
  <section class="section">
    <div class="container">
      <h1>Dashboard Punto</h1>

      <div class="card">
        <div class="topbar">
          <div style="display:flex; gap:8px; align-items:center;">
            <input type="search" class="input light searchbox" id="q" placeholder="Cerca username…">
          </div>
          <div id="paging" style="display:flex; gap:8px;">
            <button class="btn btn--outline" id="prev">←</button>
            <span id="pginfo" class="muted"></span>
            <button class="btn btn--outline" id="next">→</button>
          </div>
        </div>
        <div class="table-wrap">
          <table class="table" id="tbl">
            <thead>
            <tr>
              <th class="sortable" data-sort="id">ID <span class="arrow">↕</span></th>
              <th class="sortable" data-sort="username">Username <span class="arrow">↕</span></th>
              <th class="sortable" data-sort="nome">Nome <span class="arrow">↕</span></th>
              <th class="sortable" data-sort="cognome">Cognome <span class="arrow">↕</span></th>
              <th class="sortable" data-sort="coins">Arena Coins <span class="arrow">↕</span></th>
              <th class="sortable" data-sort="status">Stato <span class="arrow">↕</span></th>
              <th>Punto</th>
              <th>Azioni</th>
            </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      </div>

      <!-- Modale Movimenti -->
      <div class="modal" id="mdMov" aria-hidden="true">
        <div class="modal-backdrop" data-close></div>
        <div class="modal-card">
          <div class="modal-head">
            <h3>Movimenti utente</h3>
            <button class="modal-x" data-close>&times;</button>
          </div>
          <div class="modal-body">
            <div class="table-wrap">
              <table class="table" id="tblMov">
                <thead>
                <tr>
                  <th>Data</th>
                  <th>Delta (AC)</th>
                  <th>Motivo</th>
                </tr>
                </thead>
                <tbody></tbody>
              </table>
            </div>
          </div>
          <div class="modal-foot">
            <button class="btn btn--primary" data-close>Chiudi</button>
          </div>
        </div>
      </div>

    </div>
  </section>
</main>

<?php include __DIR__ . '/../../partials/footer.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', ()=>{
  const $ = s=>document.querySelector(s);
  const $$= (s,p=document)=>[...p.querySelectorAll(s)];
  function openM(){ $('#mdMov').setAttribute('aria-hidden','false'); document.body.classList.add('modal-open'); }
  function closeM(){ $('#mdMov').setAttribute('aria-hidden','true'); document.body.classList.remove('modal-open'); }

  let q='', sort='username', dir='asc', page=1, per=10, pages=1, pointUser='';

  async function load(){
    const u = new URL('?action=list_users', location.href);
    u.searchParams.set('search', q);
    u.searchParams.set('sort', sort);
    u.searchParams.set('dir', dir);
    u.searchParams.set('page', page);
    u.searchParams.set('per', per);
    const r = await fetch(u, {cache:'no-store'}); const j = await r.json();
    const tb = $('#tbl tbody'); tb.innerHTML='';
    if (!j.ok) { tb.innerHTML = '<tr><td colspan="8">Errore</td></tr>'; return; }
    pages = j.pages||1; pointUser = j.point_username||'';
    $('#pginfo').textContent = `Pagina ${j.page||1} di ${pages}`;
    $('#prev').disabled = (page<=1);
    $('#next').disabled = (page>=pages);

    j.rows.forEach(row=>{
      const tr=document.createElement('tr');
      tr.innerHTML = `
        <td>${row.user_code ? row.user_code : row.id}</td>
        <td>${row.username||'-'}</td>
        <td>${row.nome||'-'}</td>
        <td>${row.cognome||'-'}</td>
        <td>${Number(row.coins||0).toFixed(2)}</td>
        <td>${row.is_active==1?'Attivo':'Disabilitato'}</td>
        <td>${pointUser||'-'}</td>
        <td><button class="btn btn--outline btn--sm" data-mov="${row.id}">Movimenti</button></td>
      `;
      tb.appendChild(tr);
    });
  }

  // Sort
  $('#tbl thead').addEventListener('click', (e)=>{
    const th=e.target.closest('[data-sort]'); if(!th) return;
    const s=th.getAttribute('data-sort');
    if (sort===s) dir = (dir==='asc'?'desc':'asc'); else { sort=s; dir='asc'; }
    page=1; load();
  });

  // Search
  $('#q').addEventListener('input', e=>{ q=e.target.value.trim(); page=1; load(); });

  // Paging
  $('#prev').addEventListener('click', ()=>{ if (page>1){ page--; load(); } });
  $('#next').addEventListener('click', ()=>{ if (page<pages){ page++; load(); } });

  // Movimenti
  $('#tbl').addEventListener('click', async (e)=>{
    const b=e.target.closest('button[data-mov]'); if(!b) return;
    const uid=b.getAttribute('data-mov');
    const u = new URL('?action=user_movements', location.href); u.searchParams.set('user_id', uid);
    const r = await fetch(u); const j = await r.json();
    const tb = $('#tblMov tbody'); tb.innerHTML='';
    if (!j.ok){ tb.innerHTML='<tr><td colspan="3">Errore</td></tr>'; openM(); return; }
    j.rows.forEach(m=>{
      const dt = m.created_at ? new Date(m.created_at).toLocaleString() : '-';
      const tr=document.createElement('tr');
      tr.innerHTML = `<td>${dt}</td><td>${Number(m.delta||0).toFixed(2)}</td><td>${m.reason||''}</td>`;
      tb.appendChild(tr);
    });
    openM();
  });

  $$('#mdMov [data-close], #mdMov .modal-backdrop').forEach(x=>x.addEventListener('click', closeM));

  load();
});
</script>
