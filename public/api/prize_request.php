<?php
// public/api/prize_request.php — crea richiesta premio (debito immediato dei coins)
declare(strict_types=1);

require_once __DIR__ . '/../../partials/db.php';

define('APP_ROOT', dirname(__DIR__, 2));
require_once APP_ROOT . '/partials/csrf.php';

if (session_status()===PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');

function json($a){ echo json_encode($a); exit; }
function only_post(){ if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST'){ http_response_code(405); json(['ok'=>false,'error'=>'method']); } }

/** helper: controlla se esiste una tabella (no placeholder per SHOW) */
function table_exists(PDO $pdo, string $tbl): bool {
  $like = $pdo->quote($tbl);
  $sql  = "SHOW TABLES LIKE $like";
  $st   = $pdo->query($sql);
  return (bool)($st ? $st->fetchColumn() : false);
}

/** helper: controlla se una colonna esiste nella tabella */
function col_exists(PDO $pdo, string $table, string $col): bool {
  $q = $pdo->prepare(
    "SELECT 1
       FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = ?
        AND COLUMN_NAME  = ?
      LIMIT 1"
  );
  $q->execute([$table, $col]);
  return (bool)$q->fetchColumn();
}

/** helper: info su nullability e default di una colonna */
function col_info(PDO $pdo, string $table, string $col): array {
  $q = $pdo->prepare(
    "SELECT IS_NULLABLE, COLUMN_DEFAULT
       FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = ?
        AND COLUMN_NAME  = ?
      LIMIT 1"
  );
  $q->execute([$table, $col]);
  $row = $q->fetch(PDO::FETCH_ASSOC) ?: [];
  return [
    'exists'   => !empty($row),
    'nullable' => isset($row['IS_NULLABLE']) && strtoupper((string)$row['IS_NULLABLE']) === 'YES',
    'default'  => $row['COLUMN_DEFAULT'] ?? null,
  ];
}

/** helper: genera un codice richiesta unico */
function genReqCode(PDO $pdo): string {
  for($i=0;$i<20;$i++){
    // base_convert vuole stringhe
    $n = (string)random_int(0, 36**8 - 1);
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
if ($uid<=0) { http_response_code(401); json(['ok'=>false,'error'=>'auth_required']); }

$action = $_GET['action'] ?? ($_POST['action'] ?? 'request');

if ($action === 'request') {
  only_post();
  csrf_verify_or_die(); // 🔒

  $prize_id = (int)($_POST['prize_id'] ?? 0);

  // indirizzo (allineati ai nomi nella UI)
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
    // ===== VERIFICA PRELIMINARE: l’utente esiste davvero? =====
    $ucheck = $pdo->prepare("SELECT id FROM users WHERE id=? LIMIT 1");
    $ucheck->execute([$uid]);
    $urow = $ucheck->fetch(PDO::FETCH_ASSOC);
    if (!$urow) { http_response_code(400); json(['ok'=>false,'error'=>'user_not_found']); }

    $pdo->beginTransaction();

    // 1) premio valido e abilitato (se vuoi limitare ai soli "elencati", aggiungi p.is_listed=1)
    $p = $pdo->prepare("SELECT id, prize_code, amount_coins, is_enabled FROM prizes WHERE id=? LIMIT 1");
    $p->execute([$prize_id]);
    $pr = $p->fetch(PDO::FETCH_ASSOC);
    if (!$pr) { $pdo->rollBack(); json(['ok'=>false,'error'=>'prize_not_found']); }
    if ((int)$pr['is_enabled'] !== 1){ $pdo->rollBack(); json(['ok'=>false,'error'=>'prize_disabled']); }

    $amount = (float)($pr['amount_coins'] ?? 0);
    if ($amount < 0){ $pdo->rollBack(); json(['ok'=>false,'error'=>'amount_invalid']); }

    // 2) scala coins in modo atomico (impedisce saldo negativo / race)
    $u = $pdo->prepare("UPDATE users SET coins = coins - ? WHERE id=? AND coins >= ?");
    $u->execute([$amount, $uid, $amount]);
    if ($u->rowCount() === 0){
      $pdo->rollBack(); json(['ok'=>false,'error'=>'insufficient_coins']);
    }

    // 3) crea richiesta (con requested_at=NOW())
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

    // 4) log movimento (se la tabella esiste) — admin_id gestito dinamicamente
    if (table_exists($pdo, 'points_balance_log')){
      $cols = ['user_id','delta','reason'];   $vals = ['?','?','?'];  $par = [$uid, -$amount, 'PRIZE_REQUEST '.$req_code.' PRIZE_ID:'.$prize_id];

      if (col_exists($pdo,'points_balance_log','admin_id')) {
        $info = col_info($pdo,'points_balance_log','admin_id');
        // Se c'è un default configurato, non forzare la colonna (userà il default)
        if ($info['default'] === null) {
          // se NON nullable → metto 0; se nullable → metto NULL esplicito
          $cols[] = 'admin_id';
          if ($info['nullable']) {
            $vals[] = 'NULL';
          } else {
            $vals[] = '?';
            $par[]  = 0; // fallback neutro per NOT NULL
          }
        }
      }

      $sql = "INSERT INTO points_balance_log (".implode(',', $cols).") VALUES (".implode(',', $vals).")";
      $lg  = $pdo->prepare($sql);
      $lg->execute($par);
    }

    $pdo->commit();
    json(['ok'=>true,'req_code'=>$req_code]);

  } catch (Throwable $e){
    if ($pdo->inTransaction()) $pdo->rollBack();

    // dettaglio PDO (codice/driver/SQLSTATE)
    $code = ($e instanceof PDOException && $e->errorInfo[0] ?? null) ? $e->errorInfo[0] : null;
    $drv  = ($e instanceof PDOException && $e->errorInfo[1] ?? null) ? $e->errorInfo[1] : null;
    $msg  = ($e instanceof PDOException && $e->errorInfo[2] ?? null) ? $e->errorInfo[2] : $e->getMessage();

    // categorie note – aiuta a capire al volo
    $kind = 'db';
    if ($code === '23000') { // vincoli/unique/fk
      if (stripos($msg, 'foreign key') !== false) $kind = 'db_fk';
      if (stripos($msg, 'cannot be null') !== false || stripos($msg, 'not null') !== false) $kind = 'db_notnull';
      if (stripos($msg, 'duplicate') !== false || stripos($msg, 'unique') !== false) $kind = 'db_unique';
    } elseif ($code === '42000') { // sintassi
      $kind = 'db_sql';
    }

    http_response_code(500);
    json([
      'ok'     => false,
      'error'  => $kind,
      'detail' => $msg,
      'sqlstate' => $code,
      'driver'   => $drv,
      'line'     => $e->getLine()
    ]);
  }
