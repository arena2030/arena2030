<?php
// /app/public/api/pay_commission.php
require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }

/* ===== Accesso: solo ADMIN ===== */
$isAdmin = (
  (($_SESSION['role'] ?? '') === 'ADMIN') ||
  ((int)($_SESSION['is_admin'] ?? 0) === 1)
);
if (!$isAdmin) {
  header('Content-Type: application/json; charset=utf-8');
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'forbidden']);
  exit;
}

/* ===== Utils ===== */
function json_out($a){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($a); exit; }
function only_post(){ if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST'){ http_response_code(405); json_out(['ok'=>false,'error'=>'method']); } }
function norm_period_ym(string $s): string { $s = trim($s); return preg_match('/^\d{4}-\d{2}$/',$s) ? $s : ''; }
function gen_tx_code(int $len=12): string { $hex = strtoupper(bin2hex(random_bytes(max(6, min(16,$len))))); return substr($hex, 0, $len); }
function col_exists(PDO $pdo,string $t,string $c): bool{
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $q->execute([$t,$c]); return (bool)$q->fetchColumn();
}
function table_exists(PDO $pdo,string $t): bool{
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
  $q->execute([$t]); return (bool)$q->fetchColumn();
}

/* Cur YM (da DB, non dal client) */
$CUR_YM = (string)$pdo->query("SELECT DATE_FORMAT(CURDATE(), '%Y-%m')")->fetchColumn();

/* ===== Router ===== */
$action = $_GET['action'] ?? $_POST['action'] ?? '';

/* ===== Overview per tutti i punti ===== */
if ($action==='overview_all') {
  if (!table_exists($pdo,'point_commission_monthly')){
    // Nessun ledger: restituisci i punti con 0
    $sql0="SELECT u.id AS point_user_id, u.username,
                  0.00 AS total_generated, 0.00 AS to_pay_ready, 0.00 AS awaiting_invoice, 0.00 AS current_month
           FROM users u JOIN points p ON p.user_id=u.id
           WHERE u.role='PUNTO' ORDER BY u.username ASC";
    $rows=$pdo->query($sql0)->fetchAll(PDO::FETCH_ASSOC);
    json_out(['ok'=>true,'rows'=>$rows,'curYM'=>$CUR_YM]);
  }

  $hasInvAt   = col_exists($pdo,'point_commission_monthly','invoice_ok_at');
  $hasInvBool = col_exists($pdo,'point_commission_monthly','invoice_ok');
  $hasPaidAt  = col_exists($pdo,'point_commission_monthly','paid_at');

  // espresso una volta sola e riusato in CASE
  if     ($hasInvAt && $hasInvBool) $exprOK = "(m.invoice_ok_at IS NOT NULL OR COALESCE(m.invoice_ok,0)=1)";
  elseif ($hasInvAt)                $exprOK = "(m.invoice_ok_at IS NOT NULL)";
  elseif ($hasInvBool)              $exprOK = "(COALESCE(m.invoice_ok,0)=1)";
  else                              $exprOK = "0";

  $condNotPaid = $hasPaidAt ? "m.paid_at IS NULL AND " : "";

  $sql = "
    SELECT u.id AS point_user_id, u.username,
           COALESCE(SUM(m.amount_coins),0) AS total_generated,
           COALESCE(SUM(CASE WHEN $condNotPaid m.period_ym < :cur AND $exprOK THEN m.amount_coins ELSE 0 END),0) AS to_pay_ready,
           COALESCE(SUM(CASE WHEN $condNotPaid m.period_ym < :cur AND NOT($exprOK) THEN m.amount_coins ELSE 0 END),0) AS awaiting_invoice,
           COALESCE(SUM(CASE WHEN m.period_ym = :cur THEN m.amount_coins ELSE 0 END),0) AS current_month
    FROM users u
    JOIN points p ON p.user_id = u.id
    LEFT JOIN point_commission_monthly m ON m.point_user_id = u.id
    WHERE u.role='PUNTO'
    GROUP BY u.id, u.username
    ORDER BY u.username ASC";
  $st=$pdo->prepare($sql);
  $st->execute([':cur'=>$CUR_YM]);
  json_out(['ok'=>true,'rows'=>$st->fetchAll(PDO::FETCH_ASSOC),'curYM'=>$CUR_YM]);
}

/* ===== Storico per singolo punto ===== */
if ($action==='history') {
  $pid = (int)($_GET['point_user_id'] ?? 0);
  if ($pid<=0) json_out(['ok'=>false,'error'=>'bad_point']);

  $hasInvAt   = col_exists($pdo,'point_commission_monthly','invoice_ok_at');
  $hasInvBool = col_exists($pdo,'point_commission_monthly','invoice_ok');
  $hasPaidAt  = col_exists($pdo,'point_commission_monthly','paid_at');

  $selInvAt   = $hasInvAt   ? "invoice_ok_at"                    : "NULL AS invoice_ok_at";
  $selInvBool = $hasInvBool ? "COALESCE(invoice_ok,0) AS invoice_ok" : "NULL AS invoice_ok";
  $selPaid    = $hasPaidAt  ? "paid_at"                          : "NULL AS paid_at";

  $sql = "SELECT period_ym, amount_coins, $selInvAt, $selInvBool, $selPaid
          FROM point_commission_monthly
          WHERE point_user_id=? ORDER BY period_ym DESC";
  $st=$pdo->prepare($sql); $st->execute([$pid]);
  json_out([
    'ok'=>true,
    'rows'=>$st->fetchAll(PDO::FETCH_ASSOC),
    'curYM'=>$CUR_YM,
    'has_invoice_cols'=> ($hasInvAt || $hasInvBool),
    'has_paid_at'     => $hasPaidAt
  ]);
}

/* ===== Segna/annulla Fattura OK ===== */
if ($action==='invoice_set' || $action==='invoice_unset') {
  only_post();
  $pid = (int)($_POST['point_user_id'] ?? 0);
  $ym  = norm_period_ym((string)($_POST['period_ym'] ?? ''));
  if ($pid<=0 || $ym==='') json_out(['ok'=>false,'error'=>'bad_params']);

  $hasInvAt   = col_exists($pdo,'point_commission_monthly','invoice_ok_at');
  $hasInvBy   = col_exists($pdo,'point_commission_monthly','invoice_ok_by');
  $hasInvBool = col_exists($pdo,'point_commission_monthly','invoice_ok');
  $hasPaidAt  = col_exists($pdo,'point_commission_monthly','paid_at');

  if (!$hasInvAt && !$hasInvBool) json_out(['ok'=>false,'error'=>'schema','detail'=>'manca invoice_ok_at o invoice_ok']);

  $adminId = (int)($_SESSION['uid'] ?? 0);
  $setParts=[]; $par=[];

  if ($action==='invoice_set'){
    if ($hasInvAt) { $setParts[]="invoice_ok_at=NOW()"; if($hasInvBy){ $setParts[]="invoice_ok_by=?"; $par[]=$adminId; } }
    if ($hasInvBool){ $setParts[]="invoice_ok=1"; }
  } else {
    if ($hasInvAt) { $setParts[]="invoice_ok_at=NULL"; if($hasInvBy){ $setParts[]="invoice_ok_by=NULL"; } }
    if ($hasInvBool){ $setParts[]="invoice_ok=0"; }
  }

  $sql="UPDATE point_commission_monthly SET ".implode(', ',$setParts)
     ." WHERE point_user_id=? AND period_ym=? AND period_ym < ?";
  $par[]=$pid; $par[]=$ym; $par[]=$CUR_YM;
  if ($hasPaidAt) $sql.=" AND paid_at IS NULL";

  $st=$pdo->prepare($sql); $st->execute($par);
  json_out(['ok'=>true,'updated'=>$st->rowCount()]);
}

/* ===== Paga un singolo mese ===== */
if ($action==='pay_one') {
  only_post();
  $pid = (int)($_POST['point_user_id'] ?? 0);
  $ym  = norm_period_ym((string)($_POST['period_ym'] ?? ''));
  if ($pid<=0 || $ym==='') json_out(['ok'=>false,'error'=>'bad_params']);
  if (!($ym < $CUR_YM)) json_out(['ok'=>false,'error'=>'not_closing_month','detail'=>'Periodo non ancora concluso']);

  $hasInvAt   = col_exists($pdo,'point_commission_monthly','invoice_ok_at');
  $hasInvBool = col_exists($pdo,'point_commission_monthly','invoice_ok');

  $adminId = (int)($_SESSION['uid'] ?? 0);
  $tx = gen_tx_code(12);

  $pdo->beginTransaction();
  try{
    $st=$pdo->prepare("SELECT id, amount_coins, paid_at, "
        .($hasInvAt?"invoice_ok_at, ":"")
        .($hasInvBool?"COALESCE(invoice_ok,0) AS invoice_ok, ":"")
        ."period_ym
        FROM point_commission_monthly
        WHERE point_user_id=? AND period_ym=? FOR UPDATE");
    $st->execute([$pid,$ym]); $row=$st->fetch(PDO::FETCH_ASSOC);
    if (!$row) { $pdo->rollBack(); json_out(['ok'=>false,'error'=>'not_found']); }
    if ($row['paid_at']!==null) { $pdo->rollBack(); json_out(['ok'=>false,'error'=>'already_paid']); }

    $ok = false;
    if ($hasInvAt   && !empty($row['invoice_ok_at'])) $ok=true;
    if ($hasInvBool && (int)($row['invoice_ok'] ?? 0)===1) $ok=true;
    if (!$ok){ $pdo->rollBack(); json_out(['ok'=>false,'error'=>'invoice_missing']); }

    $amt = (float)$row['amount_coins'];
    if ($amt <= 0) { $pdo->rollBack(); json_out(['ok'=>false,'error'=>'zero_amount']); }

    $pdo->prepare("UPDATE users SET coins = COALESCE(coins,0) + ? WHERE id=? AND role='PUNTO'")
        ->execute([$amt,$pid]);

    $reason = 'Commissioni '.$ym;
    $pdo->prepare("INSERT INTO points_balance_log (user_id, delta, reason, admin_id) VALUES (?,?,?,?)")
        ->execute([$pid, $amt, $reason, $adminId]);

    $pdo->prepare("UPDATE point_commission_monthly
                   SET paid_at = NOW(), paid_amount = ?, paid_by_admin_id=?, paid_tx_code=?
                   WHERE point_user_id=? AND period_ym=?")
        ->execute([$amt, $adminId, $tx, $pid, $ym]);

    $pdo->commit();
    json_out(['ok'=>true,'paid_amount'=>round($amt,2),'period_ym'=>$ym,'point_user_id'=>$pid,'tx'=>$tx]);
  }catch(Throwable $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    json_out(['ok'=>false,'error'=>'pay_failed','detail'=>$e->getMessage()]);
  }
}

/* ===== Paga tutti i mesi con fattura OK (prima del mese corrente) ===== */
if ($action==='pay_all_ready') {
  only_post();
  $pid = (int)($_POST['point_user_id'] ?? 0);
  if ($pid<=0) json_out(['ok'=>false,'error'=>'bad_point']);
  $adminId = (int)($_SESSION['uid'] ?? 0);

  $hasInvAt   = col_exists($pdo,'point_commission_monthly','invoice_ok_at');
  $hasInvBool = col_exists($pdo,'point_commission_monthly','invoice_ok');

  if     ($hasInvAt && $hasInvBool) $exprOK = "(invoice_ok_at IS NOT NULL OR COALESCE(invoice_ok,0)=1)";
  elseif ($hasInvAt)                $exprOK = "(invoice_ok_at IS NOT NULL)";
  elseif ($hasInvBool)              $exprOK = "(COALESCE(invoice_ok,0)=1)";
  else                              $exprOK = "0";

  $pdo->beginTransaction();
  try{
    $q = $pdo->prepare("SELECT id, period_ym, amount_coins
                        FROM point_commission_monthly
                        WHERE point_user_id=? AND period_ym < ? AND paid_at IS NULL AND $exprOK
                        ORDER BY period_ym ASC
                        FOR UPDATE");
    $q->execute([$pid,$CUR_YM]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows){ $pdo->rollBack(); json_out(['ok'=>false,'error'=>'nothing_to_pay']); }

    $total = 0.0; $count=0;
    foreach($rows as $r){
      $amt = (float)$r['amount_coins'];
      if ($amt <= 0) continue;

      $pdo->prepare("UPDATE users SET coins = COALESCE(coins,0) + ? WHERE id=? AND role='PUNTO'")
          ->execute([$amt,$pid]);

      $reason = 'Commissioni '.$r['period_ym'];
      $pdo->prepare("INSERT INTO points_balance_log (user_id, delta, reason, admin_id) VALUES (?,?,?,?)")
          ->execute([$pid, $amt, $reason, $adminId]);

      $tx = gen_tx_code(12);
      $pdo->prepare("UPDATE point_commission_monthly
                     SET paid_at = NOW(), paid_amount = ?, paid_by_admin_id=?, paid_tx_code=?
                     WHERE id=?")
          ->execute([$amt, $adminId, $tx, (int)$r['id']]);

      $total = round($total + $amt, 2);
      $count++;
    }

    if ($count===0) { $pdo->rollBack(); json_out(['ok'=>false,'error'=>'nothing_positive']); }

    $pdo->commit();
    json_out(['ok'=>true,'count_paid'=>$count,'total_paid'=>round($total,2),'point_user_id'=>$pid]);
  }catch(Throwable $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    json_out(['ok'=>false,'error'=>'pay_all_failed','detail'=>$e->getMessage()]);
  }
}

http_response_code(400);
json_out(['ok'=>false,'error'=>'unknown_action']);
