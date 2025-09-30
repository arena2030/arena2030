<?php
require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['uid']) || !(($_SESSION['role'] ?? 'USER')==='ADMIN' || (int)($_SESSION['is_admin'] ?? 0)===1)) {
  header('Location: /login.php'); exit;
}
function json($a){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($a); exit; }

if (isset($_GET['action'])) {
  $a = $_GET['action'];
  if ($a==='list_published') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    $st = $pdo->query("SELECT tour_code,name,buyin,seats_max,seats_infinite,lives_max_user,guaranteed_prize,buyin_to_prize_pct,rake_pct,lock_at,created_at
                       FROM tournaments WHERE status='published' ORDER BY created_at DESC");
    json(['ok'=>true,'rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
  }
  http_response_code(400); json(['ok'=>false,'error'=>'unknown_action']);
}

$page_css = '/pages-css/admin-dashboard.css';
include __DIR__ . '/../../partials/head.php';
include __DIR__ . '/../../partials/header_admin.php';
?>
<main>
  <section class="section">
    <div class="container">
      <h1>Gestisci tornei</h1>

      <!-- Card tornei normali -->
      <div class="card">
        <h2 class="card-title">Tornei pubblicati</h2>
        <div class="table-wrap">
          <table class="table" id="tbl">
            <thead>
              <tr>
                <th>Codice</th>
                <th>Nome</th>
                <th>Buy-in</th>
                <th>Posti</th>
                <th>Lives max</th>
                <th>Garantito</th>
                <th>%→prize / Rake%</th>
                <th>Lock</th>
                <th>Apri</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
        <div class="table-foot"><span id="rowsInfo"></span></div>
      </div>
      <!-- /Card tornei normali -->

      <!-- Card tornei Flash -->
      <?php
      try {
       $qFlash = $pdo->query("
  SELECT id, code, name, buyin, seats_max, seats_infinite, lives_max_user,
         guaranteed_prize, buyin_to_prize_pct, rake_pct, status, current_round, created_at
  FROM tournament_flash
  WHERE status IN ('published','locked')   -- solo in corso
  ORDER BY created_at DESC
");
        $flashRows = $qFlash->fetchAll(PDO::FETCH_ASSOC);
      } catch (Throwable $e) {
        $flashRows = [];
      }
      ?>

      <div class="card" id="flash-admin-list" style="margin-top:16px;">
        <h2 class="card-title">Gestione tornei Flash</h2>

        <?php if (empty($flashRows)): ?>
          <p class="muted">Nessun torneo Flash in corso al momento.</p>
        <?php else: ?>
          <div class="table-wrap">
            <table class="table">
              <thead>
                <tr>
                  <th>Codice</th>
                  <th>Nome</th>
                  <th>Buy-in</th>
                  <th>Posti</th>
                  <th>Vite max</th>
                  <th>Status</th>
                  <th>Round</th>
                  <th>Apri</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($flashRows as $row): ?>
                <?php
                  $seats = ((int)$row['seats_infinite']===1) ? '∞' : ((string)($row['seats_max'] ?? '-'));
                  $buyin = number_format((float)$row['buyin'], 2, ',', '.');
                  $status = htmlspecialchars($row['status']);
                  $round  = (int)$row['current_round'];
                ?>
                <tr>
                  <td><?= htmlspecialchars($row['code']) ?></td>
                  <td><?= htmlspecialchars($row['name']) ?></td>
                  <td><?= $buyin ?></td>
                  <td><?= $seats ?></td>
                  <td><?= (int)$row['lives_max_user'] ?></td>
                  <td><?= $status ?></td>
                  <td><?= $round ?></td>
                  <td>
                    <a class="btn btn--outline btn--sm" href="/admin/flash_torneo_manage.php?code=<?= urlencode($row['code']) ?>">Apri</a>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
      <!-- /Card tornei Flash -->

    </div>
  </section>
</main>
<?php include __DIR__ . '/../../partials/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', ()=>{
  const $ = s=>document.querySelector(s);

  function seatsLabel(row){ return row.seats_infinite==1 ? '∞' : (row.seats_max ?? '-'); }

  async function loadPublished(){
    const r = await fetch('?action=list_published', { cache:'no-store',
      headers:{'Cache-Control':'no-cache, no-store, max-age=0','Pragma':'no-cache'}});
    const j = await r.json();
    if(!j.ok){ alert('Errore caricamento'); return; }

    const tb = document.querySelector('#tbl tbody');
    tb.innerHTML = '';

    j.rows.forEach(row=>{
      const tr = document.createElement('tr');
      const seats = seatsLabel(row);
      const gprize = row.guaranteed_prize ? Number(row.guaranteed_prize).toFixed(2) : '-';
      const lockLabel = row.lock_at ? new Date(row.lock_at.replace(' ','T')).toLocaleString() : '-';
      tr.innerHTML = `
        <td>${row.tour_code}</td>
        <td>${row.name}</td>
        <td>${Number(row.buyin).toFixed(2)}</td>
        <td>${seats}</td>
        <td>${row.lives_max_user}</td>
        <td>${gprize}</td>
        <td>${Number(row.buyin_to_prize_pct).toFixed(2)} / ${Number(row.rake_pct).toFixed(2)}</td>
        <td>${lockLabel}</td>
        <td><a class="btn btn--outline btn--sm" href="/admin/torneo_manage.php?code=${row.tour_code}">Apri</a></td>
      `;
      tb.appendChild(tr);
    });

    $('#rowsInfo').textContent = `${j.rows.length} torneo/i pubblicati`;
  }

  loadPublished();
});
</script>
