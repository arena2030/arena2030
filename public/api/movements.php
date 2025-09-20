<?php
// /public/api/movements.php — lista movimenti dell'utente loggato (paginazione + normalizza "torneo #ID" -> "#CODICE")
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

// ---- Retrocompatibilità ----
// Preferiamo page/per, ma se arrivano limit/offset li onoriamo e calcoliamo page di conseguenza.
$per    = (int)($_GET['per']    ?? 7);
$page   = (int)($_GET['page']   ?? 1);
$limit  = isset($_GET['limit'])  ? (int)$_GET['limit']  : null;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : null;

if ($limit !== null || $offset !== null) {
  // Vecchio stile
  $per  = max(1, min(200, $limit ?? 7));
  $off  = max(0, (int)($offset ?? 0));
  $page = (int)floor($off / $per) + 1;
} else {
  // Nuovo stile
  $per  = max(1, min(200, $per ?: 7));
  $page = max(1, $page ?: 1);
  $off  = ($page - 1) * $per;
}

// Verifica se le colonne esistono
function col_exists(PDO $pdo, string $table, string $col): bool {
  $q = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $q->execute([$table,$col]); return (bool)$q->fetchColumn();
}
$hasTS = col_exists($pdo, 'points_balance_log', 'created_at');

// Mappa di possibili colonne "code" dei tornei
function detect_tour_code_col(PDO $pdo): ?string {
  foreach (['tour_code','code','t_code','short_id'] as $c) {
    $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tournaments' AND COLUMN_NAME=?");
    $q->execute([$c]); if ($q->fetchColumn()) return $c;
  }
  return null;
}
$tourCodeCol = detect_tour_code_col($pdo);

// Estrae un eventuale ID torneo da una stringa "… torneo #123"
function extract_tournament_id_from_reason(string $s): ?int {
  if (preg_match('/torneo\s*#\s*(\d+)/i', $s, $m)) {
    return (int)$m[1];
  }
  return null;
}

// Sostituisce "torneo #ID" con "torneo #CODICE" se disponibile
function normalize_reason(PDO $pdo, string $reason, ?string $tourCodeCol): string {
  if (!$tourCodeCol) return $reason;
  $tid = extract_tournament_id_from_reason($reason);
  if (!$tid || $tid <= 0) return $reason;

  try {
    $q = $pdo->prepare("SELECT $tourCodeCol FROM tournaments WHERE id=? LIMIT 1");
    $q->execute([$tid]);
    $code = $q->fetchColumn();
    if ($code && $code !== '') {
      // sostituisci solo la prima occorrenza "torneo #<ID>"
      return preg_replace('/(torneo\s*#\s*)\d+/i', '$1' . strtoupper((string)$code), $reason, 1);
    }
  } catch (Throwable $e) {}
  return $reason;
}

try {
  // Totale righe
  $ct = $pdo->prepare("SELECT COUNT(*) FROM points_balance_log WHERE user_id=?");
  $ct->execute([$uid]);
  $total = (int)$ct->fetchColumn();

  // Lista pagina
  $sql = $hasTS
  ? "SELECT l.id, l.delta,
            CASE
              WHEN l.reason LIKE 'Buy-in torneo%' AND t.code IS NOT NULL
                THEN CONCAT('Buy-in torneo #', t.code)
              WHEN l.reason LIKE 'Payout%' AND t.code IS NOT NULL
                THEN CONCAT('Payout #', t.code)
              ELSE l.reason
            END AS reason,
            l.created_at
     FROM points_balance_log l
     LEFT JOIN tournaments t ON l.tournament_id = t.id
     WHERE l.user_id=?
     ORDER BY l.id DESC
     LIMIT ? OFFSET ?"
  : "SELECT l.id, l.delta,
            CASE
              WHEN l.reason LIKE 'Buy-in torneo%' AND t.code IS NOT NULL
                THEN CONCAT('Buy-in torneo #', t.code)
              WHEN l.reason LIKE 'Payout%' AND t.code IS NOT NULL
                THEN CONCAT('Payout #', t.code)
              ELSE l.reason
            END AS reason,
            NULL AS created_at
     FROM points_balance_log l
     LEFT JOIN tournaments t ON l.tournament_id = t.id
     WHERE l.user_id=?
     ORDER BY l.id DESC
     LIMIT ? OFFSET ?";

  $st = $pdo->prepare($sql);
  $st->bindValue(1, $uid,             PDO::PARAM_INT);
  $st->bindValue(2, $per,             PDO::PARAM_INT);
  $st->bindValue(3, $off,             PDO::PARAM_INT);
  $st->execute();
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  // Normalizza i reason (torneo #ID -> #CODICE)
  foreach ($rows as &$r) {
    $r['reason'] = normalize_reason($pdo, (string)($r['reason'] ?? ''), $tourCodeCol);
    if ($hasTS && isset($r['created_at']) && $r['created_at']) {
      // formato ISO compatto per coerenza (opzionale)
      $r['created_at'] = date('Y-m-d H:i:s', strtotime((string)$r['created_at']));
    }
    // assicura tipi numerici coerenti
    if (isset($r['delta'])) $r['delta'] = (float)$r['delta'];
    if (isset($r['id']))    $r['id']    = (int)$r['id'];
  }
  unset($r);

  $pages = ($per > 0) ? (int)ceil($total / $per) : 1;
  echo json_encode([
    'ok'    => true,
    'rows'  => $rows,
    'page'  => $page,
    'per'   => $per,
    'total' => $total,
    'pages' => $pages
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'db_error','detail'=>$e->getMessage()]);
}
