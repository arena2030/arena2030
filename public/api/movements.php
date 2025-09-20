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

/* ===== Helpers schema ===== */
function col_exists(PDO $pdo, string $table, string $col): bool {
  $q=$pdo->prepare("SELECT 1
                    FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $q->execute([$table,$col]); return (bool)$q->fetchColumn();
}
function first_col(PDO $pdo, string $table, array $cands, string $fallback=''): string {
  foreach($cands as $c){ if (col_exists($pdo,$table,$c)) return $c; }
  return $fallback;
}

/* ===== Parametri paginazione ===== */
$limit = max(1, min(200, (int)($_GET['limit'] ?? 7)));
$page  = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

/* ===== Rileva colonne disponibili ===== */
$hasTS     = col_exists($pdo, 'points_balance_log', 'created_at');
$hasTourId = col_exists($pdo, 'points_balance_log', 'tournament_id');

$tCodeCol = first_col($pdo, 'tournaments', ['code','tour_code','t_code','short_id'], ''); // potrebbe essere vuoto
$tIdCol   = first_col($pdo, 'tournaments', ['id'], 'id'); // id c'è sempre

/* ===== Query conteggio totale ===== */
try {
  $ct = $pdo->prepare("SELECT COUNT(*) FROM points_balance_log WHERE user_id=?");
  $ct->execute([$uid]);
  $total = (int)$ct->fetchColumn();
} catch (Throwable $e){
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'db_error','detail'=>$e->getMessage()]); exit;
}

/* ===== Query righe =====
   Normalizzazione "reason":
   - Se join con tournaments disponibile → usa il codice (se presente) nei testi Buy-in/Payout
   - In fallback, prova a prendere un numero alla fine del reason (#\d+) e joina su tournaments.id
*/
try {
  if ($hasTourId || $tCodeCol !== '') {
    // Costruisci SELECT con created_at opzionale
    $selectTs = $hasTS ? 'l.created_at' : 'NULL AS created_at';

    if ($hasTourId) {
      // Join diretta su tournament_id
      $sql = "SELECT l.id, l.delta,
                     CASE
                       WHEN (l.reason LIKE 'Buy-in torneo%' OR l.reason LIKE 'Buy-in%') AND t.$tCodeCol IS NOT NULL AND t.$tCodeCol <> ''
                         THEN CONCAT('Buy-in torneo #', t.$tCodeCol)
                       WHEN (l.reason LIKE 'Payout%' OR l.reason LIKE 'Premio%') AND t.$tCodeCol IS NOT NULL AND t.$tCodeCol <> ''
                         THEN CONCAT('Payout #', t.$tCodeCol)
                       ELSE l.reason
                     END AS reason,
                     $selectTs
              FROM points_balance_log l
              LEFT JOIN tournaments t ON l.tournament_id = t.$tIdCol
              WHERE l.user_id=?
              ORDER BY l.id DESC
              LIMIT ? OFFSET ?";
      $st = $pdo->prepare($sql);
      $st->bindValue(1, $uid, PDO::PARAM_INT);
      $st->bindValue(2, $limit, PDO::PARAM_INT);
      $st->bindValue(3, $offset, PDO::PARAM_INT);
    } else {
      // Fallback: prova a estrarre l'ID dal reason (… #\d+) e joina su tournaments.id
      // NB: uso SUBSTRING_INDEX per compatibilità (MySQL 5.7/8): prende quello che segue l'ultimo '#'
      $sql = "SELECT l.id, l.delta,
                     CASE
                       WHEN (l.reason LIKE 'Buy-in torneo%' OR l.reason LIKE 'Buy-in%')
                            AND t.$tCodeCol IS NOT NULL AND t.$tCodeCol <> ''
                         THEN CONCAT('Buy-in torneo #', t.$tCodeCol)
                       WHEN (l.reason LIKE 'Payout%' OR l.reason LIKE 'Premio%')
                            AND t.$tCodeCol IS NOT NULL AND t.$tCodeCol <> ''
                         THEN CONCAT('Payout #', t.$tCodeCol)
                       ELSE l.reason
                     END AS reason,
                     $selectTs
              FROM points_balance_log l
              LEFT JOIN tournaments t
                     ON t.$tIdCol = CAST(SUBSTRING_INDEX(l.reason, '#', -1) AS UNSIGNED)
              WHERE l.user_id=?
              ORDER BY l.id DESC
              LIMIT ? OFFSET ?";
      $st = $pdo->prepare($sql);
      $st->bindValue(1, $uid, PDO::PARAM_INT);
      $st->bindValue(2, $limit, PDO::PARAM_INT);
      $st->bindValue(3, $offset, PDO::PARAM_INT);
    }
  } else {
    // Non abbiamo una colonna "code" nei tornei → nessuna normalizzazione, solo select base
    $sql = $hasTS
      ? "SELECT id, delta, reason, created_at
         FROM points_balance_log
         WHERE user_id=?
         ORDER BY id DESC
         LIMIT ? OFFSET ?"
      : "SELECT id, delta, reason, NULL AS created_at
         FROM points_balance_log
         WHERE user_id=?
         ORDER BY id DESC
         LIMIT ? OFFSET ?";
    $st = $pdo->prepare($sql);
    $st->bindValue(1, $uid, PDO::PARAM_INT);
    $st->bindValue(2, $limit, PDO::PARAM_INT);
    $st->bindValue(3, $offset, PDO::PARAM_INT);
  }

  $st->execute();
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  // Calcola pagine
  $pages = max(1, (int)ceil($total / $limit));

  echo json_encode([
    'ok'     => true,
    'rows'   => $rows,
    'total'  => $total,
    'page'   => $page,
    'pages'  => $pages,
    'limit'  => $limit,
    'offset' => $offset
  ]);
} catch (Throwable $e){
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'db_error','detail'=>$e->getMessage()]);
}
