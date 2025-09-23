<?php
// /public/api/messages.php
declare(strict_types=1);

require_once __DIR__ . '/../../partials/db.php';
require_once __DIR__ . '/../../partials/csrf.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');

function jexit(array $a, int $code = 200): void {
  http_response_code($code);
  echo json_encode($a);
  exit;
}
function only_post(): void {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jexit(['ok'=>false,'error'=>'method_not_allowed'], 405);
  }
}
// tabella messaggi (adatta se usi un nome diverso)
$TBL_MSG = 'user_messages';

// ====== session basics ======
$uid     = (int)($_SESSION['uid'] ?? 0);
$role    = $_SESSION['role'] ?? 'USER';
$isAdmin = ($role === 'ADMIN') || ((int)($_SESSION['is_admin'] ?? 0) === 1);

if ($uid <= 0) {
  jexit(['ok'=>false,'error'=>'auth_required'], 401);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ====== ACTION: search_users (ADMIN) ======
if ($action === 'search_users') {
  if (!$isAdmin) jexit(['ok'=>false,'error'=>'forbidden'], 403);

  $q = trim((string)($_GET['q'] ?? ''));

  try {
    if ($q === '') {
      // top 20 recenti
      $st = $pdo->prepare("
        SELECT id, user_code, username, email, role
        FROM users
        WHERE is_active=1 AND role IN ('USER','PUNTO')
        ORDER BY created_at DESC, id DESC
        LIMIT 20
      ");
      $st->execute();
    } else {
      $like = '%'.$q.'%';
      $st = $pdo->prepare("
        SELECT id, user_code, username, email, role
        FROM users
        WHERE is_active=1 AND role IN ('USER','PUNTO')
          AND (username LIKE ? OR email LIKE ? OR user_code LIKE ?
               OR nome LIKE ? OR cognome LIKE ? OR cell LIKE ?)
        ORDER BY username ASC, id DESC
        LIMIT 50
      ");
      $st->execute([$like,$like,$like,$like,$like,$like]);
    }
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    jexit(['ok'=>true,'rows'=>$rows]);
  } catch (Throwable $e) {
    jexit(['ok'=>false,'error'=>'db','detail'=>$e->getMessage()], 500);
  }
}

// ====== ACTION: send (ADMIN) ======
if ($action === 'send') {
  if (!$isAdmin) jexit(['ok'=>false,'error'=>'forbidden'], 403);
  only_post();
  csrf_verify_or_die();

  $recipient_id = (int)($_POST['recipient_user_id'] ?? 0);
  $message_text = trim((string)($_POST['message_text'] ?? ''));

  if ($recipient_id <= 0) jexit(['ok'=>false,'error'=>'recipient_required'], 400);
  if ($message_text === '') jexit(['ok'=>false,'error'=>'text_required'], 400);
  if (mb_strlen($message_text) > 2000) jexit(['ok'=>false,'error'=>'text_too_long'], 400);

  try {
    // verifica destinatario valido e attivo (USER/PUNTO)
    $chk = $pdo->prepare("SELECT id FROM users WHERE id=? AND is_active=1 AND role IN ('USER','PUNTO') LIMIT 1");
    $chk->execute([$recipient_id]);
    if (!$chk->fetchColumn()) {
      jexit(['ok'=>false,'error'=>'prize_not_found','detail'=>'recipient_not_found_or_inactive'], 400);
    }

    $ins = $pdo->prepare("
      INSERT INTO {$TBL_MSG}
        (recipient_user_id, sender_admin_id, message_text, status, created_at)
      VALUES
        (?, ?, ?, 'new', NOW())
    ");
    $ins->execute([$recipient_id, $uid, $message_text]);

    jexit(['ok'=>true]);
  } catch (Throwable $e) {
    jexit(['ok'=>false,'error'=>'db','detail'=>$e->getMessage()], 500);
  }
}

// ====== ACTION: list (USER/PUNTO) ======
if ($action === 'list') {
  // mostra solo i messaggi del richiedente (esclusi archiviati)
  $limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));

  try {
    $st = $pdo->prepare("
      SELECT m.id, m.recipient_user_id, m.sender_admin_id, m.message_text, m.status,
             m.created_at, m.read_at, m.archived_at,
             u.username AS sender_username
      FROM {$TBL_MSG} m
      LEFT JOIN users u ON u.id = m.sender_admin_id
      WHERE m.recipient_user_id = ? AND m.status <> 'archived'
      ORDER BY m.id DESC
      LIMIT {$limit}
    ");
    $st->execute([$uid]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    jexit(['ok'=>true,'rows'=>$rows]);
  } catch (Throwable $e) {
    jexit(['ok'=>false,'error'=>'db','detail'=>$e->getMessage()], 500);
  }
}

// ====== ACTION: count_unread (USER/PUNTO) ======
if ($action === 'count_unread') {
  try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM {$TBL_MSG} WHERE recipient_user_id=? AND status='new'");
    $st->execute([$uid]);
    $n = (int)$st->fetchColumn();
    jexit(['ok'=>true,'count'=>$n]);
  } catch (Throwable $e) {
    jexit(['ok'=>false,'error'=>'db','detail'=>$e->getMessage()], 500);
  }
}

// ====== ACTION: mark_read (USER/PUNTO) ======
if ($action === 'mark_read') {
  only_post();
  csrf_verify_or_die();

  $mid = (int)($_POST['message_id'] ?? 0);
  if ($mid <= 0) jexit(['ok'=>false,'error'=>'bad_id'], 400);

  try {
    $up = $pdo->prepare("
      UPDATE {$TBL_MSG}
      SET status='read', read_at=NOW()
      WHERE id=? AND recipient_user_id=? AND status='new'
    ");
    $up->execute([$mid, $uid]);
    jexit(['ok'=>true, 'updated'=>$up->rowCount()]);
  } catch (Throwable $e) {
    jexit(['ok'=>false,'error'=>'db','detail'=>$e->getMessage()], 500);
  }
}

// ====== ACTION: archive (USER/PUNTO) ======
if ($action === 'archive') {
  only_post();
  csrf_verify_or_die();

  $mid = (int)($_POST['message_id'] ?? 0);
  if ($mid <= 0) jexit(['ok'=>false,'error'=>'bad_id'], 400);

  try {
    $up = $pdo->prepare("
      UPDATE {$TBL_MSG}
      SET status='archived', archived_at=NOW()
      WHERE id=? AND recipient_user_id=?
    ");
    $up->execute([$mid, $uid]);
    jexit(['ok'=>true, 'updated'=>$up->rowCount()]);
  } catch (Throwable $e) {
    jexit(['ok'=>false,'error'=>'db','detail'=>$e->getMessage()], 500);
  }
}

// ====== Default ======
jexit(['ok'=>false,'error'=>'unknown_action'], 400);
