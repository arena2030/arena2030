<?php
require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['uid']) || !(($_SESSION['role'] ?? 'USER')==='ADMIN' || (int)($_SESSION['is_admin'] ?? 0)===1)) {
  header('Location: /login.php'); exit;
}

function json($a){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($a); exit; }
function only_post(){ if ($_SERVER['REQUEST_METHOD']!=='POST'){ http_response_code(405); json(['ok'=>false,'error'=>'method']); } }
function tourByCode(PDO $pdo, $code){ $st=$pdo->prepare("SELECT * FROM tournaments WHERE tour_code=? LIMIT 1"); $st->execute([$code]); return $st->fetch(PDO::FETCH_ASSOC); }
function genCode($len=6){ $n=random_int(0,36**$len-1); $b=strtoupper(base_convert($n,10,36)); return str_pad($b,$len,'0',STR_PAD_LEFT); }
function getFreeCode(PDO $pdo,$table,$col){ for($i=0;$i<12;$i++){ $c=genCode(6); $st=$pdo->prepare("SELECT 1 FROM {$table} WHERE {$col}=? LIMIT 1"); $st->execute([$c]); if(!$st->fetch()) return $c; } throw new RuntimeException('code'); }

$code = trim($_GET['code'] ?? '');
$uiRound = isset($_GET['round']) ? max(1, (int)$_GET['round']) : null;

/* ==== AJAX ==== */
if (isset($_GET['action'])) {
  if ($code==='') json(['ok'=>false,'error'=>'tour_code_missing']);
  $tour = tourByCode($pdo,$code);
  if (!$tour) json(['ok'=>false,'error'=>'tour_not_found']);

  $round = isset($_GET['round']) ? max(1,(int)$_GET['round']) : 1;
  $a=$_GET['action'];

  /* teams suggest */
  if ($a==='teams_suggest') {
    $q=trim($_GET['q'] ?? ''); $lim=20;
    if ($q===''){ $st=$pdo->query("SELECT id,name FROM teams ORDER BY name ASC LIMIT $lim"); json(['ok'=>true,'rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]); }
    $st=$pdo->prepare("SELECT id,name FROM teams WHERE name LIKE ? ORDER BY name ASC LIMIT $lim"); $st->execute(['%'.$q.'%']);
    json(['ok'=>true,'rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
  }

  /* list eventi per round */
  if ($a==='list_events') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0'); header('Pragma: no-cache');
    $st=$pdo->prepare("SELECT te.id,te.event_code,te.home_team_id,te.away_team_id,te.is_locked,te.result,te.round,
                              th.name AS home_name, ta.name AS away_name
                       FROM tournament_events te
                       JOIN teams th ON th.id=te.home_team_id
                       JOIN teams ta ON ta.id=te.away_team_id
                       WHERE te.tournament_id=? AND te.round=?
                       ORDER BY te.id DESC");
    $st->execute([$tour['id'], $round]);
    json(['ok'=>true,'rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
  }

  /* add event nel round corrente */
  if ($a==='add_event') {
    only_post();
    $homeId=(int)($_POST['home_team_id'] ?? 0); $awayId=(int)($_POST['away_team_id'] ?? 0);
    $homeName=trim($_POST['home_team_name'] ?? ''); $awayName=trim($_POST['away_team_name'] ?? '');
    $evRound = (int)($_POST['round'] ?? $round); if ($evRound<1) $evRound=1;

    if ($homeId<=0 && $homeName!==''){ $s=$pdo->prepare("SELECT id FROM teams WHERE name=? LIMIT 1"); $s->execute([$homeName]); $homeId=(int)$s->fetchColumn();
      if($homeId<=0){ $s=$pdo->prepare("SELECT id FROM teams WHERE name LIKE ? ORDER BY name ASC LIMIT 1"); $s->execute(['%'.$homeName.'%']); $homeId=(int)$s->fetchColumn(); } }
    if ($awayId<=0 && $awayName!==''){ $s=$pdo->prepare("SELECT id FROM teams WHERE name=? LIMIT 1"); $s->execute([$awayName]); $awayId=(int)$s->fetchColumn();
      if($awayId<=0){ $s=$pdo->prepare("SELECT id FROM teams WHERE name LIKE ? ORDER BY name ASC LIMIT 1"); $s->execute(['%'.$awayName.'%']); $awayId=(int)$s->fetchColumn(); } }
    if ($homeId<=0 || $awayId<=0 || $homeId===$awayId) json(['ok'=>false,'error'=>'teams_invalid']);

    try{
      $codeEv=getFreeCode($pdo,'tournament_events','event_code');
      $st=$pdo->prepare("INSERT INTO tournament_events(event_code,tournament_id,home_team_id,away_team_id,round) VALUES (?,?,?,?,?)");
      $st->execute([$codeEv,$tour['id'],$homeId,$awayId,$evRound]);
      json(['ok'=>true]);
    }catch(Throwable $e){ json(['ok'=>false,'error'=>'db','detail'=>$e->getMessage()]); }
  }

  /* toggle lock evento */
  if ($a==='toggle_lock_event') { only_post(); $id=(int)$_POST['event_id'];
    $st=$pdo->prepare("UPDATE tournament_events SET is_locked=CASE WHEN is_locked=1 THEN 0 ELSE 1 END WHERE id=? AND tournament_id=?");
    $st->execute([$id,$tour['id']]); json(['ok'=>true]); }

  /* update risultato */
  if ($a==='update_result_event') { only_post(); $id=(int)$_POST['event_id']; $result=$_POST['result'] ?? 'UNKNOWN';
    $allowed=['HOME','AWAY','DRAW','VOID','POSTPONED','UNKNOWN']; if(!in_array($result,$allowed,true)) json(['ok'=>false,'error'=>'result_invalid']);
    $st=$pdo->prepare("UPDATE tournament_events SET result=?, result_set_at=NOW() WHERE id=? AND tournament_id=?");
    $st->execute([$result,$id,$tour['id']]); json(['ok'=>true]); }

  /* delete evento */
  if ($a==='delete_event') { only_post(); $id=(int)$_POST['event_id'];
    $st=$pdo->prepare("DELETE FROM tournament_events WHERE id=? AND tournament_id=? LIMIT 1"); $st->execute([$id,$tour['id']]); json(['ok'=>true]); }

  /* set/rimuovi lock globale */
  if ($a==='set_lock') { only_post(); $lock=trim($_POST['lock_at'] ?? ''); $val=($lock!=='')?$lock:null;
    $st=$pdo->prepare("UPDATE tournaments SET lock_at=? WHERE id=?"); $st->execute([$val,$tour['id']]); json(['ok'=>true,'lock_at'=>$val]); }

  /* delete torneo
     - pending: elimina e basta
     - published: rimborsa vite alive/out e poi elimina tutto
  */
  if ($a==='delete_tournament') {
    only_post();
    $status = $tour['status'] ?? 'pending';
    if ($status === 'published') {
      $pdo->beginTransaction();
      try{
        // rimborsi per ogni utente
        $sqlC = "SELECT user_id, COUNT(*) AS lives
                 FROM tournament_lives
                 WHERE tournament_id=? AND status IN ('alive','out')
                 GROUP BY user_id";
        $stC = $pdo->prepare($sqlC); $stC->execute([$tour['id']]);
        $rows = $stC->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
          $refund = (float)$tour['buyin'] * (int)$r['lives'];
          if ($refund>0) {
            $pdo->prepare("UPDATE users SET coins = COALESCE(coins,0) + ? WHERE id=?")
                ->execute([ round($refund,2), (int)$r['user_id'] ]);
          }
        }
        // marca vite rimborsate
        $pdo->prepare("UPDATE tournament_lives SET status='refunded' WHERE tournament_id=? AND status IN ('alive','out')")
            ->execute([$tour['id']]);
        // elimina tutto
        $pdo->prepare("DELETE FROM tournament_picks WHERE tournament_id=?")->execute([$tour['id']]);
        $pdo->prepare("DELETE FROM tournament_events WHERE tournament_id=?")->execute([$tour['id']]);
        $pdo->prepare("DELETE FROM tournament_lives  WHERE tournament_id=?")->execute([$tour['id']]);
        $pdo->prepare("DELETE FROM tournaments      WHERE id=?")->execute([$tour['id']]);
        $pdo->commit();
        json(['ok'=>true,'refunded_users'=>count($rows)]);
      } catch(Throwable $e){
        $pdo->rollBack();
        json(['ok'=>false,'error'=>'delete_failed','detail'=>$e->getMessage()]);
      }
    } else {
      try{
        $pdo->prepare("DELETE FROM tournament_picks WHERE tournament_id=?")->execute([$tour['id']]);
        $pdo->prepare("DELETE FROM tournament_events WHERE tournament_id=?")->execute([$tour['id']]);
        $pdo->prepare("DELETE FROM tournament_lives  WHERE tournament_id=?")->execute([$tour['id']]);
        $pdo->prepare("DELETE FROM tournaments      WHERE id=?")->execute([$tour['id']]);
        json(['ok'=>true]);
      } catch(Throwable $e){
        json(['ok'=>false,'error'=>'delete_failed','detail'=>$e->getMessage()]);
      }
    }
  }

  /* publish torneo */
  if ($a==='publish') {
    only_post();
    $st = $pdo->prepare("UPDATE tournaments SET status='published' WHERE id=?");
    $st->execute([$tour['id']]);
    json(['ok'=>true]);
  }

  /* === calc round === */
  if ($a==='calc_round') {
    only_post();
    $round = isset($_GET['round']) ? max(1,(int)$_GET['round']) : 1;

    $pdo->beginTransaction();
    try {
      // picks con risultati
      $sql = "SELECT tl.id AS life_id, tl.user_id, tp.event_id, tp.choice, te.result
              FROM tournament_lives tl
              JOIN tournament_picks tp ON tp.life_id=tl.id AND tp.round=?
              JOIN tournament_events te ON te.id=tp.event_id
              WHERE tl.tournament_id=? AND tl.status='alive' AND tl.round=?";
      $st = $pdo->prepare($sql);
      $st->execute([$round, $tour['id'], $round]);
      $rows = $st->fetchAll(PDO::FETCH_ASSOC);

      if (!$rows) { $pdo->rollBack(); json(['ok'=>false,'error'=>'no_picks_for_round']); }

      $pass=[]; $out=[];
      foreach ($rows as $r) {
        $res = $r['result'];
        if ($res==='UNKNOWN') { $pdo->rollBack(); json(['ok'=>false,'error'=>'results_missing']); }
        $ok=false;
        if ($res==='POSTPONED' || $res==='VOID') $ok=true;
        elseif ($res==='HOME' && $r['choice']==='HOME') $ok=true;
        elseif ($res==='AWAY' && $r['choice']==='AWAY') $ok=true;
        elseif ($res==='DRAW') $ok=false;
        else $ok=false;

        if ($ok) $pass[]=(int)$r['life_id']; else $out[]=(int)$r['life_id'];
      }

      if ($out) {
        $in = str_repeat('?,', count($out)-1) . '?';
        $pdo->prepare("UPDATE tournament_lives SET status='out' WHERE id IN ($in)")->execute($out);
      }
      if ($pass) {
        $in = str_repeat('?,', count($pass)-1) . '?';
        $pdo->prepare("UPDATE tournament_lives SET round=round+1 WHERE id IN ($in)")->execute($pass);
      }

      // utenti vivi dopo calc
      $st2=$pdo->prepare("SELECT COUNT(*) AS lives, COUNT(DISTINCT user_id) AS users FROM tournament_lives WHERE tournament_id=? AND status='alive'");
      $st2->execute([$tour['id']]);
      $agg=$st2->fetch(PDO::FETCH_ASSOC);

      if ((int)$agg['users'] < 2) {
        $pdo->commit();
        json(['ok'=>false,'error'=>'not_enough_players','alive_lives'=>(int)$agg['lives'],'alive_users'=>(int)$agg['users']]);
      }

      $pdo->commit();
      json(['ok'=>true,'passed'=>count($pass),'out'=>count($out),'next_round'=>$round+1]);
    } catch(Throwable $e){
      $pdo->rollBack();
      json(['ok'=>false,'error'=>'calc_failed','detail'=>$e->getMessage()]);
    }
  }

  /* === close and pay === */
  if ($a==='close_and_pay') {
    only_post();

    // vite totali acquistate (per pool buyin)
    $totalLives = (int)$pdo->query("SELECT COUNT(*) FROM tournament_lives WHERE tournament_id={$tour['id']}")->fetchColumn();
    $pct = (float)$tour['buyin_to_prize_pct']; if ($pct<0) $pct=0; if ($pct>100) $pct=100;
    $poolFromBuyin = $totalLives * (float)$tour['buyin'] * ($pct/100.0);
    $guaranteed    = (float)($tour['guaranteed_prize'] ?? 0.0);
    $prizePool     = max($guaranteed, $poolFromBuyin);

    // vincitori (vite ancora alive)
    $sqlW = "SELECT user_id, COUNT(*) AS lives
             FROM tournament_lives
             WHERE tournament_id=? AND status='alive'
             GROUP BY user_id";
    $stW=$pdo->prepare($sqlW); $stW->execute([$tour['id']]);
    $winners=$stW->fetchAll(PDO::FETCH_ASSOC);

    $pdo->beginTransaction();
    try{
      if ($prizePool > 0 && $winners) {
        $totalLivesAlive = array_sum(array_map(fn($w)=> (int)$w['lives'], $winners));
        foreach ($winners as $w) {
          $share = $prizePool * ((int)$w['lives'] / $totalLivesAlive);
          $pdo->prepare("UPDATE users SET coins = COALESCE(coins,0) + ? WHERE id=?")
              ->execute([ round($share,2), (int)$w['user_id'] ]);
        }
      }

      // marca vite pagate e chiudi torneo
      $pdo->prepare("UPDATE tournament_lives SET status='paid' WHERE tournament_id=? AND status='alive'")
          ->execute([$tour['id']]);
      $pdo->prepare("UPDATE tournaments SET status='closed' WHERE id=?")->execute([$tour['id']]);

      $pdo->commit();
      json(['ok'=>true,'pool'=>round($prizePool,2),'winners'=>count($winners)]);
    } catch(Throwable $e){
      $pdo->rollBack();
      json(['ok'=>false,'error'=>'close_failed','detail'=>$e->getMessage()]);
    }
  }

  http_response_code(400); json(['ok'=>false,'error'=>'unknown_action']);
}

/* ===== Page view ===== */
$code = trim($_GET['code'] ?? '');
$tour = $code ? tourByCode($pdo,$code) : null;
if (!$tour) { echo "<main class='section'><div class='container'><h1>Torneo non trovato</h1></div></main>"; include __DIR__.'/../../partials/footer.php'; exit; }

$page_css='/pages-css/admin-dashboard.css';
include __DIR__.'/../../partials/head.php';
include __DIR__.'/../../partials/header_admin.php';

$maxRoundRow = $pdo->prepare("SELECT COALESCE(MAX(round),1) AS mr FROM tournament_events WHERE tournament_id=?");
$maxRoundRow->execute([$tour['id']]);
$maxRound = max(1, (int)$maxRoundRow->fetchColumn());

$currentRound = ($uiRound && $uiRound >=1 && $uiRound <= $maxRound) ? $uiRound : 1;
$isPublished  = isset($tour['status']) && $tour['status']==='published';

$teamsInit=$pdo->query("SELECT id,name FROM teams ORDER BY name ASC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
?>
<main>
<section class="section">
  <div class="container">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
      <h1>Torneo: <?= htmlspecialchars($tour['name']) ?> <span class="muted">(<?= htmlspecialchars($tour['tour_code']) ?>)</span></h1>
      <button type="button" class="btn btn--outline btn--sm btn-danger" id="btnDeleteTour">Elimina torneo</button>
    </div>

    <div class="card" style="margin-bottom:16px;">
      <h2 class="card-title">Impostazioni</h2>

      <div class="grid2" style="align-items:end;">
        <div class="field">
          <label class="label">Lock scelte (data/ora)</label>
          <input class="input light" id="lock_at" type="datetime-local" value="<?= $tour['lock_at'] ? date('Y-m-d\TH:i', strtotime($tour['lock_at'])) : '' ?>">
        </div>
        <div class="field">
          <label class="label">&nbsp;</label>
          <button type="button" class="btn btn--outline" id="btnToggleLock"><?= $tour['lock_at'] ? 'Rimuovi lock' : 'Imposta lock' ?></button>
        </div>
      </div>

      <?php if (!$isPublished): ?>
      <div style="margin-top:8px;">
        <button type="button" class="btn btn--primary" id="btnPublish">Pubblica torneo</button>
      </div>
      <?php else: ?>
      <div class="round-row" style="margin-top:8px;">
        <span class="label" style="margin:0 8px 0 0;">Round</span>
        <select id="round_select" class="select light round-select">
          <?php for($i=1;$i<=$maxRound;$i++): ?>
            <option value="<?= $i ?>" <?= $i===$currentRound ? 'selected':'' ?>>Round <?= $i ?></option>
          <?php endfor; ?>
        </select>
        <button type="button" class="btn btn--outline" id="btnCalcRound">Calcola round</button>
        <button type="button" class="btn btn--outline btn-danger" id="btnClosePay">Chiudi torneo e paga</button>
      </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2 class="card-title">Eventi</h2>

      <form id="fEv" class="grid2" onsubmit="return false;">
       <div class="field">
  <label class="label">Casa</label>
  <select id="home_team" class="select light team-select">
    <option value="">— Seleziona —</option>
    <?php foreach($teamsInit as $t): ?>
      <option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
    <?php endforeach; ?>
  </select>
</div>
<div class="field">
  <label class="label">Trasferta</label>
  <select id="away_team" class="select light team-select">
    <option value="">— Seleziona —</option>
    <?php foreach($teamsInit as $t): ?>
      <option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
    <?php endforeach; ?>
  </select>
</div>
        <div class="field" style="grid-column: span 2;">
          <button type="button" class="btn btn--primary" id="btnAddEv">Aggiungi evento</button>
        </div>
      </form>

      <div class="table-wrap" style="margin-top:12px;">
        <table class="table" id="tblEv">
          <thead><tr><th>Codice</th><th>Casa</th><th>Trasferta</th><th>Blocco</th><th>Risultato</th><th>Azioni</th></tr></thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>
</section>
</main>
<?php include __DIR__ . '/../../partials/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const $ = s=>document.querySelector(s);
  const tourCode   = "<?= htmlspecialchars($tour['tour_code']) ?>";
  const isPublished= <?= $isPublished ? 'true':'false' ?>;
  const currentRound = <?= (int)$currentRound ?>;
  const baseUrl    = `?code=${encodeURIComponent(tourCode)}&round=${encodeURIComponent(currentRound)}`;

  async function loadEvents(){
    const r = await fetch(`${baseUrl}&action=list_events`, {
      cache:'no-store',
      headers:{'Cache-Control':'no-cache, no-store, max-age=0','Pragma':'no-cache'}
    });
    const j = await r.json();
    if(!j.ok){ alert('Errore caricamento'); return; }

    const tb = document.getElementById('tblEv').querySelector('tbody');
    tb.innerHTML = '';

    j.rows.forEach(ev=>{
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${ev.event_code}</td>
        <td>${ev.home_name}</td>
        <td>${ev.away_name}</td>
        <td>
          <button type="button" class="btn btn--outline btn--sm" data-lock="${ev.id}">
            ${ev.is_locked==1 ? 'Sblocca' : 'Blocca'}
          </button>
        </td>
        <td>
          <select class="select light result-select" data-res="${ev.id}">
            <option value="UNKNOWN" ${ev.result==='UNKNOWN'?'selected':''}>—</option>
            <option value="HOME" ${ev.result==='HOME'?'selected':''}>Casa vince</option>
            <option value="AWAY" ${ev.result==='AWAY'?'selected':''}>Trasferta vince</option>
            <option value="DRAW" ${ev.result==='DRAW'?'selected':''}>Pareggio</option>
            <option value="VOID" ${ev.result==='VOID'?'selected':''}>Annullata</option>
            <option value="POSTPONED" ${ev.result==='POSTPONED'?'selected':''}>Rinviata</option>
          </select>
        </td>
        <td class="actions-cell">
          <button type="button" class="btn btn--outline btn--sm" data-save-res="${ev.id}">Applica</button>
          <button type="button" class="btn btn--outline btn--sm btn-danger" data-del="${ev.id}">Elimina</button>
        </td>
      `;
      tb.appendChild(tr);
    });
  }

document.getElementById('btnAddEv').addEventListener('click', async ()=>{
  const homeId = document.getElementById('home_team').value;
  const awayId = document.getElementById('away_team').value;

  if (!homeId || !awayId || homeId === awayId) {
    alert('Seleziona due squadre valide (diverse)'); 
    return;
  }

  const fd = new URLSearchParams({
    home_team_id: homeId,
    away_team_id: awayId,
    round: String(currentRound)
  });

  const r = await fetch(`${baseUrl}&action=add_event`, { method:'POST', body: fd });
  const j = await r.json();
  if (!j.ok) { alert('Errore: ' + (j.error || '')); return; }

  // reset e ricarica
  document.getElementById('home_team').value = '';
  document.getElementById('away_team').value = '';
  await loadEvents();
});

  document.getElementById('tblEv').addEventListener('click', async (e)=>{
    const b=e.target.closest('button'); if(!b) return;
    try{
      if(b.hasAttribute('data-lock')){
        const id=b.getAttribute('data-lock');
        const r=await fetch(`${baseUrl}&action=toggle_lock_event`,{method:'POST',body:new URLSearchParams({event_id:id})});
        const j=await r.json(); if(!j.ok){ alert('Errore lock'); return; } await loadEvents(); return;
      }
      if(b.hasAttribute('data-save-res')){
        const id=b.getAttribute('data-save-res'); const sel=document.querySelector(`select[data-res="${id}"]`); const res=sel?sel.value:'UNKNOWN';
        const r=await fetch(`${baseUrl}&action=update_result_event`,{method:'POST',body:new URLSearchParams({event_id:id,result:res})});
        const j=await r.json(); if(!j.ok){ alert('Errore risultato'); return; } await loadEvents(); return;
      }
      if(b.classList.contains('btn-danger') && b.hasAttribute('data-del')){
        const id=b.getAttribute('data-del'); if(!confirm('Eliminare l\'evento?')) return;
        const r=await fetch(`${baseUrl}&action=delete_event`,{method:'POST',body:new URLSearchParams({event_id:id})});
        const j=await r.json(); if(!j.ok){ alert('Errore eliminazione'); return; } await loadEvents(); return;
      }
    }catch(err){ console.error(err); alert('Errore imprevisto'); }
  });

  document.getElementById('btnToggleLock').addEventListener('click', async ()=>{
    const val=$('#lock_at').value.trim();
    const r=await fetch(`${baseUrl}&action=set_lock`,{method:'POST',body:new URLSearchParams({lock_at:val})});
    const j=await r.json(); if(!j.ok){ alert('Errore lock torneo'); return; }
    document.getElementById('btnToggleLock').textContent = (val==='') ? 'Imposta lock' : 'Rimuovi lock';
    alert('Lock aggiornato');
  });

  <?php if (!$isPublished): ?>
  document.getElementById('btnPublish').addEventListener('click', async ()=>{
    if(!confirm('Pubblicare il torneo?')) return;
    const r=await fetch(`${baseUrl}&action=publish`,{method:'POST'}); const j=await r.json();
    if(!j.ok){ alert('Errore publish'); return; }
    alert('Torneo pubblicato'); window.location.href='/admin/gestisci-tornei.php';
  });
  <?php endif; ?>

  <?php if ($isPublished): ?>
  document.getElementById('round_select').addEventListener('change', (e)=>{
    const v = e.target.value;
    window.location.href = `?code=${encodeURIComponent("<?= $tour['tour_code'] ?>")}&round=${encodeURIComponent(v)}`;
  });

  document.getElementById('btnCalcRound').addEventListener('click', async ()=>{
    const v = document.getElementById('round_select').value;
    const r = await fetch(`?code=${encodeURIComponent("<?= $tour['tour_code'] ?>")}&round=${encodeURIComponent(v)}&action=calc_round`, { method:'POST' });
    const j = await r.json();
    if (!j.ok) {
      if (j.error==='not_enough_players') alert('Non ci sono almeno 2 utenti in gioco. Chiudi e paga il torneo.');
      else if (j.error==='no_picks_for_round') alert('Nessuna pick per questo round.');
      else if (j.error==='results_missing') alert('Risultati mancanti in questo round.');
      else alert('Errore calcolo: ' + (j.detail||j.error));
      return;
    }
    alert(`Calcolo completato. Passano: ${j.passed}, Eliminati: ${j.out}.`);
    window.location.href = `?code=${encodeURIComponent("<?= $tour['tour_code'] ?>")}&round=${encodeURIComponent(Number(v)+1)}`;
  });

  document.getElementById('btnClosePay').addEventListener('click', async ()=>{
    const v = document.getElementById('round_select').value;
    if (!confirm(`Chiudere torneo e pagare (round ${v})?`)) return;
    const r = await fetch(`?code=${encodeURIComponent("<?= $tour['tour_code'] ?>")}&action=close_and_pay`, { method:'POST' });
    const j = await r.json();
    if (!j.ok) { alert('Errore chiusura: ' + (j.detail||j.error)); return; }
    alert(`Torneo chiuso. Montepremi distribuito: €${Number(j.pool).toFixed(2)} a ${j.winners} vincitore/i.`);
    window.location.href='/admin/gestisci-tornei.php';
  });
  <?php endif; ?>

  document.getElementById('btnDeleteTour').addEventListener('click', async ()=>{
    if(!confirm('Eliminare definitivamente il torneo e tutti i suoi eventi?')) return;
    const r=await fetch(`?code=${encodeURIComponent("<?= $tour['tour_code'] ?>")}&action=delete_tournament`,{method:'POST'}); const j=await r.json();
    if(!j.ok){ alert('Errore eliminazione torneo'); return; }
    window.location.href='/admin/crea-tornei.php';
  });

  loadEvents();
});
</script>
