<?php
// /public/api/messages.php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/../../partials/db.php';

// In questo progetto "partials" vive sotto /public/partials
define('APP_ROOT', dirname(__DIR__, 1)); // -> /public
require_once APP_ROOT . '/partials/csrf.php';

header('Content-Type: application/json; charset=utf-8');

function jexit(array $a, int $code = 200){ if ($code!==200) http_response_code($code); echo json_encode($a); exit; }
function only_post(){ if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jexit(['ok'=>false,'error'=>'method'],405); }

$uid     = (int)($_SESSION['uid'] ?? 0);
$role    = (string)($_SESSION['role'] ?? 'USER');
$isAdmin = ($role === 'ADMIN') || (int)($_SESSION['is_admin'] ?? 0) === 1;

if ($uid <= 0) jexit(['ok'=>false,'error'=>'auth_required'], 401);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/**
 * SEARCH USERS (ADMIN)
 * GET /api/messages.php?action=search_users&q=...
 * q può essere vuota: in tal caso restituisco i top N (ultimi creati)
 */
if ($action === 'search_users') {
  if (!$isAdmin) jexit(['ok'=>false,'error'=>'forbidden'], 403);

  $q = trim((string)($_GET['q'] ?? ''));
  try{
    if ($q === '') {
      // Top 20 utenti/punti (escludo admin)
      $st = $pdo->prepare("
        SELECT id, user_code, username, email, role
        FROM users
        WHERE is_active=1
          AND (role IN ('USER','PUNTO') OR (role <> 'ADMIN' AND is_admin=0))
        ORDER BY created_at DESC, id DESC
        LIMIT 20
      ");
      $st->execute();
    } else {
      $like = '%' . $q . '%';
      $st = $pdo->prepare("
        SELECT id, user_code, username, email, role
        FROM users
        WHERE is_active=1
          AND (role IN ('USER','PUNTO') OR (role <> 'ADMIN' AND is_admin=0))
          AND (
               username LIKE ? OR email LIKE ? OR user_code LIKE ?
            OR  nome LIKE ? OR cognome LIKE ? OR cell LIKE ?
          )
        ORDER BY username ASC, id DESC
        LIMIT 50
      ");
      $st->execute([$like,$like,$like,$like,$like,$like]);
    }
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    jexit(['ok'=>true,'rows'=>$rows]);
  }catch(Throwable $e){
    jexit(['ok'=>false,'error'=>'db','detail'=>$e->getMessage()],500);
  }
}

/**
 * SEND (ADMIN)
 * POST /api/messages.php?action=send
 * body: recipient_user_id, message_text, csrf_token
 */
if ($action === 'send') {
  if (!$isAdmin) jexit(['ok'=>false,'error'=>'forbidden'], 403);
  only_post();
  csrf_verify_or_die();

  $rid = (int)($_POST['recipient_user_id'] ?? 0);
  $txt = trim((string)($_POST['message_text'] ?? ''));

  if ($rid <= 0) jexit(['ok'=>false,'error'=>'bad_request','detail'=>'recipient_user_id']);
  if ($txt === '') jexit(['ok'=>false,'error'=>'bad_request','detail'=>'message_text']);

  try{
    // Verifica destinatario valido
    $chk = $pdo->prepare("SELECT id FROM users WHERE id=? AND is_active=1 LIMIT 1");
    $chk->execute([$rid]);
    if (!$chk->fetchColumn()) jexit(['ok'=>false,'error'=>'user_not_found'],404);

    $ins = $pdo->prepare("
      INSERT INTO messages (sender_admin_id, recipient_user_id, message_text, status)
      VALUES (?, ?, ?, 'new')
    ");
    $ins->execute([$uid, $rid, $txt]);

    jexit(['ok'=>true]);
  }catch(Throwable $e){
    jexit(['ok'=>false,'error'=>'db','detail'=>$e->getMessage()],500);
  }
}

/**
 * COUNT UNREAD (USER/PUNTO)
 * GET /api/messages.php?action=count_unread
 */
if ($action === 'count_unread') {
  try{
    $st = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE recipient_user_id=? AND status='new'");
    $st->execute([$uid]);
    $n = (int)$st->fetchColumn();
    jexit(['ok'=>true,'count'=>$n]);
  }catch(Throwable $e){
    jexit(['ok'=>false,'error'=>'db','detail'=>$e->getMessage()],500);
  }
}

/**
 * LIST (USER/PUNTO)
 * GET /api/messages.php?action=list&limit=50&offset=0
 * Ritorna solo new/read (gli archiviati non compaiono)
 */
if ($action === 'list') {
  $limit  = max(1, min(200, (int)($_GET['limit'] ?? 50)));
  $offset = max(0, (int)($_GET['offset'] ?? 0));

  try{
    $sql = "
      SELECT m.id, m.message_text, m.status, m.created_at,
             u.username AS sender_username
      FROM messages m
      LEFT JOIN users u ON u.id = m.sender_admin_id
      WHERE m.recipient_user_id = ?
        AND m.status IN ('new','read')
      ORDER BY m.created_at DESC, m.id DESC
      LIMIT ? OFFSET ?
    ";
    $st = $pdo->prepare($sql);
    $st->execute([$uid, $limit, $offset]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    jexit(['ok'=>true,'rows'=>$rows]);
  }catch(Throwable $e){
    jexit(['ok'=>false,'error'=>'db','detail'=>$e->getMessage()],500);
  }
}

/**
 * MARK READ (USER/PUNTO)
 * POST /api/messages.php?action=mark_read
 * body: message_id
 */
if ($action === 'mark_read') {
  only_post();
  csrf_verify_or_die();
  $mid = (int)($_POST['message_id'] ?? 0);
  if ($mid<=0) jexit(['ok'=>false,'error'=>'bad_request','detail'=>'message_id']);

  try{
    $up = $pdo->prepare("UPDATE messages SET status='read', read_at=NOW() WHERE id=? AND recipient_user_id=? AND status='new'");
    $up->execute([$mid, $uid]);
    jexit(['ok'=>true,'updated'=>$up->rowCount()]);
  }catch(Throwable $e){
    jexit(['ok'=>false,'error'=>'db','detail'=>$e->getMessage()],500);
  }
}

/**
 * ARCHIVE (USER/PUNTO)
 * POST /api/messages.php?action=archive
 * body: message_id
 */
if ($action === 'archive') {
  only_post();
  csrf_verify_or_die();
  $mid = (int)($_POST['message_id'] ?? 0);
  if ($mid<=0) jexit(['ok'=>false,'error'=>'bad_request','detail'=>'message_id']);

  try{
    $up = $pdo->prepare("UPDATE messages SET status='archived', archived_at=NOW() WHERE id=? AND recipient_user_id=?");
    $up->execute([$mid, $uid]);
    jexit(['ok'=>true,'updated'=>$up->rowCount()]);
  }catch(Throwable $e){
    jexit(['ok'=>false,'error'=>'db','detail'=>$e->getMessage()],500);
  }
}

jexit(['ok'=>false,'error'=>'unknown_action'],400);
