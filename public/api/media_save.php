<?php
// /public/api/media_save.php — Salva metadati media e aggiorna entità collegate (team logo / prize image / avatar)
// Backwards compatible: controlla colonne prima di aggiornare. JSON sempre.
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$__db = __DIR__ . '/../../partials/db.php';
if (!file_exists($__db)) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'db_bootstrap_missing']);
  exit;
}
require_once $__db;

// CSRF soft: valida solo se presente
$__csrf = __DIR__ . '/../../partials/csrf.php';
if (file_exists($__csrf)) {
  require_once $__csrf;
  if (function_exists('csrf_verify_or_die') && isset($_POST['csrf_token'])) {
    csrf_verify_or_die();
  }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'method_not_allowed']);
  exit;
}

// Helpers
function col_exists(PDO $pdo, string $t, string $c): bool {
  $q = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $q->execute([$t,$c]); return (bool)$q->fetchColumn();
}
function table_exists(PDO $pdo, string $t): bool {
  $q = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
  $q->execute([$t]); return (bool)$q->fetchColumn();
}

// Input
$type  = $_POST['type'] ?? 'generic';     // 'team_logo' | 'prize' | 'avatar' | 'generic'
$key   = trim($_POST['storage_key'] ?? '');
$url   = trim($_POST['url'] ?? '');
$etag  = trim($_POST['etag'] ?? '');
$uid   = (int)($_SESSION['uid'] ?? 0);

// Supporta vecchi parametri
$owner = (int)($_POST['owner_id'] ?? 0);
$prize = isset($_POST['prize_id']) ? (int)$_POST['prize_id'] : null;
// Fallback comuni: user_id, team_id
if (!$owner) {
  if (isset($_POST['user_id'])) $owner = (int)$_POST['user_id'];
  if (isset($_POST['team_id'])) $owner = (int)$_POST['team_id'];
}

if ($key === '' || $url === '') {
  http_response_code(422);
  echo json_encode(['ok'=>false,'error'=>'missing_fields']);
  exit;
}

try {
  $pdo->beginTransaction();

  // 1) Media (se la tabella esiste, altrimenti salta senza rompere)
  $media_id = null;
  if (table_exists($pdo,'media')) {
    $st = $pdo->prepare("INSERT INTO media (storage_key, type, owner_id, prize_id, created_at) VALUES (?, ?, ?, ?, NOW())");
    $st->execute([$key, $type, $owner ?: null, $prize]);
    $media_id = (int)$pdo->lastInsertId();
  }

  // 2) Aggiornamenti tabellari condizionati

  // TEAM LOGO
  if ($type === 'team_logo' && $owner > 0 && table_exists($pdo,'teams')) {
    $cols = []; $params=[];
    if (col_exists($pdo,'teams','logo_key'))  { $cols[]="logo_key=?";  $params[]=$key; }
    if (col_exists($pdo,'teams','logo_url'))  { $cols[]="logo_url=?";  $params[]=$url; }
    if (col_exists($pdo,'teams','logo_etag')) { $cols[]="logo_etag=?"; $params[]=($etag!==''?$etag:null); }
    if ($cols) {
      $params[] = $owner;
      $pdo->prepare("UPDATE teams SET ".implode(',',$cols)." WHERE id=?")->execute($params);
    }
  }

  // PRIZE IMAGE
  if (($type === 'prize' || $prize || $owner) && table_exists($pdo,'prizes')) {
    $pid = $prize ?: ($owner ?: 0);
    if ($pid > 0) {
      if (col_exists($pdo,'prizes','image_media_id') && $media_id) {
        $pdo->prepare("UPDATE prizes SET image_media_id=? WHERE id=?")->execute([$media_id, $pid]);
      } else {
        // fallback: chiavi dirette se esistono
        $sets=[]; $params=[];
        foreach (['image_key','img_key'] as $c) if (col_exists($pdo,'prizes',$c)) { $sets[]="$c=?"; $params[]=$key; break; }
        foreach (['image_url','img_url'] as $c) if (col_exists($pdo,'prizes',$c)) { $sets[]="$c=?"; $params[]=$url; break; }
        foreach (['image_etag','img_etag'] as $c) if (col_exists($pdo,'prizes',$c)) { $sets[]="$c=?"; $params[]=($etag!==''?$etag:null); break; }
        if ($sets) {
          $params[]=$pid;
          $pdo->prepare("UPDATE prizes SET ".implode(',',$sets)." WHERE id=?")->execute($params);
        }
      }
    }
  }

  // AVATAR utente/punto
  if ($type === 'avatar' && $owner > 0 && table_exists($pdo,'users')) {
    $sets=[]; $params=[];
    if (col_exists($pdo,'users','avatar_key'))  { $sets[]='avatar_key=?';  $params[]=$key; }
    if (col_exists($pdo,'users','avatar_url'))  { $sets[]='avatar_url=?';  $params[]=$url; }
    if (col_exists($pdo,'users','avatar_etag')) { $sets[]='avatar_etag=?'; $params[]=($etag!==''?$etag:null); }
    if ($sets) {
      $params[]=$owner;
      $pdo->prepare("UPDATE users SET ".implode(',',$sets)." WHERE id=?")->execute($params);
    }
  }

  $pdo->commit();
  echo json_encode(['ok'=>true,'media_id'=>$media_id]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'server_error','detail'=>$e->getMessage()]);
}
