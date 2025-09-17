<?php
// public/api/prize_request.php — crea richiesta premio (debito immediato dei coins)
declare(strict_types=1);
require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');

function json($a){ echo json_encode($a); exit; }
function only_post(){ if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST'){ http_response_code(405); json(['ok'=>false,'error'=>'method']); } }

$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid<=0) { http_response_code(401); json(['ok'=>false,'error'=>'auth_required']); }

$action = $_GET['action'] ?? ($_POST['action'] ?? 'request');

if ($action==='request') {
  only_post();
  $prize_id = (int)($_POST['prize_id'] ?? 0);

  // indirizzo
  $stato     = trim($_POST['ship_stato'] ?? '');
  $citta     = trim($_POST['ship_citta'] ?? '');
  $comune    = trim($_POST['ship_comune'] ?? '');
  $provincia = trim($_POST['ship_provincia'] ?? '');
  $via       = trim($_POST['ship_via'] ?? '');
  $civico    = trim($_POST['ship_civico'] ?? '');
  $cap       = trim($_POST['ship_cap'] ?? '');

  foreach (['ship_stato'=>$stato,'ship_citta'=>$citta,'ship_comune'=>$comune,'ship_provincia'=>$provincia,'ship_via'=>$via,'ship_civico'=>$civico,'ship_cap'=>$cap] as $k=>$v){
    if ($v==='') json(['ok'=>false,'error'=>'address_'.$k.'_required']);
  }

  $pdo->beginTransaction();
  try{
    // premio valido e abilitato
    $p = $pdo->prepare("SELECT id, prize_code, amount_coins, is_enabled FROM prizes WHERE id=? AND is_listed=1 LIMIT 1");
    $p->execute([$prize_id]); $pr = $p->fetch(PDO::FETCH_ASSOC);
    if (!$pr) { $pdo->rollBack(); json(['ok'=>false,'error'=>'prize_not_found']); }
    if ((int)$pr['is_enabled']!==1){ $pdo->rollBack(); json(['ok'=>false,'error'=>'prize_disabled']); }

    $amount = (float)$pr['amount_coins'];
    if ($amount<0){ $pdo->rollBack(); json(['ok'=>false,'error'=>'amount_invalid']); }

    // scala coins atomico (evita saldo negativo)
    $u = $pdo->prepare("UPDATE users SET coins = coins - ? WHERE id=? AND coins >= ?");
    $u->execute([$amount,$uid,$amount]);
    if ($u->rowCount()===0){ $pdo->rollBack(); json(['ok'=>false,'error'=>'insufficient_coins']); }

    // log movimento
    $pdo->prepare("INSERT INTO points_balance_log (user_id, delta, reason, admin_id) VALUES (?,?,?,NULL)")
        ->execute([$uid, -$amount, 'PRIZE_REQUEST pending PRIZE:'.$pr['prize_code'], null]);

    // crea richiesta
    $req_code = genReqCode($pdo);
    $ins = $pdo->prepare("INSERT INTO prize_requests
      (req_code, user_id, prize_id, status, ship_stato, ship_citta, ship_comune, ship_provincia, ship_via, ship_civico, ship_cap)
      VALUES (?,?,?,'requested', ?,?,?,?,?,?,?)");
    $ins->execute([$req_code, $uid, (int)$pr['id'], $stato,$citta,$comune,$provincia,$via,$civico,$cap]);

    $pdo->commit();
    json(['ok'=>true,'req_code'=>$req_code]);
  } catch (Throwable $e){
    if ($pdo->inTransaction()) $pdo->rollBack();
    json(['ok'=>false,'error'=>'db','detail'=>$e->getMessage()]);
  }
}

json(['ok'=>false,'error'=>'unknown_action']);

function genReqCode(PDO $pdo): string {
  for($i=0;$i<20;$i++){
    $n=random_int(0,36**8-1); $b=strtoupper(base_convert($n,10,36)); $code=str_pad($b,8,'0',STR_PAD_LEFT);
    $st=$pdo->prepare("SELECT 1 FROM prize_requests WHERE req_code=? LIMIT 1"); $st->execute([$code]);
    if(!$st->fetch()) return $code;
  }
  throw new RuntimeException('req_code');
}
