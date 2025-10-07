<?php
require_once __DIR__ . '/../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }

/* Helpers */
function json($a){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($a); exit; }
function tourByCode(PDO $pdo, $code){
  $st=$pdo->prepare("SELECT * FROM tournaments WHERE tour_code=? LIMIT 1");
  $st->execute([$code]);
  return $st->fetch(PDO::FETCH_ASSOC);
}

/* ===== AJAX ===== */
if (isset($_GET['action'])) {
  $a = $_GET['action'];

  /* Lista tornei chiusi */
  if ($a==='list_closed') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0'); header('Pragma: no-cache');
    $st=$pdo->query("SELECT id,tour_code,name,created_at,guaranteed_prize,buyin,buyin_to_prize_pct
                     FROM tournaments WHERE status='closed'
                     ORDER BY created_at DESC");
    json(['ok'=>true,'rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
  }

  /* Dettaglio torneo (round + eventi + partecipanti) */
  if ($a==='tournament_detail') {
    $code = trim($_GET['code'] ?? '');
    if ($code==='') json(['ok'=>false,'error'=>'code_missing']);
    $tour = tourByCode($pdo,$code);
    if (!$tour || ($tour['status']??'')!=='closed') json(['ok'=>false,'error'=>'not_found']);

    // round->eventi
    $st=$pdo->prepare("SELECT round, id AS event_id, home_team_id, away_team_id, result
                       FROM tournament_events
                       WHERE tournament_id=?
                       ORDER BY round ASC, id ASC");
    $st->execute([$tour['id']]);
    $events = $st->fetchAll(PDO::FETCH_ASSOC);

    // mappa team id -> nome
    $tmap=[]; if ($events){
      $ids=[]; foreach($events as $e){ $ids[]=(int)$e['home_team_id']; $ids[]=(int)$e['away_team_id']; }
      $ids=array_values(array_unique($ids));
      if ($ids){
        $in = implode(',', array_fill(0,count($ids),'?'));
        $stm=$pdo->prepare("SELECT id,name FROM teams WHERE id IN ($in)");
        $stm->execute($ids);
        foreach($stm->fetchAll(PDO::FETCH_ASSOC) as $r){ $tmap[(int)$r['id']]=$r['name']; }
      }
    }
    $byRound=[];
    foreach($events as $e){
      $r=(int)$e['round'];
      $byRound[$r][]=[
        'event_id'=>(int)$e['event_id'],
        'home'=>$tmap[(int)$e['home_team_id']] ?? ('#'.$e['home_team_id']),
        'away'=>$tmap[(int)$e['away_team_id']] ?? ('#'.$e['away_team_id']),
        'result'=>$e['result']
      ];
    }

    // partecipanti
    $stp=$pdo->prepare("SELECT u.id,u.username,
                               SUM(CASE WHEN tl.status IN ('alive','out','paid') THEN 1 ELSE 0 END) AS lives_total,
                               SUM(CASE WHEN tl.status='paid' THEN 1 ELSE 0 END) AS lives_paid
                        FROM tournament_lives tl
                        JOIN users u ON u.id=tl.user_id
                        WHERE tl.tournament_id=?
                        GROUP BY u.id,u.username
                        ORDER BY u.username ASC");
    $stp->execute([$tour['id']]);
    $participants=$stp->fetchAll(PDO::FETCH_ASSOC);

    json(['ok'=>true,'tournament'=>[
      'code'=>$tour['tour_code'],'name'=>$tour['name'],'created_at'=>$tour['created_at'],
      'guaranteed_prize'=>$tour['guaranteed_prize'],'buyin'=>$tour['buyin'],'pct'=>$tour['buyin_to_prize_pct']
    ],'rounds'=>$byRound,'participants'=>$participants]);
  }

  /* Picks per utente */
  if ($a==='user_picks') {
    $code = trim($_GET['code'] ?? ''); $uid=(int)($_GET['user'] ?? 0);
    if ($code==='' || !$uid) json(['ok'=>false,'error'=>'params_missing']);
    $tour = tourByCode($pdo,$code);
    if (!$tour) json(['ok'=>false,'error'=>'not_found']);

    $sql="SELECT tp.life_id,tp.round,tp.event_id,tp.choice,te.result,
                 th.name AS home, ta.name AS away
          FROM tournament_picks tp
          JOIN tournament_events te ON te.id=tp.event_id
          JOIN teams th ON th.id=te.home_team_id
          JOIN teams ta ON ta.id=te.away_team_id
          JOIN tournament_lives tl ON tl.id=tp.life_id
          WHERE tp.tournament_id=? AND tl.user_id=?
          ORDER BY tp.round ASC, tp.life_id ASC";
    $st=$pdo->prepare($sql); $st->execute([$tour['id'],$uid]);
    $rows=$st->fetchAll(PDO::FETCH_ASSOC);

    json(['ok'=>true,'rows'=>$rows]);
  }

  http_response_code(400); json(['ok'=>false,'error'=>'unknown_action']);
}

/* ===== VIEW ===== */
$page_css = '/pages-css/admin-dashboard.css';
include __DIR__ . '/../partials/head.php';

/* Header dinamico in base al profilo */
$role = $_SESSION['role'] ?? null;
$isAdmin = !empty($_SESSION['is_admin']);
if ($isAdmin || $role==='ADMIN') {
  include __DIR__ . '/../partials/header_admin.php';
} elseif ($role==='PUNTO') {
  include __DIR__ . '/../partials/header_punto.php';
} elseif ($role==='USER') {
  include __DIR__ . '/../partials/header_user.php';
} else {
  include __DIR__ . '/../partials/header_guest.php';
}

$code = trim($_GET['code'] ?? '');
$user = isset($_GET['user']) ? (int)$_GET['user'] : 0;
?>
<style>
  /* ======= Look “card eleganti” — solo stile, nessun cambio logico ======= */
  .section{ padding-top:24px; }
  .container{ max-width:1100px; margin:0 auto; }
  h1{ color:#fff; font-size:26px; font-weight:900; letter-spacing:.2px; margin:0 0 12px; }
  .muted{ color:#9ca3af; font-weight:500; }

  /* Card scura premium */
  .card{
    position:relative; border-radius:20px; padding:18px;
    background:
      radial-gradient(1000px 300px at 50% -120px, rgba(99,102,241,.10), transparent 60%),
      linear-gradient(135deg,#0e1526 0%, #0b1220 100%);
    border:1px solid rgba(255,255,255,.08);
    color:#fff;
    box-shadow: 0 20px 60px rgba(0,0,0,.35);
    transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease, background .15s ease;
    overflow:hidden; margin-bottom:16px;
  }
  .card::before{
    content:""; position:absolute; left:0; top:0; bottom:0; width:4px;
    background:linear-gradient(180deg,#1e3a8a 0%, #0ea5e9 100%); opacity:.35;
  }
  .card:hover{ transform: translateY(-2px); box-shadow: 0 26px 80px rgba(0,0,0,.48); border-color:#21324b; }
  .card-title{ margin:0 0 10px; font-size:18px; font-weight:800; color:#dbeafe; letter-spacing:.3px; }

  /* Griglia info (tourCard) */
  .grid2{ display:grid; grid-template-columns:1fr 1fr; gap:16px; }
  @media (max-width:860px){ .grid2{ grid-template-columns:1fr; } }

  /* Tabella elegante */
  .table-wrap{ overflow:auto; border-radius:12px; }
  .table{ width:100%; border-collapse:separate; border-spacing:0; }
  .table thead th{
    text-align:left; font-weight:900; font-size:12px; letter-spacing:.3px;
    color:#9fb7ff; padding:10px 12px; background:#0f172a; border-bottom:1px solid #1e293b;
  }
  .table tbody td{
    padding:12px; border-bottom:1px solid #122036; color:#e5e7eb; font-size:14px;
    background:linear-gradient(0deg, rgba(255,255,255,.02), rgba(255,255,255,.02));
    vertical-align:middle;
  }
  .table tbody tr:hover td{ background:rgba(255,255,255,.025); }
  .table tbody tr:last-child td{ border-bottom:0; }

  /* Footer tabella */
  .table-foot{
    display:flex; justify-content:space-between; align-items:center;
    gap:8px; padding:10px 0; color:#9ca3af;
  }

  /* Chip/badge */
  .chip{
    display:inline-block; height:26px; line-height:26px; padding:0 10px; border-radius:9999px;
    border:1px solid #243244; background:transparent; font-size:12px;
  }
  .chip--ok{ border-color:#27ae60; color:#a7e3bf; background:rgba(39,174,96,.08); }
  .chip--off{ border-color:#ff8a8a; color:#ff8a8a; background:rgba(255,138,138,.08); }

  /* Bottoni nella tabella */
  .table td .btn{ height:32px; line-height:32px; border-radius:9999px; padding:0 12px; }
</style>

<main>
  <section class="section">
    <div class="container">
      <?php if ($code==='' && !$user): ?>
      <h1>Tornei chiusi</h1>
      <div class="card">
        <h2 class="card-title">Elenco</h2>
        <div class="table-wrap">
          <table class="table" id="tbl">
            <thead>
              <tr>
                <th>Codice</th>
                <th>Nome</th>
                <th>Creato il</th>
                <th>Montepremi</th>
                <th>Apri</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
        <div class="table-foot"><span id="rowsInfo"></span></div>
      </div>

      <?php elseif ($code!=='' && !$user): ?>
      <h1>Torneo chiuso</h1>
      <div class="card" id="tourCard"></div>

      <div class="card" id="roundsCard" style="margin-top:16px;">
        <h2 class="card-title">Round ed eventi</h2>
        <div id="roundsWrap"></div>
      </div>

      <div class="card" id="usersCard" style="margin-top:16px;">
        <h2 class="card-title">Utenti partecipanti</h2>
        <div class="table-wrap">
          <table class="table" id="tblUsers">
            <thead>
              <tr>
                <th>User</th>
                <th>Vite totali</th>
                <th>Vite vincenti</th>
                <th>Dettaglio</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      </div>

      <?php else: ?>
      <h1>Scelte utente</h1>
      <div class="card">
        <h2 class="card-title">Dettaglio pick</h2>
        <div class="table-wrap">
          <table class="table" id="tblPicks">
            <thead>
              <tr>
                <th>Round</th>
                <th>Evento</th>
                <th>Scelta</th>
                <th>Risultato</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
        <a class="btn btn--outline" href="/tornei-chiusi.php?code=<?= htmlspecialchars($code) ?>">← Torna al torneo</a>
      </div>
      <?php endif; ?>
    </div>
  </section>
</main>
<?php include __DIR__ . '/../partials/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', ()=>{
  const $ = s=>document.querySelector(s);

  const pageCode = new URLSearchParams(location.search).get('code') || '';
  const pageUser = new URLSearchParams(location.search).get('user') || '';

  /* Lista tornei chiusi */
async function loadClosed(){
  try{
    const r = await fetch('?action=list_closed', {
      cache:'no-store',
      headers:{'Cache-Control':'no-cache'}
    });

    const txt = await r.text();
    let j = null;
    try { j = JSON.parse(txt); } catch(e) {
      console.error('list_closed non-JSON:', txt);
      alert('Errore caricamento elenco tornei chiusi (risposta non valida).');
      return;
    }
    if(!j.ok){ console.error(j); alert('Errore caricamento: ' + (j.error||'')); return; }

    const tb = document.querySelector('#tbl tbody');
    tb.innerHTML='';

    j.rows.forEach(row=>{
      // formattazione sicura data/ora
      let created = '-';
      if (row.created_at) {
        try {
          const iso = row.created_at.includes('T') ? row.created_at : row.created_at.replace(' ','T');
          created = new Date(iso).toLocaleString();
        } catch(e) {
          console.warn('created_at non parsabile:', row.created_at, e);
          created = row.created_at; // fallback grezzo
        }
      }

      // montepremi safe
      const pool = (row.guaranteed_prize !== null && row.guaranteed_prize !== undefined && row.guaranteed_prize !== '')
        ? Number(row.guaranteed_prize).toFixed(2)
        : '-';

      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${row.tour_code}</td>
        <td>${row.name}</td>
        <td>${created}</td>
        <td>${pool}</td>
        <td><a class="btn btn--outline btn--sm" href="/tornei-chiusi.php?code=${encodeURIComponent(row.tour_code)}">Apri</a></td>
      `;
      tb.appendChild(tr);
    });

    document.getElementById('rowsInfo').textContent = `${j.rows.length} torneo/i chiusi`;
  }catch(err){
    console.error('loadClosed error:', err);
    alert('Errore imprevisto nel caricamento elenco.');
  }
}

  /* Dettaglio torneo */
  async function loadTourDetail(code){
    const r = await fetch(`?action=tournament_detail&code=${encodeURIComponent(code)}`, {cache:'no-store'});
    const j = await r.json(); if(!j.ok){ alert('Torneo non trovato'); return; }

    const t = j.tournament;
    document.getElementById('tourCard').innerHTML = `
      <div class="grid2">
        <div><div class="muted">Codice</div><div><strong>${t.code}</strong></div></div>
        <div><div class="muted">Nome</div><div><strong>${t.name}</strong></div></div>
        <div><div class="muted">Creato</div><div>${new Date(t.created_at.replace(' ','T')).toLocaleString()}</div></div>
        <div><div class="muted">Montepremi</div><div>${t.guaranteed_prize ? Number(t.guaranteed_prize).toFixed(2) : '-'}</div></div>
      </div>
    `;

    const wrap = document.getElementById('roundsWrap');
    wrap.innerHTML = '';
    const rounds = Object.keys(j.rounds).map(n=>parseInt(n,10)).sort((a,b)=>a-b);
    if (rounds.length===0) wrap.innerHTML = '<p class="muted">Nessun evento registrato.</p>';
    rounds.forEach(rn=>{
      const card = document.createElement('div');
      card.className = 'card';
      let rows = '';
      j.rounds[rn].forEach(ev=>{
        rows += `
          <tr>
            <td>${ev.event_id}</td>
            <td>${ev.home} vs ${ev.away}</td>
            <td>${ev.result}</td>
          </tr>
        `;
      });
      card.innerHTML = `
        <h3 class="card-title">Round ${rn}</h3>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>ID</th><th>Match</th><th>Esito</th></tr></thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
      `;
      wrap.appendChild(card);
    });

    const tbU = document.querySelector('#tblUsers tbody'); tbU.innerHTML='';
    j.participants.forEach(u=>{
      const isWinner = Number(u.lives_paid)>0;
      const badge = isWinner ? `<span class="chip chip--ok">Vincitore</span>` : '';
      const link = `<a href="/tornei-chiusi.php?code=${encodeURIComponent(code)}&user=${u.id}" class="btn btn--outline btn--sm">Vedi scelte</a>`;
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${u.username} ${badge}</td>
        <td>${u.lives_total}</td>
        <td>${u.lives_paid}</td>
        <td>${link}</td>
      `;
      tbU.appendChild(tr);
    });
  }

  /* Picks utente */
  async function loadUserPicks(code, user){
    const r = await fetch(`?action=user_picks&code=${encodeURIComponent(code)}&user=${encodeURIComponent(user)}`, {cache:'no-store'});
    const j = await r.json(); if(!j.ok){ alert('Errore caricamento'); return; }
    const tb = document.querySelector('#tblPicks tbody'); tb.innerHTML='';
    j.rows.forEach(p=>{
      const pass = (p.result==='POSTPONED' || p.result==='VOID' || (p.result==='HOME' && p.choice==='HOME') || (p.result==='AWAY' && p.choice==='AWAY'));
      const draw = p.result==='DRAW';
      const outcome = pass ? '<span class="chip chip--ok">passa</span>' : (draw ? '<span class="chip chip--off">pari (out)</span>' : '<span class="chip chip--off">out</span>');
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${p.round}</td>
        <td>${p.home} vs ${p.away}</td>
        <td>${p.choice}</td>
        <td>${p.result} &nbsp; ${outcome}</td>
      `;
      tb.appendChild(tr);
    });
  }

/* Router semplice */
if (!pageCode && !pageUser)      loadClosed();
else if (pageCode && !pageUser)  loadTourDetail(pageCode);
else if (pageCode && pageUser)   loadUserPicks(pageCode, pageUser);
});
</script>
