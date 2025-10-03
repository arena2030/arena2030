<?php
require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['uid']) || (($_SESSION['role'] ?? '')!=='PUNTO')) { header('Location: /login.php'); exit; }

function json($a){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($a); exit; }
function only_get(){ if ($_SERVER['REQUEST_METHOD']!=='GET'){ http_response_code(405); json(['ok'=>false,'error'=>'method']); } }
function tableExists(PDO $pdo, string $t): bool {
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
  $q->execute([$t]); return (bool)$q->fetchColumn();
}
function ledgerAvailable(PDO $pdo): bool {
  if (!tableExists($pdo,'point_commission_monthly')) return false;
  $cols = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='point_commission_monthly'")->fetchAll(PDO::FETCH_COLUMN);
  foreach (['point_user_id','period_ym','amount_coins'] as $c) if (!in_array($c,$cols,true)) return false;
  return true;
}

/* ===== AJAX ===== */
if (isset($_GET['action'])) {
  $a = $_GET['action'];

  if ($a==='list_commissions'){ only_get();
    $uid = (int)($_SESSION['uid']);
    $period = $_GET['period'] ?? 'current'; // current | history
    $has = ledgerAvailable($pdo);

    if ($period==='current'){
      if ($has){
        $ym = $pdo->query("SELECT DATE_FORMAT(CURDATE(), '%Y-%m')")->fetchColumn();
        $st = $pdo->prepare("SELECT amount_coins, COALESCE(calculated_at, NOW()) AS calculated_at FROM point_commission_monthly WHERE point_user_id=? AND period_ym=? ORDER BY calculated_at DESC LIMIT 1");
        $st->execute([$uid,$ym]); $row=$st->fetch(PDO::FETCH_ASSOC);
        $amt = $row ? (float)$row['amount_coins'] : 0.00;
        json(['ok'=>true,'period'=>$ym,'amount_coins'=>$amt,'calculated_at'=>$row['calculated_at'] ?? null]);
      } else {
        $ym = $pdo->query("SELECT DATE_FORMAT(CURDATE(), '%Y-%m')")->fetchColumn();
        json(['ok'=>true,'period'=>$ym,'amount_coins'=>0.00,'calculated_at'=>null]);
      }
    }

    if ($period==='history'){
      $page   = max(1,(int)($_GET['page'] ?? 1));
      $per    = min(50, max(1,(int)($_GET['per'] ?? 10)));
      $off    = ($page-1)*$per;

      if ($has){
        $tot = $pdo->prepare("SELECT COUNT(*) FROM point_commission_monthly WHERE point_user_id=? AND period_ym < DATE_FORMAT(CURDATE(), '%Y-%m')");
        $tot->execute([$uid]); $total=(int)$tot->fetchColumn();
        $st = $pdo->prepare("SELECT period_ym, amount_coins, COALESCE(calculated_at, NULL) AS calculated_at
                             FROM point_commission_monthly
                             WHERE point_user_id=? AND period_ym < DATE_FORMAT(CURDATE(), '%Y-%m')
                             ORDER BY period_ym DESC LIMIT $per OFFSET $off");
        $st->execute([$uid]);
        json(['ok'=>true,'rows'=>$st->fetchAll(PDO::FETCH_ASSOC),'total'=>$total,'page'=>$page,'pages'=>(int)ceil($total/$per)]);
      } else {
        json(['ok'=>true,'rows'=>[],'total'=>0,'page'=>1,'pages'=>0]);
      }
    }

    http_response_code(400); json(['ok'=>false,'error'=>'bad_period']);
  }

  http_response_code(400); json(['ok'=>false,'error'=>'unknown_action']);
}

/* ===== VIEW ===== */
$page_css='/pages-css/admin-dashboard.css';
include __DIR__ . '/../../partials/head.php';
include __DIR__ . '/../../partials/header_punto.php';
?>
<style>
  /* ===== Stili allineati alla pagina “Premi” (identici) ===== */

  /* Layout titolo pagina */
  .section{ padding-top:24px; }
  .container{ max-width:1100px; margin:0 auto; }
  h1{ color:#fff; font-size:26px; font-weight:900; letter-spacing:.2px; margin:0 0 12px; }

  /* Card scura premium */
  .card{
    position:relative; border-radius:20px; padding:18px 18px 16px;
    background:
      radial-gradient(1000px 300px at 50% -120px, rgba(99,102,241,.10), transparent 60%),
      linear-gradient(135deg,#0e1526 0%, #0b1220 100%);
    border:1px solid rgba(255,255,255,.08);
    color:#fff;
    box-shadow: 0 20px 60px rgba(0,0,0,.35);
    transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease, background .15s ease;
    overflow:hidden;
    margin-bottom:16px;
  }
  .card::before{
    content:""; position:absolute; left:0; top:0; bottom:0; width:4px;
    background:linear-gradient(180deg,#1e3a8a 0%, #0ea5e9 100%); opacity:.35;
  }
  .card:hover{ transform: translateY(-2px); box-shadow: 0 26px 80px rgba(0,0,0,.48); border-color:#21324b; }

  /* Intestazione card */
  .topbar{ display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:12px; }
  .card-title{ margin:0; font-size:18px; font-weight:900; }

  /* Badge/secondari */
  .muted{ color:#9ca3af; font-size:12px; }

  /* Valore grande */
  .big-amount{ font-size:28px; font-weight:700; }

  /* Tabella scura come premi */
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

  /* Pulsanti paginazione: usa gli stessi che già hai (btn/btn--outline) */
</style>

<main class="cm-page">
  <section class="section">
    <div class="container">
      <h1>Commissioni</h1>

      <div class="card">
        <div class="topbar">
          <h2 class="card-title">Mese corrente</h2>
          <span id="cmPeriod" class="muted"></span>
        </div>
        <div style="padding:12px;">
          <div> Aggi maturati: <span class="big-amount" id="cmAmount">0.00</span> <span class="muted">AC</span></div>
          <div class="muted" id="cmHint"></div>
        </div>
      </div>

      <div class="card">
        <div class="topbar">
          <h2 class="card-title">Storico (ultimi mesi)</h2>
          <div id="paging" style="display:flex; gap:8px;">
            <button class="btn btn--outline" id="prev">←</button>
            <span id="pginfo" class="muted"></span>
            <button class="btn btn--outline" id="next">→</button>
          </div>
        </div>
        <div class="table-wrap">
          <table class="table" id="tblHist">
            <thead>
            <tr>
              <th>Mese</th>
              <th>Aggi (AC)</th>
              <th>Calcolato il</th>
            </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      </div>

    </div>
  </section>
</main>

<?php include __DIR__ . '/../../partials/footer.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', ()=>{
  const $=s=>document.querySelector(s);

  async function loadCurrent(){
    const r=await fetch('?action=list_commissions&period=current'); const j=await r.json();
    if (!j.ok) return;
    $('#cmPeriod').textContent = j.period || '';
    $('#cmAmount').textContent = Number(j.amount_coins||0).toFixed(2);
    $('#cmHint').textContent = j.calculated_at ? ('Aggiornato il '+ new Date(j.calculated_at).toLocaleString()) : 'In attesa del primo calcolo.';
  }

  let page=1, per=10, pages=1;
  async function loadHist(){
    const u=new URL('?action=list_commissions', location.href); u.searchParams.set('period','history'); u.searchParams.set('page',page); u.searchParams.set('per',per);
    const r=await fetch(u); const j=await r.json();
    const tb = $('#tblHist tbody'); tb.innerHTML='';
    if (!j.ok){ tb.innerHTML='<tr><td colspan="3">Errore</td></tr>'; return; }
    pages=j.pages||1; $('#pginfo').textContent=`Pagina ${j.page||1} di ${pages}`;
    $('#prev').disabled = (page<=1); $('#next').disabled=(page>=pages);
    (j.rows||[]).forEach(row=>{
      const tr=document.createElement('tr');
      tr.innerHTML = `
        <td>${row.period_ym || '-'}</td>
        <td>${Number(row.amount_coins||0).toFixed(2)}</td>
        <td>${row.calculated_at ? new Date(row.calculated_at).toLocaleString() : '-'}</td>
      `;
      tb.appendChild(tr);
    });
  }
  $('#prev').addEventListener('click', ()=>{ if (page>1){ page--; loadHist(); } });
  $('#next').addEventListener('click', ()=>{ if (page<pages){ page++; loadHist(); } });

  loadCurrent(); loadHist();
});
</script>
