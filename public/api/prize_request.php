<?php
// public/api/prize_request.php — crea richiesta premio (debito immediato dei coins)
declare(strict_types=1);

require_once __DIR__ . '/../../partials/db.php';

define('APP_ROOT', dirname(__DIR__, 2));
require_once APP_ROOT . '/partials/csrf.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');

function json($a){ echo json_encode($a); exit; }
function only_post(){ if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'){ http_response_code(405); json(['ok'=>false,'error'=>'method']); } }

/** helper: controlla se esiste una tabella */
function table_exists(PDO $pdo, string $tbl): bool {
  $q = $pdo->prepare("SHOW TABLES LIKE ?");
  $q->execute([$tbl]);
  return (bool)$q->fetchColumn();
}

/** helper: elenco colonne tabella (nome => info) */
function table_columns(PDO $pdo, string $tbl): array {
  $q = $pdo->prepare("SHOW COLUMNS FROM `$tbl`");
  $q->execute();
  $cols = [];
  foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $cols[$r['Field']] = $r; // Field, Type, Null, Key, Default, Extra
  }
  return $cols;
}

/** helper: genera un codice richiesta unico */
function genReqCode(PDO $pdo): string {
  for($i=0; $i<20; $i++){
    $n = random_int(0, 36**8 - 1);
    $b = strtoupper(base_convert($n, 10, 36));
    $code = str_pad($b, 8, '0', STR_PAD_LEFT);
    $st = $pdo->prepare("SELECT 1 FROM prize_requests WHERE req_code=? LIMIT 1");
    $st->execute([$code]);
    if (!$st->fetch()) return $code;
  }
  throw new RuntimeException('req_code');
}

// ====== Auth minima ======
$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) { http_response_code(401); json(['ok'=>false,'error'=>'auth_required']); }

$action = $_GET['action'] ?? ($_POST['action'] ?? 'request');

if ($action === 'request') {
  only_post();
  csrf_verify_or_die(); // 🔒 CSRF

  // --- input
  $prize_id  = (int)($_POST['prize_id'] ?? 0);

  $stato     = trim((string)($_POST['ship_stato']     ?? ''));
  $citta     = trim((string)($_POST['ship_citta']     ?? ''));
  $comune    = trim((string)($_POST['ship_comune']    ?? ''));
  $provincia = trim((string)($_POST['ship_provincia'] ?? ''));
  $via       = trim((string)($_POST['ship_via']       ?? ''));
  $civico    = trim((string)($_POST['ship_civico']    ?? ''));
  $cap       = trim((string)($_POST['ship_cap']       ?? ''));

  foreach ([
    'ship_stato'     => $stato,
    'ship_citta'     => $citta,
    'ship_comune'    => $comune,
    'ship_provincia' => $provincia,
    'ship_via'       => $via,
    'ship_civico'    => $civico,
    'ship_cap'       => $cap
  ] as $k=>$v){
    if ($v === '') { json(['ok'=>false,'error'=>'bad_request','detail'=>"missing $k"]); }
  }
  if ($prize_id <= 0) { json(['ok'=>false,'error'=>'prize_not_found']); }

  // --- costanti
  $DEFAULT_STATUS = 'requested';

  try{
    $pdo->beginTransaction();

    // 1) premio valido e abilitato
    //    (se vuoi limitare ai soli "elencati", aggiungi: AND is_listed=1)
    $p = $pdo->prepare("
      SELECT id, prize_code, amount_coins, is_enabled
      FROM prizes
      WHERE id = ?
      LIMIT 1
    ");
    $p->execute([$prize_id]);
    $pr = $p->fetch(PDO::FETCH_ASSOC);
    if (!$pr) { $pdo->rollBack(); json(['ok'=>false,'error'=>'prize_not_found']); }
    if ((int)$pr['is_enabled'] !== 1){ $pdo->rollBack(); json(['ok'=>false,'error'=>'prize_disabled']); }

    $amount = (float)($pr['amount_coins'] ?? 0);
    if ($amount < 0){ $pdo->rollBack(); json(['ok'=>false,'error'=>'amount_invalid']); }

    // 2) scala coins atomico (impedisce saldo negativo e condizioni di race)
    $u = $pdo->prepare("UPDATE users SET coins = coins - ? WHERE id = ? AND coins >= ?");
    $u->execute([$amount, $uid, $amount]);
    if ($u->rowCount() === 0){
      $pdo->rollBack(); json(['ok'=>false,'error'=>'insufficient_coins']);
    }

    // 3) crea richiesta (adattiva alle colonne presenti)
    $reqCols = table_columns($pdo, 'prize_requests');

    $req_code = genReqCode($pdo);

    $cols = [];
    $vals = [];
    $pars = [];

    // sempre se esistono:
    if (isset($reqCols['req_code']))   { $cols[]='req_code';   $vals[]='?'; $pars[]=$req_code; }
    if (isset($reqCols['user_id']))    { $cols[]='user_id';    $vals[]='?'; $pars[]=$uid; }
    if (isset($reqCols['prize_id']))   { $cols[]='prize_id';   $vals[]='?'; $pars[]=(int)$pr['id']; }

    // status (se presente metto 'requested' salvo diversa esigenza)
    if (isset($reqCols['status']))     { $cols[]='status';     $vals[]='?'; $pars[]=$DEFAULT_STATUS; }

    // requested_at (se presente lo imposto esplicitamente a NOW())
    if (isset($reqCols['requested_at'])) { $cols[]='requested_at'; $vals[]='NOW()'; }

    // reason_admin se NOT NULL lo imposto a stringa vuota
    if (isset($reqCols['reason_admin'])) { $cols[]='reason_admin'; $vals[]='?'; $pars[]=''; }

    // indirizzo di spedizione (se le colonne esistono)
    if (isset($reqCols['ship_stato']))     { $cols[]='ship_stato';     $vals[]='?'; $pars[]=$stato; }
    if (isset($reqCols['ship_citta']))     { $cols[]='ship_citta';     $vals[]='?'; $pars[]=$citta; }
    if (isset($reqCols['ship_comune']))    { $cols[]='ship_comune';    $vals[]='?'; $pars[]=$comune; }
    if (isset($reqCols['ship_provincia'])) { $cols[]='ship_provincia'; $vals[]='?'; $pars[]=$provincia; }
    if (isset($reqCols['ship_via']))       { $cols[]='ship_via';       $vals[]='?'; $pars[]=$via; }
    if (isset($reqCols['ship_civico']))    { $cols[]='ship_civico';    $vals[]='?'; $pars[]=$civico; }
    if (isset($reqCols['ship_cap']))       { $cols[]='ship_cap';       $vals[]='?'; $pars[]=$cap; }

    if (empty($cols)) {
      // non dovrebbe mai accadere, ma evito SQL invalida
      throw new RuntimeException('No insertable columns in prize_requests');
    }

    $sql = "INSERT INTO prize_requests (".implode(',',$cols).") VALUES (".implode(',',$vals).")";
    $ins = $pdo->prepare($sql);
    $ins->execute($pars);

    // 4) log movimento (se la tabella esiste)
    if (table_exists($pdo, 'points_balance_log')){
      // admin_id NULL per coerenza con la maggior parte degli schemi
      $lg = $pdo->prepare("INSERT INTO points_balance_log (user_id, delta, reason, admin_id) VALUES (?, ?, ?, NULL)");
      $lg->execute([$uid, -$amount, 'PRIZE_REQUEST '.$req_code.' PRIZE_ID:'.$prize_id]);
    }

    $pdo->commit();
    json(['ok'=>true,'req_code'=>$req_code]);

  } catch (Throwable $e){
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    json(['ok'=>false,'error'=>'db','detail'=>$e->getMessage(),'line'=>$e->getLine()]);
  }
}

http_response_code(400);
json(['ok'=>false,'error'=>'unknown_action']);
