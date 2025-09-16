<?php
require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['uid']) || !(($_SESSION['role'] ?? 'USER')==='ADMIN' || (int)($_SESSION['is_admin'] ?? 0)===1)) {
  header('Location: /login.php'); exit;
}

/* Helpers */
function json($a){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($a); exit; }

/* Tabella chiave/valore per i reset (se serve, la creo) */
$pdo->exec("CREATE TABLE IF NOT EXISTS admin_kv (
  k VARCHAR(64) PRIMARY KEY,
  v VARCHAR(255) NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* Endpoints AJAX (rake) */
if (isset($_GET['action'])) {
  $a = $_GET['action'];

  $getKV = function(string $key) use ($pdo){
    $st = $pdo->prepare("SELECT v FROM admin_kv WHERE k=?"); $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v ?: '1970-01-01 00:00:00';
  };
  $setKV = function(string $key, string $val) use ($pdo){
    $st = $pdo->prepare("INSERT INTO admin_kv(k,v) VALUES(?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)");
    $st->execute([$key,$val]);
  };

  if ($a==='rake_stats') {
    $resetAll = $getKV('rake_reset_all_at');
    $resetMon = $getKV('rake_reset_monthly_at');

    // Totale rake (da reset totale)
    $sqlAll = "SELECT COALESCE(SUM(t.buyin * (t.rake_pct/100)),0) AS rake_total, COUNT(*) AS lives
               FROM tournament_lives tl
               JOIN tournaments t ON t.id=tl.tournament_id
               WHERE tl.created_at >= ? AND t.status IN ('published','closed')";
    $stA = $pdo->prepare($sqlAll); $stA->execute([$resetAll]);
    $tot = $stA->fetch(PDO::FETCH_ASSOC);

    // Mensile (da reset mensile)
    $sqlMon = "SELECT DATE_FORMAT(tl.created_at,'%Y-%m') AS ym,
                      COALESCE(SUM(t.buyin * (t.rake_pct/100)),0) AS rake_month,
                      COUNT(*) AS lives
               FROM tournament_lives tl
               JOIN tournaments t ON t.id=tl.tournament_id
               WHERE tl.created_at >= ? AND t.status IN ('published','closed')
               GROUP BY ym
               ORDER BY ym DESC";
    $stM = $pdo->prepare($sqlMon); $stM->execute([$resetMon]);
    $rows = $stM->fetchAll(PDO::FETCH_ASSOC);

    json(['ok'=>true,
      'total'=>[
        'reset_at'=>$resetAll,
        'lives'=>(int)($tot['lives'] ?? 0),
        'rake'=>round((float)($tot['rake_total'] ?? 0),2)
      ],
      'monthly'=>[
        'reset_at'=>$resetMon,
        'rows'=>array_map(fn($r)=>[
          'ym'=>$r['ym'],
          'lives'=>(int)$r['lives'],
          'rake'=>round((float)$r['rake_month'],2)
        ], $rows)
      ]
    ]);
  }

  if ($a==='rake_reset_all')      { $setKV('rake_reset_all_at', date('Y-m-d H:i:s')); json(['ok'=>true]); }
  if ($a==='rake_reset_monthly')  { $setKV('rake_reset_monthly_at', date('Y-m-d H:i:s')); json(['ok'=>true]); }

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
      <h1>Amministrazione</h1>

      <!-- Card 1: Report pubblici -->
      <div class="card" style="max-width:640px; margin-bottom:16px;">
        <h2 class="card-title">Report pubblici</h2>
        <p class="muted">Sezione consultabile anche pubblicamente.</p>
        <div style="display:flex; gap:12px; margin-top:12px;">
          <a class="btn btn--primary" href="/tornei-chiusi.php">Tornei chiusi</a>
        </div>
      </div>

      <!-- Card 2: Statistiche Rake (totale) -->
      <div class="card" style="margin-bottom:16px;">
        <h2 class="card-title">Statistiche Rake</h2>
        <div class="grid2">
          <div class="field">
            <div class="muted">Rake totale (da reset)</div>
            <div id="rkTotal" style="font-size:28px; font-weight:700;">€ 0,00</div>
            <div id="rkTotInfo" class="muted">—</div>
            <div style="margin-top:8px;">
              <button type="button" class="btn btn--outline btn--sm" id="btnResetAll">Azzera totale</button>
            </div>
          </div>
          <div class="field"></div>
        </div>
      </div>

      <!-- Card 3: Rake mensile -->
      <div class="card">
        <h2 class="card-title">Rake mensile</h2>
        <p class="muted" id="rkMonInfo">Da: —</p>
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:8px;">
          <button type="button" class="btn btn--outline btn--sm" id="btnOpenMonths">Apri</button>
          <button type="button" class="btn btn--outline btn--sm" id="btnResetMon">Azzera mensile</button>
        </div>
      </div>

      <!-- Modale: elenco mesi -->
      <div class="modal" id="monModal" aria-hidden="true">
        <div class="modal-backdrop" data-close></div>
        <div class="modal-card" style="max-width:560px;">
          <div class="modal-head">
            <h3>Rake per mese</h3>
            <button class="modal-x" data-close>&times;</button>
          </div>
          <div class="modal-body scroller">
            <div class="table-wrap">
              <table class="table" id="tblMon">
                <thead>
                  <tr>
                    <th>Mese</th>
                    <th>Vite</th>
                    <th>Rake</th>
                  </tr>
                </thead>
                <tbody>
                  <tr><td colspan="3" class="muted">—</td></tr>
                </tbody>
              </table>
            </div>
          </div>
          <div class="modal-foot">
            <button type="button" class="btn btn--outline" data-close>Chiudi</button>
          </div>
        </div>
      </div>

    </div>
  </section>
</main>
<?php include __DIR__ . '/../../partials/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', ()=>{
  const € = n => '€ ' + Number(n).toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2});

  let monthsCache = { reset_at:null, rows:[] };
  const monModal = document.getElementById('monModal');
  const openMon = ()=>{ monModal.setAttribute('aria-hidden','false'); document.body.classList.add('modal-open'); };
  const closeMon= ()=>{ monModal.setAttribute('aria-hidden','true');  document.body.classList.remove('modal-open'); };
  document.querySelectorAll('[data-close]').forEach(b=>b.addEventListener('click', closeMon));

  async function loadRake(){
    const r = await fetch('?action=rake_stats', { cache:'no-store', headers:{'Cache-Control':'no-cache'} });
    const j = await r.json();
    if(!j.ok){ alert('Errore caricamento rake'); return; }

    // Totale
    document.getElementById('rkTotal').textContent = €(j.total.rake || 0);
    const lives = j.total.lives || 0;
    const rst   = j.total.reset_at ? new Date(j.total.reset_at.replace(' ','T')).toLocaleString() : '-';
    document.getElementById('rkTotInfo').textContent = `Vite: ${lives} • Da: ${rst}`;

    // Mensile (solo cache + label)
    monthsCache = j.monthly || { reset_at:null, rows:[] };
    const rsm = monthsCache.reset_at ? new Date(monthsCache.reset_at.replace(' ','T')).toLocaleString() : '-';
    document.getElementById('rkMonInfo').textContent = `Da: ${rsm}`;
  }

  document.getElementById('btnOpenMonths').addEventListener('click', ()=>{
    const tb = document.querySelector('#tblMon tbody'); tb.innerHTML='';
    if (!monthsCache.rows || monthsCache.rows.length===0){
      tb.innerHTML = '<tr><td colspan="3" class="muted">Nessun dato disponibile.</td></tr>';
    } else {
      monthsCache.rows.forEach(rw=>{
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${rw.ym}</td><td>${rw.lives}</td><td>€ ${Number(rw.rake).toFixed(2)}</td>`;
        tb.appendChild(tr);
      });
    }
    openMon();
  });

  document.getElementById('btnResetAll').addEventListener('click', async ()=>{
    if(!confirm('Azzerare la rake totale?')) return;
    const r=await fetch('?action=rake_reset_all',{method:'POST'}); const j=await r.json();
    if(!j.ok){ alert('Errore reset'); return; }
    await loadRake(); alert('Rake totale azzerata.');
  });

  document.getElementById('btnResetMon').addEventListener('click', async ()=>{
    if(!confirm('Azzerare la rake mensile?')) return;
    const r=await fetch('?action=rake_reset_monthly',{method:'POST'}); const j=await r.json();
    if(!j.ok){ alert('Errore reset'); return; }
    await loadRake(); alert('Rake mensile azzerata.');
  });

  loadRake();
});
</script>
