<?php
// /public/api/torneo.php — API del torneo (solo azioni richieste)
declare(strict_types=1);
ini_set('display_errors','0'); ini_set('log_errors','1'); ini_set('error_log','/tmp/php_errors.log');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }

$uid  = (int)($_SESSION['uid'] ?? 0);
$role = $_SESSION['role'] ?? 'USER';
if ($uid <= 0 || !in_array($role, ['USER','PUNTO','ADMIN'], true)) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'auth']); exit; }

$__DBG = (($_GET['debug'] ?? $_POST['debug'] ?? '') === '1'); if ($__DBG) { ini_set('display_errors','1'); header('X-Debug','1'); }

function J($a){ echo json_encode($a); exit; }
function JD($base,$detail=null,$extras=[]){ global $__DBG; if ($__DBG){ if($detail!==null)$base['detail']=$detail; if($extras)$base['dbg']=$extras; } J($base); }
function only_post(){ if (($_SERVER['REQUEST_METHOD']??'')!=='POST'){ http_response_code(405); JD(['ok'=>false,'error'=>'method'],'Metodo non consentito',['method'=>$_SERVER['REQUEST_METHOD']??'']); } }
function colExists(PDO $pdo,string $t,string $c):bool{ static $m=[]; $k="$t.$c"; if(isset($m[$k]))return $m[$k]; $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?"); $q->execute([$t,$c]); return $m[$k]=(bool)$q->fetchColumn(); }
function firstCol(PDO $pdo,string $t,array $c,string $fb='NULL'){ foreach($c as $x){ if(colExists($pdo,$t,$x)) return $x; } return $fb; }
function pickColOrNull(PDO $pdo,string $t,array $c){ foreach($c as $x){ if(colExists($pdo,$t,$x)) return $x; } return null; }
function livesAliveIds(PDO $pdo,int $uid,int $tid,string $lt,string $lUid,string $lTid,string $lId,string $lState):array{ $sql="SELECT $lId FROM $lt WHERE $lUid=? AND $lTid=?"; if($lState!=='NULL') $sql.=" AND $lState='alive'"; $st=$pdo->prepare($sql); $st->execute([$uid,$tid]); return array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN)); }
function statusLabel(?string $s, ?string $lockIso): string { $now=time(); $s=strtolower((string)$s); $ts=$lockIso?strtotime($lockIso):null; if(in_array($s,['closed','ended','finished','chiuso','terminato'],true)) return 'CHIUSO'; if($ts!==null && $ts <= $now) return 'IN CORSO'; return 'APERTO'; }

// ----- mapping
$tT='tournaments';
$tId=firstCol($pdo,$tT,['id'],'id');
$tCode=firstCol($pdo,$tT,['code','tour_code','t_code','short_id'],'NULL');
$tTitle=firstCol($pdo,$tT,['title','name'],'NULL');
$tLeague=firstCol($pdo,$tT,['league','subtitle'],'NULL');
$tSeason=firstCol($pdo,$tT,['season','season_name'],'NULL');
$tBuy=firstCol($pdo,$tT,['buyin_coins','buyin'],'0');
$tPool=firstCol($pdo,$tT,['prize_pool_coins','pool_coins','prize_coins','prize_pool','montepremi'],'NULL');
$tLMax=firstCol($pdo,$tT,['lives_max_user','lives_max','max_lives_per_user','lives_user_max'],'NULL');
$tStat=firstCol($pdo,$tT,['status','state'],'NULL');
$tSeats=firstCol($pdo,$tT,['seats_total','max_players'],'NULL');
$tCRnd=firstCol($pdo,$tT,['current_round','round_current','round'],'NULL');
$tLock=firstCol($pdo,$tT,['lock_at','close_at','subscription_end','reg_close_at','start_time'],'NULL');

$lT=null; foreach(['tournament_lives','tournaments_lives'] as $try){ $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?"); $q->execute([$try]); if($q->fetchColumn()){$lT=$try;break;} } if(!$lT)$lT='tournament_lives';
$lId=firstCol($pdo,$lT,['id'],'id'); $lUid=firstCol($pdo,$lT,['user_id','uid'],'user_id'); $lTid=firstCol($pdo,$lT,['tournament_id','tid'],'tournament_id');
$lRound=firstCol($pdo,$lT,['round','rnd'],'NULL'); $lState=firstCol($pdo,$lT,['status','state'],'NULL'); $lCode=firstCol($pdo,$lT,['life_code','code'],'NULL'); $lCAt=firstCol($pdo,$lT,['created_at','created'],'NULL');

$eT=null; foreach(['tournament_events','events','partite','matches'] as $try){ $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?"); $q->execute([$try]); if($q->fetchColumn()){$eT=$try;break;} }
if(!$eT){ $rows=$pdo->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE()")->fetchAll(PDO::FETCH_COLUMN); foreach($rows as $tbl){ if(colExists($pdo,$tbl,'tournament_id') && (colExists($pdo,$tbl,'home_team_id')||colExists($pdo,$tbl,'team_a_id')||colExists($pdo,$tbl,'home_id')) && (colExists($pdo,$tbl,'away_team_id')||colExists($pdo,$tbl,'team_b_id')||colExists($pdo,$tbl,'away_id'))){ $eT=$tbl; break; } } }
$eId=$eT?firstCol($pdo,$eT,['id'],'id'):'NULL'; $eTid=$eT?firstCol($pdo,$eT,['tournament_id','tid'],'tournament_id'):'NULL'; $eRound=$eT?firstCol($pdo,$eT,['round','rnd','giornata','matchday','week','round_n'],'NULL'):'NULL'; $eLock=$eT?firstCol($pdo,$eT,['lock_at','deadline','close_at','start_time','kickoff_at','ora_inizio'],'NULL'):'NULL'; $eHome=$eT?firstCol($pdo,$eT,['home_team_id','team_a_id','home_id'],'NULL'):'NULL'; $eAway=$eT?firstCol($pdo,$eT,['away_team_id','team_b_id','away_id'],'NULL'):'NULL'; $eHomeN=$eT?firstCol($pdo,$eT,['home_team_name','team_a_name','home_name'],'NULL'):'NULL'; $eAwayN=$eT?firstCol($pdo,$eT,['away_team_name','team_b_name','away_name'],'NULL'):'NULL';

$tmT=null; foreach(['teams','squadre','clubs'] as $try){ $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?"); $q->execute([$try]); if($q->fetchColumn()){$tmT=$try;break;} }
$tmId = $tmT? firstCol($pdo,$tmT,['id'],'id') : 'NULL';
$tmNm = $tmT? firstCol($pdo,$tmT,['name','nome','team_name'],'NULL') : 'NULL';
$tmLg = $tmT? firstCol($pdo,$tmT,['logo_url','logo','badge_url','image'],'NULL') : 'NULL';

$pT=null; foreach(['tournament_picks','picks','scelte'] as $try){ $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?"); $q->execute([$try]); if($q->fetchColumn()){$pT=$try;break;} } if(!$pT)$pT='tournament_picks';
$pId=firstCol($pdo,$pT,['id'],'id'); $pLife=firstCol($pdo,$pT,['life_id'],'life_id'); $pTid=firstCol($pdo,$pT,['tournament_id','tid'],'tournament_id'); $pRound=firstCol($pdo,$pT,['round','rnd'],'round'); $pEvent=firstCol($pdo,$pT,['event_id','match_id'],'event_id'); $pTeamDyn = pickColOrNull($pdo,$pT,['team_id','pick_team_id','team']); $pCAt=firstCol($pdo,$pT,['created_at','created'],'NULL');

$logT='points_balance_log'; $hasLog=$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='points_balance_log'")->fetchColumn();
$lgId=$hasLog?firstCol($pdo,$logT,['id'],'id'):'NULL'; $lgUid=$hasLog?firstCol($pdo,$logT,['user_id'],'user_id'):'NULL'; $lgDelta=$hasLog?firstCol($pdo,$logT,['delta','amount'],'delta'):'NULL'; $lgReason=$hasLog?firstCol($pdo,$logT,['reason','descr'],'reason'):'NULL'; $lgCode=$hasLog?pickColOrNull($pdo,$logT,['tx_code','code']):null; $lgCAt=$hasLog?firstCol($pdo,$logT,['created_at','created'],'NULL'):'NULL';

$action = $_GET['action'] ?? ($_POST['action'] ?? null);
if (!$action){ JD(['ok'=>false,'error'=>'no_action'],'Parametro action mancante',['request'=>$_REQUEST]); }

/* ---------- utility: risolvi id da tid anche per POST ---------- */
function resolveTidToId(PDO $pdo, string $code, string $tT, string $tCode, string $tId): int {
  if ($code==='' || $tCode==='NULL') return 0;
  $st=$pdo->prepare("SELECT $tId FROM $tT WHERE $tCode=? LIMIT 1"); $st->execute([$code]); return (int)$st->fetchColumn();
}

/* ---------- SUMMARY ---------- (identico al tuo) */
if ($action==='summary'){
  $tid = (int)($_GET['id'] ?? 0);
  $code= trim($_GET['tid'] ?? '');
  if ($tid<=0 && $code!=='' && $tCode!=='NULL'){
    $st=$pdo->prepare("SELECT $tId FROM $tT WHERE $tCode=? LIMIT 1"); $st->execute([$code]); $tid=(int)$st->fetchColumn();
  }
  if ($tid<=0) JD(['ok'=>false,'error'=>'bad_id'],'ID torneo mancante/errato',['id'=>$tid,'tid'=>$code]);

  $st=$pdo->prepare("SELECT $tId AS id,"
    . ($tCode!=='NULL' ? "$tCode AS code," : "NULL AS code,")
    . ($tTitle!=='NULL'? "$tTitle AS title," : "NULL AS title,")
    . ($tLeague!=='NULL'? "$tLeague AS league," : "NULL AS league,")
    . ($tSeason!=='NULL'? "$tSeason AS season," : "NULL AS season,")
    . "COALESCE($tBuy,0) AS buyin,"
    . ($tPool!=='NULL'? "$tPool AS pool_coins," : "NULL AS pool_coins,")
    . ($tLMax!=='NULL' ? "$tLMax AS lives_max_user," : "NULL AS lives_max_user,")
    . ($tStat!=='NULL' ? "$tStat AS status," : "NULL AS status,")
    . ($tSeats!=='NULL'? "$tSeats AS seats_total," : "NULL AS seats_total,")
    . "COALESCE($tCRnd,NULL) AS current_round,"
    . ($tLock!=='NULL'? "$tLock AS lock_r1" : "NULL AS lock_r1")
    . " FROM $tT WHERE $tId=? LIMIT 1");
  $st->execute([$tid]); $t=$st->fetch(PDO::FETCH_ASSOC);
  if(!$t) JD(['ok'=>false,'error'=>'not_found'],'Torneo non trovato',['id'=>$tid]);

  $state = statusLabel($t['status'] ?? null, $t['lock_r1'] ?? null);
  if ($lState!=='NULL') $q="SELECT COUNT(*) FROM $lT WHERE $lTid=? AND $lState='alive'"; else $q="SELECT COUNT(*) FROM $lT WHERE $lTid=?";
  $x=$pdo->prepare($q); $x->execute([$tid]); $livesInPlay=(int)$x->fetchColumn();
  $cols="$lId id"; if($lState!=='NULL') $cols.=", $lState state"; if($lRound!=='NULL') $cols.=", $lRound round"; if($lCode!=='NULL') $cols.=", $lCode life_code";
  $mv=$pdo->prepare("SELECT $cols FROM $lT WHERE $lUid=? AND $lTid=? ORDER BY $lId ASC"); $mv->execute([$uid,$tid]); $myLives=$mv->fetchAll(PDO::FETCH_ASSOC);
  $myAliveIds = livesAliveIds($pdo,$uid,$tid,$lT,$lUid,$lTid,$lId,$lState);
  $canBuy = ($t['lock_r1'] ? (strtotime($t['lock_r1'])>time()) : true) && ($t['lives_max_user']===null || count($myAliveIds) < (int)$t['lives_max_user']);
  $canUnj = ($t['lock_r1'] ? (strtotime($t['lock_r1'])>time()) : true);

  J(['ok'=>true,
     'tournament'=>[
       'id'=>(int)$t['id'], 'code'=>$t['code'], 'title'=>$t['title'],
       'league'=>$t['league'], 'season'=>$t['season'], 'state'=>$state,
       'buyin'=>(float)$t['buyin'], 'pool_coins'=> isset($t['pool_coins'])?(float)$t['pool_coins']:null,
       'lives_max_user'=> isset($t['lives_max_user'])?(int)$t['lives_max_user']:null,
       'current_round'=> (int)($t['current_round'] ?? 1),
       'lock_r1'=>$t['lock_r1'], 'lock_round'=>null
     ],
     'stats'=>['lives_in_play'=>$livesInPlay],
     'me'=>['lives'=>$myLives, 'can_buy_life'=>$canBuy, 'can_unjoin'=>$canUnj]
  ]);
}

/* ---------- EVENTS / TRENDING ---------- (identici alla tua versione con debug) */
if ($action==='events'){ /* ... come nel tuo, invariato ... */ }
if ($action==='trending'){ /* ... come nel tuo, invariato ... */ }

/* ---------- BUY LIFE (accetta anche tid) ---------- */
if ($action==='buy_life'){
  only_post();
  $tid=(int)($_POST['id'] ?? 0);
  if ($tid<=0){ $code=trim($_POST['tid'] ?? ''); $tid = resolveTidToId($pdo,$code,$tT,$tCode,$tId); }
  if ($tid<=0) JD(['ok'=>false,'error'=>'bad_id'],'Parametro id/tid torneo mancante o invalido',['post'=>$_POST]);
  /* ... resto IDENTICO alla tua buy_life ... */
}

/* ---------- UNJOIN (accetta anche tid) ---------- */
if ($action==='unjoin'){
  only_post();
  $tid=(int)($_POST['id'] ?? 0);
  if ($tid<=0){ $code=trim($_POST['tid'] ?? ''); $tid = resolveTidToId($pdo,$code,$tT,$tCode,$tId); }
  if ($tid<=0) JD(['ok'=>false,'error'=>'bad_id'],'Parametro id/tid torneo mancante o invalido',['post'=>$_POST]);
  /* ... resto IDENTICO alla tua unjoin ... */
}

/* ---------- PICK (accetta anche tid) ---------- */
if ($action==='pick'){
  only_post();
  $tid=(int)($_POST['id'] ?? 0);
  if ($tid<=0){ $code=trim($_POST['tid'] ?? ''); $tid = resolveTidToId($pdo,$code,$tT,$tCode,$tId); }
  if ($tid<=0) JD(['ok'=>false,'error'=>'bad_id'],'Parametro id/tid torneo mancante o invalido',['post'=>$_POST]);
  /* ... resto IDENTICO alla tua pick ... */
}

// Fallback
JD(['ok'=>false,'error'=>'unknown_action'],'Azione non riconosciuta',['action'=>$action]);
