<?php
// /public/torneo.php — Card riepilogo torneo + Disiscrivi (refund e redirect)
require_once __DIR__ . '/../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }

// Auth base
$uid  = (int)($_SESSION['uid'] ?? 0);
$role = $_SESSION['role'] ?? '';
if ($uid <= 0 || !in_array($role, ['USER','PUNTO','ADMIN'], true)) {
  header('Location: /login.php'); exit;
}

/* ==== helpers compat ==== */
function colExists(PDO $pdo,string $t,string $c):bool{
  static $cache=[]; $k="$t.$c"; if(isset($cache[$k])) return $cache[$k];
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $q->execute([$t,$c]); return $cache[$k]=(bool)$q->fetchColumn();
}
function firstCol(PDO $pdo,string $t,array $cands,string $fallback='NULL'){
  foreach($cands as $c){ if(colExists($pdo,$t,$c)) return $c; } return $fallback;
}
function tableExists(PDO $pdo,string $t):bool{
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
  $q->execute([$t]); return (bool)$q->fetchColumn();
}
function statusLabel(?string $s, ?string $lockIso): string {
  $now=time(); $s=strtolower((string)$s); $ts=$lockIso?strtotime($lockIso):null;
  if(in_array($s,['closed','ended','finished','chiuso','terminato'],true)) return 'CHIUSO';
  if($ts!==null && $ts <= $now) return 'IN CORSO';
  return 'APERTO';
}

/* ==== id/tid ==== */
$tid  = isset($_GET['id'])  ? (int)$_GET['id'] : 0;
$tcode= isset($_GET['tid']) ? trim($_GET['tid']) : '';

/* ==== mapping tournaments ==== */
$tT='tournaments';
$tId    = firstCol($pdo,$tT,['id'],'id');
$tCode  = firstCol($pdo,$tT,['code','tour_code','t_code','short_id'],'NULL');
$tTitle = firstCol($pdo,$tT,['title','name'],'NULL');
$tBuy   = firstCol($pdo,$tT,['buyin_coins','buyin'],'0');
$tPool  = firstCol($pdo,$tT,['prize_pool_coins','pool_coins','prize_coins','prize_pool','montepremi'],'NULL');
$tLMax  = firstCol($pdo,$tT,['lives_max_user','lives_max','max_lives_per_user','lives_user_max'],'NULL');
$tStat  = firstCol($pdo,$tT,['status','state'],'NULL');
$tLock  = firstCol($pdo,$tT,['lock_at','close_at','subscription_end','reg_close_at','start_time'],'NULL');
$tCRnd  = firstCol($pdo,$tT,['current_round','round_current','round'],'NULL');

/* ==== resolve id by code ==== */
if ($tid<=0 && $tcode!=='' && $tCode!=='NULL'){
  $q=$pdo->prepare("SELECT $tId FROM $tT WHERE $tCode=? LIMIT 1");
  $q->execute([$tcode]); $tid=(int)$q->fetchColumn();
}

/* ==== lives table mapping ==== */
$lT = tableExists($pdo,'tournament_lives') ? 'tournament_lives' :
      (tableExists($pdo,'tournaments_lives') ? 'tournaments_lives' : null);
if ($lT){
  $lId   = firstCol($pdo,$lT,['id'],'id');
  $lUid  = firstCol($pdo,$lT,['user_id','uid'],'user_id');
  $lTid  = firstCol($pdo,$lT,['tournament_id','tid'],'tournament_id');
  $lState= firstCol($pdo,$lT,['status','state'],'NULL');
}

/* ==== POST: unjoin (refund) ==== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='unjoin') {
  header('Content-Type: application/json; charset=utf-8');
  $tidPost = (int)($_POST['id'] ?? 0);
  if ($tidPost<=0 || $tidPost!==$tid) { echo json_encode(['ok'=>false,'error'=>'bad_id']); exit; }

  // carica buyin e pool
  $st=$pdo->prepare("SELECT $tId id, COALESCE($tBuy,0) buyin".($tPool!=='NULL'? ", COALESCE($tPool,0) pool": ", 0 pool")." FROM $tT WHERE $tId=?");
  $st->execute([$tid]); $t=$st->fetch(PDO::FETCH_ASSOC);
  if (!$t){ echo json_encode(['ok'=>false,'error'=>'not_found']); exit; }

  // quante vite possiede l'utente su questo torneo
  if (!$lT){ echo json_encode(['ok'=>false,'error'=>'no_lives_table']); exit; }
  $sqlLives="SELECT $lId FROM $lT WHERE $lUid=? AND $lTid=?";
  if ($lState!=='NULL') $sqlLives .= " AND $lState='alive'";
  $st=$pdo->prepare($sqlLives); $st->execute([$uid,$tid]); $ids=array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN));
  $nLives = count($ids);
  if ($nLives===0){ echo json_encode(['ok'=>false,'error'=>'no_lives']); exit; }

  $refund = (float)$t['buyin'] * $nLives;

  try{
    $pdo->beginTransaction();
    // accredito
    $pdo->prepare("UPDATE users SET coins = COALESCE(coins,0) + ? WHERE id=?")->execute([$refund,$uid]);

    // aggiorna montepremi se esiste
    if ($tPool!=='NULL'){
      $pdo->prepare("UPDATE $tT SET $tPool = GREATEST(COALESCE($tPool,0) - ?, 0) WHERE $tId=?")->execute([$refund,$tid]);
    }

    // elimina o marca refunded
    $in=implode(',', array_fill(0,$nLives,'?'));
    if (colExists($pdo,$lT,'refunded_at') && $lState!=='NULL'){
      $pdo->prepare("UPDATE $lT SET $lState='refunded', refunded_at=NOW() WHERE $lId IN ($in)")->execute($ids);
    } else {
      $pdo->prepare("DELETE FROM $lT WHERE $lId IN ($in)")->execute($ids);
    }

    $pdo->commit();
    echo json_encode(['ok'=>true,'refunded'=>$refund,'lives'=>$nLives]);
  }catch(Throwable $e){
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['ok'=>false,'error'=>'db','detail'=>$e->getMessage()]);
  }
  exit;
}

/* ==== Carica torneo per la view ==== */
if ($tid>0){
  $sql="SELECT $tId AS id,"
     . ($tCode!=='NULL' ? "$tCode AS code," : "NULL AS code,")
     . ($tTitle!=='NULL'? "$tTitle AS title," : "NULL AS title,")
     . "COALESCE($tBuy,0) AS buyin,"
     . ($tPool!=='NULL' ? "$tPool AS pool_coins," : "NULL AS pool_coins,")
     . ($tLMax!=='NULL'  ? "$tLMax AS lives_max_user," : "NULL AS lives_max_user,")
     . ($tStat!=='NULL'  ? "$tStat AS status," : "NULL AS status,")
     . ($tCRnd!=='NULL'  ? "$tCRnd AS current_round," : "NULL AS current_round,")
     . ($tLock!=='NULL'  ? "$tLock AS lock_r1" : "NULL AS lock_r1")
     . " FROM $tT WHERE $tId=? LIMIT 1";
  $q=$pdo->prepare($sql); $q->execute([$tid]); $torneo=$q->fetch(PDO::FETCH_ASSOC);
} else {
  $torneo=null;
}

/* ==== vite in gioco ==== */
$livesInPlay = 0;
if ($torneo && $lT){
  if ($lState!=='NULL'){
    $q=$pdo->prepare("SELECT COUNT(*) FROM $lT WHERE $lTid=? AND $lState='alive'");
  } else {
    $q=$pdo->prepare("SELECT COUNT(*) FROM $lT WHERE $lTid=?");
  }
  $q->execute([$tid]); $livesInPlay=(int)$q->fetchColumn();
}

/* ==== valori view ==== */
$title   = $torneo['title']    ?? 'Torneo';
$state   = statusLabel($torneo['status'] ?? null, $torneo['lock_r1'] ?? null);
$pool    = isset($torneo['pool_coins']) ? (float)$torneo['pool_coins'] : null;
$lmax    = isset($torneo['lives_max_user']) ? (int)$torneo['lives_max_user'] : null;
$lockIso = $torneo['lock_r1'] ?? null;
$lockTs  = $lockIso ? strtotime($lockIso)*1000 : 0;
$buyin   = isset($torneo['buyin']) ? (float)$torneo['buyin'] : 0.0;

$page_css='/pages-css/admin-dashboard.css';
include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/header_utente.php';
?>
<style>
/* Card riepilogo */
.twrapper{ max-width: 1100px; margin: 0 auto; }
.tcard{
  position:relative;
  background:linear-gradient(135deg,#1e3a8a 0%, #0f172a 100%);
  border:1px solid rgba(255,255,255,.12);
  border-radius:20px; padding:18px; color:#fff;
  box-shadow:0 18px 60px rgba(0,0,0,.35);
}
.tcard h1{ margin:0; font-size:22px; font-weight:900; letter-spacing:.3px; }
.tstate{ position:absolute; top:14px; right:14px; font-size:12px; font-weight:800; letter-spacing:.4px;
  padding:4px 10px; border-radius:9999px; border:1px solid rgba(255,255,255,.25); background:rgba(0,0,0,.2); }
.tstate.open{ border-color:rgba(52,211,153,.45); color:#d1fae5; }
.tstate.live{ border-color:rgba(250,204,21,.55); color:#fef9c3; }
.tstate.end{  border-color:rgba(239,68,68,.45); color:#fee2e2; }

.kpis{ display:grid; grid-template-columns: repeat(4, 1fr); gap:12px; margin-top:14px; }
.kpi{ background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.10); border-radius:14px; padding:12px; text-align:center; }
.kpi .lbl{ font-size:12px; opacity:.9;}
.kpi .val{ font-size:18px; font-weight:900; letter-spacing:.3px; }
.countdown{ font-variant-numeric: tabular-nums; font-weight:900; }

/* footer azione */
.tcard-foot{ display:flex; justify-content:flex-end; margin-top:12px; }
.btn{ cursor:pointer; border-radius:9999px; padding:8px 14px; border:1px solid rgba(255,255,255,.2); background:rgba(255,255,255,.06); color:#fff; }
.btn:hover{ background:rgba(255,255,255,.1); }

/* Modale base */
.modal[aria-hidden="true"]{ display:none; } .modal{ position:fixed; inset:0; z-index:90;}
.modal-backdrop{ position:absolute; inset:0; background:rgba(0,0,0,.55); }
.modal-card{ position:relative; z-index:91; width:min(520px,94vw); margin:12vh auto 0;
  background:var(--c-bg); border:1px solid var(--c-border); border-radius:16px; overflow:hidden; color:#fff; }
.modal-head{ padding:12px 16px; border-bottom:1px solid var(--c-border); font-weight:800;}
.modal-body{ padding:16px;}
.modal-foot{ padding:12px 16px; border-top:1px solid var(--c-border); display:flex; justify-content:flex-end; gap:8px;}
.btn-ghost{ background:transparent; border:1px solid var(--c-border); }
.btn-primary{ background:linear-gradient(90deg,#2f80ff,#00c2ff); border:0; }
</style>

<main class="section">
  <div class="container">
    <div class="twrapper">
      <div class="tcard">
        <h1><?= htmlspecialchars($title) ?></h1>
        <?php $stClass = $state==='APERTO' ? 'open' : ($state==='IN CORSO' ? 'live' : 'end'); ?>
        <div class="tstate <?= $stClass ?>"><?= htmlspecialchars($state) ?></div>

        <div class="kpis">
          <div class="kpi">
            <div class="lbl">Vite in gioco</div>
            <div class="val"><?= (int)$livesInPlay ?></div>
          </div>
          <div class="kpi">
            <div class="lbl">Montepremi (AC)</div>
            <div class="val"><?= number_format((float)($pool ?? 0), 2, '.', '') ?></div>
          </div>
          <div class="kpi">
            <div class="lbl">Vite max/utente</div>
            <div class="val"><?= $lmax===null ? 'n/d' : (int)$lmax ?></div>
          </div>
          <div class="kpi">
            <div class="lbl">Lock round</div>
            <div class="val countdown" id="lockVal" data-lock="<?= (int)$lockTs ?>">—</div>
          </div>
        </div>

        <!-- Footer con tasto Disiscrivi -->
        <?php if ($torneo): ?>
        <div class="tcard-foot">
          <button id="btnUnjoin" class="btn">Disiscrivi</button>
        </div>
        <?php endif; ?>
      </div>

      <?php if(!$torneo): ?>
        <p class="muted" style="margin-top:12px;">Torneo non trovato.</p>
      <?php endif; ?>
    </div>
  </div>
</main>

<!-- Modale conferma disiscrizione -->
<div class="modal" id="mdUnjoin" aria-hidden="true">
  <div class="modal-backdrop" data-close></div>
  <div class="modal-card">
    <div class="modal-head">Conferma disiscrizione</div>
    <div class="modal-body">
      <p>Sei sicuro di volerti disiscrivere da questo torneo?</p>
      <small class="muted">Ti verranno rimborsati gli Arena Coins pari al numero di vite acquistate per questo torneo.</small>
    </div>
    <div class="modal-foot">
      <button class="btn btn-ghost" data-close>Annulla</button>
      <button class="btn btn-primary" id="doUnjoin">Conferma</button>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>

<script>
// Countdown lock semplice
document.addEventListener('DOMContentLoaded', ()=>{
  const lockEl = document.getElementById('lockVal');
  if (lockEl){
    const ts = Number(lockEl.getAttribute('data-lock')||0);
    if (!ts){ lockEl.textContent='—'; }
    else {
      (function tick(){
        const now = Date.now();
        let diff = Math.floor((ts - now)/1000);
        if (diff <= 0){ lockEl.textContent = 'CHIUSO'; return; }
        const d = Math.floor(diff/86400); diff%=86400;
        const h = String(Math.floor(diff/3600)).padStart(2,'0'); diff%=3600;
        const m = String(Math.floor(diff/60)).padStart(2,'0');  const s = String(diff%60).padStart(2,'0');
        lockEl.textContent = (d>0? d+'g ':'') + h + ':' + m + ':' + s;
        requestAnimationFrame(tick);
      })();
    }
  }

  // Modale helpers
  function showM(id){ const m=document.getElementById(id); if(!m) return; m.setAttribute('aria-hidden','false'); }
  function hideM(id){ const m=document.getElementById(id); if(!m) return; if (m.contains(document.activeElement)) document.activeElement.blur(); m.setAttribute('aria-hidden','true'); }

  const btnUn = document.getElementById('btnUnjoin');
  if (btnUn){
    btnUn.addEventListener('click', ()=> showM('mdUnjoin'));
  }
  document.querySelectorAll('#mdUnjoin [data-close], #mdUnjoin .modal-backdrop').forEach(el=>{
    el.addEventListener('click', ()=> hideM('mdUnjoin'));
  });

  // Conferma disiscrizione → POST al medesimo file, poi redirect lobby
  const doUn = document.getElementById('doUnjoin');
  if (doUn){
    doUn.addEventListener('click', async ()=>{
      doUn.disabled = true;
      try{
        const url = new URL(location.href);
        const fd  = new URLSearchParams({ action:'unjoin', id: (new URLSearchParams(location.search)).get('id') || '0' });
        const rsp = await fetch(url.toString(), {
          method:'POST',
          headers:{ 'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8', 'Accept':'application/json' },
          body: fd.toString(),
          credentials:'same-origin'
        });
        const txt = await rsp.text();
        let j; try{ j = JSON.parse(txt); }catch(e){ alert('Errore disiscrizione (risposta non valida)'); return; }
        if (!j.ok){ 
          alert(j.error==='no_lives' ? 'Non hai vite attive da rimborsare.' : 'Errore disiscrizione'); 
          return; 
        }
        // OK → chiudi modale e vai in lobby
        hideM('mdUnjoin');
        location.href = '/lobby.php';
      }catch(e){
        alert('Errore rete disiscrizione');
      }finally{
        doUn.disabled = false;
      }
    });
  }
});
</script>
