<?php
// /public/api/torneo.php — API del torneo (solo azioni richieste)
// Sicuro da includere separato dall'HTML della pagina 'torneo.php'

declare(strict_types=1);
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log','/tmp/php_errors.log');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }

/* ===== Debug flag ===== */
$DBG = (($_GET['debug'] ?? '') === '1') || (($_POST['debug'] ?? '') === '1') || (($_GET['dbg'] ?? '') === '1') || (($_POST['dbg'] ?? '') === '1');
if ($DBG) { ini_set('display_errors','1'); error_reporting(E_ALL); header('X-Debug','1'); }

/* ===== Helpers debug-safe ===== */
function J(array $arr){ echo json_encode($arr); exit; }
function JERR(string $code, string $detail='', array $extra = []){
  $out = ['ok'=>false,'error'=>$code];
  if ($detail!=='') $out['detail']=$detail;
  foreach($extra as $k=>$v){ $out[$k]=$v; }
  J($out);
}
function only_post(){ if (($_SERVER['REQUEST_METHOD']??'')!=='POST'){ http_response_code(405); JERR('method','Solo POST'); } }

/* Trap fatali/throw: restituisco JSON */
set_exception_handler(function(Throwable $e) use ($DBG){
  $out = ['ok'=>false,'error'=>'exception'];
  if ($DBG){ $out['detail']=$e->getMessage(); $out['trace']=$e->getTraceAsString(); }
  http_response_code(500); echo json_encode($out); exit;
});
register_shutdown_function(function() use ($DBG){
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR], true)){
    $out=['ok'=>false,'error'=>'fatal'];
    if ($DBG){ $out['detail']=$e['message'].' at '.$e['file'].':'.$e['line']; }
    http_response_code(500); echo json_encode($out);
  }
});

/* ===== Auth ===== */
$uid  = (int)($_SESSION['uid'] ?? 0);
$role = $_SESSION['role'] ?? 'USER';
if ($uid <= 0 || !in_array($role, ['USER','PUNTO','ADMIN'], true)) {
  http_response_code(401);
  J(['ok'=>false,'error'=>'auth']);
}

/* ===== Utility DB meta ===== */
function colExists(PDO $pdo,string $t,string $c):bool{
  static $cch=[]; $k="$t.$c"; if(isset($cch[$k])) return $cch[$k];
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $q->execute([$t,$c]); return $cch[$k]=(bool)$q->fetchColumn();
}
function firstCol(PDO $pdo,string $t,array $cands,string $fallback='NULL'){
  foreach($cands as $c){ if(colExists($pdo,$t,$c)) return $c; } return $fallback;
}
function pickColOrNull(PDO $pdo,string $t,array $cands):?string{
  foreach($cands as $c){ if(colExists($pdo,$t,$c)) return $c; } return null;
}
function livesAliveIds(PDO $pdo,int $uid,int $tid,string $lt,string $lUid,string $lTid,string $lId,string $lState):array{
  $sql="SELECT $lId FROM $lt WHERE $lUid=? AND $lTid=?";
  if($lState!=='NULL') $sql.=" AND $lState='alive'";
  $st=$pdo->prepare($sql); $st->execute([$uid,$tid]); return array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN));
}
function statusLabel(?string $s, ?string $lockIso): string {
  $now=time(); $s=strtolower((string)$s); $ts=$lockIso?strtotime($lockIso):null;
  if(in_array($s,['closed','ended','finished','chiuso','terminato'],true)) return 'CHIUSO';
  if($ts!==null && $ts <= $now) return 'IN CORSO';
  return 'APERTO';
}
/* Risolve id torneo da id o da codice tid (sia GET che POST) */
function resolveTidToId(PDO $pdo, ?int $id, ?string $code, string $tT, string $tId, string $tCode): int {
  $id = (int)($id ?? 0);
  $code = trim((string)($code ?? ''));
  if ($id>0) return $id;
  if ($code!=='' && $tCode!=='NULL'){
    $st=$pdo->prepare("SELECT $tId FROM $tT WHERE $tCode=? LIMIT 1");
    $st->execute([$code]); $x=(int)$st->fetchColumn(); if($x>0) return $x;
  }
  return 0;
}

/* ===== Mapping tabelle/colonne (dinamico) ===== */
$tT='tournaments';
$tId   = firstCol($pdo,$tT,['id'],'id');
$tCode = firstCol($pdo,$tT,['code','tour_code','t_code','short_id'],'NULL');
$tTitle= firstCol($pdo,$tT,['title','name'],'NULL');
$tLeague=firstCol($pdo,$tT,['league','subtitle'],'NULL');
$tSeason=firstCol($pdo,$tT,['season','season_name'],'NULL');
$tBuy  = firstCol($pdo,$tT,['buyin_coins','buyin'],'0');
$tPool = firstCol($pdo,$tT,['prize_pool_coins','pool_coins','prize_coins','prize_pool','montepremi'],'NULL');
$tLMax = firstCol($pdo,$tT,['lives_max_user','lives_max','max_lives_per_user','lives_user_max'],'NULL');
$tStat = firstCol($pdo,$tT,['status','state'],'NULL');
$tSeats= firstCol($pdo,$tT,['seats_total','max_players'],'NULL');
$tCRnd = firstCol($pdo,$tT,['current_round','round_current','round'],'NULL');
$tLock = firstCol($pdo,$tT,['lock_at','close_at','subscription_end','reg_close_at','start_time'],'NULL');

$lT=null; foreach(['tournament_lives','tournaments_lives'] as $try){ $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?"); $q->execute([$try]); if($q->fetchColumn()){$lT=$try;break;} }
if(!$lT){ $lT='tournament_lives'; }
$lId=firstCol($pdo,$lT,['id'],'id');
$lUid=firstCol($pdo,$lT,['user_id','uid'],'user_id');
$lTid=firstCol($pdo,$lT,['tournament_id','tid'],'tournament_id');
$lRound=firstCol($pdo,$lT,['round','rnd'],'NULL');
$lState=firstCol($pdo,$lT,['status','state'],'NULL');
$lCode=firstCol($pdo,$lT,['life_code','code'],'NULL');
$lCAt=firstCol($pdo,$lT,['created_at','created'],'NULL');

$eT=null; foreach(['tournament_events','events','partite','matches'] as $try){ $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?"); $q->execute([$try]); if($q->fetchColumn()){$eT=$try;break;} }
if(!$eT){ $rows=$pdo->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE()")->fetchAll(PDO::FETCH_COLUMN);
  foreach($rows as $tbl){ if(colExists($pdo,$tbl,'tournament_id') && (colExists($pdo,$tbl,'home_team_id')||colExists($pdo,$tbl,'team_a_id')||colExists($pdo,$tbl,'home_id')) && (colExists($pdo,$tbl,'away_team_id')||colExists($pdo,$tbl,'team_b_id')||colExists($pdo,$tbl,'away_id'))){ $eT=$tbl; break; } }
}
$eId   =$eT? firstCol($pdo,$eT,['id'],'id') : 'NULL';
$eTid  =$eT? firstCol($pdo,$eT,['tournament_id','tid'],'tournament_id') : 'NULL';
$eRound=$eT? firstCol($pdo,$eT,['round','rnd','giornata','matchday','week','round_n'],'NULL') : 'NULL';
$eLock =$eT? firstCol($pdo,$eT,['lock_at','deadline','close_at','start_time','kickoff_at','ora_inizio'],'NULL') : 'NULL';
$eHome =$eT? firstCol($pdo,$eT,['home_team_id','team_a_id','home_id'],'NULL') : 'NULL';
$eAway =$eT? firstCol($pdo,$eT,['away_team_id','team_b_id','away_id'],'NULL') : 'NULL';
$eHomeN=$eT? firstCol($pdo,$eT,['home_team_name','team_a_name','home_name'],'NULL') : 'NULL';
$eAwayN=$eT? firstCol($pdo,$eT,['away_team_name','team_b_name','away_name'],'NULL') : 'NULL';

$tmT=null; foreach(['teams','squadre','clubs'] as $try){ $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?"); $q->execute([$try]); if($q->fetchColumn()){$tmT=$try;break;} }
$tmId = $tmT? firstCol($pdo,$tmT,['id'],'id') : 'NULL';
$tmNm = $tmT? firstCol($pdo,$tmT,['name','nome','team_name'],'NULL') : 'NULL';
$tmLg = $tmT? firstCol($pdo,$tmT,['logo_url','logo','badge_url','image'],'NULL') : 'NULL';

$pT=null; foreach(['tournament_picks','picks','scelte'] as $try){ $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?"); $q->execute([$try]); if($q->fetchColumn()){$pT=$try;break;} }
if(!$pT){ $pT='tournament_picks'; }
$pId   = firstCol($pdo,$pT,['id'],'id');
$pLife = firstCol($pdo,$pT,['life_id'],'life_id');
$pTid  = firstCol($pdo,$pT,['tournament_id','tid'],'tournament_id');
$pRound= firstCol($pdo,$pT,['round','rnd'],'round');
$pEvent= firstCol($pdo,$pT,['event_id','match_id'],'event_id');
$pTeamDyn = pickColOrNull($pdo,$pT,['team_id','pick_team_id','team']); // DINAMICA
$pCAt  = firstCol($pdo,$pT,['created_at','created'],'NULL');

$logT='points_balance_log'; $hasLog=$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='points_balance_log'")->fetchColumn();
$lgId    = $hasLog? firstCol($pdo,$logT,['id'],'id') : 'NULL';
$lgUid   = $hasLog? firstCol($pdo,$logT,['user_id'],'user_id') : 'NULL';
$lgDelta = $hasLog? firstCol($pdo,$logT,['delta','amount'],'delta') : 'NULL';
$lgReason= $hasLog? firstCol($pdo,$logT,['reason','descr'],'reason') : 'NULL';
$lgCode  = $hasLog? pickColOrNull($pdo,$logT,['tx_code','code']) : null;
$lgCAt   = $hasLog? firstCol($pdo,$logT,['created_at','created'],'NULL') : 'NULL';

/* ===== Router azioni ===== */
$action = strtolower((string)($_GET['action'] ?? ($_POST['action'] ?? '')));
if ($action===''){ JERR('no_action','Parametro action mancante'); }

/* ---------- SUMMARY ---------- */
if ($action==='summary'){
  $tid = resolveTidToId($pdo,
    isset($_GET['id']) ? (int)$_GET['id'] : null,
    isset($_GET['tid'])? (string)$_GET['tid'] : null,
    $tT,$tId,$tCode
  );
  if ($tid<=0) JERR('bad_id','Parametro id/tid torneo mancante o invalido.', $DBG? ['received'=>['id'=>($_GET['id']??null),'tid'=>($_GET['tid']??null)]]:[]);

  $sql="SELECT $tId AS id,"
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
    . " FROM $tT WHERE $tId=? LIMIT 1";
  $st=$pdo->prepare($sql); $st->execute([$tid]); $t=$st->fetch(PDO::FETCH_ASSOC);
  if(!$t) JERR('not_found','Torneo non trovato', $DBG? ['sql'=>$sql,'params'=>[$tid]]:[]);

  $state = statusLabel($t['status'] ?? null, $t['lock_r1'] ?? null);
  if ($lState!=='NULL') $q="SELECT COUNT(*) FROM $lT WHERE $lTid=? AND $lState='alive'";
  else $q="SELECT COUNT(*) FROM $lT WHERE $lTid=?";
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
     'me'=>['lives'=>$myLives, 'can_buy_life'=>$canBuy, 'can_unjoin'=>$canUnj],
     'dbg'=> $DBG ? ['tables'=>['events'=>$eT,'teams'=>$tmT,'picks'=>$pT]] : null
  ]);
}

/* ---------- EVENTS ---------- */
if ($action==='events'){
  $tid = resolveTidToId($pdo,
    isset($_GET['id']) ? (int)$_GET['id'] : null,
    isset($_GET['tid'])? (string)$_GET['tid'] : null,
    $tT,$tId,$tCode
  );
  $round=(int)($_GET['round'] ?? 1);
  if($tid<=0) JERR('bad_id','Parametro id/tid torneo mancante o invalido.', $DBG? ['received'=>['id'=>($_GET['id']??null),'tid'=>($_GET['tid']??null)]]:[]);
  if(!$eT){ J(['ok'=>true,'events'=>[],'note'=>'events_table_not_found','dbg'=>$DBG?['scan'=>'no events table detected']:null]); }

  $cols = "e.$eId AS id";
  $cols.= ($eRound!=='NULL')? ", e.$eRound AS round" : ", NULL AS round";
  $cols.= ($eLock!=='NULL') ? ", e.$eLock AS lock_at" : ", NULL AS lock_at";
  $tj=""; 
  if ($tmT && $eHome!=='NULL' && $eAway!=='NULL' && $tmNm!=='NULL'){
    $cols.=", e.$eHome AS home_id, e.$eAway AS away_id, ta.$tmNm AS home_name, tb.$tmNm AS away_name";
    $cols.= ($tmLg!=='NULL')? ", ta.$tmLg AS home_logo, tb.$tmLg AS away_logo" : ", NULL AS home_logo, NULL AS away_logo";
    $tj = " LEFT JOIN $tmT ta ON ta.$tmId = e.$eHome LEFT JOIN $tmT tb ON tb.$tmId = e.$eAway ";
  }else{
    $cols.= ($eHome!=='NULL')? ", e.$eHome AS home_id" : ", NULL AS home_id";
    $cols.= ($eAway!=='NULL')? ", e.$eAway AS away_id" : ", NULL AS away_id";
    $cols.= ($eHomeN!=='NULL')? ", e.$eHomeN AS home_name" : ", NULL AS home_name";
    $cols.= ($eAwayN!=='NULL')? ", e.$eAwayN AS away_name" : ", NULL AS away_name";
    $cols.= ", NULL AS home_logo, NULL AS away_logo";
  }
  $where="e.$eTid=?"; $par=[$tid];
  if ($eRound!=='NULL'){ $where.=" AND e.$eRound=?"; $par[]=$round; }
  $sql="SELECT $cols FROM $eT e $tj WHERE $where ORDER BY e.$eId ASC";
  $st=$pdo->prepare($sql); $st->execute($par); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
  J(['ok'=>true,'events'=>$rows, 'dbg'=>$DBG? ['sql'=>$sql,'params'=>$par]:null]);
}

/* ---------- TRENDING ---------- */
if ($action==='trending'){
  $tid = resolveTidToId($pdo,
    isset($_GET['id']) ? (int)$_GET['id'] : null,
    isset($_GET['tid'])? (string)$_GET['tid'] : null,
    $tT,$tId,$tCode
  );
  $round=(int)($_GET['round'] ?? 1);
  if($tid<=0) JERR('bad_id','Parametro id/tid torneo mancante o invalido.', $DBG? ['received'=>['id'=>($_GET['id']??null),'tid'=>($_GET['tid']??null)]]:[]);
  $teamCol = $pTeamDyn ?: pickColOrNull($pdo,$pT,['team_id','pick_team_id','team']);
  if (!$teamCol) J(['ok'=>true,'total'=>0,'items'=>[],'dbg'=>$DBG?['reason'=>'no_team_column_in_picks']:null]);
  $sql="SELECT p.$teamCol AS team_id, COUNT(*) cnt FROM $pT p WHERE p.$pTid=? AND p.$pRound=? GROUP BY p.$teamCol";
  $st=$pdo->prepare($sql); $st->execute([$tid,$round]); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
  if ($tmT){
    foreach($rows as &$r){
      $q="SELECT $tmNm AS name".($tmLg!=='NULL'? ", $tmLg AS logo": "")." FROM $tmT WHERE $tmId=? LIMIT 1";
      $x=$pdo->prepare($q); $x->execute([(int)$r['team_id']]); $a=$x->fetch(PDO::FETCH_ASSOC)?:['name'=>null,'logo'=>null];
      $r['name']=$a['name']; if(array_key_exists('logo',$a)) $r['logo']=$a['logo'];
    }
  }
  usort($rows,function($a,$b){ return ($b['cnt']??0) <=> ($a['cnt']??0); });
  $tot=0; foreach($rows as $r){ $tot+=(int)$r['cnt']; }
  J(['ok'=>true,'total'=>$tot,'items'=>$rows,'dbg'=>$DBG?['team_col'=>$teamCol,'sql'=>$sql,'params'=>[$tid,$round]]:null]);
}

/* ---------- CHOICES INFO ---------- */
if ($action==='choices_info'){
  $tid = resolveTidToId($pdo,
    isset($_GET['id']) ? (int)$_GET['id'] : null,
    isset($_GET['tid'])? (string)$_GET['tid'] : null,
    $tT,$tId,$tCode
  );
  $round=(int)($_GET['round'] ?? 1);
  if($tid<=0) JERR('bad_id','Parametro id/tid torneo mancante o invalido.', $DBG? ['received'=>['id'=>($_GET['id']??null),'tid'=>($_GET['tid']??null)]]:[]);
  $teamCol = $pTeamDyn ?: pickColOrNull($pdo,$pT,['team_id','pick_team_id','team']);
  if (!$teamCol) J(['ok'=>true,'rows'=>[],'dbg'=>$DBG?['reason'=>'no_team_column_in_picks']:null]);

  $unameCol = colExists($pdo,'users','username') ? 'username' : firstCol($pdo,'users',['name','email','cell'],'id');
  $sql="SELECT u.$unameCol AS username, p.$teamCol AS team_id
        FROM $pT p
        JOIN $lT l ON l.$lId=p.$pLife
        JOIN users u ON u.id=l.$lUid
        WHERE p.$pTid=? AND p.$pRound=?
        ORDER BY username ASC";
  $st=$pdo->prepare($sql); $st->execute([$tid,$round]);
  $rows=$st->fetchAll(PDO::FETCH_ASSOC);
  if ($tmT){
    foreach($rows as &$r){
      $x=$pdo->prepare("SELECT $tmNm AS name FROM $tmT WHERE $tmId=?");
      $x->execute([(int)$r['team_id']]); $nm=$x->fetchColumn();
      $r['team_name']=$nm ?: ('#'.$r['team_id']);
    }
  } else {
    foreach($rows as &$r){ $r['team_name']='#'.$r['team_id']; }
  }
  J(['ok'=>true,'rows'=>$rows,'dbg'=>$DBG?['sql'=>$sql,'params'=>[$tid,$round],'team_col'=>$teamCol]:null]);
}

/* ---------- BUY LIFE (iscrizione/aggiunta) ---------- */
if ($action==='buy_life'){
  only_post();
  $tid = resolveTidToId($pdo,
    isset($_POST['id']) ? (int)$_POST['id'] : null,
    isset($_POST['tid'])? (string)$_POST['tid'] : null,
    $tT,$tId,$tCode
  );
  if ($tid<=0) JERR('bad_id','Parametro id/tid torneo mancante o invalido.', $DBG? ['received'=>['id'=>($_POST['id']??null),'tid'=>($_POST['tid']??null)]]:[]);

  $sql = "SELECT $tId id, COALESCE($tBuy,0) buyin, ".($tLMax!=='NULL'? "$tLMax lives_max_user,":"NULL lives_max_user,")." ".($tPool!=='NULL'? "$tPool pool_coins,":"NULL pool_coins,")." ".($tLock!=='NULL'? "$tLock lock_r1,":"NULL lock_r1")." FROM $tT WHERE $tId=?";
  $st=$pdo->prepare($sql); $st->execute([$tid]); $t=$st->fetch(PDO::FETCH_ASSOC); if(!$t) JERR('not_found','Torneo non trovato', $DBG? ['sql'=>$sql,'params'=>[$tid]]:[]);
  $lock1 = $t['lock_r1'] ?? null; if ($lock1 && strtotime($lock1)<=time()) JERR('closed','Lock round 1 passato');

  $myAlive = livesAliveIds($pdo,$uid,$tid,$lT,$lUid,$lTid,$lId,$lState);
  if ($t['lives_max_user']!==null && count($myAlive) >= (int)$t['lives_max_user']) JERR('limit','Limite vite raggiunto');

  $buyin=(float)$t['buyin']; $x=$pdo->prepare("SELECT COALESCE(coins,0) FROM users WHERE id=?"); $x->execute([$uid]); $coins=(float)$x->fetchColumn();
  if ($coins < $buyin) JERR('insufficient_funds','Saldo insufficiente');

  try{
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE users SET coins=coins-? WHERE id=? AND coins>=?")->execute([$buyin,$uid,$buyin]);

    $cols=[$lUid,$lTid]; $vals=['?','?']; $par=[$uid,$tid];
    if ($lRound!=='NULL'){ $cols[]=$lRound; $vals[]='?'; $par[]=1; }
    if ($lState!=='NULL'){ $cols[]=$lState; $vals[]='?'; $par[]='alive'; }
    if ($lCAt!=='NULL'){ $cols[]=$lCAt; $vals[]='NOW()'; }
    $ins="INSERT INTO $lT(".implode(',',$cols).") VALUES(".implode(',',$vals).")";
    $pdo->prepare($ins)->execute($par);

    if ($tPool!=='NULL'){ $pdo->prepare("UPDATE $tT SET $tPool=COALESCE($tPool,0)+? WHERE $tId=?")->execute([$buyin,$tid]); }
    if ($hasLog){
      $cols=[$lgUid,$lgDelta,$lgReason]; $vals=['?','?','?']; $par2=[$uid,-$buyin,'Acquisto vita torneo #'.$tid];
      if ($lgCAt!=='NULL'){ $cols[]=$lgCAt; $vals[]='NOW()'; }
      $pdo->prepare("INSERT INTO $logT(".implode(',',$cols).") VALUES(".implode(',',$vals).")")->execute($par2);
    }
    $pdo->commit();
    $x=$pdo->prepare("SELECT COALESCE(coins,0) FROM users WHERE id=?"); $x->execute([$uid]); $new=(float)$x->fetchColumn();
    J(['ok'=>true,'new_balance'=>$new,'dbg'=>$DBG?['ins'=>$ins,'ins_params'=>$par]:null]);
  }catch(Throwable $e){ if($pdo->inTransaction()) $pdo->rollBack(); JERR('buy_failed',$e->getMessage(), $DBG? ['trace'=>$e->getTraceAsString()]:[]); }
}

/* ---------- UNJOIN (rimborso) ---------- */
if ($action==='unjoin'){
  only_post();
  $tid = resolveTidToId($pdo,
    isset($_POST['id']) ? (int)$_POST['id'] : null,
    isset($_POST['tid'])? (string)$_POST['tid'] : null,
    $tT,$tId,$tCode
  );
  if ($tid<=0) JERR('bad_id','Parametro id/tid torneo mancante o invalido.', $DBG? ['received'=>['id'=>($_POST['id']??null),'tid'=>($_POST['tid']??null)]]:[]);

  $sql="SELECT $tId id, COALESCE($tBuy,0) buyin, ".($tPool!=='NULL'? "COALESCE($tPool,0) pool_coins,":"0 pool_coins,")." ".($tLock!=='NULL'? "$tLock lock_r1,":"NULL lock_r1")." FROM $tT WHERE $tId=?";
  $st=$pdo->prepare($sql); $st->execute([$tid]); $t=$st->fetch(PDO::FETCH_ASSOC); if(!$t) JERR('not_found','Torneo non trovato', $DBG? ['sql'=>$sql,'params'=>[$tid]]:[]);
  $lock1 = $t['lock_r1'] ?? null; if ($lock1 && strtotime($lock1)<=time()) JERR('closed','Lock round 1 passato');

  $ids = livesAliveIds($pdo,$uid,$tid,$lT,$lUid,$lTid,$lId,$lState);
  if (!$ids) JERR('no_lives','Non hai vite attive da rimborsare.');

  $refund = (float)$t['buyin'] * count($ids);

  try{
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE users SET coins=coins+? WHERE id=?")->execute([$refund,$uid]);
    if ($tPool!=='NULL'){ $pdo->prepare("UPDATE $tT SET $tPool=GREATEST(COALESCE($tPool,0)-?,0) WHERE $tId=?")->execute([$refund,$tid]); }
    $in=implode(',', array_fill(0,count($ids),'?'));
    if ($lState!=='NULL' && colExists($pdo,$lT,'refunded_at')){
      $pdo->prepare("UPDATE $lT SET $lState='refunded', refunded_at=NOW() WHERE $lId IN ($in)")->execute($ids);
    } else {
      $pdo->prepare("DELETE FROM $lT WHERE $lId IN ($in)")->execute($ids);
    }
    if ($hasLog){
      $cols=[$lgUid,$lgDelta,$lgReason]; $vals=['?','?','?']; $par=[$uid,+$refund,'Disiscrizione torneo #'.$tid];
      if ($lgCAt!=='NULL'){ $cols[]=$lgCAt; $vals[]='NOW()'; }
      $pdo->prepare("INSERT INTO $logT(".implode(',',$cols).") VALUES(".implode(',',$vals).")")->execute($par);
    }
    $pdo->commit();
    $x=$pdo->prepare("SELECT COALESCE(coins,0) FROM users WHERE id=?"); $x->execute([$uid]); $new=(float)$x->fetchColumn();
    J(['ok'=>true,'new_balance'=>$new,'dbg'=>$DBG?['refunded_life_ids'=>$ids,'refund'=>$refund]:null]);
  }catch(Throwable $e){ if($pdo->inTransaction()) $pdo->rollBack(); JERR('unjoin_failed',$e->getMessage(), $DBG? ['trace'=>$e->getTraceAsString()]:[]); }
}

/* ---------- PICK (senza vincoli “ciclo”) ---------- */
if ($action==='pick'){
  only_post();
  $tid = resolveTidToId($pdo,
    isset($_POST['id']) ? (int)$_POST['id'] : null,
    isset($_POST['tid'])? (string)$_POST['tid'] : null,
    $tT,$tId,$tCode
  );
  $life=(int)($_POST['life_id'] ?? 0);
  $event=(int)($_POST['event_id'] ?? 0);
  $team=(int)($_POST['team_id'] ?? 0);
  $round=(int)($_POST['round'] ?? 1);
  if($tid<=0 || $life<=0 || $event<=0 || $team<=0) JERR('bad_params','Parametri insufficienti', $DBG? ['received'=>$_POST]:[]);

  $sql="SELECT $lId id ".($lState!=='NULL'? ", $lState state":"")." FROM $lT WHERE $lId=? AND $lUid=? AND $lTid=? LIMIT 1";
  $st=$pdo->prepare($sql); $st->execute([$life,$uid,$tid]); $v=$st->fetch(PDO::FETCH_ASSOC);
  if(!$v) JERR('life_not_found','Vita non trovata', $DBG? ['sql'=>$sql,'params'=>[$life,$uid,$tid]]:[]);
  if ($lState!=='NULL' && strtolower((string)($v['state']??''))!=='alive') JERR('life_not_alive','Vita non attiva');

  if ($eT){
    $q="SELECT 1 FROM $eT WHERE $eId=? AND $eTid=?"; $par=[$event,$tid];
    if ($eRound!=='NULL'){ $q.=" AND $eRound=?"; $par[]=$round; }
    if ($eLock!=='NULL'){  $q.=" AND ($eLock IS NULL OR $eLock>NOW())"; }
    $x=$pdo->prepare($q); $x->execute($par); if(!$x->fetchColumn()) JERR('event_locked','Evento non selezionabile', $DBG? ['sql'=>$q,'params'=>$par]:[]);
  }

  $teamCol = $pTeamDyn ?: pickColOrNull($pdo,$pT,['team_id','pick_team_id','team']);
  if (!$teamCol) JERR('no_team_col','Colonna team non trovata nella tabella picks');

  try{
    $pdo->beginTransaction();
    $chk=$pdo->prepare("SELECT $pId FROM $pT WHERE $pLife=? AND $pTid=? AND $pRound=? AND $pEvent=? LIMIT 1");
    $chk->execute([$life,$tid,$round,$event]); $pid=(int)$chk->fetchColumn();
    if ($pid>0){
      $u=$pdo->prepare("UPDATE $pT SET $teamCol=? WHERE $pId=?");
      $u->execute([$team,$pid]);
    } else {
      $cols=[$pLife,$pTid,$pRound,$pEvent,$teamCol]; $vals=['?','?','?','?','?']; $par2=[ $life,$tid,$round,$event,$team ];
      if ($pCAt!=='NULL'){ $cols[]=$pCAt; $vals[]='NOW()'; }
      $ins="INSERT INTO $pT(".implode(',',$cols).") VALUES(".implode(',',$vals).")";
      $pdo->prepare($ins)->execute($par2);
    }
    $pdo->commit();
    J(['ok'=>true,'dbg'=>$DBG?['team_col'=>$teamCol,'life'=>$life,'event'=>$event,'round'=>$round,'team'=>$team]:null]);
  }catch(Throwable $e){ if($pdo->inTransaction()) $pdo->rollBack(); JERR('pick_failed',$e->getMessage(), $DBG? ['trace'=>$e->getTraceAsString()]:[]); }
}

/* ---------- Fallback ---------- */
JERR('unknown_action','Azione non riconosciuta', $DBG? ['action'=>$action]:[]);
