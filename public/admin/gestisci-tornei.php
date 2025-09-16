<?php
require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['uid']) || !(($_SESSION['role'] ?? 'USER')==='ADMIN' || (int)($_SESSION['is_admin'] ?? 0)===1)) {
  header('Location: /login.php'); exit;
}

/* Helpers */
function json($a){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($a); exit; }

/* Endpoints AJAX (solo lista per ora) */
if (isset($_GET['action'])) {
  $a = $_GET['action'];

  if ($a==='list_published') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    $st = $pdo->query("SELECT id,tour_code,name,buyin,seats_max,seats_infinite,lives_max_user,guaranteed_prize,buyin_to_prize_pct,rake_pct,lock_at,created_at
                       FROM tournaments
                       WHERE status='published'
                       ORDER BY created_at DESC");
    json(['ok'=>true,'rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
  }

  // placeholder per quando vorrai attivare le logiche:
  if ($a==='not_implemented') { json(['ok'=>false,'error'=>'not_implemented']); }

  http_response_code(400); json(['ok'=>false,'error'=>'unknown_action']);
}

/* View */
$page_css = '/pages-css/admin-dashboard.css';
include __DIR__ . '/../../partials/head.php';
include __DIR__ . '/../../partials/header_admin.php';
?>
<main>
  <section class="section">
    <div class="container">
      <h1>Gestisci tornei</h1>

      <div class="card">
        <h2 class="card-title">Tornei pubblicati</h2>
        <div class="table-wrap">
          <table class="table" id="tbl">
            <thead>
              <tr>
                <th>Codice</th>
                <th>Nome</th>
                <th>Round</th>
                <th>Lock</th>
                <th>%→prize / Rake%</th>
                <th class="th-actions">Azioni</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>

        <div class="table-foot">
          <span id="rowsInfo"></span>
        </div>
      </div>
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

      const roundInput = `<input class="input light input--xs" type="number" min="1" step="1" value="1" data-round="${row.tour_code}" style="width:80px">`;

      const lockLabel  = row.lock_at ? new Date(row.lock_at.replace(' ','T')).toLocaleString() : '-';

      tr.innerHTML = `
        <td>${row.tour_code}</td>
        <td>${row.name}</td>
        <td>${roundInput}</td>
        <td>${lockLabel}</td>
        <td>${Number(row.buyin_to_prize_pct).toFixed(2)} / ${Number(row.rake_pct).toFixed(2)}</td>
        <td class="actions-cell">
          <button type="button" class="btn btn--outline btn--sm" data-prelock="${row.tour_code}">Blocca scelte</button>
          <button type="button" class="btn btn--outline btn--sm" data-calc="${row.tour_code}">Calcola round</button>
          <button type="button" class="btn btn--outline btn--sm" data-history="${row.tour_code}">Storico round</button>
          <button type="button" class="btn btn--outline btn--sm btn-danger" data-close="${row.tour_code}">Chiudi torneo e paga</button>
        </td>
      `;
      tb.appendChild(tr);
    });

    $('#rowsInfo').textContent = `${j.rows.length} torneo/i pubblicati`;
  }

  // Lista iniziale
  loadPublished();

  // Listener placeholder — per ora mostrano solo un alert.
  document.getElementById('tbl').addEventListener('click', async (e)=>{
    const b = e.target.closest('button'); if(!b) return;
    const code = b.getAttribute('data-prelock') || b.getAttribute('data-calc') || b.getAttribute('data-history') || b.getAttribute('data-close');
    if (!code) return;

    if (b.hasAttribute('data-prelock')) {
      alert(`(Placeholder) Blocca scelte per torneo ${code}`);
      return;
    }
    if (b.hasAttribute('data-calc')) {
      // esempio lettura round dalla cella
      const input = document.querySelector(`input[data-round="${code}"]`);
      const round = input ? (input.value||'1') : '1';
      alert(`(Placeholder) Calcola round ${round} per torneo ${code}`);
      return;
    }
    if (b.hasAttribute('data-history')) {
      alert(`(Placeholder) Storico round per torneo ${code}`);
      return;
    }
    if (b.hasAttribute('data-close')) {
      if (!confirm(`Chiudere e pagare il torneo ${code}?`)) return;
      alert(`(Placeholder) Chiusura/pagamento torneo ${code}`);
      return;
    }
  });
});
</script>
