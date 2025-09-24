<?php
// /public/flash/torneo.php — VIEW Flash, layout identico al torneo normale (con fallback DB)

if (session_status()===PHP_SESSION_NONE) { session_start(); }
define('APP_ROOT', dirname(__DIR__, 2)); // /app
require_once APP_ROOT . '/partials/db.php';
require_once APP_ROOT . '/partials/csrf.php';
$CSRF = htmlspecialchars(csrf_token(), ENT_QUOTES);

/* ===== Auth ===== */
$uid  = (int)($_SESSION['uid'] ?? 0);
$role = $_SESSION['role'] ?? '';
if ($uid <= 0 || !in_array($role, ['USER','PUNTO','ADMIN'], true)) {
  header('Location: /login.php'); exit;
}

/* ==== helpers colonne dinamiche (per eventi) ==== */
function col_exists(PDO $pdo, string $table, string $col): bool {
  static $cache = [];
  $k="$table.$col";
  if(isset($cache[$k])) return $cache[$k];
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $q->execute([$table,$col]);
  return $cache[$k]=(bool)$q->fetchColumn();
}

/* ===== Pre-caricamento per hero + vite + eventi ===== */
$pre = [
  'found' => false, 'id' => null, 'code' => null, 'name' => null,
  'buyin' => 0.0, 'lives_max_user' => null, 'lock_at' => null,
  'guaranteed_prize' => 0.0, 'buyin_to_prize_pct' => 0.0,
  'pool' => null, 'players' => null
];
$preLives = [];
$preEvents = [1=>[],2=>[],3=>[]];

try{
  $code = isset($_GET['code']) ? trim($_GET['code']) : null;
  $tid  = isset($_GET['id'])   ? (int)$_GET['id']     : 0;

  if ($code || $tid){
    // Torneo
    $st = $pdo->prepare("SELECT id, code, name, buyin, lives_max_user, lock_at,
                                COALESCE(guaranteed_prize,0) AS guaranteed_prize,
                                COALESCE(buyin_to_prize_pct,0) AS buyin_to_prize_pct,
                                status
                         FROM tournament_flash
                         WHERE ".($code ? "code=?" : "id=?")." LIMIT 1");
    $st->execute([$code ?: $tid]);
    if ($row = $st->fetch(PDO::FETCH_ASSOC)){
      $pre['found']=true;
      $pre['id']   =(int)$row['id'];
      $pre['code'] =$row['code'];
      $pre['name'] =$row['name'];
      $pre['buyin']=(float)$row['buyin'];
      $pre['lives_max_user']= $row['lives_max_user']!==null ? (int)$row['lives_max_user'] : null;
      $pre['lock_at']=$row['lock_at'];
      $pre['guaranteed_prize']=(float)$row['guaranteed_prize'];
      $pre['buyin_to_prize_pct']=(float)$row['buyin_to_prize_pct'];
      $tStatus = strtolower((string)($row['status'] ?? 'published'));

      // Pool dinamico = max(garantito, buyin * #vite * %)
      $stc = $pdo->prepare("SELECT COUNT(*) FROM tournament_flash_lives WHERE tournament_id=?");
      $stc->execute([$pre['id']]);
      $livesCnt = (int)$stc->fetchColumn();
      $pct = $pre['buyin_to_prize_pct']; if($pct>0 && $pct<=1) $pct*=100.0; $pct=max(0.0,min(100.0,$pct));
      $poolFrom = round($pre['buyin'] * $livesCnt * ($pct/100.0), 2);
      $pre['pool'] = max($poolFrom, $pre['guaranteed_prize']);

      // Partecipanti
      $stp = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM tournament_flash_lives WHERE tournament_id=?");
      $stp->execute([$pre['id']]); $players = (int)$stp->fetchColumn();
      if ($players<=0){
        $stp2 = $pdo->prepare("SELECT COUNT(*) FROM tournament_flash_users WHERE tournament_id=?");
        $stp2->execute([$pre['id']]); $players = (int)$stp2->fetchColumn();
      }
      $pre['players'] = $players;

      // === Auto-heal: se l'utente è iscritto ma non ha vite, crea la prima vita (alive, round=1)
      $stU = $pdo->prepare("SELECT 1 FROM tournament_flash_users WHERE tournament_id=? AND user_id=? LIMIT 1");
      $stU->execute([$pre['id'],$uid]); $isJoined = (bool)$stU->fetchColumn();

      $stL = $pdo->prepare("SELECT COUNT(*) FROM tournament_flash_lives WHERE tournament_id=? AND user_id=?");
      $stL->execute([$pre['id'],$uid]); $myLivesCnt=(int)$stL->fetchColumn();

      $lockTs = $pre['lock_at'] ? strtotime($pre['lock_at']) : null;
      $lockPassed = ($lockTs && time() >= $lockTs);

      if ($isJoined && $myLivesCnt===0 && !$lockPassed && ($pre['lives_max_user'] ?? 1) > 0){
        try{
          $ins=$pdo->prepare("INSERT INTO tournament_flash_lives (tournament_id,user_id,life_no,status,`round`) VALUES (?,?,?,?,1)");
          $ins->execute([$pre['id'],$uid,1,'alive']);
          // aggiorna conteggi
          $stc->execute([$pre['id']]); $livesCnt = (int)$stc->fetchColumn();
          $poolFrom = round($pre['buyin'] * $livesCnt * ($pct/100.0), 2);
          $pre['pool'] = max($poolFrom, $pre['guaranteed_prize']);
        }catch(Throwable $e){ /* ignora se FK o duplicato race */ }
      }

      // Vite utente (post fix)
      $stl = $pdo->prepare("SELECT id, status FROM tournament_flash_lives WHERE tournament_id=? AND user_id=? ORDER BY id ASC");
      $stl->execute([$pre['id'], $uid]);
      $preLives = $stl->fetchAll(PDO::FETCH_ASSOC) ?: [];

      // === Pre-carica eventi (fallback)
      $tableEv = 'tournament_flash_events';
      $cols = [
        'id' => col_exists($pdo,$tableEv,'id') ? 'id' : null,
        'round' => col_exists($pdo,$tableEv,'round') ? 'round' : (col_exists($pdo,$tableEv,'rnd') ? 'rnd' : null),
        'home_id' => col_exists($pdo,$tableEv,'home_id') ? 'home_id' : null,
        'away_id' => col_exists($pdo,$tableEv,'away_id') ? 'away_id' : null,
        'home_name' => col_exists($pdo,$tableEv,'home_name') ? 'home_name' : null,
        'away_name' => col_exists($pdo,$tableEv,'away_name') ? 'away_name' : null,
        'home_logo' => col_exists($pdo,$tableEv,'home_logo') ? 'home_logo' : null,
        'away_logo' => col_exists($pdo,$tableEv,'away_logo') ? 'away_logo' : null,
        'tourn_fk'  => col_exists($pdo,$tableEv,'tournament_id') ? 'tournament_id' : null,
      ];
      if ($cols['round'] && $cols['tourn_fk']){
        $sel = array_filter([
          $cols['id'] ? "e.{$cols['id']} AS id" : null,
          "e.{$cols['round']} AS round",
          $cols['home_id']  ? "e.{$cols['home_id']} AS home_id"   : null,
          $cols['away_id']  ? "e.{$cols['away_id']} AS away_id"   : null,
          $cols['home_name']? "e.{$cols['home_name']} AS home_name" : null,
          $cols['away_name']? "e.{$cols['away_name']} AS away_name" : null,
          $cols['home_logo']? "e.{$cols['home_logo']} AS home_logo" : null,
          $cols['away_logo']? "e.{$cols['away_logo']} AS away_logo" : null,
        ]);
        $sql = "SELECT ".implode(',', $sel)." FROM {$tableEv} e WHERE e.{$cols['tourn_fk']}=? ORDER BY e.{$cols['round']} ASC, e.".( $cols['id'] ?: $cols['round'] )." ASC";
        $se = $pdo->prepare($sql);
        $se->execute([$pre['id']]);
        while($r=$se->fetch(PDO::FETCH_ASSOC)){
          $R = (int)$r['round'];
          if ($R<1 || $R>3) continue;
          $preEvents[$R][] = [
            'id'   => isset($r['id']) ? (int)$r['id'] : null,
            'home_id'   => $r['home_id']   ?? null,
            'away_id'   => $r['away_id']   ?? null,
            'home_name' => $r['home_name'] ?? null,
            'away_name' => $r['away_name'] ?? null,
            'home_logo' => $r['home_logo'] ?? null,
            'away_logo' => $r['away_logo'] ?? null,
          ];
        }
      }

      /* === Pre-carica loghi team (per eventi precaricati) === */
      $teamLogos = [];
      $teamIds = [];
      foreach ($preEvents as $roundList) {
        foreach ($roundList as $ev) {
          if (!empty($ev['home_id'])) $teamIds[] = (int)$ev['home_id'];
          if (!empty($ev['away_id'])) $teamIds[] = (int)$ev['away_id'];
        }
      }
      $teamIds = array_values(array_unique(array_filter($teamIds)));
      if ($teamIds) {
        $in = implode(',', array_fill(0, count($teamIds), '?'));
        $stt = $pdo->prepare("SELECT id, slug, logo_url, logo_key FROM teams WHERE id IN ($in)");
        $stt->execute($teamIds);
        while($t = $stt->fetch(PDO::FETCH_ASSOC)){
          $url = $t['logo_url'] ?: ( ($t['logo_key'] ?? '') ? '/'.ltrim($t['logo_key'],'/') : '' );
          if ($url) {
            $teamLogos[(string)$t['id']] = $url;
            if (!empty($t['slug'])) $teamLogos[$t['slug']] = $url;
          }
        }
      }
    }
  }
}catch(Throwable $e){ /* fallback */ }

$page_css='/pages-css/admin-dashboard.css';
include APP_ROOT . '/partials/head.php';
include APP_ROOT . '/partials/header_utente.php';
?>
<style>
/* ===== Layout & hero ===== */
.twrap{ max-width:1100px; margin:0 auto; }
.hero{
  position:relative; background:linear-gradient(135deg,#1e3a8a 0%, #0f172a 100%);
  border:1px solid rgba(255,255,255,.1); border-radius:20px; padding:18px 18px 14px;
  color:#fff; box-shadow:0 18px 60px rgba(0,0,0,.35);
}
.hero h1{ margin:0 0 4px; font-size:22px; font-weight:900; letter-spacing:.3px; }
.hero .sub{ opacity:.9; font-size:13px; }
.state{ position:absolute; top:12px; right:12px; font-size:12px; font-weight:800; letter-spacing:.4px;
  padding:4px 10px; border-radius:9999px; border:1px solid rgba(255,255,255,.25); background:rgba(0,0,0,.2); pointer-events:none; }
.state.open{ border-color: rgba(52,211,153,.45); color:#d1fae5; }
.state.live{ border-color: rgba(250,204,21,.55); color:#fef9c3; }
.state.end{  border-color: rgba(239,68,68,.45); color:#fee2e2; }

/* 4 KPI */
.kpis{ display:grid; grid-template-columns: repeat(4, 1fr); gap:12px; margin-top:12px; }
.kpi{ background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.08); border-radius:14px; padding:12px; text-align:center; }
.kpi .lbl{ font-size:12px; opacity:.9;}
.kpi .val{ font-size:18px; font-weight:900; letter-spacing:.3px; }
.countdown{ font-variant-numeric:tabular-nums; font-weight:900; }

/* Azioni */
.actions{ display:flex; justify-content:space-between; align-items:center; gap:12px; margin-top:12px; position:relative; z-index:5; }
.actions-left, .actions-right{ display:flex; gap:8px; align-items:center; }
.actions .btn { pointer-events:auto; }

/* ===== Le mie vite ===== */
.vite-card{ margin-top:16px; background:#0b1220; border:1px solid #121b2d; border-radius:16px; padding:14px; color:#fff; }
.vbar{ display:flex; align-items:center; flex-wrap:wrap; gap:10px; margin-top:10px;}
.life{
  position:relative; display:flex; align-items:center; gap:6px; padding:6px 10px; border-radius:9999px;
  background:linear-gradient(135deg,#13203a 0%,#0c1528 100%); border:1px solid #1f2b46;
  cursor:pointer;
}
.life.active{ box-shadow:0 0 0 2px #2563eb inset; }
.life img.logo{ width:18px; height:18px; object-fit:cover; border-radius:50%; border:1px solid rgba(255,255,255,.35); }
.heart{ width:18px; height:18px; display:inline-block; background:url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" fill="%23FF3B3B" viewBox="0 0 24 24"><path d="M12 21s-8-6.438-8-11a5 5 0 0 1 9-3 5 5 0 0 1 9 3c0 4.562-8 11-8 11z"/></svg>') no-repeat center/contain; }
.life.lost .heart{ filter:grayscale(1) opacity(.5); }

/* ===== Eventi ===== */
.events-card{ margin-top:16px; background:#0b1220; border:1px solid #121b2d; border-radius:16px; padding:14px; color:#fff; }
.round-head{ display:flex; align-items:center; gap:12px; margin-bottom:8px;}
.round-head h3{ margin:0; font-size:18px; font-weight:900;}
.eitem{ display:grid; grid-template-columns: 1fr auto; align-items:center; gap:12px; }
.evt{
  position:relative; display:flex; align-items:center; justify-content:center; gap:12px;
  background:radial-gradient(900px 200px at 50% -100px, rgba(99,102,241,.15), transparent 60%), linear-gradient(125deg,#111827 0%, #0b1120 100%);
  border:1px solid #1f2937; border-radius:9999px; padding:12px 16px;
}
.team{ display:flex; align-items:center; gap:8px; min-width:0;}
.team img{ width:28px; height:28px; border-radius:50%; object-fit:cover; }
.vs{ font-weight:900; opacity:.9; }
.team .pick-dot{ width:10px; height:10px; border-radius:50%; background:transparent; box-shadow:none; display:inline-block; }
.team.picked .pick-dot{ background:#fde047; box-shadow:0 0 10px #fde047, 0 0 20px #fde047; }

.choices{ display:flex; gap:8px; }
.choices .btn{ min-width:98px; }
.choices .btn.active{ box-shadow:0 0 0 2px #fde047 inset; }

.btn[type="button"]{ cursor:pointer; }
.muted{ color:#9ca3af; font-size:12px; }

/* Modali */
.modal[aria-hidden="true"]{ display:none; } .modal{ position:fixed; inset:0; z-index:85;}
.modal-backdrop{ position:absolute; inset:0; background:rgba(0,0,0,.55); }
.modal-card{ position:relative; z-index:86; width:min(520px,94vw); margin:12vh auto 0;
  background:var(--c-bg); border:1px solid var(--c-border); border-radius:16px; overflow:hidden; box-shadow:0 18px 50px rgba(0,0,0,.5); color:#fff;}
.modal-head{ padding:12px 16px; border-bottom:1px solid var(--c-border); display:flex; align-items:center; gap:8px;}
.modal-body{ padding:16px;}
.modal-foot{ padding:12px 16px; border-top:1px solid var(--c-border); display:flex; justify-content:flex-end; gap:8px;}
</style>

<main class="section">
  <div class="container">
    <div class="twrap">
      <!-- HERO -->
      <div class="hero">
        <div class="code" id="tCode"><?= $pre['found'] ? '#'.htmlspecialchars($pre['code'],ENT_QUOTES) : '#' ?></div>
        <div class="state" id="tState">APERTO</div>
        <h1 id="tTitle"><?= $pre['found'] ? htmlspecialchars($pre['name'],ENT_QUOTES) : 'Torneo Flash' ?></h1>
        <div class="sub" id="tSub">Flash • 3 round</div>
        <div class="kpis">
          <div class="kpi"><div class="lbl">Montepremi</div><div class="val" id="kPool"><?= $pre['pool']!==null ? number_format($pre['pool'],2,'.','') : '—' ?></div></div>
          <div class="kpi"><div class="lbl">Partecipanti</div><div class="val" id="kPlayers"><?= $pre['players']!==null ? (int)$pre['players'] : '—' ?></div></div>
          <div class="kpi"><div class="lbl">Vite max/utente</div><div class="val" id="kLmax"><?= $pre['lives_max_user']!==null ? (int)$pre['lives_max_user'] : 'n/d' ?></div></div>
          <div class="kpi"><div class="lbl">Lock round</div><div class="val countdown" id="kLock" data-lock="<?= $pre['lock_at']? (int)(strtotime($pre['lock_at'])*1000) : 0 ?>"></div></div>
        </div>
        <div class="actions">
          <div class="actions-left">
            <button class="btn btn--primary btn--sm" type="button" id="btnBuy">Acquista una vita</button>
          </div>
          <div class="actions-right">
            <button class="btn btn--outline btn--sm" type="button" id="btnUnjoin">Disiscrivi</button>
          </div>
        </div>
        <span class="muted" id="hint"></span>
      </div>

      <!-- LE MIE VITE (render immediato + JS) -->
      <div class="vite-card">
        <strong>Le mie vite</strong>
        <div class="vbar" id="vbar">
          <?php if (!empty($preLives)): ?>
            <?php foreach($preLives as $i=>$lv):
              $lost = in_array(strtolower((string)($lv['status']??'')), ['lost','eliminated','dead','out','persa','eliminata'], true);
            ?>
              <div class="life<?= $lost?' lost':'' ?>" data-id="<?= (int)$lv['id'] ?>">
                <span class="heart"></span><span>Vita <?= $i+1 ?></span>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <span class="muted">Nessuna vita: acquista una vita per iniziare.</span>
          <?php endif; ?>
        </div>
      </div>

      <!-- EVENTI: Round 1 -->
      <div class="events-card">
        <div class="round-head">
          <h3>Eventi torneo — Round 1</h3>
          <span class="muted" id="lockTxt1"></span>
        </div>
        <div id="eventsR1"><div class="muted">Caricamento…</div></div>
      </div>
      <!-- EVENTI: Round 2 -->
      <div class="events-card">
        <div class="round-head">
          <h3>Eventi torneo — Round 2</h3>
          <span class="muted" id="lockTxt2"></span>
        </div>
        <div id="eventsR2"><div class="muted">Caricamento…</div></div>
      </div>
      <!-- EVENTI: Round 3 -->
      <div class="events-card">
        <div class="round-head">
          <h3>Eventi torneo — Round 3</h3>
          <span class="muted" id="lockTxt3"></span>
        </div>
        <div id="eventsR3"><div class="muted">Caricamento…</div></div>
      </div>
    </div>
  </div>
</main>

<!-- Modal conferme -->
<div class="modal" id="mdConfirm" aria-hidden="true">
  <div class="modal-backdrop" data-close></div>
  <div class="modal-card">
    <div class="modal-head"><h3 id="mdTitle">Conferma</h3></div>
    <div class="modal-body"><p id="mdText"></p></div>
    <div class="modal-foot">
      <button class="btn btn--outline" type="button" data-close>Annulla</button>
      <button class="btn btn--primary" type="button" id="mdOk">Conferma</button>
    </div>
  </div>
</div>

<!-- Modal avvisi -->
<div class="modal" id="mdAlert" aria-hidden="true">
  <div class="modal-backdrop" data-close></div>
  <div class="modal-card">
    <div class="modal-head"><h3 id="alertTitle">Avviso</h3></div>
    <div class="modal-body"><p id="alertText" class="muted"></p></div>
    <div class="modal-foot"><button class="btn btn--primary" type="button" id="alertOk">Ok</button></div>
  </div>
</div>

<?php include APP_ROOT . '/partials/footer.php'; ?>
<script src="/js/policy_guard.js"></script>
<script>
document.addEventListener('DOMContentLoaded', ()=>{
  const $  = s=>document.querySelector(s);
  const $$ = (s,p=document)=>[...p.querySelectorAll(s)];

  const qs   = new URLSearchParams(location.search);
  const FID  = Number(qs.get('id')||0) || 0;
  const FCOD = (qs.get('code')||'').toUpperCase();
  const CSRF = '<?= $CSRF ?>';

  /* === Pre-events dal server (fallback DB) === */
  const PRE_EVENTS = <?= json_encode($preEvents, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  /* === Mappa loghi team precaricati (id/slug -> logo_url) === */
  const TEAM_LOGOS = <?= json_encode($teamLogos ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

  /* ==== Countdown ==== */
  function countdownTick(){
    const el=$('#kLock'); const ts=Number(el.getAttribute('data-lock')||0);
    const now=Date.now(); const diff=Math.floor((ts-now)/1000);
    if(!ts){ el.textContent='—'; return; }
    if(diff<=0){ el.textContent='CHIUSO'; return; }
    let d=diff, dd=Math.floor(d/86400); d%=86400;
    const hh=String(Math.floor(d/3600)).padStart(2,'0'); d%=3600;
    const mm=String(Math.floor(d/60)).padStart(2,'0'); const ss=String(d%60).padStart(2,'0');
    el.textContent = (dd>0? dd+'g ':'')+hh+':'+mm+':'+ss;
    requestAnimationFrame(countdownTick);
  }
  countdownTick();

/* ==== API layer: endpoint candidati (multi endpoint) ==== */
const CANDIDATE_BASES = [
  '/api/flash_tournament.php', // ✅ endpoint reale per il torneo flash
  '/api/torneo.php',           // fallback legacy
  '/api/flash_torneo.php',     // alias eventuali (se presenti in qualche deploy)
  '/api/flash/torneo.php',
  '/api/torneo_flash.php',
  '/api/tournament_flash.php'
];
  function buildParams(action, extra={}) {
    const p = new URLSearchParams({action});
    if (FID) { p.set('id', String(FID)); p.set('tournament_id', String(FID)); }
    if (FCOD){ p.set('code', FCOD); p.set('tid', FCOD); p.set('tcode', FCOD); }
    p.set('is_flash','1'); p.set('flash','1');
    for (const k in extra) p.set(k, String(extra[k]));
    return p;
  }
  async function apiGET(action, extra={}){
    const p = buildParams(action, extra);
    for (const base of CANDIDATE_BASES){
      try{
        const u = new URL(base, location.origin); u.search = p.toString();
        const r  = await fetch(u.toString(), { cache:'no-store', credentials:'same-origin', headers:{'Accept':'application/json'} });
        const tx = await r.text();
        try{ return { ok:true, data: JSON.parse(tx) }; }catch(_){}
      }catch(_){}
    }
    return { ok:false };
  }
  async function apiPOST(action, extra={}){
    for (const base of CANDIDATE_BASES){
      try{
        const u   = new URL(base, location.origin);
        const body= buildParams(action, extra); body.set('csrf_token', CSRF);
        const r = await fetch(u.toString(), {
          method:'POST',
          headers:{ 'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8', 'Accept':'application/json', 'X-CSRF-Token':CSRF },
          body: body.toString(),
          credentials:'same-origin'
        });
        const tx = await r.text();
        try{ return { ok:true, data: JSON.parse(tx) }; }catch(_){}
      }catch(_){}
    }
    return { ok:false };
  }

  /* ==== UI utils ==== */
  const fmt2  = n => Number(n||0).toFixed(2);
  const toast = msg => { const h=$('#hint'); h.textContent=msg; setTimeout(()=>h.textContent='', 2200); };
  function showAlert(title, html){
    $('#alertTitle').textContent = title || 'Avviso';
    $('#alertText').innerHTML    = html  || '';
    document.getElementById('mdAlert').setAttribute('aria-hidden','false');
  }
  document.getElementById('alertOk')?.addEventListener('click', ()=>document.getElementById('mdAlert').setAttribute('aria-hidden','true'));

  /* ==== Stato pagina ==== */
  let LIVES = [];
  let ACTIVE_LIFE = 0;

  // pick default dalla vita renderizzata server-side (se presente)
  (function bootstrapActiveLife(){
    const first = document.querySelector('#vbar .life');
    if (first){ first.classList.add('active'); ACTIVE_LIFE = Number(first.getAttribute('data-id'))||0; }
  })();

  /* ==== Summary ==== */
  async function loadSummary(){
    const r = await apiGET('summary');
    if (!r.ok || !r.data || r.data.ok===false) return;
    const t = r.data.tournament || {};
    $('#tTitle').textContent = t.name || t.title || 'Torneo Flash';
    $('#tCode').textContent  = t.code ? ('#'+t.code) : (t.id?('#'+t.id):'#');
    const st = (t.state||'open').toString().toUpperCase();
    const lab = st.includes('END')||st.includes('CLOSED')||st.includes('FINAL') ? 'CHIUSO'
               : ( (t.lock_at && Date.now()>=new Date(t.lock_at).getTime()) ? 'IN CORSO' : 'APERTO' );
    const se=$('#tState'); se.textContent=lab; se.className='state '+(lab==='APERTO'?'open':(lab==='IN CORSO'?'live':'end'));

    let pool = (typeof t.pool_coins!=='undefined' && t.pool_coins!==null) ? Number(t.pool_coins) : <?= $pre['pool']!==null ? json_encode($pre['pool']) : 'null' ?>;
    if ((pool===null || Number.isNaN(pool)) && t.buyin && (t.buyin_to_prize_pct || t.prize_pct) && typeof t.lives_total!=='undefined'){
      const pct = (t.buyin_to_prize_pct || t.prize_pct); const P = (pct>0 && $pct<=1) ? $pct*100 : $pct; // lasciato invariato
      pool = Math.max(Number(t.guaranteed_prize||0), Math.round(Number(t.buyin)*Number(t.lives_total)*(Number(P)/100)*100)/100);
    }
    if (pool!=null) $('#kPool').textContent = fmt2(pool);

    const players = (r.data.stats && typeof r.data.stats.participants!=='undefined') ? Number(r.data.stats.participants)
                    : (typeof r.data.players!=='undefined' ? Number(r.data.players) : null);
    if (players!=null && !Number.isNaN(players)) $('#kPlayers').textContent = String(players);
    if (t.lives_max_user!=null) $('#kLmax').textContent = String(t.lives_max_user);
    if (t.lock_at){ $('#kLock').setAttribute('data-lock', String((new Date(t.lock_at)).getTime())); }
  }

  /* ==== Le mie vite ==== */
  async function loadLives(){
    const r = await apiGET('my_lives');
    if (r.ok && r.data && Array.isArray(r.data.lives)){
      LIVES = r.data.lives;
      const vbar = $('#vbar'); vbar.innerHTML='';
      if (!LIVES.length){ vbar.innerHTML='<span class="muted">Nessuna vita: acquista una vita per iniziare.</span>'; ACTIVE_LIFE=0; return; }
      LIVES.forEach((lv,idx)=>{
        const d=document.createElement('div'); d.className='life'; d.setAttribute('data-id', String(lv.id));
        const s = String(lv.status||lv.state||'').toLowerCase();
        if (['lost','eliminated','dead','out','persa','eliminata'].includes(s) || lv.is_alive===0) d.classList.add('lost');
        d.innerHTML = `<span class="heart"></span><span>Vita ${idx+1}</span>`;
        d.addEventListener('click', ()=>{
          $$('.life').forEach(x=>x.classList.remove('active'));
          d.classList.add('active'); ACTIVE_LIFE = Number(lv.id);
        });
        vbar.appendChild(d);
      });
      const first=$('.life'); if(first){ first.classList.add('active'); ACTIVE_LIFE = Number(first.getAttribute('data-id'))||0; }
    }
  }

/* ==== Render eventi (usa prima PRE_EVENTS, poi API) ==== */
function renderEvents(list, mountId){
  const box = document.getElementById(mountId); if(!box) return;
  if (!list || !list.length){ box.innerHTML='<div class="muted">Nessun evento per questo round.</div>'; return; }
  box.innerHTML='';
  list.forEach(ev=>{
    const rawPick = (ev.my_pick || ev.choice || ev.pick || '').toString().toLowerCase();
    const wasHome = ['1','h','home','casa'].includes(rawPick);
    const wasDraw = ['x','d','draw','pareggio'].includes(rawPick);
    const wasAway = ['2','a','away','trasferta'].includes(rawPick);

    // Fallback logo: 1) evento; 2) TEAM_LOGOS da DB; 3) file statico per id
    const hKey = String(ev.home_id ?? '');
    const aKey = String(ev.away_id ?? '');
    const homeLogo = ev.home_logo || TEAM_LOGOS[hKey] || (ev.home_id ? `/assets/logos/${ev.home_id}.png` : '');
    const awayLogo = ev.away_logo || TEAM_LOGOS[aKey] || (ev.away_id ? `/assets/logos/${ev.away_id}.png` : '');

    const wrap=document.createElement('div'); wrap.className='eitem';
    const oval=document.createElement('div'); oval.className='evt';
    // Template: [logo home] NomeHome  VS  NomeAway [logo away]
    oval.innerHTML = `
      <div class="team home ${wasHome?'picked':''}">
        <span class="pick-dot"></span>
        ${homeLogo ? `<img src="${homeLogo}" alt="${ev.home_name||''}" onerror="this.style.display='none'">` : ''}
        <strong>${ev.home_name||('#'+(ev.home_id||'?'))}</strong>
      </div>
      <div class="vs">VS</div>
      <div class="team away ${wasAway?'picked':''}">
        <strong>${ev.away_name||('#'+(ev.away_id||'?'))}</strong>
        ${awayLogo ? `<img src="${awayLogo}" alt="${ev.away_name||''}" onerror="this.style.display='none'">` : ''}
        <span class="pick-dot"></span>
      </div>
    `;

    const choices=document.createElement('div'); choices.className='choices';
    const bHome = document.createElement('button'); bHome.type='button'; bHome.className='btn btn--outline'+(wasHome?' active':''); bHome.textContent='Casa';
    const bDraw = document.createElement('button'); bDraw.type='button'; bDraw.className='btn btn--outline'+(wasDraw?' active':''); bDraw.textContent='Pareggio';
    const bAway = document.createElement('button'); bAway.type='button'; bAway.className='btn btn--outline'+(wasAway?' active':''); bAway.textContent='Trasferta';
    choices.append(bHome,bDraw,bAway);

    async function doPick(choice){
      if (!ACTIVE_LIFE){ showAlert('Seleziona una vita', 'Prima seleziona una vita nella sezione <strong>Le mie vite</strong>.'); return; }
      try{
        const g = await fetch(`/api/tournament_core.php?action=policy_guard&what=pick&is_flash=1&code=${encodeURIComponent(FCOD)}&tid=${encodeURIComponent(FCOD)}&round=${encodeURIComponent(ev.round||1)}`, {cache:'no-store', credentials:'same-origin'}).then(r=>r.json());
        if (!g || !g.ok || !g.allowed){ showAlert('Operazione non consentita', (g && g.popup) ? g.popup : 'Non puoi effettuare la scelta in questo momento.'); return; }
      }catch(_){}

      const pick3 = (choice==='home' ? '1' : (choice==='draw' ? 'X' : '2'));
      const teamId = (choice==='home' ? (ev.home_id||'') : (choice==='away' ? (ev.away_id||'') : ''));

      const r = await apiPOST('pick', { round: (ev.round||1), event_id: ev.id, life_id: ACTIVE_LIFE, choice, pick: pick3, team_id: teamId });
      if (!r.ok || !r.data || r.data.ok===false){
        showAlert('Errore scelta', (r.data && (r.data.detail||r.data.error)) ? (r.data.detail||r.data.error) : 'Scelta non registrata'); return;
      }
      [bHome,bDraw,bAway].forEach(b=>b.classList.remove('active'));
      if (choice==='home') bHome.classList.add('active');
      if (choice==='draw') bDraw.classList.add('active');
      if (choice==='away') bAway.classList.add('active');
      oval.querySelector('.team.home')?.classList.toggle('picked', choice==='home');
      oval.querySelector('.team.away')?.classList.toggle('picked', choice==='away');
      toast('Scelta registrata');
    }
    bHome.addEventListener('click', ()=>doPick('home'));
    bDraw.addEventListener('click', ()=>doPick('draw'));
    bAway.addEventListener('click', ()=>doPick('away'));

    wrap.appendChild(oval); wrap.appendChild(choices); box.appendChild(wrap);
  });
}

  async function loadRound(round, mountId){
    // 1) fallback server: se ho eventi preiniettati, uso quelli
    if (Array.isArray(PRE_EVENTS[round]) && PRE_EVENTS[round].length){
      renderEvents(PRE_EVENTS[round].map(e=>({...e, round})), mountId);
      return;
    }
// 2) altrimenti API
const box = document.getElementById(mountId); 
if (box) box.innerHTML = '<div class="muted">Caricamento…</div>';

// 2.1 Prima: API flash
let r = await apiGET('list_events', { round_no: round });

// 2.2 Fallback legacy: API classica
if (!r.ok || !r.data || r.data.ok === false) r = await apiGET('events', { round });

// 2.3 Altro fallback legacy
if (!r.ok || !r.data || r.data.ok === false) r = await apiGET('round_events', { round });

if (!r.ok || !r.data) { 
  if (box) box.innerHTML = '<div class="muted">Errore caricamento.</div>'; 
  return; 
}

// Normalizza il payload: API flash usa "rows", legacy usa "events"
const payload = r.data.rows || r.data.events || [];
const evs = Array.isArray(payload) ? payload : [];

renderEvents(evs.map(e => ({
  // normalizza anche gli id squadra così il renderer non va a vuoto
  ...e,
  round,
  home_id: e.home_id ?? e.home_team_id ?? e.home ?? null,
  away_id: e.away_id ?? e.away_team_id ?? e.away ?? null
})), mountId);
  }

  // BUY LIFE
  $('#btnBuy').addEventListener('click', async ()=>{
    try{
      const g = await fetch(`/api/tournament_core.php?action=policy_guard&what=buy_life&is_flash=1&code=${encodeURIComponent(FCOD)}&tid=${encodeURIComponent(FCOD)}`, {cache:'no-store',credentials:'same-origin'}).then(r=>r.json());
      if (!g || !g.ok || !g.allowed){ showAlert('Operazione non consentita', (g && g.popup) ? g.popup : 'Non puoi acquistare vite in questo momento.'); return; }
    }catch(_){}
    // conferma
    $('#mdTitle').textContent='Acquista vita';
    $('#mdText').innerHTML='Confermi l’acquisto di <strong>1 vita</strong>?';
    const okBtn = $('#mdOk'); const clone = okBtn.cloneNode(true); okBtn.parentNode.replaceChild(clone, okBtn);
    $('#mdConfirm').setAttribute('aria-hidden','false');
    const ok = $('#mdOk');
    ok.addEventListener('click', async ()=>{
      ok.disabled=true;
      try{
        const r = await apiPOST('buy_life', {});
        if (!r.ok || !r.data || r.data.ok===false){
          showAlert('Errore acquisto', (r.data && (r.data.detail||r.data.error)) ? (r.data.detail||r.data.error) : 'Errore acquisto');
        } else {
          toast('Vita acquistata'); document.dispatchEvent(new CustomEvent('refresh-balance'));
          await Promise.all([loadSummary(), loadLives(), loadRound(1,'eventsR1'), loadRound(2,'eventsR2'), loadRound(3,'eventsR3')]);
        }
      } finally {
        ok.disabled=false; document.getElementById('mdConfirm').setAttribute('aria-hidden','true');
      }
    }, { once:true });
  });

  // UNJOIN
  $('#btnUnjoin').addEventListener('click', async ()=>{
    try{
      const g = await fetch(`/api/tournament_core.php?action=policy_guard&what=unjoin&is_flash=1&code=${encodeURIComponent(FCOD)}&tid=${encodeURIComponent(FCOD)}`, {cache:'no-store',credentials:'same-origin'}).then(r=>r.json());
      if (!g || !g.ok || !g.allowed){ showAlert('Operazione non consentita', (g && g.popup) ? g.popup : 'Non puoi disiscriverti in questo momento.'); return; }
    }catch(_){}
    $('#mdTitle').textContent='Disiscrizione';
    $('#mdText').innerHTML='Confermi la disiscrizione?';
    const okBtn = $('#mdOk'); const clone = okBtn.cloneNode(true); okBtn.parentNode.replaceChild(clone, okBtn);
    $('#mdConfirm').setAttribute('aria-hidden','false');
    const ok = $('#mdOk');
    ok.addEventListener('click', async ()=>{
      ok.disabled=true;
      try{
        const r = await apiPOST('unjoin', {});
        if (!r.ok || !r.data || r.data.ok===false){
          showAlert('Errore disiscrizione', (r.data && (r.data.detail||r.data.error)) ? (r.data.detail||r.data.error) : 'Errore disiscrizione');
        } else {
          toast('Disiscrizione eseguita'); document.dispatchEvent(new CustomEvent('refresh-balance'));
          location.href='/lobby.php';
        }
      } finally {
        ok.disabled=false; document.getElementById('mdConfirm').setAttribute('aria-hidden','true');
      }
    }, { once:true });
  });

  // Boot
  (async()=>{
    await loadSummary();
    await loadLives();
    await Promise.all([loadRound(1,'eventsR1'), loadRound(2,'eventsR2'), loadRound(3,'eventsR3')]);
  })();
});
</script>
