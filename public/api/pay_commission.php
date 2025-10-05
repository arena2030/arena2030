<?php
// /app/public/api/pay_commission.php
require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }

// ===== Solo ADMIN =====
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
 *  - to_pay_ready         (mesi < curYM con fattura OK e non pagati)
 *  - awaiting_invoice     (mesi < curYM SENZA fattura OK e non pagati)
 *  - current_month        (curYM)
 */
if ($action==='overview_all') {
  $sql = "
    SELECT u.id AS point_user_id, u.username,
           COALESCE(SUM(m.amount_coins),0) AS total_generated,
           COALESCE(SUM(CASE WHEN m.period_ym < :cur AND m.paid_at IS NULL AND m.invoice_ok_at IS NOT NULL THEN m.amount_coins ELSE 0 END),0) AS to_pay_ready,
           COALESCE(SUM(CASE WHEN m.period_ym < :cur AND m.paid_at IS NULL AND m.invoice_ok_at IS NULL THEN m.amount_coins ELSE 0 END),0) AS awaiting_invoice,
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

  $sql = "SELECT period_ym, amount_coins, invoice_ok_at, invoice_ok_by, paid_at, paid_amount, paid_by_admin_id
          FROM point_commission_monthly
          WHERE point_user_id=?
          ORDER BY period_ym DESC";
  $st=$pdo->prepare($sql); $st->execute([$pid]);
  json_out(['ok'=>true,'rows'=>$st->fetchAll(PDO::FETCH_ASSOC),'curYM'=>$CUR_YM]);
}

/**
 * POST invoice_set
 * Segna fattura OK per un mese (solo se non pagato).
 */
if ($action==='invoice_set') {
  only_post();
  $pid = (int)($_POST['point_user_id'] ?? 0);
  $ym  = norm_period_ym((string)($_POST['period_ym'] ?? ''));
  if ($pid<=0 || $ym==='') json_out(['ok'=>false,'error'=>'bad_params']);

  // Non vincoliamo al mese < curYM per consentire pre-validazioni, ma il pagamento le filtra.
  $adminId = (int)($_SESSION['uid'] ?? 0);
  $sql = "UPDATE point_commission_monthly
          SET invoice_ok_at = NOW(), invoice_ok_by = ?
          WHERE point_user_id=? AND period_ym=? AND paid_at IS NULL";
  $st=$pdo->prepare($sql);
  $st->execute([$adminId,$pid,$ym]);
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

  $sql = "UPDATE point_commission_monthly
          SET invoice_ok_at = NULL, invoice_ok_by = NULL
          WHERE point_user_id=? AND period_ym=? AND paid_at IS NULL";
  $st=$pdo->prepare($sql);
  $st->execute([$pid,$ym]);
  json_out(['ok'=>true,'updated'=>$st->rowCount()]);
}

/**
 * POST pay_one
 * Paga un mese (richiede: period_ym < curYM, invoice_ok_at NON NULL, not paid, amount>0).
 * Accredita users.coins, scrive log, marca paid_*.
 */
if ($action==='pay_one') {
  only_post();
  $pid = (int)($_POST['point_user_id'] ?? 0);
  $ym  = norm_period_ym((string)($_POST['period_ym'] ?? ''));
  if ($pid<=0 || $ym==='') json_out(['ok'=>false,'error'=>'bad_params']);

  // Mese pagabile: concluso (rigorosamente < curYM)
  if (!($ym < $CUR_YM)) json_out(['ok'=>false,'error'=>'not_closing_month','detail'=>'Periodo non ancora concluso']);

  $adminId = (int)($_SESSION['uid'] ?? 0);
  $tx = gen_tx_code(12);

  $pdo->beginTransaction();
  try{
    // Lock record
    $st=$pdo->prepare("SELECT id, amount_coins, paid_at, invoice_ok_at
                       FROM point_commission_monthly
                       WHERE point_user_id=? AND period_ym=? FOR UPDATE");
    $st->execute([$pid,$ym]); $row=$st->fetch(PDO::FETCH_ASSOC);
    if (!$row) { $pdo->rollBack(); json_out(['ok'=>false,'error'=>'not_found']); }
    if ($row['paid_at']!==null) { $pdo->rollBack(); json_out(['ok'=>false,'error'=>'already_paid']); }
    if ($row['invoice_ok_at']===null) { $pdo->rollBack(); json_out(['ok'=>false,'error'=>'invoice_missing']); }

    $amt = (float)$row['amount_coins'];
    if ($amt <= 0) { $pdo->rollBack(); json_out(['ok'=>false,'error'=>'zero_amount']); }

    // Accredito saldo al punto
    $pdo->prepare("UPDATE users SET coins = COALESCE(coins,0) + ? WHERE id=? AND role='PUNTO'")->execute([$amt,$pid]);

    // Log movimento
    $reason = 'Commissioni '.$ym;
    $pdo->prepare("INSERT INTO points_balance_log (user_id, delta, reason, admin_id) VALUES (?,?,?,?)")
        ->execute([$pid, $amt, $reason, $adminId]);

    // Marca pagato
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

/**
 * POST pay_all_ready
 * Paga TUTTI i mesi < curYM con fattura OK e non pagati per un punto.
 */
if ($action==='pay_all_ready') {
  only_post();
  $pid = (int)($_POST['point_user_id'] ?? 0);
  if ($pid<=0) json_out(['ok'=>false,'error'=>'bad_point']);
  $adminId = (int)($_SESSION['uid'] ?? 0);

  $pdo->beginTransaction();
  try{
    // Lock tutti i mesi pagabili
    $q = $pdo->prepare("SELECT id, period_ym, amount_coins
                        FROM point_commission_monthly
                        WHERE point_user_id=? AND period_ym < ? AND paid_at IS NULL AND invoice_ok_at IS NOT NULL
                        ORDER BY period_ym ASC
                        FOR UPDATE");
    $q->execute([$pid,$CUR_YM]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows){ $pdo->rollBack(); json_out(['ok'=>false,'error'=>'nothing_to_pay']); }

    $total = 0.0; $count=0;
    foreach($rows as $r){
      $amt = (float)$r['amount_coins'];
      if ($amt <= 0) continue;

      // accredita
      $pdo->prepare("UPDATE users SET coins = COALESCE(coins,0) + ? WHERE id=? AND role='PUNTO'")
          ->execute([$amt,$pid]);

      // log
      $reason = 'Commissioni '.$r['period_ym'];
      $pdo->prepare("INSERT INTO points_balance_log (user_id, delta, reason, admin_id) VALUES (?,?,?,?)")
          ->execute([$pid, $amt, $reason, $adminId]);

      // marca pagato
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
