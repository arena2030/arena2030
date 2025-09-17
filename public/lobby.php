<?php
// /public/lobby.php — Lobby tornei (I miei tornei + Tornei in partenza) — versione con code in alto-sx e countdown in basso-sx
require_once __DIR__ . '/../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }

$uid  = (int)($_SESSION['uid'] ?? 0);
$role = $_SESSION['role'] ?? 'USER';
if ($uid <= 0 || !in_array($role, ['USER','PUNTO','ADMIN'], true)) {
  header('Location: /login.php'); exit;
}

/* ==== utils ==== */
function json($a){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($a); exit; }
function only_post(){ if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST'){ http_response_code(405); json(['ok'=>false,'error'=>'method']); } }
function columnExists(PDO $pdo, string $table, string $col): bool {
  static $cache = [];
  $k="$table.$col"; if(isset($cache[$k])) return $cache[$k];
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $q->execute([$table,$col]);
  return $cache[$k]=(bool)$q->fetchColumn();
}
function firstCol(PDO $pdo, string $table, array $cands, $fallback='NULL'){
  foreach($cands as $c){ if(columnExists($pdo,$table,$c)) return $c; }
  return $fallback;
}
/* Codice random (HEX uppercase) */
function genCode(int $len=8): string {
  $hex = strtoupper(bin2hex(random_bytes(max(4, min(32,$len)))));
  return substr($hex, 0, $len);
}
/* Ritorna nome colonna esistente tra cand, altrimenti null */
function pickColOrNull(PDO $pdo, string $table, array $cands): ?string {
  foreach($cands as $c){ if(columnExists($pdo,$table,$c)) return $c; }
  return null;
}
/* Genera codice univoco per tabella/colonna (se possibile) */
function uniqueCode(PDO $pdo, string $table, string $col, int $len=8, string $prefix=''): string {
  $tries=0;
  do {
    $code = $prefix . genCode($len);
    $q = $pdo->prepare("SELECT 1 FROM `$table` WHERE `$col`=? LIMIT 1");
    $q->execute([$code]);
    $exists = (bool)$q->fetchColumn();
    $tries++;
  } while ($exists && $tries < 10);
  return $code;
}

/* ===== mapping colonne ===== */
$tTable   = 'tournaments';
$tId      = firstCol($pdo,$tTable,['id'],'id');
$tCode    = firstCol($pdo,$tTable,['code','tour_code','t_code','short_id'],'NULL');  // <— ID UNIVOCO ADMIN
$tTitle   = firstCol($pdo,$tTable,['title','name'],'NULL');
$tBuyin   = firstCol($pdo,$tTable,['buyin_coins','buyin'],'0');
$tSeats   = firstCol($pdo,$tTable,['seats_total','max_players'],'NULL');
$tLives   = firstCol($pdo,$tTable,['lives_max','max_lives','lives_max_per_user','max_lives_per_user'],'NULL');
$tPool    = firstCol($pdo,$tTable,['prize_pool_coins','pool_coins','prize_coins','prize_pool','montepremi'],'NULL');
$tLock    = firstCol($pdo,$tTable,['lock_at','close_at','subscription_end','reg_close_at','start_time'],'NULL');
$tStatus  = firstCol($pdo,$tTable,['status','state'],'NULL');
$tLeague  = firstCol($pdo,$tTable,['league','subtitle'],'NULL');
$tSeason  = firstCol($pdo,$tTable,['season','season_name'],'NULL');
$tIsGua   = firstCol($pdo,$tTable,['is_guaranteed','guaranteed','has_guarantee'],'NULL');

/* join table */
$joinTable=null;
foreach(['tournament_players','tournaments_players','tournament_lives'] as $jt){
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
  $q->execute([$jt]); if($q->fetchColumn()){ $joinTable=$jt; break; }
}
if(!$joinTable) $joinTable='tournament_players';
$jTid = firstCol($pdo,$joinTable,['tournament_id','tid'],'tournament_id');
$jUid = firstCol($pdo,$joinTable,['user_id','uid'],'user_id');

function statusLabel(?string $s, ?string $lockIso): string {
  $now=time(); $s=strtolower((string)$s); $ts=$lockIso?strtotime($lockIso):null;
  if(in_array($s,['closed','ended','finished','chiuso','terminato'],true)) return 'CHIUSO';
  if($ts!==null && $ts <= $now) return 'IN CORSO';
  return 'APERTO';
}

/* ===== API ===== */
if (isset($_GET['action'])) {
  $a=$_GET['action'];

  if ($a==='list') {
    header('Content-Type: application/json; charset=utf-8');

    $base = "t.$tId AS id,"
          . ($tCode!=='NULL'  ? "t.$tCode"   : "NULL")." AS code,"
          . ($tTitle!=='NULL' ? "t.$tTitle"  : "NULL")." AS title,"
          . "COALESCE(t.$tBuyin,0) AS buyin,"
          . ($tSeats!=='NULL' ? "t.$tSeats"  : "NULL")." AS seats_total,"
          . ($tLives!=='NULL' ? "t.$tLives"  : "NULL")." AS lives_max,"
          . ($tPool!=='NULL'  ? "t.$tPool"   : "NULL")." AS pool_coins,"
          . ($tLock!=='NULL'  ? "t.$tLock"   : "NULL")." AS lock_at,"
          . ($tStatus!=='NULL'? "t.$tStatus" : "NULL")." AS status,"
          . ($tLeague!=='NULL'? "t.$tLeague" : "NULL")." AS league,"
          . ($tSeason!=='NULL'? "t.$tSeason" : "NULL")." AS season,"
          . ($tIsGua!=='NULL' ? "t.$tIsGua"  : "NULL")." AS is_guaranteed";

    $seatsUsedSql = ($tSeats!=='NULL') ? "(SELECT COUNT(*) FROM $joinTable jp WHERE jp.$jTid=t.$tId)" : "0";

    $st=$pdo->prepare("SELECT $base, $seatsUsedSql AS seats_used
                       FROM $tTable t
                       WHERE EXISTS (SELECT 1 FROM $joinTable jp WHERE jp.$jUid=? AND jp.$jTid=t.$tId)
                       ORDER BY COALESCE(t.$tLock, NOW()) ASC");
    $st->execute([$uid]); $my = $st->fetchAll(PDO::FETCH_ASSOC);

    $where = "1=1";
    if ($tStatus!=='NULL') $where .= " AND LOWER(t.$tStatus) IN ('active','open','published','aperto')";
    if ($tLock!=='NULL')   $where .= " AND (t.$tLock IS NULL OR t.$tLock > NOW())";

    $st=$pdo->prepare("SELECT $base, $seatsUsedSql AS seats_used
                       FROM $tTable t
                       WHERE $where
                         AND NOT EXISTS (SELECT 1 FROM $joinTable jp WHERE jp.$jUid=? AND jp.$jTid=t.$tId)
                       ORDER BY COALESCE(t.$tLock, NOW()) ASC
                       LIMIT 200");
    $st->execute([$uid]); $open = $st->fetchAll(PDO::FETCH_ASSOC);

    $fmt=function($r){
      $r['state']=statusLabel($r['status']??null,$r['lock_at']??null);
      $r['buyin']=(float)$r['buyin'];
      $r['pool_coins']=isset($r['pool_coins'])?(float)$r['pool_coins']:null;
      $r['seats_used']=isset($r['seats_used'])?(int)$r['seats_used']:0;
      $r['seats_total']=isset($r['seats_total'])&&$r['seats_total']!==null?(int)$r['seats_total']:null;
      $r['lives_max']=isset($r['lives_max'])&&$r['lives_max']!==null?(int)$r['lives_max']:null;
      return $r;
    };
    $my=array_map($fmt,$my); $open=array_map($fmt,$open);
    json(['ok'=>true,'my'=>$my,'open'=>$open]);
  }

  if ($a==='join') {
    only_post();
    $tid=(int)($_POST['tournament_id']??0);
    if($tid<=0){ http_response_code(400); json(['ok'=>false,'error'=>'bad_id']); }

    // load tournament
    $st=$pdo->prepare("SELECT t.$tId AS id, ".($tTitle!=='NULL'?"t.$tTitle":"NULL")." AS title,
                              COALESCE(t.$tBuyin,0) AS buyin,
                              ".($tSeats!=='NULL'?"t.$tSeats":"NULL")." AS seats_total,
                              ".($tLock!=='NULL'?"t.$tLock":"NULL")." AS lock_at,
                              ".($tStatus!=='NULL'?"t.$tStatus":"NULL")." AS status,
                              ".($tPool!=='NULL'?"t.$tPool":"NULL")." AS pool_now
                       FROM $tTable t WHERE t.$tId=? LIMIT 1");
    $st->execute([$tid]); $t=$st->fetch(PDO::FETCH_ASSOC);
    if(!$t){ http_response_code(404); json(['ok'=>false,'error'=>'not_found']); }

    // chiusura iscrizioni se lock passato o stato non aperto
    if (statusLabel($t['status']??null,$t['lock_at']??null)!=='APERTO'){
      http_response_code(409); json(['ok'=>false,'error'=>'registration_closed']);
    }

    // già iscritto?
    $st=$pdo->prepare("SELECT 1 FROM $joinTable WHERE $jUid=? AND $jTid=? LIMIT 1");
    $st->execute([$uid,$t['id']]); if($st->fetchColumn()){ http_response_code(409); json(['ok'=>false,'error'=>'already_joined']); }

    // posti disponibili (pre-check soft)
    if(!is_null($t['seats_total']) && (int)$t['seats_total']>0){
      $st=$pdo->prepare("SELECT COUNT(*) FROM $joinTable WHERE $jTid=?");
      $st->execute([$t['id']]); $used=(int)$st->fetchColumn();
      if ($used >= (int)$t['seats_total']) { http_response_code(409); json(['ok'=>false,'error'=>'sold_out']); }
    }

    // fondi utente
    $buyin=(float)$t['buyin'];
    $st=$pdo->prepare("SELECT COALESCE(coins,0) FROM users WHERE id=?"); $st->execute([$uid]); $coins=(float)$st->fetchColumn();
    if ($coins < $buyin){ http_response_code(402); json(['ok'=>false,'error'=>'insufficient_funds']); }

    try{
      $pdo->beginTransaction();

      // lock torneo (per aggiornare pool e contare posti in sicurezza)
      $pdo->prepare("SELECT $tId FROM $tTable WHERE $tId=? FOR UPDATE")->execute([$t['id']]);

      // posti disponibili (re-check hard)
      if(!is_null($t['seats_total']) && (int)$t['seats_total']>0){
        $st=$pdo->prepare("SELECT COUNT(*) FROM $joinTable WHERE $jTid=? FOR UPDATE");
        $st->execute([$t['id']]); $used=(int)$st->fetchColumn();
        if ($used >= (int)$t['seats_total']) { throw new Exception('sold_out'); }
      }

      // scala saldo (condizionato)
      $u=$pdo->prepare("UPDATE users SET coins = coins - ? WHERE id=? AND coins >= ?");
      $u->execute([$buyin,$uid,$buyin]); if($u->rowCount()===0) throw new Exception('balance_update_failed');

      /* === inserimento iscrizione con eventuale codice === */
      $joinCodeCol = pickColOrNull($pdo, $joinTable, ['reg_code','join_code','ticket_code','code']);
      $regCode = $joinCodeCol ? uniqueCode($pdo,$joinTable,$joinCodeCol,8,'J') : null;

      if ($joinTable==='tournament_lives') {
        // join = già una vita (round=1, status='alive'), con eventuale code
        $roundCol  = columnExists($pdo,'tournament_lives','round') ? 'round' : (columnExists($pdo,'tournament_lives','rnd') ? 'rnd' : null);
        $statusCol = columnExists($pdo,'tournament_lives','status') ? 'status' : (columnExists($pdo,'tournament_lives','state') ? 'state' : null);
        $lifeCodeCol = pickColOrNull($pdo,'tournament_lives',['life_code','code']);
        $lifeCode = $lifeCodeCol ? uniqueCode($pdo,'tournament_lives',$lifeCodeCol,8,'L') : null;

        $cols = [$jUid,$jTid];
        $vals = ['?','?'];
        $par  = [$uid,$t['id']];

        if ($roundCol){  $cols[]=$roundCol;  $vals[]='?'; $par[]=1; }
        if ($statusCol){ $cols[]=$statusCol; $vals[]='?'; $par[]='alive'; }
        if ($joinCodeCol){ $cols[]=$joinCodeCol; $vals[]='?'; $par[]=$regCode; }
        if ($lifeCodeCol){ $cols[]=$lifeCodeCol; $vals[]='?'; $par[]=$lifeCode; }

        $cols[]='created_at'; $vals[]='NOW()';

        $sql="INSERT INTO tournament_lives(".implode(',',$cols).") VALUES(".implode(',',$vals).")";
        $pdo->prepare($sql)->execute($par);
      } else {
        // iscrizione su tournament_players (con possibile reg_code)
        $cols = [$jUid,$jTid,'created_at'];
        $vals = ['?','?','NOW()'];
        $par  = [$uid,$t['id']];
        if ($joinCodeCol){ array_splice($cols,2,0,$joinCodeCol); array_splice($vals,2,0,'?'); array_splice($par,2,0,$regCode); }
        $sql="INSERT INTO $joinTable(".implode(',',$cols).") VALUES(".implode(',',$vals).")";
        $pdo->prepare($sql)->execute($par);

        // crea PRIMA VITA se esiste la tabella tournament_lives
        $hasLives = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tournament_lives'");
        $hasLives->execute(); if ($hasLives->fetchColumn()){
          $roundCol  = columnExists($pdo,'tournament_lives','round') ? 'round' : (columnExists($pdo,'tournament_lives','rnd') ? 'rnd' : null);
          $statusCol = columnExists($pdo,'tournament_lives','status') ? 'status' : (columnExists($pdo,'tournament_lives','state') ? 'state' : null);
          $lifeCodeCol = pickColOrNull($pdo,'tournament_lives',['life_code','code']);
          $lifeCode = $lifeCodeCol ? uniqueCode($pdo,'tournament_lives',$lifeCodeCol,8,'L') : null;

          $cols = [$jUid,$jTid];
          $vals = ['?','?'];
          $par  = [$uid,$t['id']];
          if ($roundCol){  $cols[]=$roundCol;  $vals[]='?'; $par[]=1; }
          if ($statusCol){ $cols[]=$statusCol; $vals[]='?'; $par[]='alive'; }
          if ($lifeCodeCol){ $cols[]=$lifeCodeCol; $vals[]='?'; $par[]=$lifeCode; }
          $cols[]='created_at'; $vals[]='NOW()';
          $sql="INSERT INTO tournament_lives(".implode(',',$cols).") VALUES(".implode(',',$vals).")";
          $pdo->prepare($sql)->execute($par);
        }
      }

      // aggiorna MONTEPREMI se la colonna esiste
      if ($tPool !== 'NULL') {
        $pdo->prepare("UPDATE $tTable SET $tPool = COALESCE($tPool,0) + ? WHERE $tId=?")->execute([$buyin, $t['id']]);
      }

      // log movimento (delta negativo) con eventuale codice
      $hasLog = $pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='points_balance_log'")->fetchColumn();
      if ($hasLog){
        $txCol = pickColOrNull($pdo,'points_balance_log',['tx_code','code']);
        $txCode= $txCol ? uniqueCode($pdo,'points_balance_log',$txCol,10,'T') : null;
        $cols=['user_id','delta','reason','created_at'];
        $vals=['?','?','?','NOW()'];
        $par =[$uid,-$buyin,'Buy-in torneo #'.$t['id']];
        if ($txCol){ array_splice($cols,0,0,$txCol); array_splice($vals,0,0,'?'); array_splice($par,0,0,$txCode); }
        $sql="INSERT INTO points_balance_log(".implode(',',$cols).") VALUES(".implode(',',$vals).")";
        $pdo->prepare($sql)->execute($par);
      }

      $pdo->commit();
    }catch(Throwable $e){
      if($pdo->inTransaction()) $pdo->rollBack();
      if ($e->getMessage()==='sold_out'){ http_response_code(409); json(['ok'=>false,'error'=>'sold_out']); }
      http_response_code(500); json(['ok'=>false,'error'=>'join_failed','detail'=>$e->getMessage()]);
    }

    // saldo aggiornato
    $st=$pdo->prepare("SELECT COALESCE(coins,0) FROM users WHERE id=?"); $st->execute([$uid]); $new=(float)$st->fetchColumn();
    json(['ok'=>true,'new_balance'=>$new]);
  }

  http_response_code(400); json(['ok'=>false,'error'=>'unknown_action']);
}

/* ==== VIEW ==== */
$page_css='/pages-css/admin-dashboard.css';
include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/header_utente.php';
?>
<style>
.lobby-wrap{ max-width:1100px; margin:0 auto; }
.lobby-section{ margin-top:22px; }
.lobby-section h2{ font-size:28px; margin:8px 0 14px; }

/* griglia */
.grid{ display:grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap:16px; }
@media (max-width:1100px){ .grid{ grid-template-columns: repeat(3, minmax(0,1fr)); } }
@media (max-width:820px){  .grid{ grid-template-columns: repeat(2, minmax(0,1fr)); } }
@media (max-width:520px){  .grid{ grid-template-columns: 1fr; } }

/* CARD blu verticale */
.tcard{
  position:relative;
  background: linear-gradient(160deg, #1f3a66 0%, #0f223d 100%);
  border:1px solid rgba(255,255,255,.08);
  border-radius:18px; color:#fff;
  padding:16px 14px 14px;
  box-shadow: 0 18px 45px rgba(0,0,0,.35);
  cursor:pointer; min-height: 260px;
  transition: transform .15s ease, box-shadow .15s ease;
}
.tcard:hover{ transform: translateY(-2px); box-shadow:0 22px 56px rgba(0,0,0,.45); }

/* ID e STATO */
.tid{ position:absolute; left:14px; top:12px; font-weight:800; letter-spacing:.5px; opacity:.95; }
.tstate{
  position:absolute; right:14px; top:10px;
  font-size:12px; font-weight:800; letter-spacing:.4px;
  padding:4px 10px; border-radius:9999px; border:1px solid rgba(255,255,255,.25);
  background: rgba(0,0,0,.18);
}
.tstate.open{  border-color: rgba(52,211,153,.45); color:#d1fae5; }
.tstate.live{  border-color: rgba(250,204,21,.55); color:#fef9c3; }
.tstate.end{   border-color: rgba(239,68,68,.45); color:#fee2e2; }

/* Titolo e sottotitolo */
.ttitle{ margin-top:30px; font-size:18px; font-weight:900; }
.tsub{ opacity:.85; font-size:13px; margin-top:2px; }

/* righe info */
.row{ display:flex; gap:12px; margin-top:14px; }
.col{ flex:1 1 0; min-width:0; }
.lbl{ font-size:12px; opacity:.85; }
.val{ font-size:16px; font-weight:800; }

/* countdown in basso a sinistra, SENZA etichetta */
.tfoot{
  position:absolute; left:14px; bottom:12px;
  display:flex; align-items:center; gap:8px;
}
.countdown{ font-weight:800; letter-spacing:.4px; font-variant-numeric: tabular-nums; }

/* messaggi vuoti */
.empty{ padding:18px; border:1px dashed rgba(255,255,255,.2); border-radius:12px; color:#cbd5e1; }

/* modal */
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

      <div class="lobby-section">
        <h2>I miei tornei</h2>
        <div class="grid" id="gridMy"></div>
        <div class="empty" id="emptyMy" style="display:none;">Non sei iscritto a nessun torneo.</div>
      </div>

      <div class="lobby-section">
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
    <div class="modal-head"><h3>Iscrizione torneo</h3></div>
    <div class="modal-body">
      <p id="joinTxt">Sei sicuro di volerti iscrivere?</p>
      <small class="muted">Il buy-in verrà scalato dal tuo saldo.</small>
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
  const $=s=>document.querySelector(s); const $$=(s,p=document)=>[...p.querySelectorAll(s)];

  function esc(s){return (s??'').toString().replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m]));}
  function seatsLabel(total,used){ if(total==null || total<=0) return '∞'; return String(Math.max(0,(total)-(used||0))); }
  function fmtCoins(n){ return Number(n||0).toFixed(2)+' crediti'; }
  function bClass(state){ return state==='APERTO'?'open':(state==='IN CORSO'?'live':'end'); }

  // countdown
  function tick(){
    const now=Date.now();
    $$('.countdown').forEach(el=>{
      const ms=parseInt(el.getAttribute('data-lock')||'0',10); if(!ms){ el.textContent=''; return; }
      let diff=Math.max(0,Math.floor((ms-now)/1000));
      const d=Math.floor(diff/86400); diff%=86400;
      const h=Math.floor(diff/3600);  diff%=3600;
      const m=Math.floor(diff/60);    const s=diff%60;
      el.textContent = (Math.floor((ms-now)/1000)<=0) ? 'CHIUSO' : ((d>0? d+'g ':'')+String(h).padStart(2,'0')+':'+String(m).padStart(2,'0')+':'+String(s).padStart(2,'0'));
    });
  }
  setInterval(tick,1000);

  function card(t,ctx){
    const d=document.createElement('div'); d.className='tcard';
    const lockMs = t.lock_at ? (new Date(t.lock_at)).getTime() : 0;
    d.innerHTML = `
      <div class="tid">#${esc(t.code || t.id)}</div>
      <div class="tstate ${bClass(t.state)}">${t.state}</div>
      <div class="ttitle">${esc(t.title || 'Torneo')}</div>
      ${ (t.league||t.season) ? `<div class="tsub">${esc(t.league||'')}${t.league&&t.season?' · ':''}${esc(t.season||'')}</div>` : '' }

      <div class="row">
        <div class="col"><div class="lbl">Buy-in</div><div class="val">${fmtCoins(t.buyin)}</div></div>
        <div class="col"><div class="lbl">Posti</div><div class="val">${seatsLabel(t.seats_total,t.seats_used)}</div></div>
      </div>
      <div class="row">
        <div class="col"><div class="lbl">Vite max/utente</div><div class="val">${t.lives_max!=null ? t.lives_max : 'n/d'}</div></div>
        <div class="col"><div class="lbl">Montepremi</div><div class="val">${t.pool_coins!=null ? fmtCoins(t.pool_coins) : 'n/d'}</div></div>
      </div>

      <div class="tfoot">
        <div class="countdown" data-lock="${lockMs || 0}"></div>
      </div>
    `;
    if (ctx==='open' && t.state==='APERTO') {
      d.addEventListener('click', ()=>askJoin(t));
    } else if (ctx==='my') {
      d.addEventListener('click', ()=>{
        const q = t.code ? ('?tid='+encodeURIComponent(t.code)) : ('?id='+encodeURIComponent(t.id));
        location.href='/torneo.php'+q;
      });
    }
    return d;
  }

  async function load(){
    const r=await fetch('?action=list',{cache:'no-store'}); const j=await r.json();
    const gMy=$('#gridMy'), gOp=$('#gridOpen'); gMy.innerHTML=''; gOp.innerHTML='';
    if(j.ok){
      (j.my||[]).forEach(t=> gMy.appendChild(card(t,'my')));
      (j.open||[]).forEach(t=> gOp.appendChild(card(t,'open')));
      $('#emptyMy').style.display = (j.my&&j.my.length)?'none':'block';
      $('#emptyOpen').style.display = (j.open&&j.open.length)?'none':'block';
      tick();
    }
  }

  // join flow
  let JT=null;
  const joinTxt=$('#joinTxt');
  $('#btnConfirmJoin').addEventListener('click', doJoin);
  function askJoin(t){
    JT=t; joinTxt.innerHTML=`Iscrizione al torneo <strong>${esc(t.title||('#'+(t.code||t.id)))}</strong><br>Buy-in: <strong>${fmtCoins(t.buyin)}</strong>`;
    $('#mdJoin').setAttribute('aria-hidden','false');
  }
  async function doJoin(){
    if(!JT){ $('#mdJoin').setAttribute('aria-hidden','true'); return; }
    const fd=new URLSearchParams(); fd.set('tournament_id', String(JT.id));
    const rsp=await fetch('?action=join',{method:'POST',body:fd}); const j=await rsp.json();
    if(!j.ok){
      let msg='Errore iscrizione';
      if(j.error==='already_joined') msg='Sei già iscritto';
      if(j.error==='insufficient_funds') msg='Saldo insufficiente';
      if(j.error==='sold_out') msg='Posti esauriti';
      if(j.error==='registration_closed') msg='Registrazioni chiuse';
      alert(msg); $('#mdJoin').setAttribute('aria-hidden','true'); return;
    }
    $('#mdJoin').setAttribute('aria-hidden','true');
    await load();
    document.dispatchEvent(new CustomEvent('refresh-balance'));
  }

  // chiusura modale
  $$('#mdJoin [data-close], #mdJoin .modal-backdrop').forEach(el=>el.addEventListener('click', ()=>$('#mdJoin').setAttribute('aria-hidden','true')));

  load();
});
</script>
