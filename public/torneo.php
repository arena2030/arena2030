<?php
// /public/torneo.php — Solo card riepilogo torneo (nessun bottone/azione)
require_once __DIR__ . '/../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }

// Auth base
$uid  = (int)($_SESSION['uid'] ?? 0);
$role = $_SESSION['role'] ?? '';
if ($uid <= 0 || !in_array($role, ['USER','PUNTO','ADMIN'], true)) {
  header('Location: /login.php'); exit;
}

// ==== Helpers minimi (compatibilità colonne/tabella) ====
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

// ==== Leggi id/tid dalla query ====
$tid  = isset($_GET['id'])  ? (int)$_GET['id'] : 0;
$tcode= isset($_GET['tid']) ? trim($_GET['tid']) : '';

// ==== Mapping tabella tornei ====
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

// Risolvi id da code se serve
if ($tid<=0 && $tcode!=='' && $tCode!=='NULL'){
  $q=$pdo->prepare("SELECT $tId FROM $tT WHERE $tCode=? LIMIT 1");
  $q->execute([$tcode]); $tid=(int)$q->fetchColumn();
}

// Carica torneo
if ($tid<=0){
  $torneo=null;
}else{
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
}

// Conta vite in gioco (alive) — tabella compatibile
$livesInPlay = 0;
if ($torneo){
  // individua tabella vite
  $lT = tableExists($pdo,'tournament_lives') ? 'tournament_lives' :
        (tableExists($pdo,'tournaments_lives') ? 'tournaments_lives' : null);
  if ($lT){
    $lTid  = firstCol($pdo,$lT,['tournament_id','tid'],'tournament_id');
    $lState= firstCol($pdo,$lT,['status','state'],'NULL');
    if ($lState!=='NULL'){
      $q=$pdo->prepare("SELECT COUNT(*) FROM $lT WHERE $lTid=? AND $lState='alive'");
    }else{
      $q=$pdo->prepare("SELECT COUNT(*) FROM $lT WHERE $lTid=?");
    }
    $q->execute([$tid]); $livesInPlay=(int)$q->fetchColumn();
  }
}

// Prepara valori per la card
$title   = $torneo['title']    ?? 'Torneo';
$state   = statusLabel($torneo['status'] ?? null, $torneo['lock_r1'] ?? null);
$pool    = isset($torneo['pool_coins']) ? (float)$torneo['pool_coins'] : null;
$lmax    = isset($torneo['lives_max_user']) ? (int)$torneo['lives_max_user'] : null;
$lockIso = $torneo['lock_r1'] ?? null;
$lockTs  = $lockIso ? strtotime($lockIso)*1000 : 0;

$page_css='/pages-css/admin-dashboard.css';
include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/header_utente.php';
?>
<style>
/* Card riepilogo minimal */
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
</style>

<main class="section">
  <div class="container">
    <div class="twrapper">
      <div class="tcard">
        <h1><?= htmlspecialchars($title) ?></h1>
        <?php
          $stClass = $state==='APERTO' ? 'open' : ($state==='IN CORSO' ? 'live' : 'end');
        ?>
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
      </div>

      <?php if(!$torneo): ?>
        <p class="muted" style="margin-top:12px;">Torneo non trovato.</p>
      <?php endif; ?>

    </div>
  </div>
</main>

<?php include __DIR__ . '/../partials/footer.php'; ?>

<script>
// Countdown lock semplice (se presente)
document.addEventListener('DOMContentLoaded', ()=>{
  const el = document.getElementById('lockVal');
  if (!el) return;
  const ts = Number(el.getAttribute('data-lock')||0);
  if (!ts){ el.textContent='—'; return; }

  function tick(){
    const now = Date.now();
    let diff = Math.floor((ts - now)/1000);
    if (diff <= 0){ el.textContent = 'CHIUSO'; return; }
    const d = Math.floor(diff/86400); diff%=86400;
    const h = String(Math.floor(diff/3600)).padStart(2,'0'); diff%=3600;
    const m = String(Math.floor(diff/60)).padStart(2,'0');
    const s = String(diff%60).padStart(2,'0');
    el.textContent = (d>0? d+'g ':'') + h + ':' + m + ':' + s;
    requestAnimationFrame(tick);
  }
  tick();
});
</script>
