<?php
require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['uid']) || !(($_SESSION['role'] ?? 'USER')==='ADMIN' || (int)($_SESSION['is_admin'] ?? 0)===1)) {
  header('Location: /login.php'); exit;
}

/* Helpers */
function json($a){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($a); exit; }
function tableExists(PDO $pdo, string $t): bool {
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
  $q->execute([$t]); return (bool)$q->fetchColumn();
}
function columnExists(PDO $pdo, string $t, string $c): bool {
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $q->execute([$t,$c]); return (bool)$q->fetchColumn();
}

/* Tabella chiave/valore per i reset (se serve, la creo) */
$pdo->exec("CREATE TABLE IF NOT EXISTS admin_kv (
  k VARCHAR(64) PRIMARY KEY,
  v VARCHAR(255) NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* Endpoints AJAX */
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

  /**
   * RAKE NETTA DEL SITO
   * - lordo:  SUM(t.buyin * (t.rake_pct/100)) su tournament_lives + tournaments
   * - punti:  SUM(point_commission_monthly.amount_coins)
   * - sito:   lordo - punti
   */
  if ($a==='rake_stats') {
    // reset e mese corrente
    $resetAll = $getKV('rake_reset_all_at');
    $resetMon = $getKV('rake_reset_monthly_at');
    $curYm    = (string)$pdo->query("SELECT DATE_FORMAT(CURDATE(), '%Y-%m')")->fetchColumn();
    $resetAllYm = substr($resetAll, 0, 7);
    $resetMonYm = substr($resetMon, 0, 7);

    // Esistenza tabelle
    $hasTL   = tableExists($pdo,'tournament_lives');
    $hasT    = tableExists($pdo,'tournaments');
    $hasRake = $hasT && columnExists($pdo,'tournaments','rake_pct') && columnExists($pdo,'tournaments','buyin');
    $hasPCM  = tableExists($pdo,'point_commission_monthly');

    // ----- Lordo totale (da reset totale)
    $gross_total = 0.0; $lives_total = 0;
    if ($hasTL && $hasT && $hasRake) {
      $sqlAll = "SELECT COALESCE(SUM(t.buyin * (t.rake_pct/100.0)),0) AS rake_total,
                        COUNT(*) AS lives
                 FROM tournament_lives tl
                 JOIN tournaments t ON t.id=tl.tournament_id
                 WHERE tl.created_at >= ? AND t.status IN ('published','closed')";
      $stA=$pdo->prepare($sqlAll); $stA->execute([$resetAll]); $row=$stA->fetch(PDO::FETCH_ASSOC);
      $gross_total = (float)($row['rake_total'] ?? 0);
      $lives_total = (int)($row['lives'] ?? 0);
    }
    // Punti totale (da reset totale)
    $points_total = 0.0;
    if ($hasPCM){
      $st=$pdo->prepare("SELECT COALESCE(SUM(amount_coins),0) FROM point_commission_monthly WHERE period_ym >= ?");
      $st->execute([$resetAllYm]); $points_total=(float)$st->fetchColumn();
    }
    $site_total = round($gross_total - $points_total, 2);

    // ----- Mese corrente: lordo e punti
    $gross_cur = 0.0; $points_cur = 0.0;
    if ($hasTL && $hasT && $hasRake){
      $st=$pdo->prepare("SELECT COALESCE(SUM(t.buyin * (t.rake_pct/100.0)),0)
                         FROM tournament_lives tl
                         JOIN tournaments t ON t.id=tl.tournament_id
                         WHERE DATE_FORMAT(tl.created_at,'%Y-%m') = ? AND t.status IN ('published','closed')");
      $st->execute([$curYm]); $gross_cur=(float)$st->fetchColumn();
    }
    if ($hasPCM){
      $st=$pdo->prepare("SELECT COALESCE(SUM(amount_coins),0) FROM point_commission_monthly WHERE period_ym=?");
      $st->execute([$curYm]); $points_cur=(float)$st->fetchColumn();
    }
    $site_cur = round($gross_cur - $points_cur, 2);

    // ----- Storico mensile (< mese corrente) dal reset mensile
    $rows=[];
    if ($hasTL && $hasT && $hasRake){
      // lordo per mese
      $sqlMon = "SELECT DATE_FORMAT(tl.created_at,'%Y-%m') AS ym,
                        COALESCE(SUM(t.buyin * (t.rake_pct/100.0)),0) AS gross,
                        COUNT(*) AS lives
                 FROM tournament_lives tl
                 JOIN tournaments t ON t.id=tl.tournament_id
                 WHERE tl.created_at >= ? AND DATE_FORMAT(tl.created_at,'%Y-%m') < ?
                   AND t.status IN ('published','closed')
                 GROUP BY ym
                 ORDER BY ym DESC";
      $stM=$pdo->prepare($sqlMon); $stM->execute([$resetMon, $curYm]); $raw=$stM->fetchAll(PDO::FETCH_ASSOC);
      // mappa punti per mese
      $pt = [];
      if ($hasPCM){
        $q=$pdo->prepare("SELECT period_ym AS ym, COALESCE(SUM(amount_coins),0) AS points
                          FROM point_commission_monthly
                          WHERE period_ym >= ? AND period_ym < ?
                          GROUP BY period_ym");
        $q->execute([$resetMonYm, $curYm]); foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){ $pt[$r['ym']] = (float)$r['points']; }
      }
      // compone righe
      foreach($raw as $r){
        $ym = $r['ym'];
        $gross=(float)$r['gross'];
        $lives=(int)$r['lives'];
        $p = (float)($pt[$ym] ?? 0.0);
        $rows[] = [
          'ym'     => $ym,
          'lives'  => $lives,
          'gross'  => round($gross,2),
          'points' => round($p,2),
          'site'   => round($gross - $p, 2),
        ];
      }
    } else {
      // Se non ci sono tabelle tornei, prova almeno a dare mesi con soli "punti" (site = -points)
      if ($hasPCM){
        $q=$pdo->prepare("SELECT period_ym AS ym, COALESCE(SUM(amount_coins),0) AS points
                          FROM point_commission_monthly
                          WHERE period_ym >= ? AND period_ym < ?
                          GROUP BY period_ym ORDER BY period_ym DESC");
        $q->execute([$resetMonYm,$curYm]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
          $p=(float)$r['points'];
          $rows[]=['ym'=>$r['ym'],'lives'=>0,'gross'=>0.00,'points'=>round($p,2),'site'=>round(0-$p,2)];
        }
      }
    }

    json([
      'ok'=>true,
      'current'=>[
        'ym'=>$curYm,
        'gross'=>round($gross_cur,2),
        'points'=>round($points_cur,2),
        'site'=>$site_cur
      ],
      'total'=>[
        'reset_at'=>$resetAll,
        'lives'=>$lives_total,
        'gross'=>round($gross_total,2),
        'points'=>round($points_total,2),
        'site'=>$site_total
      ],
      'monthly'=>[
        'reset_at'=>$resetMon,
        'rows'=>$rows
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

<style>
  /* ===== Stile premium come nella pagina Punti ===== */
  .admin-page .card{
    position:relative; border-radius:20px; padding:18px 18px 16px;
    background:
      radial-gradient(1000px 300px at 50% -120px, rgba(99,102,241,.10), transparent 60%),
      linear-gradient(135deg,#0e1526 0%, #0b1220 100%);
    border:1px solid rgba(255,255,255,.08);
    color:#fff;
    box-shadow: 0 20px 60px rgba(0,0,0,.35);
    transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease, background .15s ease;
    overflow:hidden; margin-bottom:16px;
  }
  .admin-page .card::before{
    content:""; position:absolute; left:0; top:0; bottom:0; width:4px;
    background:linear-gradient(180deg,#1e3a8a 0%, #0ea5e9 100%); opacity:.35;
  }
  .admin-page .card:hover{ transform: translateY(-2px); box-shadow: 0 26px 80px rgba(0,0,0,.48); border-color:#21324b; }
  .admin-page .card-title{ margin:0 0 8px; font-size:18px; font-weight:900; }

  .muted{ color:#9ca3af; font-size:12px; }
  .kpi{ font-size:28px; font-weight:700; }
  .kpi-info{ font-size:13px; color:#9ca3af; }

  .table-wrap{ overflow:auto; border-radius:12px; }
  .table{ width:100%; border-collapse:separate; border-spacing:0; }
  .table thead th{
    text-align:left; font-weight:900; font-size:12px; letter-spacing:.3px;
    color:#9fb7ff; padding:10px 12px;
    background:#0f172a; border-bottom:1px solid #1e293b;
  }
  .table tbody td{
    padding:12px; border-bottom:1px solid #122036; color:#e5e7eb; font-size:14px;
    background:linear-gradient(0deg, rgba(255,255,255,.02), rgba(255,255,255,.02));
  }
  .table tbody tr:hover td{ background:rgba(255,255,255,.025); }
  .table tbody tr:last-child td{ border-bottom:0; }

  /* Modale */
  .modal[aria-hidden="true"]{ display:none; }
  .modal{ position:fixed; inset:0; z-index:60; }
  .modal-open{ overflow:hidden; }
  .modal-backdrop{ position:absolute; inset:0; background:rgba(0,0,0,.5); }
  .modal-card{
    position:relative; z-index:61; width:min(780px,96vw);
    background:var(--c-bg); border:1px solid var(--c-border); border-radius:16px;
    margin:6vh auto 0; padding:0; box-shadow:0 16px 48px rgba(0,0,0,.5);
    max-height:86vh; display:flex; flex-direction:column;
  }
  .modal-head{ display:flex; align-items:center; gap:10px; padding:12px 16px; border-bottom:1px solid var(--c-border); }
  .modal-x{ margin-left:auto; background:transparent; border:0; color:#fff; font-size:24px; cursor:pointer; }
  .modal-body{ padding:16px; overflow:auto; }
  .modal-foot{ display:flex; justify-content:flex-end; gap:8px; padding:12px 16px; border-top:1px solid var(--c-border); }
</style>
<!-- ===================================================================== -->

<main class="admin-page">
  <section class="section">
    <div class="container">
      <h1>Amministrazione</h1>

      <!-- Card: Report pubblici -->
      <div class="card" style="max-width:640px;">
        <h2 class="card-title">Report pubblici</h2>
        <p class="muted">Sezione consultabile anche pubblicamente.</p>
        <div style="display:flex; gap:12px; margin-top:6px;">
          <a class="btn btn--primary btn--sm" href="/tornei-chiusi.php">Tornei chiusi</a>
        </div>
      </div>

      <!-- Card: Statistiche Rake (NETTA del sito) -->
      <div class="card">
        <h2 class="card-title">Rake del sito — mese corrente</h2>
        <div style="display:flex; gap:28px; align-items:flex-end; flex-wrap:wrap;">
          <div>
            <div class="muted">Netta (lordo − Punti)</div>
            <div id="rkSiteCur" class="kpi">€ 0,00</div>
            <div id="rkCurInfo" class="kpi-info">—</div>
          </div>
          <div>
            <div class="muted">Totale dal reset</div>
            <div id="rkSiteTot" class="kpi">€ 0,00</div>
            <div id="rkTotInfo" class="kpi-info">—</div>
          </div>
          <div style="margin-left:auto; display:flex; gap:8px;">
            <button type="button" class="btn btn--outline btn--sm" id="btnResetAll">Azzera totale</button>
          </div>
        </div>
      </div>

      <!-- Card: Rake mensile (storico) -->
      <div class="card">
        <h2 class="card-title">Rake mensile</h2>
        <p class="muted" id="rkMonInfo">Da: —</p>
        <div class="table-wrap">
          <table class="table" id="tblMon">
            <thead>
              <tr>
                <th>Mese</th>
                <th>Vite</th>
                <th>Lordo</th>
                <th>Punti</th>
                <th>Rake sito</th>
              </tr>
            </thead>
            <tbody>
              <tr><td colspan="5">—</td></tr>
            </tbody>
          </table>
        </div>
        <div style="margin-top:8px; display:flex; gap:8px; justify-content:flex-end;">
          <button type="button" class="btn btn--outline btn--sm" id="btnResetMon">Azzera mensile</button>
        </div>
      </div>

    </div>
  </section>
</main>
<?php include __DIR__ . '/../../partials/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', ()=>{
  const fmt€ = n => '€ ' + Number(n||0).toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2});

  async function loadRake(){
    const r = await fetch('?action=rake_stats', { cache:'no-store', headers:{'Cache-Control':'no-cache'} });
    const j = await r.json();
    if(!j.ok){ alert('Errore caricamento rake'); return; }

    // ===== Mese corrente (netto)
    document.getElementById('rkSiteCur').textContent = fmt€(j.current.site||0);
    const curYm = j.current.ym || '';
    const gross = fmt€(j.current.gross||0);
    const pts   = fmt€(j.current.points||0);
    document.getElementById('rkCurInfo').textContent = `Periodo: ${curYm} • Lordo: ${gross} • Punti: ${pts}`;

    // ===== Totale dal reset (netto)
    document.getElementById('rkSiteTot').textContent = fmt€(j.total.site||0);
    const lives = j.total.lives || 0;
    const rst   = j.total.reset_at ? new Date(j.total.reset_at.replace(' ','T')).toLocaleString() : '-';
    const grossT = fmt€(j.total.gross||0);
    const ptsT   = fmt€(j.total.points||0);
    document.getElementById('rkTotInfo').textContent = `Vite: ${lives} • Lordo: ${grossT} • Punti: ${ptsT} • Da: ${rst}`;

    // ===== Storico mensile
    const rsm = j.monthly.reset_at ? new Date(j.monthly.reset_at.replace(' ','T')).toLocaleString() : '-';
    document.getElementById('rkMonInfo').textContent = `Da: ${rsm}`;

    const tb = document.querySelector('#tblMon tbody'); tb.innerHTML='';
    const rows = j.monthly.rows || [];
    if (rows.length===0){
      tb.innerHTML = '<tr><td colspan="5">Nessun dato disponibile.</td></tr>';
    } else {
      rows.forEach(rw=>{
        const tr=document.createElement('tr');
        tr.innerHTML = `
          <td>${rw.ym}</td>
          <td>${Number(rw.lives||0).toLocaleString()}</td>
          <td>${fmt€(rw.gross||0)}</td>
          <td>${fmt€(rw.points||0)}</td>
          <td>${fmt€(rw.site||0)}</td>
        `;
        tb.appendChild(tr);
      });
    }
  }

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
