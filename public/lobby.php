<?php
// /public/lobby.php — Lobby tornei (I miei tornei + Tornei in partenza)
require_once __DIR__ . '/../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }

$uid  = (int)($_SESSION['uid'] ?? 0);
$role = $_SESSION['role'] ?? 'USER';
if ($uid <= 0 || !in_array($role, ['USER','PUNTO','ADMIN'], true)) {
  header('Location: /login.php'); exit;
}

/* ============ UTIL ============ */
function json($a){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($a); exit; }
function only_post(){ if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST'){ http_response_code(405); json(['ok'=>false,'error'=>'method']); } }
function columnExists(PDO $pdo, string $table, string $col): bool {
  static $cache = [];
  $k = "$table.$col";
  if (isset($cache[$k])) return $cache[$k];
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $q->execute([$table,$col]);
  return $cache[$k]=(bool)$q->fetchColumn();
}
function firstCol(PDO $pdo, string $table, array $cands, $fallback='NULL'){
  foreach ($cands as $c){ if (columnExists($pdo,$table,$c)) return $c; }
  return $fallback;
}

/* Guess t_players table */
$joinTable = null;
foreach (['tournament_players','tournaments_players','tournament_lives'] as $jt){
  $q=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
  $q->execute([$jt]); if ((int)$q->fetchColumn() > 0) { $joinTable=$jt; break; }
}
if (!$joinTable) { $joinTable='tournament_players'; } // default fallback

/* Column map for tournaments */
$tTable   = 'tournaments';
$tId      = firstCol($pdo,$tTable,['id'],'id');
$tCode    = firstCol($pdo,$tTable,['code','t_code','short_id'], 'NULL');
$tTitle   = firstCol($pdo,$tTable,['title','name'],'NULL');
$tBuyin   = firstCol($pdo,$tTable,['buyin_coins','buyin'],'0');
$tSeats   = firstCol($pdo,$tTable,['seats_total','max_players'],'NULL');
$tLives   = firstCol($pdo,$tTable,['lives_max','max_lives'],'NULL');
$tPool    = firstCol($pdo,$tTable,['prize_pool_coins','pool_coins','prize_coins'],'NULL');
$tLock    = firstCol($pdo,$tTable,['lock_at','close_at','subscription_end','reg_close_at'],'NULL');
$tStatus  = firstCol($pdo,$tTable,['status','state'],'NULL');
$tLeague  = firstCol($pdo,$tTable,['league','subtitle'],'NULL');  // opzionale
$tSeason  = firstCol($pdo,$tTable,['season','season_name'],'NULL'); // opzionale
$tIsGua   = firstCol($pdo,$tTable,['is_guaranteed','guaranteed','has_guarantee'],'NULL');

/* Join-table columns guess */
$jTid = firstCol($pdo,$joinTable,['tournament_id','tid'],'tournament_id');
$jUid = firstCol($pdo,$joinTable,['user_id','uid'],'user_id');

/* Helpers for status label */
function statusLabel(?string $s, ?string $lockIso): string {
  $now = time();
  $lockTs = $lockIso ? strtotime($lockIso) : null;
  $s = strtolower((string)$s);

  // se lock passato → IN CORSO (registrazioni chiuse)
  if ($lockTs && $lockTs <= $now) return 'IN CORSO';

  // se esplicitamente chiuso
  if (in_array($s, ['closed','ended','finished','chiuso','terminato'], true)) return 'CHIUSO';

  // altrimenti aperto
  return 'APERTO';
}

/* ============ ENDPOINTS ============ */
if (isset($_GET['action'])) {
  $a = $_GET['action'];

  /* ---- LIST: miei + in partenza ---- */
  if ($a==='list') {
    header('Content-Type: application/json; charset=utf-8');

    // base select (usa COALESCE per valori)
    $sel = "t.$tId   AS id,
            ".($tCode!=='NULL'  ? "t.$tCode"   : "NULL")." AS code,
            ".($tTitle!=='NULL' ? "t.$tTitle"  : "NULL")." AS title,
            COALESCE(t.$tBuyin,0)  AS buyin,
            ".($tSeats!=='NULL' ? "t.$tSeats"  : "NULL")." AS seats_total,
            ".($tLives!=='NULL' ? "t.$tLives"  : "NULL")." AS lives_max,
            ".($tPool!=='NULL'  ? "t.$tPool"   : "NULL")." AS pool_coins,
            ".($tLock!=='NULL'  ? "t.$tLock"   : "NULL")." AS lock_at,
            ".($tStatus!=='NULL'? "t.$tStatus" : "NULL")." AS status,
            ".($tLeague!=='NULL'? "t.$tLeague" : "NULL")." AS league,
            ".($tSeason!=='NULL'? "t.$tSeason" : "NULL")." AS season,
            ".($tIsGua!=='NULL' ? "t.$tIsGua"  : "NULL")." AS is_guaranteed";

    // COUNT join (posti usati) se seats_total esiste
    $seatsUsedSql = "0";
    if ($tSeats!=='NULL') {
      $seatsUsedSql = "(SELECT COUNT(*) FROM $joinTable jp WHERE jp.$jTid = t.$tId)";
    }

    // Miei tornei: join-table contiene uid
    $sqlMy = "SELECT $sel, $seatsUsedSql AS seats_used
              FROM $tTable t
              WHERE EXISTS (SELECT 1 FROM $joinTable jp WHERE jp.$jUid=? AND jp.$jTid = t.$tId)
              ORDER BY COALESCE(t.$tLock, NOW()) ASC";
    $st=$pdo->prepare($sqlMy); $st->execute([$uid]);
    $my = $st->fetchAll(PDO::FETCH_ASSOC);

    // Tornei in partenza: status aperto e non iscritto
    // Stati considerati "aperti"
    $openWhere = "1=1";
    if ($tStatus!=='NULL') $openWhere .= " AND LOWER(t.$tStatus) IN ('active','open','published','aperto')";
    if ($tLock!=='NULL')   $openWhere .= " AND (t.$tLock IS NULL OR t.$tLock > NOW())";

    $sqlOpen = "SELECT $sel, $seatsUsedSql AS seats_used
                FROM $tTable t
                WHERE $openWhere
                  AND NOT EXISTS (SELECT 1 FROM $joinTable jp WHERE jp.$jUid=? AND jp.$jTid=t.$tId)
                ORDER BY COALESCE(t.$tLock, NOW()) ASC
                LIMIT 200";
    $st=$pdo->prepare($sqlOpen); $st->execute([$uid]);
    $open = $st->fetchAll(PDO::FETCH_ASSOC);

    // Enrich: labels & computed
    $fmt = function($r){
      $r['state']  = statusLabel($r['status'] ?? null, $r['lock_at'] ?? null);
      $r['buyin']  = (float)$r['buyin'];
      $r['pool_coins'] = isset($r['pool_coins']) ? (float)$r['pool_coins'] : null;
      $r['seats_used'] = isset($r['seats_used']) ? (int)$r['seats_used'] : 0;
      $r['seats_total']= isset($r['seats_total']) && $r['seats_total']!==null ? (int)$r['seats_total'] : null;
      $r['lives_max']  = isset($r['lives_max']) && $r['lives_max']!==null ? (int)$r['lives_max'] : null;
      $r['is_guaranteed'] = (int)($r['is_guaranteed'] ?? 0);
      return $r;
    };
    $my   = array_map($fmt, $my);
    $open = array_map($fmt, $open);

    json(['ok'=>true,'my'=>$my,'open'=>$open]);
  }

  /* ---- JOIN (iscrizione) ---- */
  if ($a==='join') {
    only_post();
    $tid = (int)($_POST['tournament_id'] ?? 0);      // id numerico
    $code= trim($_POST['code'] ?? '');               // oppure code

    // carica torneo
    $filter = $tid>0 ? "t.$tId=?" : ($tCode!=='NULL' ? "t.$tCode=?" : "");
    if ($filter===''){ http_response_code(400); json(['ok'=>false,'error'=>'bad_id']); }
    $st=$pdo->prepare("SELECT t.$tId AS id, ".($tTitle!=='NULL'?"t.$tTitle":"NULL")." AS title,
                              COALESCE(t.$tBuyin,0) AS buyin,
                              ".($tSeats!=='NULL'?"t.$tSeats":"NULL")." AS seats_total,
                              ".($tLock!=='NULL'?"t.$tLock":"NULL")." AS lock_at,
                              ".($tStatus!=='NULL'?"t.$tStatus":"NULL")." AS status
                       FROM $tTable t WHERE $filter LIMIT 1");
    $st->execute([$tid>0 ? $tid : $code]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if(!$t) { http_response_code(404); json(['ok'=>false,'error'=>'not_found']); }

    // reg aperte?
    $state = statusLabel($t['status'] ?? null, $t['lock_at'] ?? null);
    if ($state!=='APERTO') { http_response_code(409); json(['ok'=>false,'error'=>'registration_closed']); }

    // già iscritto?
    $st=$pdo->prepare("SELECT 1 FROM $joinTable WHERE $jUid=? AND $jTid=? LIMIT 1");
    $st->execute([$uid, $t['id']]); if ($st->fetchColumn()){ http_response_code(409); json(['ok'=>false,'error'=>'already_joined']); }

    // posti disponibili?
    if (!is_null($t['seats_total']) && (int)$t['seats_total']>0){
      $st=$pdo->prepare("SELECT COUNT(*) FROM $joinTable WHERE $jTid=?");
      $st->execute([$t['id']]); $used = (int)$st->fetchColumn();
      if ($used >= (int)$t['seats_total']) { http_response_code(409); json(['ok'=>false,'error'=>'sold_out']); }
    }

    // saldo sufficiente?
    $buyin = (float)$t['buyin'];
    $st=$pdo->prepare("SELECT COALESCE(coins,0) FROM users WHERE id=?"); $st->execute([$uid]);
    $coins = (float)$st->fetchColumn();
    if ($coins < $buyin) { http_response_code(402); json(['ok'=>false,'error'=>'insufficient_funds']); }

    // transazione: scala saldo + inserisci join + log
    try{
      $pdo->beginTransaction();

      $u = $pdo->prepare("UPDATE users SET coins = coins - ? WHERE id=? AND coins >= ?");
      $u->execute([$buyin, $uid, $buyin]);
      if ($u->rowCount()===0) { throw new Exception('balance_update_failed'); }

      // inserisci join
      if ($joinTable==='tournament_lives') {
        // columns guess per tournament_lives
        $roundCol = columnExists($pdo,'tournament_lives','round') ? 'round' : 'rnd';
        $statusCol= columnExists($pdo,'tournament_lives','status') ? 'status' : 'state';
        $ins=$pdo->prepare("INSERT INTO tournament_lives($jUid,$jTid,$roundCol,$statusCol,created_at) VALUES(?,?,1,'alive',NOW())");
        $ins->execute([$uid,$t['id']]);
      } else {
        $ins=$pdo->prepare("INSERT INTO $joinTable($jUid,$jTid,created_at) VALUES(?,?,NOW())");
        $ins->execute([$uid,$t['id']]);
      }

      // log (se esiste tabella)
      $hasLog = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='points_balance_log'")->fetchColumn();
      if ($hasLog) {
        $l=$pdo->prepare("INSERT INTO points_balance_log(user_id,delta,reason,created_at) VALUES(?,?,?,NOW())");
        $reason = 'Buy-in torneo #'.$t['id'];
        $l->execute([$uid, -$buyin, $reason]);
      }

      $pdo->commit();
    } catch(Throwable $e){
      if($pdo->inTransaction()) $pdo->rollBack();
      http_response_code(500); json(['ok'=>false,'error'=>'join_failed','detail'=>$e->getMessage()]);
    }

    // nuovo saldo
    $st=$pdo->prepare("SELECT COALESCE(coins,0) FROM users WHERE id=?"); $st->execute([$uid]);
    $newCoins = (float)$st->fetchColumn();

    json(['ok'=>true,'new_balance'=>$newCoins]);
  }

  http_response_code(400); json(['ok'=>false,'error'=>'unknown_action']);
}

/* ============ VIEW ============ */
$page_css='/pages-css/admin-dashboard.css';
include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/header_utente.php';
?>
<style>
/* layout */
.lobby-wrap{ max-width:1100px; margin:0 auto; }
.lobby-section{ margin-top:22px; }
.lobby-section h2{ font-size:28px; margin:8px 0 14px; }

/* griglia cards */
.grid{ display:grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap:16px; }
@media (max-width:1100px){ .grid{ grid-template-columns: repeat(3, minmax(0,1fr)); } }
@media (max-width:820px){  .grid{ grid-template-columns: repeat(2, minmax(0,1fr)); } }
@media (max-width:520px){  .grid{ grid-template-columns: 1fr; } }

/* Card blu verticale (leggermente più alta che larga) */
.tcard{
  position:relative;
  background: linear-gradient(160deg, #183052 0%, #0f1d33 100%);
  border:1px solid rgba(255,255,255,.08);
  border-radius:18px;
  color:#fff;
  padding:16px 14px 14px;
  box-shadow: 0 18px 45px rgba(0,0,0,.35);
  cursor:pointer;
  min-height: 260px; /* più alta che larga */
  transition: transform .15s ease, box-shadow .15s ease;
}
.tcard:hover{ transform: translateY(-2px); box-shadow:0 22px 56px rgba(0,0,0,.45); }

.tid{ position:absolute; left:14px; top:14px; font-weight:800; letter-spacing:.5px; opacity:.95; }
.tstate{
  position:absolute; right:14px; top:12px;
  font-size:12px; font-weight:800; letter-spacing:.4px;
  padding:4px 10px; border-radius:9999px; border:1px solid rgba(255,255,255,.25);
  background: rgba(0,0,0,.18);
}
.tstate.open{  border-color: rgba(52,211,153,.45); color:#d1fae5; }
.tstate.live{  border-color: rgba(250,204,21,.55); color:#fef9c3; }
.tstate.end{   border-color: rgba(239,68,68,.45); color:#fee2e2; }

.ttitle{ margin-top:28px; font-size:18px; font-weight:900; }
.tsub{ opacity:.85; font-size:13px; margin-top:2px; }

.row{ display:flex; gap:12px; margin-top:14px; }
.col{ flex:1 1 0; min-width:0; }

.lbl{ font-size:12px; opacity:.85; }
.val{ font-size:16px; font-weight:800; }

.tfoot{
  position:absolute; left:14px; right:14px; bottom:12px;
  display:flex; justify-content:space-between; align-items:center; gap:12px;
  font-size:13px; opacity:.95;
}
.countdown{ font-weight:700; letter-spacing:.4px; }

.empty{ padding:18px; border:1px dashed rgba(255,255,255,.2); border-radius:12px; color:#cbd5e1; }

.modal[aria-hidden="true"]{ display:none; } .modal{ position:fixed; inset:0; z-index:80; }
.modal-backdrop{ position:absolute; inset:0; background:rgba(0,0,0,.55); }
.modal-card{ position:relative; z-index:81; width:min(520px,94vw);
             margin:10vh auto 0; background:var(--c-bg); border:1px solid var(--c-border);
             border-radius:16px; overflow:hidden; box-shadow:0 18px 50px rgba(0,0,0,.5); }
.modal-head{ padding:12px 16px; border-bottom:1px solid var(--c-border); display:flex; align-items:center; gap:8px; }
.modal-body{ padding:16px; }
.modal-foot{ padding:12px 16px; border-top:1px solid var(--c-border); display:flex; justify-content:flex-end; gap:8px; }
</style>

<main class="section">
  <div class="container">
    <div class="lobby-wrap">
      <h1>Lobby tornei</h1>

      <div class="lobby-section" id="secMy">
        <h2>I miei tornei</h2>
        <div class="grid" id="gridMy"></div>
        <div class="empty" id="emptyMy" style="display:none;">Non sei iscritto a nessun torneo.</div>
      </div>

      <div class="lobby-section" id="secOpen">
        <h2>Tornei in partenza</h2>
        <div class="grid" id="gridOpen"></div>
        <div class="empty" id="emptyOpen" style="display:none;">Nessun torneo disponibile al momento.</div>
      </div>
    </div>
  </div>
</main>

<!-- Modal conferma join -->
<div class="modal" id="mdJoin" aria-hidden="true">
  <div class="modal-backdrop" data-close></div>
  <div class="modal-card">
    <div class="modal-head">
      <h3>Iscrizione torneo</h3>
    </div>
    <div class="modal-body">
      <p id="joinTxt">Sei sicuro di volerti iscrivere?</p>
      <small class="muted">Il buy‑in verrà scalato dal tuo saldo in Arena Coins.</small>
    </div>
    <div class="modal-foot">
      <button class="btn btn--outline" data-close>Annulla</button>
      <button class="btn btn--primary" id="btnConfirmJoin">Conferma</button>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', ()=>{
  const $ = s=>document.querySelector(s);
  const $$=(s,p=document)=>[...p.querySelectorAll(s)];

  /* ===== modal helpers ===== */
  function openM(){ $('#mdJoin')?.setAttribute('aria-hidden','false'); }
  function closeM(){ $('#mdJoin')?.setAttribute('aria-hidden','true'); }
  $$('#mdJoin [data-close], #mdJoin .modal-backdrop').forEach(x=>x.addEventListener('click', closeM));

  /* ===== countdown (tutti gli elementi .countdown con data-lock) ===== */
  function tickCountdown(){
    const now = Date.now();
    $$('.countdown').forEach(el=>{
      const lock = parseInt(el.getAttribute('data-lock')||'0', 10);
      if (!lock) { el.textContent=''; return; }
      const diff = Math.max(0, Math.floor(lock - now)/1000);
      const d = Math.floor(diff/86400);
      const h = Math.floor((diff%86400)/3600);
      const m = Math.floor((diff%3600)/60);
      const s = Math.floor(diff%60);
      let out = (d>0? (d+'g '):'') + (h<10?'0':'')+h+':'+(m<10?'0':'')+m+':'+(s<10?'0':'')+s;
      if (diff<=0) out = 'CHIUSO';
      el.textContent = out;
    });
  }
  setInterval(tickCountdown, 1000);

  /* ===== render card ===== */
  function seatsLabel(seats_total, seats_used){
    if (seats_total===null || seats_total===undefined || seats_total<=0) return '∞';
    const left = Math.max(0, seats_total - (seats_used||0));
    return String(left);
  }
  function stateClass(state){
    if (state==='APERTO') return 'open';
    if (state==='IN CORSO') return 'live';
    return 'end';
  }
  function fmtCoins(n){ return (Number(n||0)).toFixed(2) + ' crediti'; }

  function card(t, ctx){ // ctx: 'open' | 'my'
    const d = document.createElement('div');
    d.className = 'tcard';
    d.setAttribute('data-id', t.id);
    if (t.code) d.setAttribute('data-code', t.code);

    const now = Date.now();
    const lockMs = t.lock_at ? (new Date(t.lock_at)).getTime() : 0;

    d.innerHTML = `
      <div class="tid">#${t.code || t.id}</div>
      <div class="tstate ${stateClass(t.state)}">${t.state}</div>
      <div class="ttitle">${escapeHtml(t.title || 'Torneo')}</div>
      ${ (t.league||t.season) ? `<div class="tsub">${escapeHtml(t.league||'')} ${t.league&&t.season?'· ':''}${escapeHtml(t.season||'')}</div>` : '' }

      <div class="row">
        <div class="col">
          <div class="lbl">Buy‑in</div>
          <div class="val">${fmtCoins(t.buyin)}</div>
        </div>
        <div class="col">
          <div class="lbl">Posti</div>
          <div class="val">${seatsLabel(t.seats_total, t.seats_used)}</div>
        </div>
      </div>

      <div class="row">
        <div class="col">
          <div class="lbl">Vite max/utente</div>
          <div class="val">${t.lives_max!=null ? t.lives_max : 'n/d'}</div>
        </div>
        <div class="col">
          <div class="lbl">Crediti in palio</div>
          <div class="val">${t.pool_coins!=null ? fmtCoins(t.pool_coins) : 'n/d'}</div>
        </div>
      </div>

      <div class="tfoot">
        <div class="lbl">Lock</div>
        <div class="countdown" data-lock="${lockMs || 0}">${t.lock_at ? '' : '-'}</div>
      </div>
    `;

    // click behaviour
    if (ctx==='open') {
      d.addEventListener('click', ()=> askJoin(t));
    } else {
      d.addEventListener('click', ()=>{
        // vai alla pagina del torneo (adatta l'URL se diverso)
        const q = t.code ? ('?tid='+encodeURIComponent(t.code)) : ('?id='+encodeURIComponent(t.id));
        window.location.href = '/torneo.php'+q;
      });
    }
    return d;
  }

  function escapeHtml(s){ return (s??'').toString().replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;', "'":'&#039;' }[m])); }

  /* ===== Load lists ===== */
  async function load(){
    const r = await fetch('?action=list',{cache:'no-store'});
    const j = await r.json();
    if (!j.ok){ return; }

    const gMy = $('#gridMy'), gOp = $('#gridOpen');
    gMy.innerHTML=''; gOp.innerHTML='';

    (j.my||[]).forEach(t => gMy.appendChild(card(t,'my')));
    (j.open||[]).forEach(t => gOp.appendChild(card(t,'open')));

    $('#emptyMy').style.display   = (j.my && j.my.length>0) ? 'none':'block';
    $('#emptyOpen').style.display = (j.open && j.open.length>0) ? 'none':'block';

    tickCountdown(); // avvia subito
  }

  /* ===== Join flow ===== */
  let joinTarget = null;
  const joinTxt = $('#joinTxt');
  const btnConfirm = $('#btnConfirmJoin');

  function askJoin(t){
    joinTarget = t;
    joinTxt.innerHTML = `Sei sicuro di volerti iscrivere al torneo <strong>${escapeHtml(t.title||('ID #'+(t.code||t.id)))}</strong>?<br>Buy‑in: <strong>${fmtCoins(t.buyin)}</strong>`;
    openM();
  }

  btnConfirm.addEventListener('click', async ()=>{
    if (!joinTarget) { closeM(); return; }
    btnConfirm.disabled = true;

    try{
      const fd = new URLSearchParams();
      fd.set('tournament_id', String(joinTarget.id));
      const rsp = await fetch('?action=join', { method:'POST', body:fd });
      const txt = await rsp.text();
      let j; try{ j=JSON.parse(txt); } catch(e){ throw new Error('join_non_json'); }
      if (!j.ok){
        let msg = 'Errore iscrizione';
        if (j.error==='already_joined') msg='Sei già iscritto a questo torneo';
        else if (j.error==='insufficient_funds') msg='Saldo insufficiente per il buy‑in';
        else if (j.error==='sold_out') msg='Posti esauriti';
        else if (j.error==='registration_closed') msg='Registrazioni chiuse';
        alert(msg);
        return;
      }

      // refresh liste
      await load();

      // aggiorna pillola saldo (se presente sistema inline attivo)
      document.dispatchEvent(new CustomEvent('refresh-balance'));

      closeM();
    } catch(err){
      console.error(err);
      alert('Errore inatteso durante l’iscrizione');
    } finally {
      btnConfirm.disabled = false;
    }
  });

  // init
  load();
});
</script>
