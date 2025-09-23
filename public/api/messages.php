<?php
// /public/api/messages.php
declare(strict_types=1);

require_once __DIR__ . '/../../partials/db.php';

if (!defined('APP_ROOT')) {
  define('APP_ROOT', dirname(__DIR__, 2));
}
require_once APP_ROOT . '/partials/csrf.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

header('Content-Type: application/json; charset=utf-8');

/* ========= CONFIG ========= */
$TBL = 'user_messages'; // <<<<<<<<<<<<<<<<  <-- QUI il nome della tabella nel tuo DB
/* ========================= */

function json_out(array $a, int $code = 200): void {
  http_response_code($code);
  echo json_encode($a);
  exit;
}
function only_get(): void {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    json_out(['ok'=>false,'error'=>'method_not_allowed'], 405);
  }
}
function only_post(): void {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(['ok'=>false,'error'=>'method_not_allowed'], 405);
  }
}

/** Admin check (role=ADMIN oppure is_admin=1) */
function is_admin(): bool {
  $role    = $_SESSION['role']    ?? 'USER';
  $isAdmin = (int)($_SESSION['is_admin'] ?? 0);
  return ($role === 'ADMIN') || ($isAdmin === 1);
}

$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) {
  json_out(['ok'=>false,'error'=>'auth_required'], 401);
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

/* -----------------------------------------------------------
 * ACTION: search_users  (solo admin)
 * q vuoto -> top 20 (USER/PUNTO) attivi
 * ----------------------------------------------------------- */
if ($action === 'search_users') {
  only_get();
  if (!is_admin()) json_out(['ok'=>false,'error'=>'forbidden'], 403);

  $q = trim((string)($_GET['q'] ?? ''));
  try {
    if ($q === '') {
      // Top 20 utenti e punti (attivi), ordinati per username
      $st = $pdo->prepare("
        SELECT id, user_code, username, email, role
        FROM users
        WHERE is_active = 1 AND role IN ('USER','PUNTO')
        ORDER BY username ASC
        LIMIT 20
      ");
      $st->execute();
    } else {
      $like = '%' . $q . '%';
      $st = $pdo->prepare("
        SELECT id, user_code, username, email, role
        FROM users
        WHERE is_active = 1
          AND role IN ('USER','PUNTO')
          AND (
                username LIKE ? OR email LIKE ?
             OR user_code LIKE ? OR cell LIKE ?
          )
        ORDER BY username ASC
        LIMIT 50
      ");
      $st->execute([$like, $like, $like, $like]);
    }
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    json_out(['ok'=>true,'rows'=>$rows]);
  } catch (Throwable $e) {
    error_log('[messages:search_users] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
    json_out(['ok'=>false,'error'=>'db','detail'=>$e->getMessage()], 500);
  }
}

/* -----------------------------------------------------------
 * ACTION: send  (solo admin)
 *  POST:
 *    - recipient_user_id  (obbligatorio se non si usa "send_to_all")
 *    - message_text       (obbligatorio)
 *    - title              (opzionale)
 *    - send_to_all=1      (facoltativo: invia a tutti USER+PUNTO attivi)
 * ----------------------------------------------------------- */
if ($action === 'send') {
  only_post();
  if (!is_admin()) json_out(['ok'=>false,'error'=>'forbidden'], 403);
  csrf_verify_or_die(); // 🔒

  $sendAll      = (int)($_POST['send_to_all'] ?? 0) === 1;
  $recipient_id = (int)($_POST['recipient_user_id'] ?? 0);
  $title        = trim((string)($_POST['title'] ?? ''));
  $body         = trim((string)($_POST['message_text'] ?? ''));

  if ($body === '')       json_out(['ok'=>false,'error'=>'bad_request','detail'=>'message_text_empty'], 400);
  if (!$sendAll && $recipient_id <= 0) {
    json_out(['ok'=>false,'error'=>'bad_request','detail'=>'recipient_user_id'], 400);
  }

  try {
    if ($sendAll) {
      // INSERT ... SELECT su tutti gli utenti/punti attivi
      $sql = "
        INSERT INTO {$TBL}
          (sender_admin_id, recipient_user_id, title, body, is_read, is_archived, created_at, read_at, archived_at)
        SELECT ?, u.id, ?, ?, 0, 0, NOW(), NULL, NULL
        FROM users u
        WHERE u.is_active = 1 AND u.role IN ('USER','PUNTO')
      ";
      $st = $pdo->prepare($sql);
      $st->execute([$uid, $title, $body]);
      $sent = $st->rowCount(); // numero di inserimenti effettuati
      json_out(['ok'=>true, 'sent'=>$sent]);
    } else {
      // Singolo destinatario
      $chk = $pdo->prepare("SELECT id FROM users WHERE id=? AND is_active=1 LIMIT 1");
      $chk->execute([$recipient_id]);
      if (!$chk->fetchColumn()) {
        json_out(['ok'=>false,'error'=>'recipient_not_found'], 404);
      }

      $ins = $pdo->prepare("
        INSERT INTO {$TBL}
          (sender_admin_id, recipient_user_id, title, body, is_read, is_archived, created_at, read_at, archived_at)
        VALUES
          (?, ?, ?, ?, 0, 0, NOW(), NULL, NULL)
      ");
      $ins->execute([$uid, $recipient_id, $title, $body]);

      json_out(['ok'=>true, 'sent'=>1]);
    }
  } catch (Throwable $e) {
    error_log('[messages:send] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
    json_out([
      'ok'=>false,'error'=>'db','detail'=>$e->getMessage(),
      'line'=>$e->getLine(),'file'=>$e->getFile()
    ], 500);
  }
}
/* -----------------------------------------------------------
 * ACTION: count_unread (utente loggato)
 * ----------------------------------------------------------- */
if ($action === 'count_unread') {
  only_get();
  try {
    $st = $pdo->prepare("
      SELECT COUNT(*) FROM {$TBL}
      WHERE recipient_user_id = ? AND is_archived = 0 AND is_read = 0
    ");
    $st->execute([$uid]);
    $n = (int)$st->fetchColumn();
    json_out(['ok'=>true, 'count'=>$n]);
  } catch (Throwable $e) {
    error_log('[messages:count_unread] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
    json_out(['ok'=>false,'error'=>'db','detail'=>$e->getMessage()], 500);
  }
}

/* -----------------------------------------------------------
 * ACTION: list (utente loggato)  GET limit=50
 * ----------------------------------------------------------- */
if ($action === 'list') {
  only_get();
  $limit = (int)($_GET['limit'] ?? 50);
  if ($limit < 1)   $limit = 1;
  if ($limit > 200) $limit = 200;

  try {
    $st = $pdo->prepare("
      SELECT
        m.id,
        m.title,
        m.body,
        m.is_read,
        m.created_at,
        u.username AS sender_username
      FROM {$TBL} m
      LEFT JOIN users u ON u.id = m.sender_admin_id
      WHERE m.recipient_user_id = ? AND m.is_archived = 0
      ORDER BY m.created_at DESC
      LIMIT $limit
    ");
    $st->execute([$uid]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    json_out(['ok'=>true,'rows'=>$rows]);
  } catch (Throwable $e) {
    error_log('[messages:list] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
    json_out(['ok'=>false,'error'=>'db','detail'=>$e->getMessage()], 500);
  }
}

/* -----------------------------------------------------------
 * ACTION: mark_read (utente loggato) POST message_id
 * ----------------------------------------------------------- */
if ($action === 'mark_read') {
  only_post();
  csrf_verify_or_die();

  $mid = (int)($_POST['message_id'] ?? 0);
  if ($mid <= 0) json_out(['ok'=>false,'error'=>'bad_request','detail'=>'message_id'], 400);

  try {
    $st = $pdo->prepare("
      UPDATE {$TBL}
      SET is_read = 1, read_at = NOW()
      WHERE id = ? AND recipient_user_id = ?
      LIMIT 1
    ");
    $st->execute([$mid, $uid]);
    json_out(['ok'=>true, 'updated'=>$st->rowCount()]);
  } catch (Throwable $e) {
    error_log('[messages:mark_read] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
    json_out(['ok'=>false,'error'=>'db','detail'=>$e->getMessage()], 500);
  }
}

/* -----------------------------------------------------------
 * ACTION: archive (utente loggato) POST message_id
 * ----------------------------------------------------------- */
if ($action === 'archive') {
  only_post();
  csrf_verify_or_die();

  $mid = (int)($_POST['message_id'] ?? 0);
  if ($mid <= 0) json_out(['ok'=>false,'error'=>'bad_request','detail'=>'message_id'], 400);

  try {
    $st = $pdo->prepare("
      UPDATE {$TBL}
      SET is_archived = 1, archived_at = NOW()
      WHERE id = ? AND recipient_user_id = ?
      LIMIT 1
    ");
    $st->execute([$mid, $uid]);
    json_out(['ok'=>true, 'updated'=>$st->rowCount()]);
  } catch (Throwable $e) {
    error_log('[messages:archive] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
    json_out(['ok'=>false,'error'=>'db','detail'=>$e->getMessage()], 500);
  }
}

json_out(['ok'=>false,'error'=>'unknown_action'], 400);
