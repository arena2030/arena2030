<?php
// /public/torneo.php — Pagina Torneo (view + API) — UI curata e logica completa
require_once __DIR__ . '/../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }

/* ===== DEBUG param ===== */
$__DBG = (isset($_GET['debug']) && $_GET['debug']=='1') || (isset($_POST['debug']) && $_POST['debug']=='1');
if ($__DBG) { ini_set('display_errors','1'); error_reporting(E_ALL); header('X-Debug','1'); }

/* ===== Auth ===== */
$uid  = (int)($_SESSION['uid'] ?? 0);
$role = $_SESSION['role'] ?? 'USER';
if ($uid <= 0 || !in_array($role, ['USER','PUNTO','ADMIN'], true)) { header('Location: /login.php'); exit; }

/* ===== Helpers base ===== */
function json($a){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($a); exit; }
function only_post(){ if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST'){ http_response_code(405); json(['ok'=>false,'error'=>'method']); } }
function columnExists(PDO $pdo, string $table, string $col): bool {
  static $cache = [];
  $k="$table.$col"; if(isset($cache[$k])) return $cache[$k];
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $q->execute([$table,$col]); return $cache[$k]=(bool)$q->fetchColumn();
}
function firstCol(PDO $pdo, string $table, array $cands, $fallback='NULL'){
  foreach($cands as $c){ if(columnExists($pdo,$table,$c)) return $c; } return $fallback;
}
function pickColOrNull(PDO $pdo, string $table, array $cands): ?string {
  foreach($cands as $c){ if(columnExists($pdo,$table,$c)) return $c; } return null;
}
function colMaxLen(PDO $pdo, string $table, string $col): ?int {
  $q=$pdo->prepare("SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $q->execute([$table,$col]); $len=$q->fetchColumn(); return $len? (int)$len : null;
}
/* codice random (upper HEX) rispettando len colonna */
function genCodeLen(int $len): string { $bytes = max(4, ceil($len/2)); return strtoupper(substr(bin2hex(random_bytes($bytes)), 0, $len)); }
function uniqueCodeFit(PDO $pdo, string $table, string $col, int $preferredLen=12, string $prefix=''): string {
  $max = colMaxLen($pdo,$table,$col) ?? $preferredLen;
  $avail = max(4, $max - strlen($prefix));
  $tries=0; do {
    $code = $prefix . genCodeLen($avail);
    $q=$pdo->prepare("SELECT 1 FROM `$table` WHERE `$col`=? LIMIT 1");
    $q->execute([$code]); $exists=(bool)$q->fetchColumn(); $tries++;
  } while($exists && $tries<10);
  return $code;
}

/* ====== Mapping (tournaments / lives / events / teams / picks) ====== */
$tTable   = 'tournaments';
$tId      = firstCol($pdo,$tTable,['id'],'id');
$tCode    = firstCol($pdo,$tTable,['code','tour_code','t_code','short_id'],'NULL');
$tTitle   = firstCol($pdo,$tTable,['title','name'],'NULL');
$tLeague  = firstCol($pdo,$tTable,['league','subtitle'],'NULL');
$tSeason  = firstCol($pdo,$tTable,['season','season_name'],'NULL');
$tBuyin   = firstCol($pdo,$tTable,['buyin_coins','buyin'],'0');
$tPool    = firstCol($pdo,$tTable,['prize_pool_coins','pool_coins','prize_coins','prize_pool','montepremi'],'NULL');
$tLivesMx = firstCol($pdo,$tTable,['lives_max_user','lives_max','max_lives_per_user','lives_user_max'],'NULL');
$tStatus  = firstCol($pdo,$tTable,['status','state'],'NULL');
$tSeats   = firstCol($pdo,$tTable,['seats_total','max_players'],'NULL');
$tCurrRnd = firstCol($pdo,$tTable,['current_round','round_current','round'],'NULL'); // opzionale
$tLock    = firstCol($pdo,$tTable,['lock_at','close_at','subscription_end','reg_close_at','start_time'],'NULL');

/* Lives */
$lTable = null;
foreach(['tournament_lives','tournaments_lives'] as $lt){
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
  $q->execute([$lt]); if($q->fetchColumn()){ $lTable=$lt; break; }
}
if(!$lTable){ $lTable='tournament_lives'; }

$lId    = firstCol($pdo,$lTable,['id'],'id');
$lUid   = firstCol($pdo,$lTable,['user_id','uid'],'user_id');
$lTid   = firstCol($pdo,$lTable,['tournament_id','tid'],'tournament_id');
$lRound = firstCol($pdo,$lTable,['round','rnd'],'NULL');
$lState = firstCol($pdo,$lTable,['status','state'],'NULL');
$lCode  = firstCol($pdo,$lTable,['life_code','code'],'NULL');
$lCAt   = firstCol($pdo,$lTable,['created_at','created'],'NULL');

/* Events — mapping esteso + fallback auto-nomina tabella */
function findEventsTable(PDO $pdo): ?string {
  $rows=$pdo->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE()")->fetchAll(PDO::FETCH_COLUMN);
  foreach($rows as $tbl){
    $hasTid = columnExists($pdo,$tbl,'tournament_id') || columnExists($pdo,$tbl,'tid');
    $hasHome = columnExists($pdo,$tbl,'home_team_id') || columnExists($pdo,$tbl,'team_a_id') || columnExists($pdo,$tbl,'home_id');
    $hasAway = columnExists($pdo,$tbl,'away_team_id') || columnExists($pdo,$tbl,'team_b_id') || columnExists($pdo,$tbl,'away_id');
    if ($hasTid && $hasHome && $hasAway) return $tbl;
  }
  return null;
}
$eTable=null;
foreach(['tournament_events','events','partite','matches'] as $et){
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
  $q->execute([$et]); if($q->fetchColumn()){ $eTable=$et; break; }
}
if(!$eTable){ $eTable = findEventsTable($pdo); }

$eId    = $eTable? firstCol($pdo,$eTable,['id'],'id') : 'NULL';
$eTid   = $eTable? firstCol($pdo,$eTable,['tournament_id','tid'],'tournament_id') : 'NULL';
$eRound = $eTable? firstCol($pdo,$eTable,['round','rnd','giornata','matchday','week','round_n'],'NULL') : 'NULL';
$eLock  = $eTable? firstCol($pdo,$eTable,['lock_at','deadline','close_at','start_time','kickoff_at','ora_inizio'],'NULL') : 'NULL';
$eHome  = $eTable? firstCol($pdo,$eTable,['home_team_id','team_a_id','home_id'],'NULL') : 'NULL';
$eAway  = $eTable? firstCol($pdo,$eTable,['away_team_id','team_b_id','away_id'],'NULL') : 'NULL';
$eHomeN = $eTable? firstCol($pdo,$eTable,['home_team_name','team_a_name','home_name'],'NULL') : 'NULL';
$eAwayN = $eTable? firstCol($pdo,$eTable,['away_team_name','team_b_name','away_name'],'NULL') : 'NULL';

/* Teams (opzionale) */
$teamTable=null;
foreach(['teams','squadre','clubs'] as $tt){
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
  $q->execute([$tt]); if($q->fetchColumn()){ $teamTable=$tt; break; }
}
$tmId   = $teamTable? firstCol($pdo,$teamTable,['id'],'id') : 'NULL';
$tmName = $teamTable? firstCol($pdo,$teamTable,['name','nome','team_name'],'NULL') : 'NULL';
$tmLogo = $teamTable? firstCol($pdo,$teamTable,['logo_url','logo','badge_url','image'],'NULL') : 'NULL';

/* Picks */
$pTable=null;
foreach(['tournament_picks','picks','scelte'] as $pt){
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
  $q->execute([$pt]); if($q->fetchColumn()){ $pTable=$pt; break; }
}
if(!$pTable){ $pTable='tournament_picks'; }
$pId    = firstCol($pdo,$pTable,['id'],'id');
$pLife  = firstCol($pdo,$pTable,['life_id'],'life_id');
$pTid   = firstCol($pdo,$pTable,['tournament_id','tid'],'tournament_id');
$pRound = firstCol($pdo,$pTable,['round','rnd'],'round');
$pEvent = firstCol($pdo,$pTable,['event_id','match_id'],'event_id');
$pTeam  = firstCol($pdo,$pTable,['team_id','pick_team_id','team'],'team_id');
$pCAt   = firstCol($pdo,$pTable,['created_at','created'],'NULL');

/* Log movimenti */
$logTable='points_balance_log';
$hasLog = $pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='points_balance_log'")->fetchColumn();
$lgId    = $hasLog? firstCol($pdo,$logTable,['id'],'id') : 'NULL';
$lgUid   = $hasLog? firstCol($pdo,$logTable,['user_id'],'user_id') : 'NULL';
$lgDelta = $hasLog? firstCol($pdo,$logTable,['delta','amount'],'delta') : 'NULL';
$lgReason= $hasLog? firstCol($pdo,$logTable,['reason','descr'],'reason') : 'NULL';
$lgCode  = $hasLog? pickColOrNull($pdo,$logTable,['tx_code','code']) : null;
$lgCAt   = $hasLog? firstCol($pdo,$logTable,['created_at','created'],'NULL') : 'NULL';

/* ===== Utilità Torneo ===== */
function statusLabel(?string $s, ?string $lockIso): string {
  $now=time(); $s=strtolower((string)$s); $ts=$lockIso?strtotime($lockIso):null;
  if(in_array($s,['closed','ended','finished','chiuso','terminato'],true)) return 'CHIUSO';
  if($ts!==null && $ts <= $now) return 'IN CORSO';
  return 'APERTO';
}
/* round corrente stimato (parametrizzata colonna PK) */
function getCurrentRound(PDO $pdo, int $tid, string $tTable, string $tCurrRnd, ?string $eTable, ?string $eRound, ?string $eTid, ?string $eLock, string $tIdCol): int {
  if ($tCurrRnd !== 'NULL') {
    $st=$pdo->prepare("SELECT COALESCE($tCurrRnd,1) FROM $tTable WHERE $tIdCol=?"); $st->execute([$tid]);
    $r=(int)$st->fetchColumn(); return max(1,$r);
  }
  if (!$eTable || $eRound==='NULL') return 1;
  $now='NOW()';
  if ($eLock!=='NULL') {
    $st=$pdo->prepare("SELECT COALESCE(MIN($eRound),1) FROM $eTable WHERE $eTid=? AND ($eLock IS NOT NULL AND $eLock > $now)");
    $st->execute([$tid]); $r=(int)$st->fetchColumn(); if($r>0) return $r;
  }
  $st=$pdo->prepare("SELECT COALESCE(MAX($eRound),1) FROM $eTable WHERE $eTid=?");
  $st->execute([$tid]); $r=(int)$st->fetchColumn(); return max(1,$r);
}
/* lock per round (parametrizzata colonna PK) */
function getRoundLock(PDO $pdo, int $tid, int $round, ?string $eTable, ?string $eRound, ?string $eTid, ?string $eLock, string $tTable, string $tLock, string $tIdCol): ?string {
  if ($eTable && $eRound!=='NULL' && $eLock!=='NULL') {
    $st=$pdo->prepare("SELECT MIN($eLock) FROM $eTable WHERE $eTid=? AND $eRound=?");
    $st->execute([$tid,$round]); $d=$st->fetchColumn(); if($d) return $d;
  }
  if ($round===1 && $tLock!=='NULL') {
    $st=$pdo->prepare("SELECT $tLock FROM $tTable WHERE $tIdCol=?"); $st->execute([$tid]); $d=$st->fetchColumn(); if($d) return $d;
  }
  return null;
}
/* conteggi */
function livesCountAlive(PDO $pdo, int $tid, string $lTable, string $lTid, string $lState): int {
  if ($lState!=='NULL') {
    $st=$pdo->prepare("SELECT COUNT(*) FROM $lTable WHERE $lTid=? AND $lState='alive'");
  } else {
    $st=$pdo->prepare("SELECT COUNT(*) FROM $lTable WHERE $lTid=?");
  }
  $st->execute([$tid]); return (int)$st->fetchColumn();
}
function userLives(PDO $pdo, int $uid, int $tid, string $lTable, string $lUid, string $lTid, string $lId, string $lState, string $lRound, string $lCode){
  $cols="$lId id".($lState!=='NULL'?", $lState state":"").($lRound!=='NULL'?", $lRound round":"").($lCode!=='NULL'?", $lCode life_code":"");
  $st=$pdo->prepare("SELECT $cols FROM $lTable WHERE $lUid=? AND $lTid=? ORDER BY $lId ASC");
  $st->execute([$uid,$tid]); return $st->fetchAll(PDO::FETCH_ASSOC);
}
function userLivesAliveIds(PDO $pdo, int $uid, int $tid, string $lTable, string $lUid, string $lTid, string $lId, string $lState): array {
  $sql = "SELECT $lId FROM $lTable WHERE $lUid=? AND $lTid=?";
  if ($lState!=='NULL') $sql .= " AND $lState='alive'";
  $st=$pdo->prepare($sql); $st->execute([$uid,$tid]); return array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN));
}

/* ===== API ===== */
$__ACTION = $_GET['action'] ?? ($_POST['action'] ?? null);   // <<< accetta anche POST
if ($__ACTION !== null) {
  $a=$__ACTION;

  /* ---- SUMMARY ---- */
  if ($a==='summary') {
    header('Content-Type: application/json; charset=utf-8');
    $tid = (int)($_GET['id'] ?? 0);
    $code = trim($_GET['tid'] ?? '');
    if ($tid<=0 && $code!=='' && $tCode!=='NULL'){ $st=$pdo->prepare("SELECT $tId FROM $tTable WHERE $tCode=? LIMIT 1"); $st->execute([$code]); $tid=(int)$st->fetchColumn(); }
    if ($tid<=0){ json(['ok'=>false,'error'=>'bad_id']); }

    $st=$pdo->prepare("SELECT $tId AS id,"
      . ($tCode!=='NULL' ? "$tCode AS code," : "NULL AS code,")
      . ($tTitle!=='NULL'? "$tTitle AS title," : "NULL AS title,")
      . ($tLeague!=='NULL'? "$tLeague AS league," : "NULL AS league,")
      . ($tSeason!=='NULL'? "$tSeason AS season," : "NULL AS season,")
      . "COALESCE($tBuyin,0) AS buyin,"
      . ($tPool!=='NULL'? "$tPool AS pool_coins," : "NULL AS pool_coins,")
      . ($tLivesMx!=='NULL'? "$tLivesMx AS lives_max_user," : "NULL AS lives_max_user,")
      . ($tStatus!=='NULL'? "$tStatus AS status," : "NULL AS status,")
      . ($tSeats!=='NULL'? "$tSeats AS seats_total," : "NULL AS seats_total,")
      . "COALESCE($tCurrRnd, NULL) AS current_round,"
      . ($tLock!=='NULL'? "$tLock AS lock_r1" : "NULL AS lock_r1")
      . " FROM $tTable WHERE $tId=? LIMIT 1");
    $st->execute([$tid]); $t=$st->fetch(PDO::FETCH_ASSOC);
    if(!$t){ json(['ok'=>false,'error'=>'not_found']); }

    $round = (int)($t['current_round'] ?? 0);
    if ($round<=0) { $round = getCurrentRound($pdo, $tid, $tTable, $tCurrRnd, $eTable, $eRound, $eTid, $eLock, $tId); }
    $lockNow = getRoundLock($pdo, $tid, $round, $eTable, $eRound, $eTid, $eLock, $tTable, $tLock, $tId);
    $lockR1  = $t['lock_r1'] ?? null;

    $livesInPlay = livesCountAlive($pdo,$tid,$lTable,$lTid,$lState);
    $myLives     = userLives($pdo,$uid,$tid,$lTable,$lUid,$lTid,$lId,$lState,$lRound,$lCode);

    $myLivesAlive = userLivesAliveIds($pdo,$uid,$tid,$lTable,$lUid,$lTid,$lId,$lState);
    $myLivesCount = count($myLivesAlive);

    $state = statusLabel($t['status'] ?? null, $lockR1);
    $lock1Future = $lockR1 ? (strtotime($lockR1) > time()) : true;
    $canBuyLife  = $lock1Future && ($t['lives_max_user']===null || $myLivesCount < (int)$t['lives_max_user']);
    $canUnjoin   = $lock1Future;

    json([
      'ok'=>true,
      'tournament'=>[
        'id'=>(int)$t['id'],
        'code'=>$t['code'],
        'title'=>$t['title'],
        'league'=>$t['league'],
        'season'=>$t['season'],
        'state'=>$state,
        'buyin'=>(float)$t['buyin'],
        'pool_coins'=> isset($t['pool_coins'])? (float)$t['pool_coins']:null,
        'lives_max_user'=> isset($t['lives_max_user'])? (int)$t['lives_max_user']:null,
        'current_round'=>$round,
        'lock_round'=>$lockNow,
        'lock_r1'=>$lockR1
      ],
      'stats'=>[
        'lives_in_play'=>$livesInPlay
      ],
      'me'=>[
        'lives'=>$myLives,
        'can_buy_life'=>$canBuyLife,
        'can_unjoin'=>$canUnjoin
      ]
    ]);
  }

  /* ---- EVENTS by round ---- */
  if ($a==='events') {
    header('Content-Type: application/json; charset=utf-8');
    $tid=(int)($_GET['id'] ?? 0);
    $round=(int)($_GET['round'] ?? 1);
    if(!$eTable){ json(['ok'=>true,'events'=>[],'note'=>'no_events_table']); }

    // campi base evento
    $cols = "$eId id";
    if ($eRound!=='NULL') $cols .= ", $eRound round"; else $cols .= ", NULL round";
    if ($eLock!=='NULL')  $cols .= ", $eLock lock_at"; else $cols .= ", NULL lock_at";

    $teamJoin = "";
    if ($teamTable && $eHome!=='NULL' && $eAway!=='NULL' && $tmName!=='NULL'){
      $cols .= ", e.$eHome home_id, e.$eAway away_id, ta.$tmName home_name, tb.$tmName away_name";
      if ($tmLogo!=='NULL'){ $cols.=", ta.$tmLogo home_logo, tb.$tmLogo away_logo"; } else { $cols.=", NULL home_logo, NULL away_logo"; }
      $teamJoin = " LEFT JOIN $teamTable ta ON ta.$tmId = e.$eHome
                    LEFT JOIN $teamTable tb ON tb.$tmId = e.$eAway ";
    } else {
      $cols .= ($eHome!=='NULL' ? ", e.$eHome home_id" : ", NULL home_id");
      $cols .= ($eAway!=='NULL' ? ", e.$eAway away_id" : ", NULL away_id");
      $cols .= ( $eHomeN!=='NULL' ? ", e.$eHomeN AS home_name" : ", NULL home_name");
      $cols .= ( $eAwayN!=='NULL' ? ", e.$eAwayN AS away_name" : ", NULL away_name");
      $cols .= ", NULL home_logo, NULL away_logo";
    }

    $where="e.$eTid=?";
    $params=[$tid];
    if ($eRound!=='NULL'){ $where .= " AND e.$eRound=?"; $params[]=$round; }

    // JOIN prima del WHERE (fix)
    $sql="SELECT $cols FROM $eTable e $teamJoin WHERE $where ORDER BY e.$eId ASC";
    $st=$pdo->prepare($sql); $st->execute($params);
    $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    json(['ok'=>true,'events'=>$rows,'dbg'=>($__DBG? ['sql'=>$sql,'params'=>$params]:null)]);
  }

  /* ---- TRENDING (gettonate) ---- */
  if ($a==='trending') {
    header('Content-Type: application/json; charset=utf-8');
    $tid=(int)($_GET['id'] ?? 0);
    $round=(int)($_GET['round'] ?? 1);
    $sql="SELECT p.$pTeam AS team_id, COUNT(*) cnt
          FROM $pTable p
          WHERE p.$pTid=? AND p.$pRound=?
          GROUP BY p.$pTeam";
    $st=$pdo->prepare($sql); $st->execute([$tid,$round]); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    $tot=0; foreach($rows as $r){ $tot+=(int)$r['cnt']; }
    if ($teamTable){
      foreach($rows as &$r){
        $tt=$pdo->prepare("SELECT $tmName AS name".($tmLogo!=='NULL'?", $tmLogo AS logo":"")." FROM $teamTable WHERE $tmId=?");
        $tt->execute([(int)$r['team_id']]); $x=$tt->fetch(PDO::FETCH_ASSOC)?:['name'=>null,'logo'=>null];
        $r['name']=$x['name']; if(isset($x['logo'])) $r['logo']=$x['logo'];
      }
    } else {
      foreach($rows as &$r){ $r['name']=null; $r['logo']=null; }
    }
    usort($rows, function($a,$b){ return ($b['cnt']??0) <=> ($a['cnt']??0); });
    json(['ok'=>true,'total'=>$tot,'items'=>$rows]);
  }

  /* ---- BUY_LIFE ---- */
  if ($a==='buy_life') {
    only_post();
    $tid=(int)($_POST['id'] ?? 0);
    if($tid<=0){ json(['ok'=>false,'error'=>'bad_id']); }

    $st=$pdo->prepare("SELECT $tId id, COALESCE($tBuyin,0) buyin, ".($tLivesMx!=='NULL'?"$tLivesMx lives_max_user,":"NULL lives_max_user,")." ".($tPool!=='NULL'?"$tPool pool_coins,":"NULL pool_coins,")." ".($tLock!=='NULL'?"$tLock lock_r1,":"NULL lock_r1,")." ".($tStatus!=='NULL'?"$tStatus status,":"NULL status,")." COALESCE($tCurrRnd,NULL) current_round FROM $tTable WHERE $tId=?");
    $st->execute([$tid]); $t=$st->fetch(PDO::FETCH_ASSOC); if(!$t){ json(['ok'=>false,'error'=>'not_found']); }

    $lock1 = $t['lock_r1'] ?? null; $lock1Future = $lock1 ? (strtotime($lock1) > time()) : true;
    if (! $lock1Future){ json(['ok'=>false,'error'=>'closed']); }

    $myAlive = userLivesAliveIds($pdo,$uid,$tid,$lTable,$lUid,$lTid,$lId,$lState);
    if ($t['lives_max_user']!==null && count($myAlive) >= (int)$t['lives_max_user']) { json(['ok'=>false,'error'=>'limit']); }

    $buyin = (float)$t['buyin'];
    $st=$pdo->prepare("SELECT COALESCE(coins,0) FROM users WHERE id=?"); $st->execute([$uid]); $coins=(float)$st->fetchColumn();
    if ($coins < $buyin){ json(['ok'=>false,'error'=>'insufficient_funds']); }

    try{
      $pdo->beginTransaction();
      $pdo->prepare("SELECT $tId FROM $tTable WHERE $tId=? FOR UPDATE")->execute([$tid]);

      $u=$pdo->prepare("UPDATE users SET coins = coins - ? WHERE id=? AND coins >= ?");
      $u->execute([$buyin,$uid,$buyin]); if($u->rowCount()===0) throw new Exception('balance_update_failed');

      $lifeCols=[$lUid,$lTid]; $lifeVals=['?','?']; $par=[$uid,$tid];
      if ($lRound!=='NULL'){ $lifeCols[]=$lRound; $lifeVals[]='?'; $par[]=1; }
      if ($lState!=='NULL'){ $lifeCols[]=$lState; $lifeVals[]='?'; $par[]='alive'; }
      if ($lCode!=='NULL'){ $lifeCols[]=$lCode; $lifeVals[]='?'; $par[] = uniqueCodeFit($pdo,$lTable,$lCode,12,''); }
      if ($lCAt!=='NULL'){ $lifeCols[]=$lCAt;  $lifeVals[]='NOW()'; }
      $sql="INSERT INTO $lTable(".implode(',',$lifeCols).") VALUES(".implode(',',$lifeVals).")";
      $pdo->prepare($sql)->execute($par);

      if ($tPool!=='NULL'){ $pdo->prepare("UPDATE $tTable SET $tPool=COALESCE($tPool,0)+? WHERE $tId=?")->execute([$buyin,$tid]); }

      if ($hasLog){
        $cols=[$lgUid,$lgDelta,$lgReason]; $vals=['?','?','?']; $par=[$uid, -$buyin, 'Acquisto vita torneo #'.$tid];
        if ($lgCode){ array_unshift($cols,$lgCode); array_unshift($vals,'?'); array_unshift($par, uniqueCodeFit($pdo,$logTable,$lgCode,12,'T')); }
        if ($lgCAt!=='NULL'){ $cols[]=$lgCAt; $vals[]='NOW()'; }
        $pdo->prepare("INSERT INTO $logTable(".implode(',',$cols).") VALUES(".implode(',',$vals).")")->execute($par);
      }

      $pdo->commit();
      $st=$pdo->prepare("SELECT COALESCE(coins,0) FROM users WHERE id=?"); $st->execute([$uid]); $new=(float)$st->fetchColumn();
      json(['ok'=>true,'new_balance'=>$new]);
    }catch(Throwable $e){
      if($pdo->inTransaction()) $pdo->rollBack();
      if ($__DBG){ json(['ok'=>false,'error'=>'buy_failed','detail'=>$e->getMessage(),'trace'=>$e->getTraceAsString()]); }
      json(['ok'=>false,'error'=>'buy_failed','detail'=>$e->getMessage()]);
    }
  }

  /* ---- UNJOIN (rimborso totale) ---- */
  if ($a==='unjoin') {
    only_post();
    $tid=(int)($_POST['id'] ?? 0);
    if($tid<=0){ json(['ok'=>false,'error'=>'bad_id']); }

    $st=$pdo->prepare("SELECT $tId id, COALESCE($tBuyin,0) buyin, ".($tPool!=='NULL'?"COALESCE($tPool,0) pool_coins,":"0 pool_coins,")." ".($tLock!=='NULL'?"$tLock lock_r1,":"NULL lock_r1")." ".($tStatus!=='NULL'?"$tStatus status,":"NULL status")." COALESCE($tCurrRnd,NULL) current_round FROM $tTable WHERE $tId=?");
    $st->execute([$tid]); $t=$st->fetch(PDO::FETCH_ASSOC); if(!$t){ json(['ok'=>false,'error'=>'not_found']); }

    $lock1 = $t['lock_r1'] ?? null; if ($lock1 && strtotime($lock1) <= time()){ json(['ok'=>false,'error'=>'closed']); }

    $ids = userLivesAliveIds($pdo,$uid,$tid,$lTable,$lUid,$lTid,$lId,$lState);
    if (!$ids){ json(['ok'=>false,'error'=>'no_lives']); }
    if ($pTable){
      $in = implode(',', array_fill(0,count($ids),'?'));
      $pr = $pdo->prepare("SELECT COUNT(*) FROM $pTable WHERE $pRound=1 AND $pLife IN ($in)");
      $pr->execute($ids); $hasPicks=(int)$pr->fetchColumn();
      if ($hasPicks>0){ json(['ok'=>false,'error'=>'has_picks_r1']); }
    }

    $refund = (float)$t['buyin'] * count($ids);

    try{
      $pdo->beginTransaction();
      $pdo->prepare("UPDATE users SET coins=coins+? WHERE id=?")->execute([$refund,$uid]);
      if ($tPool!=='NULL'){ $pdo->prepare("UPDATE $tTable SET $tPool=GREATEST(COALESCE($tPool,0)-?,0) WHERE $tId=?")->execute([$refund,$tid]); }
      $in = implode(',', array_fill(0,count($ids),'?'));
      if ($lState!=='NULL' && columnExists($pdo,$lTable,'refunded_at')) {
        $pdo->prepare("UPDATE $lTable SET $lState='refunded', refunded_at=NOW() WHERE $lId IN ($in)")->execute($ids);
      } else {
        $pdo->prepare("DELETE FROM $lTable WHERE $lId IN ($in)")->execute($ids);
      }
      if ($hasLog){
        $cols=[$lgUid,$lgDelta,$lgReason]; $vals=['?','?','?']; $par=[$uid, +$refund, 'Disiscrizione torneo #'.$tid];
        if ($lgCode){ array_unshift($cols,$lgCode); array_unshift($vals,'?'); array_unshift($par, uniqueCodeFit($pdo,$logTable,$lgCode,12,'T')); }
        if ($lgCAt!=='NULL'){ $cols[]=$lgCAt; $vals[]='NOW()'; }
        $pdo->prepare("INSERT INTO $logTable(".implode(',',$cols).") VALUES(".implode(',',$vals).")")->execute($par);
      }
      $pdo->commit();
      $st=$pdo->prepare("SELECT COALESCE(coins,0) FROM users WHERE id=?"); $st->execute([$uid]); $new=(float)$st->fetchColumn();
      json(['ok'=>true,'new_balance'=>$new]);
    }catch(Throwable $e){
      if($pdo->inTransaction()) $pdo->rollBack();
      if ($__DBG){ json(['ok'=>false,'error'=>'unjoin_failed','detail'=>$e->getMessage(),'trace'=>$e->getTraceAsString()]); }
      json(['ok'=>false,'error'=>'unjoin_failed','detail'=>$e->getMessage()]);
    }
  }

  /* ---- PICK ---- */
  if ($a==='pick') {
    only_post();
    $tid   = (int)($_POST['id'] ?? 0);
    $life  = (int)($_POST['life_id'] ?? 0);
    $event = (int)($_POST['event_id'] ?? 0);
    $team  = (int)($_POST['team_id'] ?? 0);
    $round = (int)($_POST['round'] ?? 0);
    if($tid<=0 || $life<=0 || $event<=0 || $team<=0 || $round<=0){ json(['ok'=>false,'error'=>'bad_params']); }

    $sql="SELECT $lId id ".($lState!=='NULL'?", $lState state":"")." FROM $lTable WHERE $lId=? AND $lUid=? AND $lTid=? LIMIT 1";
    $st=$pdo->prepare($sql); $st->execute([$life,$uid,$tid]); $v=$st->fetch(PDO::FETCH_ASSOC);
    if(!$v){ json(['ok'=>false,'error'=>'life_not_found']); }
    if ($lState!=='NULL' && strtolower((string)$v['state'])!=='alive'){ json(['ok'=>false,'error'=>'life_not_alive']); }

    if ($eTable){
      $q="SELECT 1 FROM $eTable WHERE $eId=? AND $eTid=?";
      $params=[$event,$tid];
      if ($eRound!=='NULL'){ $q.=" AND $eRound=?"; $params[]=$round; }
      if ($eLock!=='NULL'){ $q.=" AND ($eLock IS NULL OR $eLock>NOW())"; }
      $st=$pdo->prepare($q); $st->execute($params); if(!$st->fetchColumn()){ json(['ok'=>false,'error'=>'event_locked']); }
    }

    // ciclo vita
    $availableTeams=[];
    if ($eTable){
      if ($eHome!=='NULL'){
        $qq="SELECT DISTINCT $eHome AS tid FROM $eTable WHERE $eTid=?"; $par=[$tid];
        if ($eRound!=='NULL'){ $qq.=" AND $eRound=?"; $par[]=$round; }
        if ($eLock!=='NULL'){  $qq.=" AND ($eLock IS NULL OR $eLock>NOW())"; }
        $st=$pdo->prepare($qq); $st->execute($par); $availableTeams=array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN));
      }
      if ($eAway!=='NULL'){
        $qq="SELECT DISTINCT $eAway AS tid FROM $eTable WHERE $eTid=?"; $par=[$tid];
        if ($eRound!=='NULL'){ $qq.=" AND $eRound=?"; $par[]=$round; }
        if ($eLock!=='NULL'){  $qq.=" AND ($eLock IS NULL OR $eLock>NOW())"; }
        $st=$pdo->prepare($qq); $st->execute($par); $availableTeams=array_merge($availableTeams,array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN)));
      }
      $availableTeams = array_values(array_unique(array_filter($availableTeams)));
    }

    $st=$pdo->prepare("SELECT DISTINCT $pTeam FROM $pTable WHERE $pLife=? AND $pTid=?");
    $st->execute([$life,$tid]); $chosen=array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN));

    $missing = array_values(array_diff($availableTeams ?: [], $chosen));
    $mustChooseFromMissing = count($missing)>0;

    if ($mustChooseFromMissing){
      if (!in_array($team, $missing, true)){ json(['ok'=>false,'error'=>'must_choose_missing']); }
    } else {
      $st=$pdo->prepare("SELECT $pTeam FROM $pTable WHERE $pLife=? AND $pTid=? AND $pRound=? LIMIT 1");
      $st->execute([$life,$tid,$round-1]); $prevTeam=(int)$st->fetchColumn();
      if ($prevTeam>0 && $prevTeam===$team){ json(['ok'=>false,'error'=>'cannot_repeat_prev']); }
    }

    try{
      $pdo->beginTransaction();
      $chk=$pdo->prepare("SELECT $pId FROM $pTable WHERE $pLife=? AND $pTid=? AND $pRound=? AND $pEvent=? LIMIT 1");
      $chk->execute([$life,$tid,$round,$event]); $pid=(int)$chk->fetchColumn();
      if ($pid>0){
        $u=$pdo->prepare("UPDATE $pTable SET $pTeam=? WHERE $pId=?");
        $u->execute([$team,$pid]);
      } else {
        $cols=[$pLife,$pTid,$pRound,$pEvent,$pTeam]; $vals=['?','?','?','?','?']; $par=[$life,$tid,$round,$event,$team];
        if ($pCAt!=='NULL'){ $cols[]=$pCAt; $vals[]='NOW()'; }
        $sql="INSERT INTO $pTable(".implode(',',$cols).") VALUES(".implode(',',$vals).")";
        $pdo->prepare($sql)->execute($par);
      }
      $pdo->commit();
      json(['ok'=>true]);
    }catch(Throwable $e){
      if($pdo->inTransaction()) $pdo->rollBack();
      if ($__DBG){ json(['ok'=>false,'error'=>'pick_failed','detail'=>$e->getMessage(),'trace'=>$e->getTraceAsString()]); }
      json(['ok'=>false,'error'=>'pick_failed','detail'=>$e->getMessage()]);
    }
  }

  /* ---- INFO SCELTE ---- */
  if ($a==='choices_info') {
    header('Content-Type: application/json; charset=utf-8');
    $tid=(int)($_GET['id'] ?? 0);
    $round=(int)($_GET['round'] ?? 1);
    $unameCol = columnExists($pdo,'users','username') ? 'username' : firstCol($pdo,'users',['name','email','cell'],'id');
    $sql="SELECT u.$unameCol AS username, p.$pTeam AS team_id
          FROM $pTable p
          JOIN $lTable l ON l.$lId=p.$pLife
          JOIN users u ON u.id=l.$lUid
          WHERE p.$pTid=? AND p.$pRound=?
          ORDER BY username ASC";
    $st=$pdo->prepare($sql); $st->execute([$tid,$round]);
    $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    if ($teamTable){
      foreach($rows as &$r){
        $x=$pdo->prepare("SELECT $tmName AS name FROM $teamTable WHERE $tmId=?");
        $x->execute([(int)$r['team_id']]); $nm=$x->fetchColumn();
        $r['team_name']=$nm ?: ('#'.$r['team_id']);
      }
    } else {
      foreach($rows as &$r){ $r['team_name']='#'.$r['team_id']; }
    }
    json(['ok'=>true,'rows'=>$rows]);
  }

  http_response_code(400); json(['ok'=>false,'error'=>'unknown_action']);
}

/* ======== VIEW ======== */
$page_css='/pages-css/admin-dashboard.css';
include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/header_utente.php';
?>
<style>
/* ===== Layout & hero ===== */
.twrap{ max-width:1100px; margin:0 auto; }
.hero{
  position:relative; background:linear-gradient(135deg,#1e3a8a 0%, #0f172a 100%);
  border:1px solid rgba(255,255,255,.1); border-radius:20px; padding:18px 18px 14px;
  color:#fff; box-shadow:0 18px 60px rgba(0,0,0,.35);
}
.hero h1{ margin:0 0 4px; font-size:22px; font-weight:900; letter-spacing:.3px; }
.hero .sub{ opacity:.9; font-size:13px; }
.state{ position:absolute; top:12px; right:12px; font-size:12px; font-weight:800; letter-spacing:.4px;
  padding:4px 10px; border-radius:9999px; border:1px solid rgba(255,255,255,.25); background:rgba(0,0,0,.2); }
.state.open{ border-color: rgba(52,211,153,.45); color:#d1fae5; }
.state.live{ border-color: rgba(250,204,21,.55); color:#fef9c3; }
.state.end{  border-color: rgba(239,68,68,.45); color:#fee2e2; }
.kpis{ display:grid; grid-template-columns: repeat(4, 1fr); gap:12px; margin-top:12px; }
.kpi{ background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.08); border-radius:14px; padding:12px; text-align:center; }
.kpi .lbl{ font-size:12px; opacity:.9;}
.kpi .val{ font-size:18px; font-weight:900; letter-spacing:.3px; }
.countdown{ font-variant-numeric:tabular-nums; font-weight:900; }

/* ===== Azioni: sinistra (buy/info) — destra (unjoin) ===== */
.actions{ display:flex; justify-content:space-between; align-items:center; gap:12px; margin-top:12px; }
.actions-left, .actions-right{ display:flex; gap:8px; align-items:center; }

/* ===== Vite ===== */
.vite-card{ margin-top:16px; background:#0b1220; border:1px solid #121b2d; border-radius:16px; padding:14px; color:#fff; }
.vbar{ display:flex; align-items:center; flex-wrap:wrap; gap:10px; margin-top:10px;}
.life{
  position:relative; display:flex; align-items:center; gap:6px; padding:6px 10px; border-radius:9999px;
  background:linear-gradient(135deg,#13203a 0%,#0c1528 100%); border:1px solid #1f2b46;
  cursor:pointer;
}
.life.active{ box-shadow:0 0 0 2px #2563eb inset; }
.life img.logo{ width:18px; height:18px; object-fit:cover; border-radius:50%; border:1px solid rgba(255,255,255,.35); }
.heart{ width:18px; height:18px; display:inline-block; background:url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" fill="%23FF3B3B" viewBox="0 0 24 24"><path d="M12 21s-8-6.438-8-11a5 5 0 0 1 9-3 5 5 0 0 1 9 3c0 4.562-8 11-8 11z"/></svg>') no-repeat center/contain; }
.life.lost .heart{ filter:grayscale(1) opacity(.5); }

/* ===== Gettonate (chips) ===== */
.trend-card{ margin-top:16px; background:#0b1220; border:1px solid #121b2d; border-radius:16px; padding:14px; color:#fff; }
.trend-title{ font-weight:800; }
.trend-chips{ display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; }
.chip{ display:flex; align-items:center; gap:8px; padding:6px 10px; border-radius:9999px; background:#0f172a; border:1px solid #14203a; }
.chip img{ width:18px; height:18px; border-radius:50%; object-fit:cover; }
.chip .cnt{ opacity:.8; font-size:12px; }

/* ===== Eventi (card ovale A vs B) ===== */
.events-card{ margin-top:16px; background:#0b1220; border:1px solid #121b2d; border-radius:16px; padding:14px; color:#fff; }
.round-head{ display:flex; align-items:center; gap:12px; margin-bottom:8px;}
.round-head h3{ margin:0; font-size:18px; font-weight:900;}
.egrid{ display:grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap:12px; }
@media (max-width:820px){ .egrid{ grid-template-columns: 1fr; } }
.evt{
  position:relative; display:flex; align-items:center; justify-content:center; gap:12px;
  background:radial-gradient(900px 200px at 50% -100px, rgba(99,102,241,.15), transparent 60%), linear-gradient(125deg,#111827 0%, #0b1120 100%);
  border:1px solid #1f2937; border-radius:9999px; padding:12px 16px; cursor:pointer;
  transition: transform .12s ease, box-shadow .12s ease;
}
.evt:hover{ transform:translateY(-1px); box-shadow:0 12px 30px rgba(0,0,0,.35);}
.team{ display:flex; align-items:center; gap:8px; min-width:0;}
.team img{ width:28px; height:28px; border-radius:50%; object-fit:cover; }
.vs{ font-weight:900; opacity:.9; }
.flag{ position:absolute; right:10px; top:-6px; width:20px; height:20px; border-radius:50%; background:#fde047; display:none; animation: pulse 1s infinite; }
@keyframes pulse{ 0%{transform:scale(.9)} 50%{transform:scale(1.1)} 100%{transform:scale(.9)} }
.evt.selected .flag{ display:block; }

/* ===== Bottoni ===== */
.btn[type="button"]{ cursor:pointer; }
.muted{ color:#9ca3af; font-size:12px; }

/* ===== Modali ===== */
.modal[aria-hidden="true"]{ display:none; } .modal{ position:fixed; inset:0; z-index:85;}
.modal-backdrop{ position:absolute; inset:0; background:rgba(0,0,0,.55); }
.modal-card{ position:relative; z-index:86; width:min(520px,94vw); margin:12vh auto 0;
  background:var(--c-bg); border:1px solid var(--c-border); border-radius:16px; overflow:hidden; box-shadow:0 18px 50px rgba(0,0,0,.5); color:#fff;}
.modal-head{ padding:12px 16px; border-bottom:1px solid var(--c-border); display:flex; align-items:center; gap:8px;}
.modal-body{ padding:16px;}
.modal-foot{ padding:12px 16px; border-top:1px solid var(--c-border); display:flex; justify-content:flex-end; gap:8px;}
</style>

<main class="section">
  <div class="container">
    <div class="twrap">
      <!-- HERO -->
      <div class="hero">
        <div class="state" id="tState">APERTO</div>
        <h1 id="tTitle">Torneo</h1>
        <div class="sub" id="tSub">Lega • Stagione</div>
        <div class="kpis">
          <div class="kpi"><div class="lbl">Vite in gioco</div><div class="val" id="kLives">0</div></div>
          <div class="kpi"><div class="lbl">Montepremi (AC)</div><div class="val" id="kPool">0.00</div></div>
          <div class="kpi"><div class="lbl">Vite max/utente</div><div class="val" id="kLmax">n/d</div></div>
          <div class="kpi"><div class="lbl">Lock round</div><div class="val countdown" id="kLock" data-lock=""></div></div>
        </div>
        <div class="actions">
          <div class="actions-left">
            <button class="btn btn--primary btn--sm" type="button" id="btnBuy">Acquista una vita</button>
            <button class="btn btn--ghost btn--sm" type="button" id="btnInfo">Infoscelte</button>
          </div>
          <div class="actions-right">
            <button class="btn btn--outline btn--sm" type="button" id="btnUnjoin">Disiscrivi</button>
          </div>
        </div>
        <span class="muted" id="hint"></span>
      </div>

      <!-- VITE -->
      <div class="vite-card">
        <strong>Le mie vite</strong>
        <div class="vbar" id="vbar"></div>
      </div>

      <!-- GETTONATE -->
      <div class="trend-card">
        <div class="trend-title">Gli utenti hanno scelto</div>
        <div class="trend-chips" id="trend"></div>
      </div>

      <!-- EVENTI -->
      <div class="events-card">
        <div class="round-head">
          <h3>Eventi torneo — Round <span id="rNow2">1</span></h3>
          <span class="muted" id="lockTxt"></span>
        </div>
        <div class="egrid" id="events"></div>
      </div>
    </div>
  </div>
</main>

<!-- Modal: conferme -->
<div class="modal" id="mdConfirm" aria-hidden="true">
  <div class="modal-backdrop" data-close></div>
  <div class="modal-card">
    <div class="modal-head"><h3 id="mdTitle">Conferma</h3></div>
    <div class="modal-body"><p id="mdText"></p></div>
    <div class="modal-foot">
      <button class="btn btn--outline" type="button" data-close>Annulla</button>
      <button class="btn btn--primary" type="button" id="mdOk">Conferma</button>
    </div>
  </div>
</div>

<!-- Modal: infoscelte -->
<div class="modal" id="mdInfo" aria-hidden="true">
  <div class="modal-backdrop" data-close></div>
  <div class="modal-card">
    <div class="modal-head"><h3>Trasparenza scelte</h3></div>
    <div class="modal-body"><div id="infoList" class="muted">Caricamento…</div></div>
    <div class="modal-foot"><button class="btn btn--primary" type="button" data-close>Chiudi</button></div>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', ()=>{
  const $=s=>document.querySelector(s), $$=(s,p=document)=>[...p.querySelectorAll(s)];
  const qs=new URLSearchParams(location.search);
  const tid = Number(qs.get('id')||0) || 0;
  const tcode= qs.get('tid')||'';
  let TID = tid, TCODE=tcode;
  let ROUND=1, LOCK_TS=0, CAN_BUY=true, CAN_UNJOIN=true, BUYIN=0;

  const flagToast=(msg)=>{ const h=$('#hint'); h.textContent=msg; setTimeout(()=>h.textContent='', 2600); };

  function fmtCoins(n){ return Number(n||0).toFixed(2); }
  function tick(){
    const el=$('#kLock'); const ts=Number(el.getAttribute('data-lock')||0);
    if(!ts){ el.textContent='—'; return; }
    const now=Date.now(), diff=Math.floor((ts-now)/1000);
    if (diff<=0){ el.textContent='CHIUSO'; return; }
    let d=diff, dd=Math.floor(d/86400); d%=86400; const hh=String(Math.floor(d/3600)).padStart(2,'0'); d%=3600; const mm=String(Math.floor(d/60)).padStart(2,'0'); const ss=String(d%60).padStart(2,'0');
    el.textContent=(dd>0?dd+'g ':'')+hh+':'+mm+':'+ss;
    $('#lockTxt').textContent='Lock tra ' + el.textContent;
  }
  setInterval(tick,1000);

  /* ===== LOAD SUMMARY ===== */
  async function loadSummary(){
    const p=new URLSearchParams({action:'summary'}); if(TID) p.set('id',TID); else p.set('tid',TCODE);
    const r=await fetch('?'+p.toString(),{cache:'no-store'}); const j=await r.json();
    if(!j.ok){ flagToast('Torneo non trovato'); return; }
    const t=j.tournament;
    TID=t.id; ROUND=t.current_round||1; BUYIN=t.buyin||0;
    $('#tTitle').textContent = t.title || 'Torneo';
    $('#tSub').textContent = [t.league,t.season].filter(Boolean).join(' • ') || '';
    const st= t.state || 'APERTO';
    const stEl=$('#tState'); stEl.textContent=st; stEl.className='state '+(st==='APERTO'?'open':(st==='IN CORSO'?'live':'end'));
    $('#kLives').textContent= j.stats.lives_in_play || 0;
    $('#kPool').textContent = fmtCoins(t.pool_coins||0);
    $('#kLmax').textContent = (t.lives_max_user==null? 'n/d' : String(t.lives_max_user));
    $('#rNow2').textContent=ROUND;
    const lock = t.lock_round || t.lock_r1 || null;
    if (lock){ LOCK_TS = (new Date(lock)).getTime(); $('#kLock').setAttribute('data-lock', String(LOCK_TS)); } else { LOCK_TS=0; $('#kLock').setAttribute('data-lock','0'); }
    tick();

    CAN_BUY    = !!(j.me && j.me.can_buy_life);
    CAN_UNJOIN = !!(j.me && j.me.can_unjoin);
    $('#btnBuy').disabled = !CAN_BUY;
    $('#btnUnjoin').disabled = !CAN_UNJOIN;

    // vite
    const vbar=$('#vbar'); vbar.innerHTML='';
    const lives= (j.me && j.me.lives) ? j.me.lives : [];
    lives.forEach((lv,idx)=>{
      const d=document.createElement('div'); d.className='life'; d.setAttribute('data-id', String(lv.id));
      d.innerHTML='<span class="heart"></span><span>Vita '+(idx+1)+'</span>';
      d.addEventListener('click', ()=>{ $$('.life').forEach(x=>x.classList.remove('active')); d.classList.add('active'); });
      vbar.appendChild(d);
    });
    if (!lives.length){ const s=document.createElement('span'); s.className='muted'; s.textContent='Nessuna vita. Acquista una vita per iniziare.'; vbar.appendChild(s); }
    const first=$('.life'); if (first) first.classList.add('active');

    await Promise.all([loadTrending(), loadEvents()]);
  }

  /* ===== TRENDING (chips) ===== */
  async function loadTrending(){
    const p=new URLSearchParams({action:'trending', id:String(TID), round:String(ROUND)});
    const r=await fetch('?'+p.toString(),{cache:'no-store'}); const j=await r.json();
    const box=$('#trend'); box.innerHTML='';
    if (!j.ok || !(j.items||[]).length){ box.innerHTML='<div class="muted">Ancora nessuna scelta.</div>'; return; }
    j.items.forEach(it=>{
      const d=document.createElement('div'); d.className='chip';
      d.innerHTML = `${it.logo? `<img src="${it.logo}" alt="">` : '<span style="width:18px;height:18px;border-radius:50%;background:#1f2937;display:inline-block;"></span>'}
                     <strong>${it.name||('#'+it.team_id)}</strong>
                     <span class="cnt">× ${it.cnt||0}</span>`;
      box.appendChild(d);
    });
  }

  function lifeActiveId(){ const a=$('.life.active'); return a? Number(a.getAttribute('data-id')): 0; }

  /* ===== EVENTI ===== */
  async function loadEvents(){
    const p=new URLSearchParams({action:'events', id:String(TID), round:String(ROUND)});
    const r=await fetch('?'+p.toString(),{cache:'no-store'}); const j=await r.json();
    const box=$('#events'); box.innerHTML='';
    if (!j.ok){ box.innerHTML='<div class="muted">Nessun evento.</div>'; return; }
    const evs = j.events||[];
    if (!evs.length){ box.innerHTML='<div class="muted">Nessun evento per questo round.</div>'; return; }

    evs.forEach(ev=>{
      const d=document.createElement('div'); d.className='evt';
      d.innerHTML = `
        <div class="team">${ev.home_logo? `<img src="${ev.home_logo}" alt="">` : ''}<strong>${ev.home_name||('#'+(ev.home_id||'?'))}</strong></div>
        <div class="vs">VS</div>
        <div class="team"><strong>${ev.away_name||('#'+(ev.away_id||'?'))}</strong>${ev.away_logo? `<img src="${ev.away_logo}" alt="">` : ''}</div>
        <div class="flag"></div>
      `;
      d.addEventListener('click', async ()=>{
        const life = lifeActiveId(); if(!life){ flagToast('Seleziona prima una vita'); return; }
        const pickTeam = await askTeam(ev); if(!pickTeam) return;
        const fd=new URLSearchParams({action:'pick', id:String(TID), life_id:String(life), event_id:String(ev.id), team_id:String(pickTeam), round:String(ROUND)});
        const rsp=await fetch('', {method:'POST', body:fd}); const jr=await rsp.json();
        if (!jr.ok){
          let msg='Errore scelta';
          if (jr.error==='must_choose_missing') msg='Devi scegliere una squadra non ancora scelta (ciclo in corso).';
          else if (jr.error==='cannot_repeat_prev') msg='Non puoi ripetere la squadra del round precedente.';
          else if (jr.error==='event_locked') msg='Scelte chiuse per questo evento.';
          flagToast(msg);
          return;
        }
        d.classList.add('selected');
        flagToast('Salvataggio effettuato con successo');
        loadTrending();
      });
      box.appendChild(d);
    });
  }

  function askTeam(ev){
    return new Promise((resolve)=>{
      const m=$('#mdConfirm'); $('#mdTitle').textContent='Conferma scelta';
      const h=`
        Scegli la squadra per la tua vita:<br><br>
        <div style="display:flex; gap:8px; align-items:center; justify-content:center;">
          <button class="btn btn--outline" type="button" id="chooseA">${ev.home_name||'#'+ev.home_id}</button>
          <strong>VS</strong>
          <button class="btn btn--outline" type="button" id="chooseB">${ev.away_name||'#'+ev.away_id}</button>
        </div>
      `;
      $('#mdText').innerHTML=h; const okBtn=$('#mdOk'); okBtn.style.display='none';
      m.setAttribute('aria-hidden','false');
      const cleanup=()=>{ okBtn.style.display=''; $$('#mdConfirm [data-close], #chooseA, #chooseB').forEach(el=> el.replaceWith(el.cloneNode(true))); };
      const close=()=>{ m.setAttribute('aria-hidden','true'); cleanup(); };
      const bind=()=> {
        $('#chooseA').addEventListener('click', ()=>{ close(); resolve(ev.home_id||0); }, {once:true});
        $('#chooseB').addEventListener('click', ()=>{ close(); resolve(ev.away_id||0); }, {once:true});
        $$('#mdConfirm [data-close], #mdConfirm .modal-backdrop').forEach(el=>el.addEventListener('click', ()=>{ close(); resolve(0); }, {once:true}));
      };
      bind();
    });
  }

  /* ===== BUY LIFE ===== */
  $('#btnBuy').addEventListener('click', ()=>{
    if (!CAN_BUY){ flagToast('Non puoi acquistare altre vite.'); return; }
    const m=$('#mdConfirm'); $('#mdTitle').textContent='Acquista vita';
    $('#mdText').innerHTML = `Confermi l’acquisto di <strong>1 vita</strong> per <strong>${fmtCoins(BUYIN)}</strong> AC?`;
    const ok=$('#mdOk');
    ok.onclick = async ()=>{
      const fd=new URLSearchParams({action:'buy_life', id:String(TID)});
      const r=await fetch('', {method:'POST', body:fd}); const j=await r.json();
      if (!j.ok){ flagToast('Errore acquisto vita'); return; }
      m.setAttribute('aria-hidden','true'); flagToast('Vita acquistata');
      document.dispatchEvent(new CustomEvent('refresh-balance'));
      await loadSummary();
    };
    $('#mdConfirm').setAttribute('aria-hidden','false');
  });

  /* ===== UNJOIN ===== */
  $('#btnUnjoin').addEventListener('click', ()=>{
    if (!CAN_UNJOIN){ flagToast('Disiscrizione non consentita.'); return; }
    const m=$('#mdConfirm'); $('#mdTitle').textContent='Disiscrizione';
    $('#mdText').innerHTML = `Confermi la disiscrizione? Ti verranno rimborsati <strong>${fmtCoins(BUYIN)}</strong> AC per ogni vita posseduta.`;
    const ok=$('#mdOk');
    ok.onclick = async ()=>{
      const fd=new URLSearchParams({action:'unjoin', id:String(TID)});
      const r=await fetch('', {method:'POST', body:fd}); const j=await r.json();
      if (!j.ok){
        let msg='Errore disiscrizione';
        if (j.error==='has_picks_r1') msg='Hai già effettuato una scelta al round 1.';
        else if (j.error==='closed') msg='Disiscrizione chiusa.';
        flagToast(msg); return;
      }
      m.setAttribute('aria-hidden','true');
      flagToast('Disiscrizione completata');
      document.dispatchEvent(new CustomEvent('refresh-balance'));
      location.href='/lobby.php';
    };
    $('#mdConfirm').setAttribute('aria-hidden','false');
  });

  /* ===== INFO SCELTE ===== */
  $('#btnInfo').addEventListener('click', async ()=>{
    const p=new URLSearchParams({action:'choices_info', id:String(TID), round:String(ROUND)});
    const r=await fetch('?'+p.toString(),{cache:'no-store'}); const j=await r.json();
    const box=$('#infoList'); box.innerHTML='';
    if (!j.ok || !(j.rows||[]).length){ box.innerHTML='<div>Nessuna scelta disponibile.</div>'; }
    else {
      const ul=document.createElement('div'); ul.style.display='grid'; ul.style.gap='6px';
      j.rows.forEach(row=>{
        const div=document.createElement('div');
        div.textContent = (row.username||'utente') + ' → ' + (row.team_name||('#'+row.team_id));
        ul.appendChild(div);
      });
      box.appendChild(ul);
    }
    $('#mdInfo').setAttribute('aria-hidden','false');
  });

  /* ===== chiusura modali ===== */
  $$('#mdConfirm [data-close], #mdConfirm .modal-backdrop').forEach(el=>el.addEventListener('click', ()=>$('#mdConfirm').setAttribute('aria-hidden','true')));
  $$('#mdInfo [data-close], #mdInfo .modal-backdrop').forEach(el=>el.addEventListener('click', ()=>$('#mdInfo').setAttribute('aria-hidden','true')));

  // init
  loadSummary();
});
</script>
