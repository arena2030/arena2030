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
$has_status   = col_exists($pdo,'tournaments','status');

$has_buyin    = col_exists($pdo,'tournaments','buyin_coins') || col_exists($pdo,'tournaments','buyin');
$buyin_col    = col_exists($pdo,'tournaments','buyin_coins') ? 'buyin_coins'
             : (col_exists($pdo,'tournaments','buyin') ? 'buyin' : null);

$has_prize    = col_exists($pdo,'tournaments','prize_pool') || col_exists($pdo,'tournaments','montepremi');
$prize_col    = col_exists($pdo,'tournaments','prize_pool') ? 'prize_pool'
             : (col_exists($pdo,'tournaments','montepremi') ? 'montepremi' : null);

$has_gtd      = col_exists($pdo,'tournaments','guaranteed_pool') || col_exists($pdo,'tournaments','is_guaranteed');
$gtd_col      = col_exists($pdo,'tournaments','guaranteed_pool') ? 'guaranteed_pool'
             : (col_exists($pdo,'tournaments','is_guaranteed') ? 'is_guaranteed' : null);

$has_seats    = col_exists($pdo,'tournaments','seats_total') || col_exists($pdo,'tournaments','max_players');
$seats_col    = col_exists($pdo,'tournaments','seats_total') ? 'seats_total'
             : (col_exists($pdo,'tournaments','max_players') ? 'max_players' : null);

$has_livesmax = col_exists($pdo,'tournaments','lives_max_per_user') || col_exists($pdo,'tournaments','max_lives_per_user');
$lives_col    = col_exists($pdo,'tournaments','lives_max_per_user') ? 'lives_max_per_user'
             : (col_exists($pdo,'tournaments','max_lives_per_user') ? 'max_lives_per_user' : null);

$has_lock     = col_exists($pdo,'tournaments','lock_at') || col_exists($pdo,'tournaments','lock_time')
             || col_exists($pdo,'tournaments','registration_lock_at') || col_exists($pdo,'tournaments','reg_lock_at')
             || col_exists($pdo,'tournaments','start_time');
$lock_col     = col_exists($pdo,'tournaments','lock_at') ? 'lock_at'
             : (col_exists($pdo,'tournaments','lock_time') ? 'lock_time'
             : (col_exists($pdo,'tournaments','registration_lock_at') ? 'registration_lock_at'
             : (col_exists($pdo,'tournaments','reg_lock_at') ? 'reg_lock_at'
             : (col_exists($pdo,'tournaments','start_time') ? 'start_time' : null))));

/* ===== AJAX ===== */
if (isset($_GET['action'])) {
  $a = $_GET['action'];

  // Lista "i miei tornei"
  if ($a==='my_list') { only_get();
    $sql = "SELECT t.id, t.tour_code, t.name".
           ($has_status ? ", t.status" : ", 'active' AS status").",
           t.created_at, t.updated_at".
           ($has_buyin ? ", t.`$buyin_col` AS buyin" : ", NULL AS buyin").
           ($has_prize ? ", t.`$prize_col` AS prize_pool" : ", NULL AS prize_pool").
           ($has_gtd   ? ", t.`$gtd_col`   AS guaranteed" : ", NULL AS guaranteed").
           ($has_seats ? ", t.`$seats_col` AS seats_total" : ", NULL AS seats_total").
           ($has_livesmax ? ", t.`$lives_col` AS lives_max_user" : ", NULL AS lives_max_user").
           ($has_lock ? ", t.`$lock_col` AS lock_at" : ", NULL AS lock_at").",
           COUNT(tl.id) AS my_lives
           FROM tournaments t
           JOIN tournament_lives tl ON tl.tournament_id = t.id AND tl.user_id = ?
           GROUP BY t.id
           ORDER BY t.created_at DESC";
    $st=$pdo->prepare($sql); $st->execute([$uid]); $rows=$st->fetchAll(PDO::FETCH_ASSOC);

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
    // mostra tutto tranne chiusi/annullati/archiviati
    $where = $has_status ? "WHERE t.status NOT IN ('closed','cancelled','archived')" : "WHERE 1=1";

    $sql = "SELECT t.id, t.tour_code, t.name".
           ($has_status ? ", t.status" : ", 'active' AS status").",
           t.created_at, t.updated_at".
           ($has_buyin ? ", t.`$buyin_col` AS buyin" : ", NULL AS buyin").
           ($has_prize ? ", t.`$prize_col` AS prize_pool" : ", NULL AS prize_pool").
           ($has_gtd   ? ", t.`$gtd_col`   AS guaranteed" : ", NULL AS guaranteed").
           ($has_seats ? ", t.`$seats_col` AS seats_total" : ", NULL AS seats_total").
           ($has_livesmax ? ", t.`$lives_col` AS lives_max_user" : ", NULL AS lives_max_user").
           ($has_lock ? ", t.`$lock_col` AS lock_at" : ", NULL AS lock_at")."
           FROM tournaments t
           $where
           AND NOT EXISTS (SELECT 1 FROM tournament_lives tl WHERE tl.tournament_id=t.id AND tl.user_id=?)
           ORDER BY t.created_at DESC";
    $st=$pdo->prepare($sql); $st->execute([$uid]); $rows=$st->fetchAll(PDO::FETCH_ASSOC);

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

  // JOIN (iscrizione torneo) → clic sulla card open
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

      // posti disponibili (se seats_total esiste)
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

        // log (se tabella esiste)
        if ($pdo->query("SHOW TABLES LIKE 'points_balance_log'")->fetchColumn()) {
          $pdo->prepare("INSERT INTO points_balance_log (user_id, delta, reason, admin_id) VALUES (?,?,?,NULL)")
              ->execute([$uid, -$buyin, 'TOURNAMENT_BUYIN '.$tid, null]);
        }
      }

      // crea life
      $ins=$pdo->prepare("INSERT INTO tournament_lives (tournament_id, user_id, round, status, created_at, updated_at)
                          VALUES (?,?,?,?, NOW(), NOW())");
      try { $ins->execute([$tid,$uid,1,'alive']); }
      catch (Throwable $e) {
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
  .lobby-wrap{ max-width: 1080px; margin: 0 auto; }

  .section-head{ display:flex; justify-content:space-between; align-items:center; margin:8px 2px 10px; }
  .section-head h2{ margin:0; }

  .grid4{ display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:16px; }
  @media (max-width:1200px){ .grid4{ grid-template-columns:repeat(3,minmax(0,1fr)); } }
  @media (max-width:900px){ .grid4{ grid-template-columns:repeat(2,minmax(0,1fr)); } }
  @media (max-width:620px){ .grid4{ grid-template-columns:1fr; } }

  /* Card verticale */
  .tour-card{
    display:flex; flex-direction:column;
    min-height: 360px;
    background: var(--c-card);
    border:1px solid var(--c-border);
    border-radius:16px;
    overflow:hidden;
    box-shadow:0 10px 28px rgba(0,0,0,.25);
    cursor:pointer; /* la card è cliccabile */
  }
  .tour-hero{
    background: linear-gradient(92deg, #2f80ff 0%, #00c2ff 100%);
    color:#fff; padding:14px 14px 10px;
    display:grid; grid-template-columns:1fr auto; grid-gap:8px; align-items:center;
  }
  .tour-id{ font-weight:800; font-size:12px; opacity:.95; }
  .tour-badge{ border:1px solid rgba(255,255,255,.35); padding:4px 8px; border-radius:9999px; font-size:12px; font-weight:800; background:rgba(0,0,0,.18); }
  .tour-name{ grid-column:1 / -1; text-align:center; font-weight:900; letter-spacing:.6px; text-transform:uppercase; font-size:18px; }

  .tour-body{ flex:1; padding:14px; display:flex; flex-direction:column; gap:12px; justify-content:space-between; }
  .meta-col{ display:grid; grid-template-columns:1fr; gap:8px; }
  .pill{
    display:flex; align-items:center; justify-content:space-between;
    gap:10px; padding:8px 10px; border-radius:12px;
    border:1px solid var(--c-border);
    background:rgba(255,255,255,.04);
    font-size:13px;
  }
  .pill b{ font-weight:700; }

  .lock{ display:flex; align-items:center; gap:8px; font-weight:800; letter-spacing:.3px; }
  .lock .t{ font-variant-numeric: tabular-nums; }

  .muted-sm{ color:#9aa4b2; font-size:12px; }
</style>

<main class="section">
  <div class="container">
    <div class="lobby-wrap">

      <!-- I MIEI TORNEI -->
      <div class="section-head"><h2>I miei tornei</h2></div>
      <div class="grid4" id="myGrid"></div>
      <p id="myInfo" class="muted-sm" style="margin:8px 2px 18px;"></p>

      <!-- TORNEI IN PARTENZA -->
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

  function fmtMoney(v){ const n=Number(v||0); return n>0 ? n.toFixed(2) : '0.00'; }
  function fmtYN(v){ if (v===null || v===undefined) return 'n/d'; const s=String(v).toLowerCase(); return (s==='1' || s==='yes' || s==='true') ? 'Sì' : (s==='0' || s==='no' || s==='false' ? 'No' : (s||'n/d')); }

  function computeBadge(row){
    // badge prioritario da status; se non presente, usa lock: futuro = APERTO, passato = IN CORSO
    const st = (row.status || '').toLowerCase();
    if (st==='closed' || st==='cancelled' || st==='archived') return 'CHIUSO';
    if (st==='active' || st==='open' || st==='published') return 'APERTO';
    if (row.lock_at){
      const ts = Date.parse(row.lock_at);
      if (Number.isFinite(ts)){
        return (ts > Date.now()) ? 'APERTO' : 'IN CORSO';
      }
    }
    return st ? st.toUpperCase() : 'APERTO';
  }

  function seatsFreeFrom(row){
    const seatsTot = (row.seats_total==null ? null : parseInt(row.seats_total,10));
    const seatsUsed= (row.seats_used==null ? null : parseInt(row.seats_used,10));
    if (seatsTot==null || seatsTot<=0) return '∞';
    const used = seatsUsed!=null ? seatsUsed : 0;
    return String(Math.max(0, seatsTot - used));
  }

  function renderCard(row, myCard){
    const name  = (row.name || 'Senza nome').toUpperCase();
    const buy   = row.buyin!=null ? fmtMoney(row.buyin) : 'n/d';
    const prize = row.prize_pool!=null ? fmtMoney(row.prize_pool) : 'n/d';
    const gtd   = row.guaranteed!=null ? fmtYN(row.guaranteed) : 'n/d';
    const seatsFree = seatsFreeFrom(row);
    const livesMax  = (row.lives_max_user!=null ? String(row.lives_max_user) : 'n/d');
    const badge = computeBadge(row);
    const lockRaw = row.lock_at || null;
    const lockAttr = lockRaw ? `data-lock="${lockRaw}"` : '';

    return `
      <div class="tour-card" ${
        myCard
          ? `data-open="/torneo.php?id=${row.id}"`
          : (badge==='APERTO' ? `data-join="${row.id}" data-name="${row.name||''}" data-buy="${row.buyin||0}"` : '')
      }>
        <div class="tour-hero">
          <div class="tour-id">#${row.tour_code || row.id}</div>
          <div class="tour-badge">${badge}</div>
          <div class="tour-name">${name}</div>
        </div>

        <div class="tour-body">
          <div class="meta-col">
            <div class="pill"><b>Buy-in</b><span>${buy}</span></div>
            <div class="pill"><b>Posti</b><span>${seatsFree}</span></div>
            <div class="pill"><b>Vite max/utente</b><span>${livesMax}</span></div>
            <div class="pill"><b>Arena Coins in palio</b><span>${prize}</span></div>
            <div class="pill"><b>Garantito</b><span>${gtd}</span></div>
          </div>

          <div class="lock" ${lockAttr}>
            <span>Lock:</span><span class="t">—:—:—</span>
          </div>
        </div>
      </div>`;
  }

  // ===== Countdown lock =====
  let lockTickId = null;
  function startLockTick(){
    if (lockTickId) clearInterval(lockTickId);
    function tick(){
      const now = Date.now();
      document.querySelectorAll('.tour-body .lock[data-lock]').forEach(el=>{
        const t = el.getAttribute('data-lock'); if (!t) return;
        const ts = Date.parse(t);
        const span = el.querySelector('.t');
        if (!Number.isFinite(ts)){ span && (span.textContent='—'); return; }
        let diff = Math.floor((ts - now)/1000);
        if (diff <= 0){ span && (span.textContent='CHIUSO'); return; }
        const d = Math.floor(diff/86400); diff%=86400;
        const h = Math.floor(diff/3600);  diff%=3600;
        const m = Math.floor(diff/60);
        const s = diff%60;
        span && (span.textContent = (d>0? d+'g ' : '') + `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`);
      });
    }
    tick(); lockTickId = setInterval(tick, 1000);
  }

  async function loadMy(){
    const r=await fetch('?action=my_list',{cache:'no-store'}); const j=await r.json();
    const grid = $('#myGrid'); grid.innerHTML=''; const info=$('#myInfo');
    if (!j.ok){ grid.innerHTML='<div class="muted-sm">Errore caricamento</div>'; info.textContent=''; return; }
    if (!j.rows || j.rows.length===0){ grid.innerHTML='<div class="muted-sm">Non sei iscritto a nessun torneo.</div>'; info.textContent=''; return; }
    j.rows.forEach(row=> grid.insertAdjacentHTML('beforeend', renderCard(row, true)) );
    info.textContent = `${j.rows.length} tornei iscritti`;
    startLockTick();
  }

  async function loadOpen(){
    const r=await fetch('?action=open_list',{cache:'no-store'}); const j=await r.json();
    const grid = $('#openGrid'); grid.innerHTML=''; const info=$('#openInfo');
    if (!j.ok){ grid.innerHTML='<div class="muted-sm">Errore caricamento</div>'; info.textContent=''; return; }
    if (!j.rows || j.rows.length===0){ grid.innerHTML='<div class="muted-sm">Nessun torneo disponibile al momento.</div>'; info.textContent=''; return; }
    j.rows.forEach(row=> grid.insertAdjacentHTML('beforeend', renderCard(row, false)) );
    info.textContent = `${j.rows.length} tornei disponibili`;
    startLockTick();
  }

  // Clic card: join (aperte) o apri torneo (mie)
  document.addEventListener('click', (e)=>{
    const card = e.target.closest('.tour-card'); if (!card) return;
    const openHref = card.getAttribute('data-open');
    const joinId   = card.getAttribute('data-join');
    if (openHref){ location.href = openHref; return; }
    if (joinId){
      joinTid  = parseInt(joinId,10);
      joinName = card.getAttribute('data-name') || '';
      joinBuyin= Number(card.getAttribute('data-buy') || 0);
      $('#joinText').innerHTML = `Sei sicuro di volerti iscrivere al torneo <strong>${(joinName||'')}</strong>?<br>Buy-in: <strong>${joinBuyin.toFixed(2)}</strong> AC`;
      openM();
    }
  });

  // Conferma JOIN
  $('#btnConfirmJoin').addEventListener('click', async ()=>{
    if (!joinTid){ closeM(); return; }
    const r=await fetch('?action=join', {method:'POST', body:new URLSearchParams({tournament_id: joinTid})});
    const j=await r.json();
    if (!j.ok){
      let msg='Errore iscrizione';
      if (j.error==='already_joined') msg='Sei già iscritto a questo torneo';
      if (j.error==='insufficient_coins') msg='Saldo insufficiente per il buy-in';
      if (j.error==='full') msg='Posti esauriti';
      alert(msg); closeM(); return;
    }
    closeM();
    await loadMy(); await loadOpen();
    document.dispatchEvent(new CustomEvent('refresh-balance'));
  });

  // INIT
  loadMy(); loadOpen();
});
</script>
