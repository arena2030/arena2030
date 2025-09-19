<?php
// /public/api/movements.php — lista movimenti dell'utente loggato (paginata)
declare(strict_types=1);

ini_set('display_errors','0'); ini_set('log_errors','1'); ini_set('error_log','/tmp/php_errors.log');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }

$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'auth_required']); exit;
}

// ===== Paging fisso: 7 righe per pagina =====
$per  = 7;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $per;

// Verifica se esiste created_at nella tabella
function col_exists(PDO $pdo, string $table, string $col): bool {
  $q = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $q->execute([$table,$col]); return (bool)$q->fetchColumn();
}
$hasTS = col_exists($pdo, 'points_balance_log', 'created_at');

try {
  // totale righe utente (per calcolare #pagine)
  $ct = $pdo->prepare("SELECT COUNT(*) FROM points_balance_log WHERE user_id=?");
  $ct->execute([$uid]);
  $total = (int)$ct->fetchColumn();

  // righe pagina
  if ($hasTS) {
    // Ordino per created_at DESC (tie-break su id DESC)
    $sql = "SELECT id, delta, reason, created_at
            FROM points_balance_log
            WHERE user_id=?
            ORDER BY created_at DESC, id DESC
            LIMIT ? OFFSET ?";
  } else {
    $sql = "SELECT id, delta, reason, NULL AS created_at
            FROM points_balance_log
            WHERE user_id=?
            ORDER BY id DESC
            LIMIT ? OFFSET ?";
  }

  $st = $pdo->prepare($sql);
  $st->bindValue(1, $uid, PDO::PARAM_INT);
  $st->bindValue(2, $per, PDO::PARAM_INT);
  $st->bindValue(3, $offset, PDO::PARAM_INT);
  $st->execute();
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode([
    'ok'    => true,
    'rows'  => $rows,
    'page'  => $page,
    'per'   => $per,
    'total' => $total,
    'pages' => (int)ceil($total / $per)
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'db_error','detail'=>$e->getMessage()]);
}
