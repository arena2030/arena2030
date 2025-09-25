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
<script>
  window.__CSRF  = '<?= $CSRF ?>';
  // 🔐 Precarico anche ID numerico e codice dal server, così esistono anche con ?code=... senza ?id=
  window.__FLASH_TID_NUM = <?= (int)($pre['id'] ?? 0) ?>;
  window.__FLASH_CODE    = '<?= htmlspecialchars(strtoupper($pre['code'] ?? ''), ENT_QUOTES) ?>';
</script>
<!-- <script src="/js/policy_guard.js"></script> -->
<script>
document.addEventListener('DOMContentLoaded', ()=>{
  const $  = s=>document.querySelector(s);
  const $$ = (s,p=document)=>[...p.querySelectorAll(s)];

  const qs       = new URLSearchParams(location.search);
  const FCOD_URL = (qs.get('code')||'').toUpperCase();
  const CSRF     = window.__CSRF || '';
  const DBG      = (qs.get('debug')==='1');

  // Canonici: codice e ID numerico torneo (anche se non passati in URL)
  const FCOD = FCOD_URL || (window.__FLASH_CODE || '');
  let   TID_NUM = Number(window.__FLASH_TID_NUM || 0);

  /* === Pre-events dal server (fallback DB) === */
  const PRE_EVENTS = <?= json_encode($preEvents, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  const TEAM_LOGOS = <?= json_encode($teamLogos ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

  /* === DEBUG helper === */
  function showAlert(title, html){
    $('#alertTitle').textContent = title || 'Avviso';
    $('#alertText').innerHTML    = html  || '';
    document.getElementById('mdAlert').setAttribute('aria-hidden','false');
  }
  document.getElementById('alertOk')?.addEventListener('click', ()=>document.getElementById('mdAlert').setAttribute('aria-hidden','true'));

  function dbg(ctx, payload){
    if (!DBG) return;
    try{ console.log('CTX:', ctx, payload); }catch(_){}
    try{
      const html = `<pre style="white-space:pre-wrap;font-size:12px;line-height:1.35">${typeof payload==='string' ? payload : JSON.stringify(payload,null,2)}</pre>`;
      showAlert('DEBUG — '+ctx, html);
    }catch(_){}
  }

  /* === Persistenza pick per UI === */
  const LS_KEY = 'flash_picks_v1';
  const lsGetAll = ()=>{ try{ return JSON.parse(localStorage.getItem(LS_KEY)||'{}'); }catch(_){ return {}; } };
  const lsSaveAll= (o)=>{ try{ localStorage.setItem(LS_KEY, JSON.stringify(o)); }catch(_){ } };
  function lsSavePicks(code, lifeId, bag){ const all=lsGetAll(); if(!all[code]) all[code]={}; all[code][lifeId]=bag; lsSaveAll(all); }
  function lsGetPicks (code, lifeId){ const all=lsGetAll(); return (all[code]||{})[lifeId] || {}; }

  /* === GUARD helper — disattivata: non chiama endpoint === */
  async function pgFlash(what, extras={}) {
    if (DBG) console.debug('[pgFlash skipped]', { what, extras });
    return { ok: true, allowed: true, skipped: true, what, extras };
  }

  /* === CORE POST (buy_life / unjoin) — SMART multi-endpoint / multi-action === */
  async function corePOST(action, extras = {}) {
    // Varianti comunemente usate lato backend
    const actionVariants = [action, `flash_${action}`, `${action}_flash`];
    // Router candidati in ambienti diversi
    const endpoints = [
      '/api/flash_tournament.php',
      '/api/flash_torneo.php',
      '/api/flash/torneo.php',
      '/api/tournament_flash.php',
      '/api/torneo_flash.php',
      '/api/torneo.php',
      '/api/tournament_core.php'
    ];

    let last = { ok:false, error:'no_route', detail:'Nessuna rotta ha accettato la richiesta' };

    for (const base of endpoints) {
      for (const act of actionVariants) {
        const body = new URLSearchParams({ action: act, csrf_token: CSRF, is_flash:'1', flash:'1' });

        // Passa SEMPRE tutti gli alias di identificazione (code + id)
        if (FCOD) { body.set('tid', FCOD); body.set('code', FCOD); body.set('tcode', FCOD); body.set('flash_code', FCOD); }
        if (TID_NUM > 0) { body.set('id', String(TID_NUM)); body.set('tournament_id', String(TID_NUM)); body.set('flash_id', String(TID_NUM)); }

        for (const k in extras) body.set(k, String(extras[k]));

        try {
          const r  = await fetch(base, {
            method:'POST',
            headers:{
              'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8',
              'Accept':'application/json',
              'X-CSRF-Token': CSRF
            },
            credentials:'same-origin',
            body: body.toString()
          });
          const tx = await r.text();
          let j=null; try { j=JSON.parse(tx); } catch(_) { j=null; }

          if (DBG) console.debug('[corePOST try]', { base, act, body:Object.fromEntries(body), res:j ?? tx });

          // Criteri di successo "robusti"
          const ok =
            (j && j.ok===true) ||
            (j && j.status && String(j.status).toLowerCase()==='ok') ||
            (j && j.success===true) ||
            (j && j.data && j.data.ok===true);

          if (ok) return j || { ok:true };

          // Errori che indicano "prova prossimo"
          const err = (j && (j.error||j.detail)) ? String(j.error||j.detail).toLowerCase() : '';
          if (['unknown_action','missing_action','bad_action','bad_tournament','api_exception'].includes(err)) {
            last = j || { ok:false, error: err || 'api_exception' };
            continue; // prova altra combinazione
          }

          // Se non c'è un chiaro errore ma nemmeno ok, salva e continua
          last = j || { ok:false, error:'unexpected_response', raw:tx };
        } catch(e) {
          last = { ok:false, fetch_error:true, message:String(e), route:base, action:act };
          // continua con prossimo endpoint
        }
      }
    }
    return last;
  }

  /* === FLASH POST: (per picks) rimane invariato === */
  async function flashPOST(action, extras={}) {
    const body = new URLSearchParams({ action, csrf_token: CSRF });
    if (FCOD) { body.set('tid', FCOD); body.set('code', FCOD); body.set('tcode', FCOD); }
    for (const k in extras) {
      const v = extras[k];
      if (k === 'payload' && typeof v !== 'string') body.set('payload', JSON.stringify(v));
      else body.set(k, String(v));
    }
    try {
      const r  = await fetch('/api/flash_tournament.php', {
        method:'POST',
        headers:{
          'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8',
          'Accept':'application/json',
          'X-CSRF-Token': CSRF
        },
        credentials:'same-origin',
        body: body.toString()
      });
      const tx = await r.text();
      let j=null; try { j=JSON.parse(tx); } catch(_) { j={ ok:false, parse_error:true, raw:tx }; }
      if (DBG) console.debug('[flashPOST]', action, Object.fromEntries(body), r.status, j);
      return j;
    } catch (e) {
      return { ok:false, fetch_error:true, message:String(e) };
    }
  }

  /* ==== API GET multi endpoint (summary/list_events/my_lives) ==== */
  const CANDIDATE_BASES = [
    '/api/flash_tournament.php',
    '/api/torneo.php',
    '/api/flash_torneo.php',
    '/api/flash/torneo.php',
    '/api/torneo_flash.php',
    '/api/tournament_flash.php'
  ];
  function buildParams(action, extra={}) {
    const p = new URLSearchParams({action});
    if (TID_NUM>0){ p.set('id', String(TID_NUM)); p.set('tournament_id', String(TID_NUM)); }
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
        const tx = await r.text(); let j=null;
        try{ j = JSON.parse(tx); }catch(_){}
        if (!j) continue;
        if (j.ok===false && String(j.error||'')==='unknown_action') continue;
        dbg('GET '+action+' @'+base, { url:u.toString(), res:j });
        return { ok:true, data:j };
      }catch(_){}
    }
    return { ok:false };
  }

  /* ==== UI utils ==== */
  const fmt2  = n => Number(n||0).toFixed(2);
  const toast = msg => { const h=$('#hint'); h.textContent=msg; setTimeout(()=>h.textContent='', 2200); };

  /* ==== Stato pagina ==== */
  let LIVES = [];
  let ACTIVE_LIFE = 0;
  const PICKS = {}; // { life_id: {1:row,2:row,3:row} }

  // pick default dalla vita renderizzata server-side (se presente)
  (function bootstrapActiveLife(){
    const first = document.querySelector('#vbar .life');
    if (first){ first.classList.add('active'); ACTIVE_LIFE = Number(first.getAttribute('data-id'))||0; }
  })();

  /* ==== Summary ==== */
  async function loadSummary(){
    const r = await apiGET('summary');
    if (!r.ok || !r.data) return;
    const t = r.data.tournament || {};
    if (!TID_NUM && t.id) TID_NUM = Number(t.id)||0;
    $('#tTitle').textContent = t.name || t.title || 'Torneo Flash';
    $('#tCode').textContent  = t.code ? ('#'+t.code) : (t.id?('#'+t.id):'#');
    const st = (t.state||'open').toString().toUpperCase();
    const lab = st.includes('END')||st.includes('CLOSED')||st.includes('FINAL') ? 'CHIUSO'
             : ((t.lock_at && Date.now()>=new Date(t.lock_at).getTime()) ? 'IN CORSO' : 'APERTO');
    const se=$('#tState'); se.textContent=lab; se.className='state '+(lab==='APERTO'?'open':(lab==='IN CORSO'?'live':'end'));

    let pool = (typeof t.pool_coins!=='undefined' && t.pool_coins!==null) ? Number(t.pool_coins) : <?= $pre['pool']!==null ? json_encode($pre['pool']) : 'null' ?>;
    if ((pool===null || Number.isNaN(pool)) && t.buyin && (t.buyin_to_prize_pct || t.prize_pct) && typeof t.lives_total!=='undefined'){
      const pct = (t.buyin_to_prize_pct || t.prize_pct);
      const P   = (pct>0 && pct<=1) ? pct*100 : pct;
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
        d.addEventListener('click', async ()=>{
          $$('.life').forEach(x=>x.classList.remove('active'));
          d.classList.add('active'); ACTIVE_LIFE = Number(lv.id);
          await Promise.all([loadRound(1,'eventsR1'), loadRound(2,'eventsR2'), loadRound(3,'eventsR3')]);
        });
        vbar.appendChild(d);
      });
      const first=$('.life'); if(first){ first.classList.add('active'); ACTIVE_LIFE = Number(first.getAttribute('data-id'))||0; }
    }
  }

  /* ==== Render eventi ==== */
  function renderEvents(list, mountId){
    const box = document.getElementById(mountId); if(!box) return;
    if (!list || !list.length){ box.innerHTML='<div class="muted">Nessun evento per questo round.</div>'; return; }
    box.innerHTML='';
    list.forEach(ev=>{
      const rawPick = (ev.my_pick || ev.choice || ev.pick || '').toString().toLowerCase();
      const wasHome = ['1','h','home','casa'].includes(rawPick);
      const wasDraw = ['x','d','draw','pareggio'].includes(rawPick);
      const wasAway = ['2','a','away','trasferta'].includes(rawPick);

      const hKey = String(ev.home_id ?? '');
      const aKey = String(ev.away_id ?? '');
      const homeLogo = ev.home_logo || TEAM_LOGOS[hKey] || (ev.home_id ? `/assets/logos/${ev.home_id}.png` : '');
      const awayLogo = ev.away_logo || TEAM_LOGOS[aKey] || (ev.away_id ? `/assets/logos/${ev.away_id}.png` : '');

      const wrap=document.createElement('div'); wrap.className='eitem';
      const oval=document.createElement('div'); oval.className='evt';
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

      async function trySubmit(toSend){
        // 1) submit_picks
        let res = await flashPOST('submit_picks', { life_id: ACTIVE_LIFE, payload: toSend });
        if (res && res.ok && (!res.data || res.data.ok!==false)) return { ok:true, via:'submit_picks', res };
        // 2) fallback: single_pick per ognuno
        for (const row of toSend){
          let r1 = await flashPOST('single_pick', {
            life_id: ACTIVE_LIFE,
            round_no: row.round_no || row.round,
            event_code: row.event_code || '',
            team_id: row.team_id || '',
            pick: row.pick || row.choice_code || '',
            choice: row.choice || ''
          });
          if (!r1 || r1.ok===false || (r1.data && r1.data.ok===false)){
            // 3) altri alias
            let r2 = await flashPOST('pick', {
              life_id: ACTIVE_LIFE,
              round_no: row.round_no || row.round,
              event_code: row.event_code || '',
              team_id: row.team_id || '',
              pick: row.pick || row.choice_code || '',
              choice: row.choice || ''
            });
            if (!r2 || r2.ok===false || (r2.data && r2.data.ok===false)){
              let r3 = await flashPOST('set_pick', {
                life_id: ACTIVE_LIFE,
                round_no: row.round_no || row.round,
                event_code: row.event_code || '',
                team_id: row.team_id || '',
                pick: row.pick || row.choice_code || '',
                choice: row.choice || ''
              });
              if (!r3 || r3.ok===false || (r3.data && r3.data.ok===false))
                return { ok:false, res:(r1||r2||r3) };
            }
          }
        }
        return { ok:true, via:'fallback' };
      }

      async function doPick(choice){
        if (!ACTIVE_LIFE){
          showAlert('Seleziona una vita', 'Prima seleziona una vita nella sezione <strong>Le mie vite</strong>.');
          return;
        }
        // guard: solo log, non blocca
        await pgFlash('pick', { round:(ev.round||1) }).catch(()=>{});

        const roundNo = Number(ev.round || 1);
        const row = {
          life_id:  ACTIVE_LIFE,
          round:    roundNo,
          round_no: roundNo,
          event_id: ev.id ?? ev.event_id ?? null,
          event_code: ev.event_code || '',
          choice:   (choice==='home' ? 'home' : (choice==='draw' ? 'draw' : 'away')),
          choice_code: (choice==='home' ? 'HOME' : (choice==='draw' ? 'DRAW' : 'AWAY')),
          pick:     (choice==='home' ? '1' : (choice==='draw' ? 'X' : '2')),
          team_id:  (choice==='home' ? (ev.home_id ?? ev.home_team_id ?? '') : (choice==='away' ? (ev.away_id ?? ev.away_team_id ?? '') : ''))
        };
        if (!PICKS[ACTIVE_LIFE]) PICKS[ACTIVE_LIFE] = {};
        PICKS[ACTIVE_LIFE][roundNo] = row;

        [bHome,bDraw,bAway].forEach(b=>b.classList.remove('active'));
        if (choice==='home') bHome.classList.add('active');
        if (choice==='draw') bDraw.classList.add('active');
        if (choice==='away') bAway.classList.add('active');
        oval.querySelector('.team.home')?.classList.toggle('picked', choice==='home');
        oval.querySelector('.team.away')?.classList.toggle('picked', choice==='away');

        const bag = PICKS[ACTIVE_LIFE];
        const toSend = [bag[1], bag[2], bag[3]].filter(Boolean);
        if (toSend.length === 3){
          $('#mdTitle').textContent='Conferma 3 scelte';
          $('#mdText').innerHTML = 'Confermi l’invio delle <strong>3 scelte</strong> per questa vita?';
          const okBtn = $('#mdOk'); const clone = okBtn.cloneNode(true); okBtn.parentNode.replaceChild(clone, okBtn);
          $('#mdConfirm').setAttribute('aria-hidden','false');
          const ok = $('#mdOk');
          ok.addEventListener('click', async ()=>{
            ok.disabled=true;
            try{
              const rsub = await trySubmit(toSend);
              if (!rsub.ok){
                const err = (rsub.res && rsub.res.data && (rsub.res.data.detail||rsub.res.data.error)) ? (rsub.res.data.detail||rsub.res.data.error) : 'Scelte non registrate';
                showAlert('Errore scelte', err); return;
              }
              lsSavePicks(FCOD, ACTIVE_LIFE, { 1: toSend[0], 2: toSend[1], 3: toSend[2] });
              toast('Scelte inviate');
              delete PICKS[ACTIVE_LIFE];

              await Promise.all([
                loadSummary(),
                loadLives(),
                loadRound(1,'eventsR1'),
                loadRound(2,'eventsR2'),
                loadRound(3,'eventsR3')
              ]);

              (function reapplyFromLS(){
                const bag = lsGetPicks(FCOD, ACTIVE_LIFE) || {};
                function applyPickToRoundBox(mountId, pick){
                  if (!pick) return;
                  const box = document.getElementById(mountId);
                  if (!box) return;
                  const evt = box.querySelector('.evt');
                  const ch  = box.querySelector('.choices');
                  if (!evt || !ch) return;
                  const bHome = ch.querySelectorAll('.btn')[0];
                  const bDraw = ch.querySelectorAll('.btn')[1];
                  const bAway = ch.querySelectorAll('.btn')[2];
                  [bHome,bDraw,bAway].forEach(b=>b && b.classList.remove('active'));
                  evt.querySelector('.team.home')?.classList.remove('picked');
                  evt.querySelector('.team.away')?.classList.remove('picked');
                  const cc = String(pick.choice || '').toLowerCase();
                  if (cc==='home'){ bHome?.classList.add('active'); evt.querySelector('.team.home')?.classList.add('picked'); }
                  if (cc==='draw'){ bDraw?.classList.add('active'); }
                  if (cc==='away'){ bAway?.classList.add('active'); evt.querySelector('.team.away')?.classList.add('picked'); }
                }
                applyPickToRoundBox('eventsR1', bag[1]);
                applyPickToRoundBox('eventsR2', bag[2]);
                applyPickToRoundBox('eventsR3', bag[3]);
              })();

            } finally {
              ok.disabled=false; document.getElementById('mdConfirm').setAttribute('aria-hidden','true');
            }
          }, { once:true });
        } else {
          toast('Scelta registrata: completa i tre round');
        }
      }
      bHome.addEventListener('click', ()=>doPick('home'));
      bDraw.addEventListener('click', ()=>doPick('draw'));
      bAway.addEventListener('click', ()=>doPick('away'));

      wrap.appendChild(oval);
      wrap.appendChild(choices);
      box.appendChild(wrap);
    });
  }

  async function loadRound(round, mountId){
    if (Array.isArray(PRE_EVENTS[round]) && PRE_EVENTS[round].length){
      renderEvents(PRE_EVENTS[round].map(e=>({...e, round})), mountId);
      return;
    }
    const box = document.getElementById(mountId); 
    if (box) box.innerHTML = '<div class="muted">Caricamento…</div>';

    let r = await apiGET('list_events', { round_no: round, life_id: String(ACTIVE_LIFE||0) });
    if (!r.ok || !r.data || r.data.ok === false) r = await apiGET('events', { round });
    if (!r.ok || !r.data || r.data.ok === false) r = await apiGET('round_events', { round });

    if (!r.ok || !r.data) { if (box) box.innerHTML = '<div class="muted">Errore caricamento.</div>'; return; }

    const payload = r.data.rows || r.data.events || [];
    const bagLS = lsGetPicks(FCOD, ACTIVE_LIFE);
    const evs = (Array.isArray(payload) ? payload : []).map(e => {
      const out = {
        ...e,
        round,
        home_id: e.home_id ?? e.home_team_id ?? e.home ?? null,
        away_id: e.away_id ?? e.away_team_id ?? e.away ?? null
      };
      const rPick = bagLS?.[round];
      if (rPick && ((rPick.event_id && rPick.event_id==e.id) || (rPick.event_code && rPick.event_code==e.event_code))) {
        out.my_pick = rPick.pick;
        out.choice  = rPick.choice;
      }
      return out;
    });

    renderEvents(evs, mountId);
  }

  // BUY LIFE
  $('#btnBuy').addEventListener('click', async ()=>{
    await pgFlash('buy_life').catch(()=>{});
    if (!window.__FLASH_TID_NUM) { await loadSummary(); }

    $('#mdTitle').textContent='Acquista vita';
    $('#mdText').innerHTML='Confermi l’acquisto di <strong>1 vita</strong>?';
    const okBtn = $('#mdOk'); const clone = okBtn.cloneNode(true); okBtn.parentNode.replaceChild(clone, okBtn);
    $('#mdConfirm').setAttribute('aria-hidden','false');
    const ok = $('#mdOk');

    ok.addEventListener('click', async ()=>{
      ok.disabled=true;
      try{
        const res = await corePOST('buy_life', {});
        if (!res || res.ok===false || (res.data && res.data.ok===false)) {
          const err = (res && (res.detail||res.error)) ? (res.detail||res.error) : (res?.data?.error||'Errore acquisto');
          showAlert('Errore acquisto', err);
          if (DBG) console.debug('BUY_LIFE_FAIL', res);
        } else {
          toast('Vita acquistata');
          document.dispatchEvent(new CustomEvent('refresh-balance'));
          await Promise.all([loadSummary(), loadLives(), loadRound(1,'eventsR1'), loadRound(2,'eventsR2'), loadRound(3,'eventsR3')]);
        }
      } finally {
        ok.disabled=false; document.getElementById('mdConfirm').setAttribute('aria-hidden','true');
      }
    }, { once:true });
  });

  // UNJOIN
  $('#btnUnjoin').addEventListener('click', async ()=>{
    await pgFlash('unjoin').catch(()=>{});
    if (!window.__FLASH_TID_NUM) { await loadSummary(); }

    $('#mdTitle').textContent='Disiscrizione';
    $('#mdText').innerHTML='Confermi la disiscrizione?';
    const okBtn = $('#mdOk'); const clone = okBtn.cloneNode(true); okBtn.parentNode.replaceChild(clone, okBtn);
    $('#mdConfirm').setAttribute('aria-hidden','false');
    const ok = $('#mdOk');

    ok.addEventListener('click', async ()=>{
      ok.disabled=true;
      try{
        const res = await corePOST('unjoin', {});
        if (!res || res.ok===false || (res.data && res.data.ok===false)) {
          const err = (res && (res.detail||res.error)) ? (res.detail||res.error) : (res?.data?.error||'Errore disiscrizione');
          showAlert('Errore disiscrizione', err);
          if (DBG) console.debug('UNJOIN_FAIL', res);
        } else {
          toast('Disiscrizione eseguita');
          document.dispatchEvent(new CustomEvent('refresh-balance'));
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
