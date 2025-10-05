<?php
// /public/api/time_now.php — fornisce l'ora "autoritaria" del sistema
require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }

// opzionale: permetti solo ad admin/punto
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['ADMIN','PUNTO','USER'], true)) {
  header('Content-Type: application/json; charset=utf-8');
  http_response_code(401); echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit;
}

header('Content-Type: application/json; charset=utf-8');

// server PHP
$php_unix = time();                     // epoch dal server PHP
$php_iso  = gmdate('Y-m-d\TH:i:s\Z');   // ISO UTC (leggibile)

// ora dal DB (preferita per coerenza con NOW() usato nelle query)
try{
  $st = $pdo->query("SELECT NOW() AS db_now, UNIX_TIMESTAMP(NOW()) AS db_unix");
  $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
  $db_iso  = $row ? $row['db_now'] : null;         // nel timezone del server MySQL
  $db_unix = $row ? (int)$row['db_unix'] : null;
}catch(Throwable $e){
  $db_iso=null; $db_unix=null;
}

// timezone configurati (diagnostica)
$php_tz = date_default_timezone_get();
try{
  $tzRow = $pdo->query("SELECT @@global.time_zone AS g_tz, @@session.time_zone AS s_tz")->fetch(PDO::FETCH_ASSOC);
}catch(Throwable $e){
  $tzRow = ['g_tz'=>null, 's_tz'=>null];
}

echo json_encode([
  'ok'=>true,
  'source'=>'server_authoritative',
  'php' => ['unix'=>$php_unix, 'iso_utc'=>$php_iso, 'tz'=>$php_tz],
  'db'  => ['unix'=>$db_unix, 'iso'=>$db_iso, 'tz_global'=>$tzRow['g_tz'] ?? null, 'tz_session'=>$tzRow['s_tz'] ?? null]
]);
