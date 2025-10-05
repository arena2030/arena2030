<?php
// /app/public/api/pay_commission.php
require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }

// ===== Solo ADMN =====
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

function json_out($a){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($a); exit; }
function only_post(){ if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST'){ http_response_code(405); json_out(['ok'=>false,'error'=>'method']); } }

// helpers schema-safe
function column_exists(PDO $pdo, string $t, string $c): bool {
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $q->execute([$t,$c]); return (bool)$q->fetchColumn();
}
function norm_period_ym(string $s): string {
  $s = trim($s);
  if (!preg_match('/^\d{4}-\d{2}$/', $s)) return '';
  return $s;
}
function gen_tx_code(int $len=12): string {
  $hex = strtoupper(bin2hex(random_bytes(max(6, min(16,$len)))));
  return substr($hex, 0, $len);
}

// Cur YM (da DB, non dal client)
$CUR_YM = (string)$pdo->query("SELECT DATE_FORMAT(CURDATE(), '%Y-%m')")->fetchColumn();

// ===== Router =====
$action = $_GET['action'] ?? $_POST['action'] ?? '';

/**
 * GET overview_all
 * Ritorna, per TUTTI i punti:
 *  - total_generated      (somma storica)
 *  - to_pay_ready         (mesi < curYM con FATTURA OK e NON pagati)
 *  - awaiting_invoice     (mesi < curYM senza FATTURA OK e NON pagati)
 *  - current_month        (curYM)
 */
if ($action==='overview_all') {
  $hasInvAt   = column_exists($pdo,'point_commission_monthly','invoice_ok_at');
  $hasInvBool = column_exists($pdo,'point_commission_monthly','invoice_ok');
  $hasPaidAt  = column_exists($pdo,'point_commission_monthly','paid_at');

  if     ($hasInvAt && $hasInvBool) $exprOK = "(m.invoice_ok_at IS NOT NULL OR COALESCE(m.invoice_ok,0)=1)";
  elseif ($hasInvAt)               $exprOK = "(m.invoice_ok_at IS NOT NULL)";
  elseif ($hasInvBool)             $exprOK = "(COALESCE(m.invoice_ok,0)=1)";
  else                             $exprOK = "0";

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
    ORDER BY u.username ASC
  ";
  $st=$pdo->prepare($sql);
  $st->execute([':cur'=>$CUR_YM]);
  json_out(['ok'=>true,'rows'=>$st->fetchAll(PDO::FETCH_ASSOC),'curYM'=>$CUR_YM]);
}

/**
 * GET history
 * Dettaglio mensile per singolo punto.
 */
if ($action==='history') {
  $pid = (int)($_GET['point_user_id'] ?? 0);
  if ($pid<=0) json_out(['ok'=>false,'error'=>'bad_point']);

  $selInvAt   = column_exists($pdo,'point_commission_monthly','invoice_ok_at')   ? "invoice_ok_at"                        : "NULL AS invoice_ok_at";
  $selInvBool = column_exists($pdo,'point_commission_monthly','invoice_ok')      ? "COALESCE(invoice_ok,0) AS invoice_ok" : "NULL AS invoice_ok";
  $selPaid    = column_exists($pdo,'point_commission_monthly','paid_at')         ? "paid_at"                              : "NULL AS paid_at";
  $selPamt    = column_exists($pdo,'point_commission_monthly','paid_amount')     ? "paid_amount"                          : "NULL AS paid_amount";
  $selPby     = column_exists($pdo,'point_commission_monthly','paid_by_admin_id')? "paid_by_admin_id"                      : "NULL AS paid_by_admin_id";

  $sql = "SELECT period_ym, amount_coins, $selInvAt, $selInvBool, $selPaid, $selPamt, $selPby
          FROM point_commission_monthly
          WHERE point_user_id=?
          ORDER BY period_ym DESC";
  $st=$pdo->prepare($sql); $st->execute([$pid]);
  json_out(['ok'=>true,'rows'=>$st->fetchAll(PDO::FETCH_ASSOC),'curYM'=>$CUR_YM]);
}

/**
 * POST invoice_set
 * Segna fattura OK per un mese (solo se non pagato).
 * Supporta sia invoice_ok_at (timestamp) sia invoice_ok (boolean).
 */
if ($action==='invoice_set') {
  only_post();
  $pid = (int)($_POST['point_user_id'] ?? 0);
  $ym  = norm_period_ym((string)($_POST['period_ym'] ?? ''));
  if ($pid<=0 || $ym==='') json_out(['ok'=>false,'error'=>'bad_params']);

  $hasInvAt  = column_exists($pdo,'point_commission_monthly','invoice_ok_at');
  $hasInvBy  = column_exists($pdo,'point_commission_monthly','invoice_ok_by');
  $hasInvBln = column_exists($pdo,'point_commission_monthly','invoice_ok');
  $hasPaidAt = column_exists($pdo,'point_commission_monthly','paid_at');

  if (!$hasInvAt && !$hasInvBln) json_out(['ok'=>false,'error'=>'schema','detail'=>'Manca invoice_ok_at o invoice_ok']);

  $adminId = (int)($_SESSION['uid'] ?? 0);

  $set=[]; $par=[];
  if ($hasInvAt){ $set[]="invoice_ok_at=NOW()"; if($hasInvBy){ $set[]="invoice_ok_by=?"; $par[]=$adminId; } }
  if ($hasInvBln){ $set[]="invoice_ok=1"; }

  $sql = "UPDATE point_commission_monthly SET ".implode(', ',$set)." WHERE point_user_id=? AND period_ym=?";
  $par[]=$pid; $par[]=$ym;
  if ($hasPaidAt) $sql .= " AND paid_at IS NULL";

  $st=$pdo->prepare($sql);
  $st->execute($par);
  json_out(['ok'=>true,'updated'=>$st->rowCount()]);
}

/**
 * POST invoice_unset
 * Rimuove fattura OK (solo se non pagato).
 */
if ($action==='invoice_unset') {
  only_post();
  $pid = (int)($_POST['point_user_id'] ?? 0);
  $ym  = norm_period_ym((string)($_POST['period_ym'] ?? ''));
  if ($pid<=0 || $ym==='') json_out(['ok'=>false,'error'=>'bad_params']);

  $hasInvAt  = column_exists($pdo,'point_commission_monthly','invoice_ok_at');
  $hasInvBy  = column_exists($pdo,'point_commission_monthly','invoice_ok_by');
  $hasInvBln = column_exists($pdo,'point_commission_monthly','invoice_ok');
  $hasPaidAt = column_exists($pdo,'point_commission_monthly','paid_at');

  if (!$hasInvAt && !$hasInvBln) json_out(['ok'=>false,'error'=>'schema','detail'=>'Manca invoice_ok_at o invoice_ok']);

  $set=[];
  if ($hasInvAt){ $set[]="invoice_ok_at=NULL"; if($hasInvBy){ $set[]="invoice_ok_by=NULL"; } }
  if ($hasInvBln){ $set[]="invoice_ok=0"; }

  $sql = "UPDATE point_commission_monthly SET ".implode(', ',$set)." WHERE point_user_id=? AND period_ym=?";
  $par = [$pid,$ym];
  if ($hasPaidAt) $sql .= " AND paid_at IS NULL";

  $st=$pdo->prepare($sql);
  $st->execute($par);
  json_out(['ok'=>true,'updated'=>$st->rowCount()]);
}

/**
 * POST pay_one
 * Paga un mese (richiede: period_ym < curYM, FATTURA OK, non pagato, amount>0).
 * Accredita users.coins, scrive log, marca paid_* (solo colonne esistenti).
 */
if ($action==='pay_one') {
  only_post();
  $pid = (int)($_POST['point_user_id'] ?? 0);
  $ym  = norm_period_ym((string)($_POST['period_ym'] ?? ''));
  if ($pid<=0 || $ym==='') json_out(['ok'=>false,'error'=>'bad_params']);

  if (!($ym < $CUR_YM)) json_out(['ok'=>false,'error'=>'not_closing_month','detail'=>'Periodo non ancora concluso']);

  $hasInvAt   = column_exists($pdo,'point_commission_monthly','invoice_ok_at');
  $hasInvBool = column_exists($pdo,'point_commission_monthly','invoice_ok');
  $hasPaidAt  = column_exists($pdo,'point_commission_monthly','paid_at');

  $selPaid = $hasPaidAt ? "paid_at" : "NULL AS paid_at";
  $selInvA = $hasInvAt  ? "invoice_ok_at" : "NULL AS invoice_ok_at";
  $selInvB = $hasInvBool? "COALESCE(invoice_ok,0) AS invoice_ok" : "NULL AS invoice_ok";

  $st=$pdo->prepare("SELECT amount_coins, $selPaid, $selInvA, $selInvB
                     FROM point_commission_monthly
                     WHERE point_user_id=? AND period_ym=? FOR UPDATE");
  $pdo->beginTransaction();
  try{
    $st->execute([$pid,$ym]); $row=$st->fetch(PDO::FETCH_ASSOC);
    if (!$row) { $pdo->rollBack(); json_out(['ok'=>false,'error'=>'not_found']); }
    if ($hasPaidAt && $row['paid_at']!==null) { $pdo->rollBack(); json_out(['ok'=>false,'error'=>'already_paid']); }

    $invoiceOk = ($hasInvAt && $row['invoice_ok_at']!==null) || ($hasInvBool && (int)$row['invoice_ok']===1);
    if (!$invoiceOk) { $pdo->rollBack(); json_out(['ok'=>false,'error'=>'invoice_missing']); }

    $amt = (float)$row['amount_coins'];
    if ($amt <= 0) { $pdo->rollBack(); json_out(['ok'=>false,'error'=>'zero_amount']); }

    // Accredito saldo al punto
    $adminId = (int)($_SESSION['uid'] ?? 0);
    $pdo->prepare("UPDATE users SET coins = COALESCE(coins,0) + ? WHERE id=? AND role='PUNTO'")->execute([$amt,$pid]);

    // Log movimento
    $reason = 'Commissioni '.$ym;
    $pdo->prepare("INSERT INTO points_balance_log (user_id, delta, reason, admin_id) VALUES (?,?,?,?)")
        ->execute([$pid, $amt, $reason, $adminId]);

    // Marca pagato (set dinamico)
    $set=[]; $par=[];
    if ($hasPaidAt){ $set[]="paid_at=NOW()"; }
    if (column_exists($pdo,'point_commission_monthly','paid_amount')) { $set[]="paid_amount=?"; $par[]=$amt; }
    if (column_exists($pdo,'point_commission_monthly','paid_by_admin_id')) { $set[]="paid_by_admin_id=?"; $par[]=$adminId; }
    if (column_exists($pdo,'point_commission_monthly','paid_tx_code')) { $set[]="paid_tx_code=?"; $par[]=gen_tx_code(12); }

    $sqlU="UPDATE point_commission_monthly SET ".implode(', ',$set)." WHERE point_user_id=? AND period_ym=?";
    $par[]=$pid; $par[]=$ym;
    $pdo->prepare($sqlU)->execute($par);

    $pdo->commit();
    json_out(['ok'=>true,'paid_amount'=>round($amt,2),'period_ym'=>$ym,'point_user_id'=>$pid]);
  }catch(Throwable $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    json_out(['ok'=>false,'error'=>'pay_failed','detail'=>$e->getMessage()]);
  }
}

/**
 * POST pay_all_ready
 * Paga TUTTI i mesi < curYM con FATTURA OK e non pagati per un punto.
 * Non usa la colonna 'id' (aggiorna con chiave point_user_id + period_ym).
 */
if ($action==='pay_all_ready') {
  only_post();
  $pid = (int)($_POST['point_user_id'] ?? 0);
  if ($pid<=0) json_out(['ok'=>false,'error'=>'bad_point']);

  $hasInvAt   = column_exists($pdo,'point_commission_monthly','invoice_ok_at');
  $hasInvBool = column_exists($pdo,'point_commission_monthly','invoice_ok');
  $hasPaidAt  = column_exists($pdo,'point_commission_monthly','paid_at');

  if     ($hasInvAt && $hasInvBool) $exprOK = "(invoice_ok_at IS NOT NULL OR COALESCE(invoice_ok,0)=1)";
  elseif ($hasInvAt)               $exprOK = "(invoice_ok_at IS NOT NULL)";
  elseif ($hasInvBool)             $exprOK = "(COALESCE(invoice_ok,0)=1)";
  else                             $exprOK = "0"; // se non esiste nessuna colonna, non c'è nulla da pagare

  $pdo->beginTransaction();
  try{
    $sqlSel = "SELECT period_ym, amount_coins
               FROM point_commission_monthly
               WHERE point_user_id=? AND period_ym < ? AND $exprOK";
    if ($hasPaidAt) $sqlSel .= " AND paid_at IS NULL";
    $sqlSel .= " ORDER BY period_ym ASC FOR UPDATE";

    $q = $pdo->prepare($sqlSel);
    $q->execute([$pid,$CUR_YM]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows){ $pdo->rollBack(); json_out(['ok'=>false,'error'=>'nothing_to_pay']); }

    $adminId = (int)($_SESSION['uid'] ?? 0);
    $total = 0.0; $count=0;

    foreach($rows as $r){
      $ym  = (string)$r['period_ym'];
      $amt = (float)$r['amount_coins'];
      if ($amt <= 0) continue;

      // accredita
      $pdo->prepare("UPDATE users SET coins = COALESCE(coins,0) + ? WHERE id=? AND role='PUNTO'")
          ->execute([$amt,$pid]);

      // log
      $reason = 'Commissioni '.$ym;
      $pdo->prepare("INSERT INTO points_balance_log (user_id, delta, reason, admin_id) VALUES (?,?,?,?)")
          ->execute([$pid, $amt, $reason, $adminId]);

      // marca pagato (set dinamico)
      $set=[]; $par=[];
      if ($hasPaidAt){ $set[]="paid_at=NOW()"; }
      if (column_exists($pdo,'point_commission_monthly','paid_amount')) { $set[]="paid_amount=?"; $par[]=$amt; }
      if (column_exists($pdo,'point_commission_monthly','paid_by_admin_id')) { $set[]="paid_by_admin_id=?"; $par[]=$adminId; }
      if (column_exists($pdo,'point_commission_monthly','paid_tx_code')) { $set[]="paid_tx_code=?"; $par[]=gen_tx_code(12); }

      if ($set){
        $sqlU = "UPDATE point_commission_monthly SET ".implode(', ',$set)." WHERE point_user_id=? AND period_ym=?";
        $par[]=$pid; $par[]=$ym;
        $pdo->prepare($sqlU)->execute($par);
      }

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
