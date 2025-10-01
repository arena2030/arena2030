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

  // ========================= NEW: RESIDENZA / DOCUMENTO =========================
  // (vanno salvati su users)
  $res_cf            = strtoupper(trim((string)($_POST['res_cf'] ?? '')));
  $res_cittadinanza  = trim((string)($_POST['res_cittadinanza'] ?? ''));
  $res_via           = trim((string)($_POST['res_via'] ?? ''));
  $res_civico        = trim((string)($_POST['res_civico'] ?? ''));
  $res_citta         = trim((string)($_POST['res_citta'] ?? ''));
  $res_prov          = strtoupper(trim((string)($_POST['res_prov'] ?? '')));
  $res_cap           = trim((string)($_POST['res_cap'] ?? ''));
  $res_nazione       = trim((string)($_POST['res_nazione'] ?? ''));
  $res_tipo_doc      = trim((string)($_POST['res_tipo_doc'] ?? ''));
  $res_num_doc       = trim((string)($_POST['res_num_doc'] ?? ''));
  $res_rilascio      = trim((string)($_POST['res_rilascio'] ?? ''));
  $res_scadenza      = trim((string)($_POST['res_scadenza'] ?? ''));
  $res_rilasciato_da = trim((string)($_POST['res_rilasciato_da'] ?? ''));

  // Validazione minima lato server (il client già valida step-by-step)
  foreach ([
    'res_cf' => $res_cf, 'res_cittadinanza' => $res_cittadinanza, 'res_via' => $res_via,
    'res_civico' => $res_civico, 'res_citta' => $res_citta, 'res_prov' => $res_prov,
    'res_cap' => $res_cap, 'res_nazione' => $res_nazione,
    'res_tipo_doc' => $res_tipo_doc, 'res_num_doc' => $res_num_doc,
    'res_rilascio' => $res_rilascio, 'res_scadenza' => $res_scadenza, 'res_rilasciato_da' => $res_rilasciato_da
  ] as $k=>$v){
    if ($v === '') { json(['ok'=>false,'error'=>'bad_request','detail'=>"missing $k"]); }
  }

  // ========================= SPEDIZIONE (può copiare la residenza) =========================
  $ship_same = (int)($_POST['ship_same_as_res'] ?? 0);

  $stato     = trim((string)($_POST['ship_stato']     ?? ''));
  $citta     = trim((string)($_POST['ship_citta']     ?? ''));
  $comune    = trim((string)($_POST['ship_comune']    ?? ''));
  $provincia = trim((string)($_POST['ship_provincia'] ?? ''));
  $via       = trim((string)($_POST['ship_via']       ?? ''));
  $civico    = trim((string)($_POST['ship_civico']    ?? ''));
  $cap       = trim((string)($_POST['ship_cap']       ?? ''));

  // Se uguale a residenza, sovrascrivi coi res_*
  if ($ship_same === 1){
    $stato     = $res_nazione;
    $citta     = $res_citta;
    $comune    = $res_citta;        // se vuoi puoi differenziare; qui duplichiamo
    $provincia = $res_prov;
    $via       = $res_via;
    $civico    = $res_civico;
    $cap       = $res_cap;
  }

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

    // 1) premio valido, abilitato
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

    // 2) scala coins in modo atomico
    $u = $pdo->prepare("UPDATE users SET coins = coins - ? WHERE id=? AND coins >= ?");
    $u->execute([$amount, $uid, $amount]);
    if ($u->rowCount() === 0){
      $pdo->rollBack(); json(['ok'=>false,'error'=>'insufficient_coins']);
    }

    // ========================= NEW: aggiorna USERS con dati residenza/doc =========================
    $up = $pdo->prepare("
      UPDATE users
      SET
        codice_fiscale = :cf,
        cittadinanza   = :cittadinanza,
        via            = :via,
        civico         = :civico,
        citta          = :citta,
        prov           = :prov,
        cap            = :cap,
        nazione        = :nazione,
        tipo_doc       = :tipo_doc,
        num_doc        = :num_doc,
        data_rilascio  = :rilascio,
        data_scadenza  = :scadenza,
        rilasciato_da  = :rilasciato_da
      WHERE id = :uid
      LIMIT 1
    ");
    $up->execute([
      ':cf'            => $res_cf,
      ':cittadinanza'  => $res_cittadinanza,
      ':via'           => $res_via,
      ':civico'        => $res_civico,
      ':citta'         => $res_citta,
      ':prov'          => $res_prov,
      ':cap'           => $res_cap,
      ':nazione'       => $res_nazione,
      ':tipo_doc'      => $res_tipo_doc,
      ':num_doc'       => $res_num_doc,
      ':rilascio'      => $res_rilascio,
      ':scadenza'      => $res_scadenza,
      ':rilasciato_da' => $res_rilasciato_da,
      ':uid'           => $uid,
    ]);

    // 3) crea richiesta premio (tabella prize_requests con indirizzo spedizione)
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

    // 4) log movimento PUNTI
    $adminId = $uid; // se vuoi usare un admin di sistema, sostituisci qui
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
