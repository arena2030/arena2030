<?php
// /app/public/api/admin_rake.php
require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }

header('Content-Type: application/json; charset=utf-8');

function out($a){ echo json_encode($a); exit; }
function only_post(){ if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST'){ http_response_code(405); out(['ok'=>false,'error'=>'method']); } }

// solo ADMIN
$isAdmin = (($_SESSION['role'] ?? '')==='ADMIN') || ((int)($_SESSION['is_admin'] ?? 0)===1);
if (!$isAdmin){ http_response_code(403); out(['ok'=>false,'error'=>'forbidden']); }

$CUR_YM = (string)$pdo->query("SELECT DATE_FORMAT(CURDATE(), '%Y-%m')")->fetchColumn();
$NOW    = (string)$pdo->query("SELECT NOW()")->fetchColumn();

$act = $_GET['action'] ?? $_POST['action'] ?? '';

if ($act==='overview'){
  // totale (da reset)
  $rst = $pdo->prepare("SELECT last_reset_at FROM site_rake_meta WHERE id=1 LIMIT 1");
  $rst->execute(); $lastReset = (string)($rst->fetchColumn() ?: '2000-01-01 00:00:00');

  $stTot = $pdo->prepare("SELECT COALESCE(SUM(amount_coins),0) FROM site_rake_monthly WHERE CONCAT(period_ym,'-01') >= DATE_FORMAT(?, '%Y-%m-01')");
  $stTot->execute([$lastReset]); $totSinceReset = (float)$stTot->fetchColumn();

  // mese corrente
  $stCur = $pdo->prepare("SELECT COALESCE(amount_coins,0) FROM site_rake_monthly WHERE period_ym=?");
  $stCur->execute([$CUR_YM]); $curAmt = (float)$stCur->fetchColumn();

  // storico (ultimi 12)
  $hist = $pdo->query("SELECT period_ym, amount_coins, calculated_at
                       FROM site_rake_monthly
                       WHERE period_ym < ".$pdo->quote($CUR_YM)."
                       ORDER BY period_ym DESC
                       LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);

  out([
    'ok'=>true,
    'now'=>$NOW,
    'curYM'=>$CUR_YM,
    'total_since_reset'=>round($totSinceReset,2),
    'last_reset_at'=>$lastReset,
    'current_month'=>round($curAmt,2),
    'history'=>$hist
  ]);
}

if ($act==='reset_total'){
  only_post();
  $pdo->prepare("UPDATE site_rake_meta SET last_reset_at=NOW() WHERE id=1")->execute();
  out(['ok'=>true]);
}

if ($act==='close_month'){
  only_post();
  // di fatto, basta valorizzare calculated_at del mese attuale; al passaggio mese, lo storico lo mostrerà perché < curYM
  $st = $pdo->prepare("UPDATE site_rake_monthly SET calculated_at=NOW() WHERE period_ym=?");
  $st->execute([$CUR_YM]);
  out(['ok'=>true,'updated'=>$st->rowCount()]);
}

/* === facoltativo: per il finalizer dei tornei ===
   chiamare: POST /api/admin_rake.php?action=accrue&amount=12.34
*/
if ($act==='accrue'){
  only_post();
  $amt = (float)($_POST['amount'] ?? 0);
  if ($amt<=0) out(['ok'=>false,'error'=>'bad_amount']);
  $pdo->beginTransaction();
  try{
    $pdo->prepare("INSERT INTO site_rake_monthly (period_ym,amount_coins) VALUES (?,?)
                   ON DUPLICATE KEY UPDATE amount_coins = amount_coins + VALUES(amount_coins)")
        ->execute([$CUR_YM,$amt]);
    $pdo->commit();
    out(['ok'=>true,'added'=>round($amt,2),'curYM'=>$CUR_YM]);
  }catch(Throwable $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    out(['ok'=>false,'error'=>'accrue_failed','detail'=>$e->getMessage()]);
  }
}

http_response_code(400);
out(['ok'=>false,'error'=>'unknown_action']);
