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
    json_out(['ok'=>false,'error'=>'db','detail'=>$e->getMessage()], 500);
  }
}

/* -----------------------------------------------------------
 * ACTION: send  (solo admin) -> inserisce in messages
 *  POST: recipient_user_id, message_text, (title opzionale)
 * ----------------------------------------------------------- */
if ($action === 'send') {
  only_post();
  if (!is_admin()) json_out(['ok'=>false,'error'=>'forbidden'], 403);
  csrf_verify_or_die(); // 🔒

  $recipient_id = (int)($_POST['recipient_user_id'] ?? 0);
  $title        = trim((string)($_POST['title'] ?? ''));             
  $body         = trim((string)($_POST['message_text'] ?? ''));      

  if ($recipient_id <= 0) json_out(['ok'=>false,'error'=>'bad_request','detail'=>'recipient_user_id'], 400);
  if ($body === '')       json_out(['ok'=>false,'error'=>'bad_request','detail'=>'message_text_empty'], 400);

  try {
    // Verifica che il ricevente esista
    $chk = $pdo->prepare("SELECT id FROM users WHERE id=? AND is_active=1 LIMIT 1");
    $chk->execute([$recipient_id]);
    if (!$chk->fetchColumn()) {
      json_out(['ok'=>false,'error'=>'recipient_not_found'], 404);
    }

    // Inserimento
    $ins = $pdo->prepare("
      INSERT INTO messages
        (sender_admin_id, recipient_user_id, title, body, is_read, is_archived, created_at, read_at, archived_at)
      VALUES
        (?, ?, ?, ?, 0, 0, NOW(), NULL, NULL)
    ");
    $ins->execute([$uid, $recipient_id, $title, $body]);

    json_out(['ok'=>true]);
  } catch (Throwable $e) {
    error_log('[messages:send] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
    json_out([
      'ok'     => false,
      'error'  => 'db',
      'detail' => $e->getMessage(),
      'line'   => $e->getLine(),
      'file'   => $e->getFile()
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
      SELECT COUNT(*) FROM messages
      WHERE recipient_user_id = ? AND is_archived = 0 AND is_read = 0
    ");
    $st->execute([$uid]);
    $n = (int)$st->fetchColumn();
    json_out(['ok'=>true, 'count'=>$n]);
  } catch (Throwable $e) {
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
      FROM messages m
      LEFT JOIN users u ON u.id = m.sender_admin_id
      WHERE m.recipient_user_id = ? AND m.is_archived = 0
      ORDER BY m.created_at DESC
      LIMIT $limit
    ");
    $st->execute([$uid]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    json_out(['ok'=>true,'rows'=>$rows]);
  } catch (Throwable $e) {
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
      UPDATE messages
      SET is_read = 1, read_at = NOW()
      WHERE id = ? AND recipient_user_id = ?
      LIMIT 1
    ");
    $st->execute([$mid, $uid]);
    json_out(['ok'=>true, 'updated'=>$st->rowCount()]);
  } catch (Throwable $e) {
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
      UPDATE messages
      SET is_archived = 1, archived_at = NOW()
      WHERE id = ? AND recipient_user_id = ?
      LIMIT 1
    ");
    $st->execute([$mid, $uid]);
    json_out(['ok'=>true, 'updated'=>$st->rowCount()]);
  } catch (Throwable $e) {
    json_out(['ok'=>false,'error'=>'db','detail'=>$e->getMessage()], 500);
  }
}

json_out(['ok'=>false,'error'=>'unknown_action'], 400);
