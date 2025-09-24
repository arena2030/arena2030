<?php
require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['uid']) || !(($_SESSION['role'] ?? 'USER')==='ADMIN' || (int)($_SESSION['is_admin'] ?? 0)===1)) {
  http_response_code(403); echo json_encode(['ok'=>false,'error'=>'forbidden']); exit;
}

$tid = trim($_POST['tid'] ?? '');
$eid = (int)($_POST['event_id'] ?? 0);
$res = strtoupper(trim((string)($_POST['result'] ?? 'UNKNOWN')));
$allowed=['UNKNOWN','HOME','AWAY','DRAW','POSTPONED','CANCELLED'];

try{
  if ($tid==='' || $eid<=0 || !in_array($res,$allowed,true)) { throw new RuntimeException('bad_params'); }
  $st=$pdo->prepare("SELECT id FROM tournament_flash WHERE code=? LIMIT 1");
  $st->execute([$tid]); $t=(int)$st->fetchColumn();
  if ($t<=0) throw new RuntimeException('bad_tournament');

  $u=$pdo->prepare("UPDATE tournament_flash_events SET result=?, result_set_at=NOW() WHERE id=? AND tournament_id=?");
  $u->execute([$res,$eid,$t]);

  echo json_encode(['ok'=>true]);
}catch(Throwable $e){
  http_response_code(500); echo json_encode(['ok'=>false,'error'=>'set_result_failed','detail'=>$e->getMessage()]);
}
