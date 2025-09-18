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

$uid  = (int)($_SESSION['uid'] ?? 0);
$role = $_SESSION['role'] ?? 'USER';
if ($uid <= 0 || !in_array($role, ['USER','PUNTO','ADMIN'], true)) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'auth']); exit;
}

$__DBG = (($_GET['debug'] ?? $_POST['debug'] ?? '') === '1'); // <<<<< toggle debug
if ($__DBG) { ini_set('display_errors','1'); header('X-Debug','1'); }

function J($arr){ echo json_encode($arr); exit; }
/* risposte con dettaglio solo se debug=1 */
function JD($base, $detail=null, $extras=[]) {
  global $__DBG; if ($__DBG) { if ($detail!==null) $base['detail']=$detail; if (!empty($extras)) $base['dbg']=$extras; }
  J($base);
}
function only_post(){ if (($_SERVER['REQUEST_METHOD']??'')!=='POST'){ http_response_code(405); JD(['ok'=>false,'error'=>'method'],'Metodo non consentito',['method'=>$_SERVER['REQUEST_METHOD']??'']); } }
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

// ===== Mapping tabelle/colonne (dinamico) =====
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

$action = $_GET['action'] ?? ($_POST['action'] ?? null);
if (!$action){ JD(['ok'=>false,'error'=>'no_action'],'Parametro action mancante',['request'=>$_REQUEST]); }

/* ---------- SUMMARY ---------- */
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
  // vite in gioco (conteggio)
  if ($lState!=='NULL') $q="SELECT COUNT(*) FROM $lT WHERE $lTid=? AND $lState='alive'";
  else $q="SELECT COUNT(*) FROM $lT WHERE $lTid=?";
  $x=$pdo->prepare($q); $x->execute([$tid]); $livesInPlay=(int)$x->fetchColumn();

  // mie vite
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

/* ---------- EVENTS ---------- */
if ($action==='events'){
  $tid=(int)($_GET['id'] ?? 0);
  $round=(int)($_GET['round'] ?? 1);
  if(!$eT) J(['ok'=>true,'events'=>[]]);
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
  J(['ok'=>true,'events'=>$rows]);
}

/* ---------- TRENDING ---------- */
if ($action==='trending'){
  $tid=(int)($_GET['id'] ?? 0);
  $round=(int)($_GET['round'] ?? 1);
  $teamCol = $pTeamDyn ?: pickColOrNull($pdo,$pT,['team_id','pick_team_id','team']);
  if (!$teamCol) J(['ok'=>true,'total'=>0,'items'=>[]]);
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
  J(['ok'=>true,'total'=>$tot,'items'=>$rows]);
}

/* ---------- BUY LIFE (iscrizione/aggiunta) ---------- */
if ($action==='buy_life'){
  only_post();
  $tid=(int)($_POST['id'] ?? 0);
  if ($tid<=0) JD(['ok'=>false,'error'=>'bad_id'],'Parametro id torneo mancante');

  $st=$pdo->prepare("SELECT $tId id, COALESCE($tBuy,0) buyin, ".($tLMax!=='NULL'? "$tLMax lives_max_user,":"NULL lives_max_user,")." ".($tPool!=='NULL'? "$tPool pool_coins,":"NULL pool_coins,")." ".($tLock!=='NULL'? "$tLock lock_r1,":"NULL lock_r1")." FROM $tT WHERE $tId=?");
  $st->execute([$tid]); $t=$st->fetch(PDO::FETCH_ASSOC); if(!$t) JD(['ok'=>false,'error'=>'not_found'],'Torneo non trovato',['id'=>$tid]);

  $lock1 = $t['lock_r1'] ?? null; if ($lock1 && strtotime($lock1)<=time()) JD(['ok'=>false,'error'=>'closed'],'Acquisto vite chiuso (lock round 1 passato)',['lock_r1'=>$lock1]);

  $myAlive = livesAliveIds($pdo,$uid,$tid,$lT,$lUid,$lTid,$lId,$lState);
  if ($t['lives_max_user']!==null && count($myAlive) >= (int)$t['lives_max_user']) JD(['ok'=>false,'error'=>'limit'],'Limite vite raggiunto',['lives_max_user'=>$t['lives_max_user']]);

  $buyin=(float)$t['buyin']; $x=$pdo->prepare("SELECT COALESCE(coins,0) FROM users WHERE id=?"); $x->execute([$uid]); $coins=(float)$x->fetchColumn();
  if ($coins < $buyin) JD(['ok'=>false,'error'=>'insufficient_funds'],'Saldo insufficiente',['need'=>$buyin,'have'=>$coins]);

  try{
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE users SET coins=coins-? WHERE id=? AND coins>=?")->execute([$buyin,$uid,$buyin]);
    $cols=[$lUid,$lTid]; $vals=['?','?']; $par=[$uid,$tid];
    if ($lRound!=='NULL'){ $cols[]=$lRound; $vals[]='?'; $par[]=1; }
    if ($lState!=='NULL'){ $cols[]=$lState; $vals[]='?'; $par[]='alive'; }
    if ($lCAt!=='NULL'){ $cols[]=$lCAt; $vals[]='NOW()'; }
    $sql="INSERT INTO $lT(".implode(',',$cols).") VALUES(".implode(',',$vals).")";
    $pdo->prepare($sql)->execute($par);
    if ($tPool!=='NULL'){ $pdo->prepare("UPDATE $tT SET $tPool=COALESCE($tPool,0)+? WHERE $tId=?")->execute([$buyin,$tid]); }
    if ($hasLog){
      $cols=[$lgUid,$lgDelta,$lgReason]; $vals=['?','?','?']; $par2=[ $uid,-$buyin,'Acquisto vita torneo #'.$tid ];
      if ($lgCAt!=='NULL'){ $cols[]=$lgCAt; $vals[]='NOW()'; }
      $pdo->prepare("INSERT INTO $logT(".implode(',',$cols).") VALUES(".implode(',',$vals).")")->execute($par2);
    }
    $pdo->commit();
    $x=$pdo->prepare("SELECT COALESCE(coins,0) FROM users WHERE id=?"); $x->execute([$uid]); $new=(float)$x->fetchColumn();
    J(['ok'=>true,'new_balance'=>$new]);
  }catch(Throwable $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    JD(['ok'=>false,'error'=>'buy_failed'],$e->getMessage(),['trace'=>$e->getTraceAsString()]);
  }
}

/* ---------- UNJOIN (rimborso) ---------- */
if ($action==='unjoin'){
  only_post();
  $tid=(int)($_POST['id'] ?? 0);
  if ($tid<=0) JD(['ok'=>false,'error'=>'bad_id'],'Parametro id torneo mancante');

  $st=$pdo->prepare("SELECT $tId id, COALESCE($tBuy,0) buyin, ".($tPool!=='NULL'? "COALESCE($tPool,0) pool_coins,":"0 pool_coins,")." ".($tLock!=='NULL'? "$tLock lock_r1,":"NULL lock_r1")." FROM $tT WHERE $tId=?");
  $st->execute([$tid]); $t=$st->fetch(PDO::FETCH_ASSOC); if(!$t) JD(['ok'=>false,'error'=>'not_found'],'Torneo non trovato',['id'=>$tid]);

  $lock1 = $t['lock_r1'] ?? null; if ($lock1 && strtotime($lock1)<=time()) JD(['ok'=>false,'error'=>'closed'],'Disiscrizione chiusa: lock round 1 passato',['lock_r1'=>$lock1]);

  $ids = livesAliveIds($pdo,$uid,$tid,$lT,$lUid,$lTid,$lId,$lState);
  if (!$ids) JD(['ok'=>false,'error'=>'no_lives'],'Nessuna vita attiva da rimborsare');

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
      $cols=[$lgUid,$lgDelta,$lgReason]; $vals=['?','?','?']; $par=[ $uid,+$refund,'Disiscrizione torneo #'.$tid ];
      if ($lgCAt!=='NULL'){ $cols[]=$lgCAt; $vals[]='NOW()'; }
      $pdo->prepare("INSERT INTO $logT(".implode(',',$cols).") VALUES(".implode(',',$vals).")")->execute($par);
    }
    $pdo->commit();
    $x=$pdo->prepare("SELECT COALESCE(coins,0) FROM users WHERE id=?"); $x->execute([$uid]); $new=(float)$x->fetchColumn();
    J(['ok'=>true,'new_balance'=>$new]);
  }catch(Throwable $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    JD(['ok'=>false,'error'=>'unjoin_failed'],$e->getMessage(),['trace'=>$e->getTraceAsString()]);
  }
}

/* ---------- PICK (senza vincoli “ciclo”) ---------- */
if ($action==='pick'){
  only_post();
  $tid=(int)($_POST['id'] ?? 0);
  $life=(int)($_POST['life_id'] ?? 0);
  $event=(int)($_POST['event_id'] ?? 0);
  $team=(int)($_POST['team_id'] ?? 0);
  $round=(int)($_POST['round'] ?? 1);
  if($tid<=0 || $life<=0 || $event<=0 || $team<=0) JD(['ok'=>false,'error'=>'bad_params'],'Parametri incompleti',['post'=>$_POST]);

  // vita mia alive
  $sql="SELECT $lId id ".($lState!=='NULL'? ", $lState state":"")." FROM $lT WHERE $lId=? AND $lUid=? AND $lTid=? LIMIT 1";
  $st=$pdo->prepare($sql); $st->execute([$life,$uid,$tid]); $v=$st->fetch(PDO::FETCH_ASSOC);
  if(!$v) JD(['ok'=>false,'error'=>'life_not_found'],'Vita non trovata o non dell’utente',['life_id'=>$life,'uid'=>$uid,'tid'=>$tid]);
  if ($lState!=='NULL' && strtolower((string)($v['state']??''))!=='alive') JD(['ok'=>false,'error'=>'life_not_alive'],'La vita non è attiva',['state'=>$v['state']??null]);

  // evento non lockato
  if ($eT){
    $q="SELECT 1 FROM $eT WHERE $eId=? AND $eTid=?"; $par=[$event,$tid];
    if ($eRound!=='NULL'){ $q.=" AND $eRound=?"; $par[]=$round; }
    if ($eLock!=='NULL'){  $q.=" AND ($eLock IS NULL OR $eLock>NOW())"; }
    $x=$pdo->prepare($q); $x->execute($par);
    if(!$x->fetchColumn()) JD(['ok'=>false,'error'=>'event_locked'],'Evento non valido o lockato',['sql'=>$q,'params'=>$par]);
  }

  // colonna team dinamica
  $teamCol = $pTeamDyn ?: pickColOrNull($pdo,$pT,['team_id','pick_team_id','team']);
  if (!$teamCol) JD(['ok'=>false,'error'=>'no_team_col'],'Impossibile determinare la colonna TEAM nelle picks',['table'=>$pT]);

  try{
    $pdo->beginTransaction();
    $chk=$pdo->prepare("SELECT $pId FROM $pT WHERE $pLife=? AND $pTid=? AND $pRound=? AND $pEvent=? LIMIT 1");
    $chk->execute([$life,$tid,$round,$event]); $pid=(int)$chk->fetchColumn();
    if ($pid>0){
      $u=$pdo->prepare("UPDATE $pT SET $teamCol=? WHERE $pId=?");
      $u->execute([$team,$pid]);
    } else {
      $cols=[$pLife,$pTid,$pRound,$pEvent,$teamCol]; $vals=['?','?','?','?','?']; $par=[$life,$tid,$round,$event,$team];
      if ($pCAt!=='NULL'){ $cols[]=$pCAt; $vals[]='NOW()'; }
      $sql="INSERT INTO $pT(".implode(',',$cols).") VALUES(".implode(',',$vals).")";
      $pdo->prepare($sql)->execute($par);
    }
    $pdo->commit();
    J(['ok'=>true]);
  }catch(Throwable $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    JD(['ok'=>false,'error'=>'pick_failed'],$e->getMessage(),['trace'=>$e->getTraceAsString()]);
  }
}

// Fallback
JD(['ok'=>false,'error'=>'unknown_action'],'Azione non riconosciuta',['action'=>$action]);
