<?php
// public/api/prize_request.php — crea richiesta premio (debito immediato dei coins)
declare(strict_types=1);

require_once __DIR__ . '/../../partials/db.php';

define('APP_ROOT', dirname(__DIR__, 2));
require_once APP_ROOT . '/partials/csrf.php';

if (session_status()===PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');

function json(array $a){ echo json_encode($a); exit; }
function only_post(){ if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST'){ http_response_code(405); json(['ok'=>false,'error'=>'method']); } }

/** Genera un codice richiesta unico (8 char esadecimali, maiuscoli) */
function genReqCode(PDO $pdo): string {
  for ($i=0; $i<20; $i++){
    // niente base_convert (strict_types): uso random_bytes
    $code = strtoupper(bin2hex(random_bytes(4))); // 8 chars
    $st = $pdo->prepare("SELECT 1 FROM prize_requests WHERE req_code=? LIMIT 1");
    $st->execute([$code]);
    if (!$st->fetch()) return $code;
  }
  throw new RuntimeException('req_code_generation_failed');
}

// ====== Auth ======
$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid<=0) { http_response_code(401); json(['ok'=>false,'error'=>'auth_required']); }

// ====== Action router ======
$action = $_GET['action'] ?? ($_POST['action'] ?? 'request');

if ($action === 'request') {
  only_post();
  csrf_verify_or_die(); // 🔒

  $prize_id = (int)($_POST['prize_id'] ?? 0);

  // indirizzo (allineati ai name della form)
  $stato     = trim((string)($_POST['ship_stato']     ?? ''));
  $citta     = trim((string)($_POST['ship_citta']     ?? ''));
  $comune    = trim((string)($_POST['ship_comune']    ?? ''));
  $provincia = trim((string)($_POST['ship_provincia'] ?? ''));
  $via       = trim((string)($_POST['ship_via']       ?? ''));
  $civico    = trim((string)($_POST['ship_civico']    ?? ''));
  $cap       = trim((string)($_POST['ship_cap']       ?? ''));

  foreach ([
    'ship_stato'      => $stato,
    'ship_citta'      => $citta,
    'ship_comune'     => $comune,
    'ship_provincia'  => $provincia,
    'ship_via'        => $via,
    'ship_civico'     => $civico,
    'ship_cap'        => $cap
  ] as $k=>$v){
    if ($v === '') { json(['ok'=>false,'error'=>'bad_request','detail'=>"missing $k"]); }
  }
  if ($prize_id <= 0) { json(['ok'=>false,'error'=>'prize_not_found']); }

  try{
    $pdo->beginTransaction();

    // 1) premio valido, visibile e abilitato
    //    (se vuoi far richiedere anche premi con is_listed=0, togli "AND is_listed=1")
    $p = $pdo->prepare("
      SELECT id, prize_code, amount_coins, is_enabled
      FROM prizes
      WHERE id=? /* AND is_listed=1 */
      LIMIT 1
    ");
    $p->execute([$prize_id]);
    $pr = $p->fetch(PDO::FETCH_ASSOC);
    if (!$pr) { $pdo->rollBack(); json(['ok'=>false,'error'=>'prize_not_found']); }
    if ((int)$pr['is_enabled'] !== 1){ $pdo->rollBack(); json(['ok'=>false,'error'=>'prize_disabled']); }

    $amount = (float)($pr['amount_coins'] ?? 0);
    if (!is_finite($amount) || $amount < 0){ $pdo->rollBack(); json(['ok'=>false,'error'=>'amount_invalid']); }

    // 2) scala coins (atomico, nessun saldo negativo possibile)
    $u = $pdo->prepare("UPDATE users SET coins = coins - ? WHERE id=? AND coins >= ?");
    $u->execute([$amount, $uid, $amount]);
    if ($u->rowCount() === 0){
      $pdo->rollBack(); json(['ok'=>false,'error'=>'insufficient_coins']);
    }

    // 3) crea richiesta (usa NOW() per requested_at; se la colonna ha default, va bene lo stesso)
    $req_code = genReqCode($pdo);
    $ins = $pdo->prepare("
      INSERT INTO prize_requests
        (req_code, user_id, prize_id, status, requested_at,
         ship_stato, ship_citta, ship_comune, ship_provincia, ship_via, ship_civico, ship_cap)
      VALUES
        (?, ?, ?, 'requested', NOW(),
         ?, ?, ?, ?, ?, ?, ?)
    ");
    $ins->execute([
      $req_code, $uid, (int)$pr['id'],
      $stato, $citta, $comune, $provincia, $via, $civico, $cap
    ]);

    // 4) log movimento PUNTI — admin_id NON NULL, FK su users(id)
    //    Qui uso l'utente stesso come "admin_id" perché l'azione è auto-generata.
    $adminId = $uid;
    $lg = $pdo->prepare("
      INSERT INTO points_balance_log (user_id, delta, reason, admin_id)
      VALUES (?, ?, ?, ?)
    ");
    $lg->execute([$uid, -$amount, 'PRIZE_REQUEST '.$req_code.' PRIZE_ID:'.$prize_id, $adminId]);

    $pdo->commit();
    json(['ok'=>true,'req_code'=>$req_code]);

  } catch (Throwable $e){
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    json([
      'ok'=>false,
      'error'=>'db',
      'detail'=>$e->getMessage(),
      'line'=>$e->getLine()
    ]);
  }
}

http_response_code(400);
json(['ok'=>false,'error'=>'unknown_action']);
