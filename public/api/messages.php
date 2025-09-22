<?php
// /public/api/messages.php — API Messaggi (utente+adin)
// Compatibile con tabella: user_messages (BIGINT UNSIGNED FK su users.id)
declare(strict_types=1);

require_once __DIR__ . '/../../partials/db.php';

define('APP_ROOT', dirname(__DIR__, 2));
require_once APP_ROOT . '/partials/csrf.php';

if (session_status()===PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');

function json($a){ echo json_encode($a); exit; }
function only_get(){ if (($_SERVER['REQUEST_METHOD'] ?? '')!=='GET'){ http_response_code(405); json(['ok'=>false,'error'=>'method']); } }
function only_post(){ if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST'){ http_response_code(405); json(['ok'=>false,'error'=>'method']); } }

$uid  = (int)($_SESSION['uid']  ?? 0);
$role = (string)($_SESSION['role'] ?? 'USER');
if ($uid<=0){ http_response_code(401); json(['ok'=>false,'error'=>'auth_required']); }

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

$is_admin = ($role === 'ADMIN'); // per sicurezza ci basiamo sul role in sessione

// --------- Helpers sicuri ----------
function int_param(string $name, $default=0): int {
  $v = $_GET[$name] ?? $_POST[$name] ?? $default;
  return (int)$v;
}
function str_param(string $name, $default=''): string {
  $v = $_GET[$name] ?? $_POST[$name] ?? $default;
  return is_string($v) ? trim($v) : $default;
}

// ====== COUNT UNREAD (utente) ======
if ($action==='count_unread'){ only_get();
  global $pdo, $uid;
  $st = $pdo->prepare("SELECT COUNT(*) FROM user_messages WHERE recipient_user_id=? AND status='new'");
  $st->execute([$uid]);
  $n = (int)$st->fetchColumn();
  json(['ok'=>true,'count'=>$n]);
}

// ====== LIST (utente) ======
if ($action==='list'){ only_get();
  global $pdo, $uid;
  $limit = max(1, min(100, int_param('limit', 50)));
  $st = $pdo->prepare("
    SELECT m.id, m.message_text, m.status, m.created_at, m.read_at,
           u.username AS sender_username, u.id AS sender_id
    FROM user_messages m
    LEFT JOIN users u ON u.id = m.sender_admin_id
    WHERE m.recipient_user_id = ?
      AND m.status IN ('new','read')
    ORDER BY m.created_at DESC
    LIMIT {$limit}
  ");
  $st->execute([$uid]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  json(['ok'=>true,'rows'=>$rows]);
}

// ====== MARK READ (utente) ======
if ($action==='mark_read'){ only_post(); csrf_verify_or_die();
  global $pdo, $uid;
  $mid = int_param('message_id', 0);
  if ($mid<=0) json(['ok'=>false,'error'=>'bad_request']);

  $st = $pdo->prepare("
    UPDATE user_messages
       SET status='read', read_at = IF(read_at IS NULL, NOW(), read_at)
     WHERE id=? AND recipient_user_id=? AND status<>'archived'
    ");
  $st->execute([$mid, $uid]);
  json(['ok'=>true, 'changed'=>$st->rowCount()]);
}

// ====== ARCHIVE (utente) ======
if ($action==='archive'){ only_post(); csrf_verify_or_die();
  global $pdo, $uid;
  $mid = int_param('message_id', 0);
  if ($mid<=0) json(['ok'=>false,'error'=>'bad_request']);

  $st = $pdo->prepare("
    UPDATE user_messages
       SET status='archived', archived_at = NOW()
     WHERE id=? AND recipient_user_id=? AND status<>'archived'
  ");
  $st->execute([$mid, $uid]);
  json(['ok'=>true, 'changed'=>$st->rowCount()]);
}

// ====== SEARCH USERS (admin) ======
if ($action==='search_users'){ only_get();
  global $pdo, $is_admin;
  if (!$is_admin){ http_response_code(403); json(['ok'=>false,'error'=>'forbidden']); }
  $q = str_param('q','');
  if ($q===''){ json(['ok'=>true,'rows'=>[]]); }
  // cerca su username, email, user_code, cell
  $like = "%$q%";
  $st = $pdo->prepare("
    SELECT id, username, email, user_code, cell
      FROM users
     WHERE (username LIKE ? OR email LIKE ? OR user_code LIKE ? OR cell LIKE ?)
       AND is_active=1
     ORDER BY created_at DESC
     LIMIT 20
  ");
  $st->execute([$like,$like,$like,$like]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  json(['ok'=>true,'rows'=>$rows]);
}

// ====== SEND (admin) ======
if ($action==='send'){ only_post(); csrf_verify_or_die();
  global $pdo, $uid, $is_admin;
  if (!$is_admin){ http_response_code(403); json(['ok'=>false,'error'=>'forbidden']); }

  $recipient_id = int_param('recipient_user_id', 0);
  $text = str_param('message_text','');
  if ($recipient_id<=0 || $text===''){ json(['ok'=>false,'error'=>'bad_request']); }

  try{
    // verifica che il destinatario esista ed è attivo
    $chk = $pdo->prepare("SELECT id FROM users WHERE id=? AND is_active=1");
    $chk->execute([$recipient_id]);
    if (!$chk->fetch()){ json(['ok'=>false,'error'=>'user_not_found']); }

    $ins = $pdo->prepare("
      INSERT INTO user_messages
        (sender_admin_id, recipient_user_id, message_text, status, created_at)
      VALUES (?, ?, ?, 'new', NOW())
    ");
    $ins->execute([$uid, $recipient_id, $text]);

    json(['ok'=>true]);
  }catch(Throwable $e){
    http_response_code(500);
    json(['ok'=>false,'error'=>'db','detail'=>$e->getMessage()]);
  }
}

// ==== Fallback ====
http_response_code(400);
json(['ok'=>false,'error'=>'unknown_action']);
