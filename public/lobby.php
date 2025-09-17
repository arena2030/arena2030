<?php
// /public/lobby.php — Lobby tornei (I miei tornei + Tornei in partenza)
require_once __DIR__ . '/../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }

$uid  = (int)($_SESSION['uid'] ?? 0);
$role = $_SESSION['role'] ?? 'USER';
if ($uid <= 0 || !in_array($role, ['USER','PUNTO','ADMIN'], true)) {
  header('Location: /login.php'); exit;
}

/* ===== Helpers ===== */
function json($a){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($a); exit; }
function only_get(){ if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'){ http_response_code(405); json(['ok'=>false,'error'=>'method']); } }
function only_post(){ if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'){ http_response_code(405); json(['ok'=>false,'error'=>'method']); } }
function col_exists(PDO $pdo, string $table, string $col): bool {
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $q->execute([$table,$col]); return (bool)$q->fetchColumn();
}

/* ===== colonne opzionali (autodetect) ===== */
$has_status   = col_exists($pdo, 'tournaments', 'status');
$has_buyin    = col_exists($pdo, 'tournaments', 'buyin') || col_exists($pdo,'tournaments','buyin_coins');
$buyin_col    = col_exists($pdo,'tournaments','buyin_coins') ? 'buyin_coins' : (col_exists($pdo,'tournaments','buyin') ? 'buyin' : null);

$has_prize    = col_exists($pdo, 'tournaments', 'prize_pool') || col_exists($pdo,'tournaments','montepremi');
$prize_col    = col_exists($pdo,'tournaments','prize_pool') ? 'prize_pool' : (col_exists($pdo,'tournaments','montepremi') ? 'montepremi' : null);

$has_gtd      = col_exists($pdo, 'tournaments', 'guaranteed_pool') || col_exists($pdo,'tournaments','is_guaranteed');
$gtd_col      = col_exists($pdo,'tournaments','guaranteed_pool') ? 'guaranteed_pool' : (col_exists($pdo,'tournaments','is_guaranteed') ? 'is_guaranteed' : null);

$has_seats    = col_exists($pdo,'tournaments','seats_total') || col_exists($pdo,'tournaments','max_players');
$seats_col    = col_exists($pdo,'tournaments','seats_total') ? 'seats_total' : (col_exists($pdo,'tournaments','max_players') ? 'max_players' : null);

/* ===== AJAX ===== */
if (isset($_GET['action'])) {
  $a = $_GET['action'];

  // Lista "miei tornei"
  if ($a==='my_list') { only_get();
    $sql = "SELECT t.id, t.tour_code, t.name".
           ($has_status ? ", t.status" : ", 'active' AS status").",
           t.created_at, t.updated_at".
           ($has_buyin ? ", t.`$buyin_col` AS buyin" : ", NULL AS buyin").
           ($has_prize ? ", t.`$prize_col` AS prize_pool" : ", NULL AS prize_pool").
           ($has_gtd   ? ", t.`$gtd_col`   AS guaranteed" : ", NULL AS guaranteed").
           ($has_seats ? ", t.`$seats_col` AS seats_total" : ", NULL AS seats_total").",
           COUNT(tl.id) AS my_lives
           FROM tournaments t
           JOIN tournament_lives tl ON tl.tournament_id = t.id AND tl.user_id = ?
           GROUP BY t.id
           ORDER BY t.created_at DESC";
    $st=$pdo->prepare($sql); $st->execute([$uid]); $rows=$st->fetchAll(PDO::FETCH_ASSOC);

    // calcola iscritti per mostrare posti liberi (se seats_total esiste)
    if ($has_seats) {
      $ids = array_column($rows,'id');
      if ($ids) {
        $in = implode(',', array_fill(0,count($ids),'?'));
        $s2=$pdo->prepare("SELECT tournament_id, COUNT(*) c FROM tournament_lives WHERE tournament_id IN ($in) GROUP BY tournament_id");
        $s2->execute($ids); $map=[]; foreach($s2->fetchAll(PDO::FETCH_ASSOC) as $r){ $map[$r['tournament_id']]=$r['c']; }
        foreach($rows as &$r){ $r['seats_used'] = (int)($map[$r['id']] ?? 0); }
      }
    }

    json(['ok'=>true,'rows'=>$rows]);
  }

// Lista "tornei in partenza" (aperti a cui NON sono iscritto)
if ($a==='open_list') { only_get();
  // Se c'è la colonna status, considera pubblicabili più varianti e scarta chiusi/annullati
  if ($has_status) {
    $where = "WHERE t.status NOT IN ('closed','cancelled','archived')";
  } else {
    $where = "WHERE 1=1";
  }

  $sql = "SELECT t.id, t.tour_code, t.name".
         ($has_status ? ", t.status" : ", 'active' AS status").",
         t.created_at, t.updated_at".
         ($has_buyin ? ", t.`$buyin_col` AS buyin" : ", NULL AS buyin").
         ($has_prize ? ", t.`$prize_col` AS prize_pool" : ", NULL AS prize_pool").
         ($has_gtd   ? ", t.`$gtd_col`   AS guaranteed" : ", NULL AS guaranteed").
         ($has_seats ? ", t.`$seats_col` AS seats_total" : ", NULL AS seats_total")."
         FROM tournaments t
         $where
         AND NOT EXISTS (SELECT 1 FROM tournament_lives tl WHERE tl.tournament_id=t.id AND tl.user_id=?)
         ORDER BY t.created_at DESC";

  $st=$pdo->prepare($sql); $st->execute([$uid]); $rows=$st->fetchAll(PDO::FETCH_ASSOC);

  // calcolo iscritti per posti liberi (se c'è seats_total/max_players)
  if ($has_seats) {
    $ids = array_column($rows,'id');
    if ($ids) {
      $in = implode(',', array_fill(0,count($ids),'?'));
      $s2=$pdo->prepare("SELECT tournament_id, COUNT(*) c FROM tournament_lives WHERE tournament_id IN ($in) GROUP BY tournament_id");
      $s2->execute($ids); $map=[]; foreach($s2->fetchAll(PDO::FETCH_ASSOC) as $r){ $map[$r['tournament_id']]=$r['c']; }
      foreach($rows as &$r){ $r['seats_used'] = (int)($map[$r['id']] ?? 0); }
    }
  }

  json(['ok'=>true,'rows'=>$rows]);
}

  // JOIN (iscrizione torneo)
  if ($a==='join') { only_post();
    $tid = (int)($_POST['tournament_id'] ?? 0);
    if ($tid<=0) json(['ok'=>false,'error'=>'bad_id']);

    try{
      $pdo->beginTransaction();

      // già iscritto?
      $chk=$pdo->prepare("SELECT id FROM tournament_lives WHERE tournament_id=? AND user_id=? LIMIT 1");
      $chk->execute([$tid,$uid]);
      if ($chk->fetchColumn()) { $pdo->rollBack(); json(['ok'=>false,'error'=>'already_joined']); }

      // leggi buyin
      $buyin = 0.0;
      if ($has_buyin) {
        $st=$pdo->prepare("SELECT COALESCE(`$buyin_col`,0) FROM tournaments WHERE id=? LIMIT 1");
        $st->execute([$tid]); $buyin = (float)$st->fetchColumn();
      }

      // posti disponibili (se seats_total c'è)
      if ($has_seats) {
        $st=$pdo->prepare("SELECT COALESCE(`$seats_col`,0) FROM tournaments WHERE id=?");
        $st->execute([$tid]); $seats_total = (int)$st->fetchColumn();
        if ($seats_total>0) {
          $st=$pdo->prepare("SELECT COUNT(*) FROM tournament_lives WHERE tournament_id=?");
          $st->execute([$tid]); $used=(int)$st->fetchColumn();
          if ($used >= $seats_total) { $pdo->rollBack(); json(['ok'=>false,'error'=>'full']); }
        }
      }

      // scala coins se serve
      if ($buyin > 0) {
        $upd=$pdo->prepare("UPDATE users SET coins = coins - ? WHERE id=? AND coins >= ?");
        $upd->execute([$buyin, $uid, $buyin]);
        if ($upd->rowCount()===0) { $pdo->rollBack(); json(['ok'=>false,'error'=>'insufficient_coins']); }

        // log movimento (se esiste la tabella)
        if ($pdo->query("SHOW TABLES LIKE 'points_balance_log'")->fetchColumn()) {
          $pdo->prepare("INSERT INTO points_balance_log (user_id, delta, reason, admin_id) VALUES (?,?,?,NULL)")
              ->execute([$uid, -$buyin, 'TOURNAMENT_BUYIN '.$tid, null]);
        }
      }

      // crea life
      $ins=$pdo->prepare("INSERT INTO tournament_lives (tournament_id, user_id, round, status, created_at, updated_at)
                          VALUES (?,?,?,?, NOW(), NOW())");
      // valori fallback per round/status se le colonne non ci sono
      try { $ins->execute([$tid,$uid,1,'alive']); }
      catch (Throwable $e) {
        // prova senza round/status
        $ins=$pdo->prepare("INSERT INTO tournament_lives (tournament_id, user_id, created_at, updated_at) VALUES (?,?, NOW(), NOW())");
        $ins->execute([$tid,$uid]);
      }

      $pdo->commit();
      json(['ok'=>true]);
    } catch (Throwable $e){
      if ($pdo->inTransaction()) $pdo->rollBack();
      json(['ok'=>false,'error'=>'db_error','detail'=>$e->getMessage()]);
    }
  }

  http_response_code(400); json(['ok'=>false,'error'=>'unknown_action']);
}

/* ===== VIEW ===== */
$page_css='/pages-css/admin-dashboard.css';
include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/header_utente.php';
?>
<style>
  .lobby-wrap{ max-width: 1280px; margin: 0 auto; }
  .section-head{ display:flex; justify-content:space-between; align-items:center; margin:8px 2px 10px; }
  .section-head h2{ margin:0; }
  .grid4{ display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:16px; }
  @media (max-width:1280px){ .grid4{ grid-template-columns:repeat(3,minmax(0,1fr)); } }
  @media (max-width:980px){ .grid4{ grid-template-columns:repeat(2,minmax(0,1fr)); } }
  @media (max-width:640px){ .grid4{ grid-template-columns:1fr; } }

  .tour-card{
    background: var(--c-card);
    border:1px solid var(--c-border);
    border-radius:16px;
    overflow:hidden;
    box-shadow:0 10px 28px rgba(0,0,0,.25);
    display:flex; flex-direction:column;
  }
  .tour-hero{ background: linear-gradient(92deg, #2f80ff 0%, #00c2ff 100%); color:#fff; padding:14px; }
  .tour-name{ font-weight:900; letter-spacing:.6px; text-transform:uppercase; text-align:center; }
  .tour-meta{ display:flex; flex-wrap:wrap; gap:6px; justify-content:center; margin-top:8px; }
  .pill{ display:inline-flex; align-items:center; gap:6px; padding:6px 10px; border-radius:9999px; border:1px solid rgba(255,255,255,.35); background:rgba(0,0,0,.15); font-size:12px; }
  .tour-body{ padding:12px; display:flex; justify-content:space-between; align-items:center; gap:12px; }
  .btn-join{ display:inline-flex; align-items:center; gap:8px; }
  .muted-sm{ color:#9aa4b2; font-size:12px; }
</style>

<main class="section">
  <div class="container">
    <div class="lobby-wrap">

      <!-- Sezione I MIEI TORNEI -->
      <div class="section-head"><h2>I miei tornei</h2></div>
      <div class="grid4" id="myGrid"></div>
      <p id="myInfo" class="muted-sm" style="margin:8px 2px 18px;"></p>

      <!-- Sezione TORNEI IN PARTENZA -->
      <div class="section-head"><h2>Tornei in partenza</h2></div>
      <div class="grid4" id="openGrid"></div>
      <p id="openInfo" class="muted-sm" style="margin:8px 2px;"></p>

    </div>
  </div>
</main>

<?php include __DIR__ . '/../partials/footer.php'; ?>

<!-- Modale conferma join -->
<div class="modal" id="mdJoin" aria-hidden="true">
  <div class="modal-backdrop" data-close></div>
  <div class="modal-card" style="max-width:560px;">
    <div class="modal-head">
      <h3>Iscrizione torneo</h3>
      <button class="modal-x" data-close>&times;</button>
    </div>
    <div class="modal-body">
      <p id="joinText">Sei sicuro di volerti iscrivere a questo torneo?</p>
    </div>
    <div class="modal-foot">
      <button class="btn btn--outline" data-close>Annulla</button>
      <button class="btn btn--primary" id="btnConfirmJoin">Conferma</button>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', ()=>{
  const $=s=>document.querySelector(s); const $$=(s,p=document)=>[...p.querySelectorAll(s)];

  let joinTid = null, joinName = '', joinBuyin = 0;

  function openM(){ $('#mdJoin').setAttribute('aria-hidden','false'); document.body.classList.add('modal-open'); }
  function closeM(){ $('#mdJoin').setAttribute('aria-hidden','true'); document.body.classList.remove('modal-open'); }

  $$('#mdJoin [data-close], #mdJoin .modal-backdrop').forEach(x=>x.addEventListener('click', closeM));

  function pill(label, value){
    return `<span class="pill"><strong>${label}:</strong> ${value}</span>`;
  }
  function fmtMoney(v){ const n=Number(v||0); return n>0 ? n.toFixed(2) : '0.00'; }
  function fmtYN(v){ if (v===null || v===undefined) return 'n/d'; const s=String(v).toLowerCase(); return (s==='1' || s==='yes' || s==='true') ? 'Sì' : (s==='0' || s==='no' || s==='false' ? 'No' : (s||'n/d')); }

  function renderCard(row, isMy){
    const name = (row.name||'Senza nome').toUpperCase();
    const buy  = row.buyin!=null ? fmtMoney(row.buyin) : 'n/d';
    const prize= row.prize_pool!=null ? fmtMoney(row.prize_pool) : 'n/d';
    const gtd  = row.guaranteed!=null ? fmtYN(row.guaranteed) : 'n/d';
    const seatsTot = row.seats_total!=null ? parseInt(row.seats_total,10) : null;
    const seatsUsed= row.seats_used!=null ? parseInt(row.seats_used,10) : null;
    const seatsFree= (seatsTot!=null && seatsUsed!=null) ? Math.max(0, seatsTot - seatsUsed) : 'n/d';

    return `
      <div class="tour-card">
        <div class="tour-hero">
          <div class="tour-name">${name}</div>
          <div class="tour-meta">
            ${pill('Buy-in', buy)} 
            ${pill('Montepremi', prize)} 
            ${pill('Garantito', gtd)} 
            ${pill('Posti liberi', seatsFree)}
          </div>
        </div>
        <div class="tour-body">
          <div class="muted-sm">#${row.tour_code || row.id}</div>
          ${isMy
            ? `<a class="btn btn--primary btn--sm" href="/torneo.php?id=${row.id}">Apri torneo</a>`
            : `<button class="btn btn--primary btn--sm btn-join" data-join="${row.id}" data-name="${row.name||''}" data-buy="${row.buyin||0}">Iscriviti</button>`
          }
        </div>
      </div>`;
  }

  async function loadMy(){
    const r=await fetch('?action=my_list',{cache:'no-store'}); const j=await r.json();
    const grid = $('#myGrid'); grid.innerHTML=''; const info=$('#myInfo');
    if (!j.ok){ grid.innerHTML='<div class="muted-sm">Errore caricamento</div>'; info.textContent=''; return; }
    if (!j.rows || j.rows.length===0){ grid.innerHTML='<div class="muted-sm">Non sei iscritto a nessun torneo.</div>'; info.textContent=''; return; }
    j.rows.forEach(row=> grid.insertAdjacentHTML('beforeend', renderCard(row, true)) );
    info.textContent = `${j.rows.length} tornei iscritti`;
  }

  async function loadOpen(){
    const r=await fetch('?action=open_list',{cache:'no-store'}); const j=await r.json();
    const grid = $('#openGrid'); grid.innerHTML=''; const info=$('#openInfo');
    if (!j.ok){ grid.innerHTML='<div class="muted-sm">Errore caricamento</div>'; info.textContent=''; return; }
    if (!j.rows || j.rows.length===0){ grid.innerHTML='<div class="muted-sm">Nessun torneo disponibile al momento.</div>'; info.textContent=''; return; }
    j.rows.forEach(row=> grid.insertAdjacentHTML('beforeend', renderCard(row, false)) );
    info.textContent = `${j.rows.length} tornei disponibili`;
  }

  // Click su JOIN
  $('#openGrid').addEventListener('click', (e)=>{
    const b=e.target.closest('button[data-join]'); if (!b) return;
    joinTid  = parseInt(b.getAttribute('data-join'),10);
    joinName = b.getAttribute('data-name') || '';
    joinBuyin= Number(b.getAttribute('data-buy') || 0);
    $('#joinText').innerHTML = `Sei sicuro di volerti iscrivere al torneo <strong>${(joinName||'')} </strong>?<br>Buy-in: <strong>${joinBuyin.toFixed(2)}</strong> AC`;
    openM();
  });

  // Conferma JOIN
  $('#btnConfirmJoin').addEventListener('click', async ()=>{
    if (!joinTid){ closeM(); return; }
    const r=await fetch('?action=join', {method:'POST', body:new URLSearchParams({tournament_id: joinTid})});
    const j=await r.json();
    if (!j.ok){
      let msg = 'Errore iscrizione';
      if (j.error==='already_joined') msg='Sei già iscritto a questo torneo';
      if (j.error==='insufficient_coins') msg='Saldo insufficiente per il buy-in';
      if (j.error==='full') msg='Posti esauriti';
      alert(msg);
      closeM(); return;
    }
    closeM();
    // Aggiorna sezioni
    await loadMy();
    await loadOpen();
    // aggiorna pillola saldo (se hai il sistema inline attivo)
    document.dispatchEvent(new CustomEvent('refresh-balance'));
  });

  // INIT
  loadMy();
  loadOpen();
});
</script>
