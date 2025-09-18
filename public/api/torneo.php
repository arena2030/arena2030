<?php
// /public/api/torneo.php — API torneo (summary/events/trending/choices_info/buy_life/unjoin/pick)

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

/* ===== Debug flag ===== */
$DBG = (isset($_GET['debug']) && $_GET['debug']=='1') || (isset($_POST['debug']) && $_POST['debug']=='1');

/* ===== Helpers ===== */
function J($arr){ echo json_encode($arr); exit; }
function only_post(){
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405); J(['ok'=>false,'error'=>'method']);
  }
}
// column exists
function colExists(PDO $pdo,string $t,string $c):bool{
  static $cch=[]; $k="$t.$c"; if(isset($cch[$k])) return $cch[$k];
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $q->execute([$t,$c]); return $cch[$k]=(bool)$q->fetchColumn();
}
// first existing column or fallback
function firstCol(PDO $pdo,string $t,array $cands,string $fallback='NULL'){
  foreach($cands as $c){ if(colExists($pdo,$t,$c)) return $c; } return $fallback;
}
// try to find a column, return null if none
function pickColOrNull(PDO $pdo,string $t,array $cands):?string{
  foreach($cands as $c){ if(colExists($pdo,$t,$c)) return $c; } return null;
}

// === Helpers per codici univoci (life_code, tx_code) ===
function genCode(int $len=8): string {
  $hex = strtoupper(bin2hex(random_bytes(max(4, min(32,$len)))));
  return substr($hex, 0, $len);
}
function colMaxLen(PDO $pdo, string $table, string $col): ?int {
  $st = $pdo->prepare("SELECT CHARACTER_MAXIMUM_LENGTH
                       FROM INFORMATION_SCHEMA.COLUMNS
                       WHERE TABLE_SCHEMA=DATABASE()
                         AND TABLE_NAME=? AND COLUMN_NAME=?");
  $st->execute([$table,$col]);
  $val = $st->fetchColumn();
  return $val!==false ? (int)$val : null;
}
function uniqueCode(PDO $pdo, string $table, string $col, int $len=8, string $prefix=''): string {
  $tries=0; do {
    $code = $prefix . genCode($len);
    $q=$pdo->prepare("SELECT 1 FROM `$table` WHERE `$col`=? LIMIT 1");
    $q->execute([$code]);
    $exists = (bool)$q->fetchColumn();
    $tries++;
  } while ($exists && $tries < 16);
  return $code;
}

function statusLabel(?string $s, ?string $lockIso): string {
  $now=time(); $s=strtolower((string)$s); $ts=$lockIso?strtotime($lockIso):null;
  if(in_array($s,['closed','ended','finished','chiuso','terminato'],true)) return 'CHIUSO';
  if($ts!==null && $ts <= $now) return 'IN CORSO';
  return 'APERTO';
}
function dbgJ(array $payload, array $dbg = []) {
  global $DBG;
  if ($DBG && $dbg) $payload['dbg'] = $dbg;
  echo json_encode($payload); exit;
}

// ===== Mapping tabelle/colonne (dinamico)
$tT     = 'tournaments';
$tId    = firstCol($pdo,$tT,['id'],'id');
$tCode  = firstCol($pdo,$tT,['code','tour_code','t_code','short_id'],'NULL');
$tTitle = firstCol($pdo,$tT,['title','name'],'NULL');
$tLeague= firstCol($pdo,$tT,['league','subtitle'],'NULL');
$tSeason= firstCol($pdo,$tT,['season','season_name'],'NULL');
$tBuy   = firstCol($pdo,$tT,['buyin_coins','buyin'],'0');
$tPool  = firstCol($pdo,$tT,['prize_pool_coins','pool_coins','prize_coins','prize_pool','montepremi'],'NULL');
$tLMax  = firstCol($pdo,$tT,['lives_max_user','lives_max','max_lives_per_user','lives_user_max'],'NULL');
$tStat  = firstCol($pdo,$tT,['status','state'],'NULL');
$tSeats = firstCol($pdo,$tT,['seats_total','seats_max','max_seats','max_players'],'NULL');
$tCRnd  = firstCol($pdo,$tT,['current_round','round_current','round'],'NULL');
$tLock  = firstCol($pdo,$tT,['lock_at','close_at','subscription_end','reg_close_at','start_time'],'NULL');

// lives
$lT = null; foreach(['tournament_lives','tournaments_lives'] as $try){
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
  $q->execute([$try]); if($q->fetchColumn()){ $lT=$try; break; }
}
if(!$lT) $lT='tournament_lives';
$lId   = firstCol($pdo,$lT,['id'],'id');
$lUid  = firstCol($pdo,$lT,['user_id','uid'],'user_id');
$lTid  = firstCol($pdo,$lT,['tournament_id','tid'],'tournament_id');
$lRound= firstCol($pdo,$lT,['round','rnd'],'NULL');
$lState= firstCol($pdo,$lT,['status','state'],'NULL');
$lCode = firstCol($pdo,$lT,['life_code','code'],'NULL');
$lCAt  = firstCol($pdo,$lT,['created_at','created'],'NULL');

// events
$eT = null; foreach(['tournament_events','events','partite','matches'] as $try){
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
  $q->execute([$try]); if($q->fetchColumn()){ $eT=$try; break; }
}
$eId   = $eT? firstCol($pdo,$eT,['id'],'id') : 'NULL';
$eTid  = $eT? firstCol($pdo,$eT,['tournament_id','tid'],'tournament_id') : 'NULL';
$eRound= $eT? firstCol($pdo,$eT,['round','rnd'],'NULL') : 'NULL';
$eLock = $eT? firstCol($pdo,$eT,['lock_at','close_at','start_at','start_time'],'NULL') : 'NULL';
$eHome = $eT? firstCol($pdo,$eT,['home_team_id','team_a_id','home_id'],'NULL') : 'NULL';
$eAway = $eT? firstCol($pdo,$eT,['away_team_id','team_b_id','away_id'],'NULL') : 'NULL';
$eHomeN= $eT? firstCol($pdo,$eT,['home_team_name','team_a_name','home_name'],'NULL') : 'NULL';
$eAwayN= $eT? firstCol($pdo,$eT,['away_team_name','team_b_name','away_name'],'NULL') : 'NULL';

// teams (optional)
$tmT = null; foreach(['teams','squadre','clubs'] as $try){
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
  $q->execute([$try]); if($q->fetchColumn()){ $tmT=$try; break; }
}
$tmId = $tmT? firstCol($pdo,$tmT,['id'],'id') : 'NULL';
$tmNm = $tmT? firstCol($pdo,$tmT,['name','nome','team_name'],'NULL') : 'NULL';
$tmLg = $tmT? firstCol($pdo,$tmT,['logo_url','logo','badge_url','image'],'NULL') : 'NULL';

// picks
$pT = null; foreach(['tournament_picks','picks','scelte'] as $try){
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
  $q->execute([$try]); if($q->fetchColumn()){ $pT=$try; break; }
}
if(!$pT) $pT='tournament_picks';
$pId   = firstCol($pdo,$pT,['id'],'id');
$pLife = firstCol($pdo,$pT,['life_id'],'life_id');
$pTid  = firstCol($pdo,$pT,['tournament_id','tid'],'tournament_id');
$pRound= firstCol($pdo,$pT,['round','rnd'],'round');
$pEvent= firstCol($pdo,$pT,['event_id','match_id'],'event_id');
$pTeamDyn = pickColOrNull($pdo,$pT,['team_id','choice','team_choice','pick_team_id','team','squadra_id','scelta','teamid','teamID','team_sel']);

// balance
$uT='users'; $uId='id'; $uCoins='coins';

// ===== utils
function resolveTid(PDO $pdo, string $tT, string $tId, string $tCode): int {
  $id   = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
  $code = trim($_GET['tid'] ?? $_POST['tid'] ?? '');
  if ($id>0) return $id;
  if ($code!=='' && $tCode!=='NULL'){
    $st=$pdo->prepare("SELECT $tId FROM $tT WHERE $tCode=? LIMIT 1"); $st->execute([$code]); return (int)$st->fetchColumn();
  }
  return 0;
}

$action = $_GET['action'] ?? ($_POST['action'] ?? null);
if (!$action){ J(['ok'=>false,'error'=>'no_action']); }

/* ---------- SUMMARY ---------- */
if ($action==='summary'){
  $tid = resolveTid($pdo,$tT,$tId,$tCode);
  if ($tid<=0) dbgJ(['ok'=>false,'error'=>'bad_id'], ['where'=>'SUMMARY IN','id'=>$_GET['id']??null,'tid'=>$_GET['tid']??null]);

  $sel = [];
  $sel[] = "$tId AS id";
  $sel[] = ($tCode!=='NULL')  ? "$tCode AS code"               : "NULL AS code";
  $sel[] = ($tTitle!=='NULL') ? "$tTitle AS title"             : "NULL AS title";
  $sel[] = ($tLeague!=='NULL')? "$tLeague AS league"           : "NULL AS league";
  $sel[] = ($tSeason!=='NULL')? "$tSeason AS season"           : "NULL AS season";
  $sel[] = "COALESCE($tBuy,0) AS buyin";
  $sel[] = ($tPool!=='NULL')  ? "$tPool AS pool_coins"         : "NULL AS pool_coins";
  $sel[] = ($tLMax!=='NULL')  ? "$tLMax AS lives_max_user"     : "NULL AS lives_max_user";
  $sel[] = ($tStat!=='NULL')  ? "$tStat AS status"             : "NULL AS status";
  $sel[] = ($tSeats!=='NULL') ? "$tSeats AS seats_total"       : "NULL AS seats_total";
  $sel[] = "COALESCE($tCRnd,NULL) AS current_round";
  $sel[] = ($tLock!=='NULL')  ? "$tLock AS lock_at"            : "NULL AS lock_at";

  $sql = "SELECT ".implode(", ", $sel)." FROM $tT WHERE $tId=? LIMIT 1";
  try{
    $st=$pdo->prepare($sql); $st->execute([$tid]); $t=$st->fetch(PDO::FETCH_ASSOC);
  }catch(Throwable $e){
    dbgJ(['ok'=>false,'error'=>'sql_error'], ['sql'=>$sql,'params'=>[$tid],'message'=>$e->getMessage()]);
  }
  if(!$t) dbgJ(['ok'=>false,'error'=>'not_found'], ['sql'=>$sql,'params'=>[$tid]]);

  $state = statusLabel($t['status'] ?? null, $t['lock_at'] ?? null);

  // vite in gioco (global)
  $q = ($lState!=='NULL') ? "SELECT COUNT(*) FROM $lT WHERE $lTid=? AND $lState='alive'"
                          : "SELECT COUNT(*) FROM $lT WHERE $lTid=?";
  $x=$pdo->prepare($q); $x->execute([$tid]); $livesInPlay=(int)$x->fetchColumn();

  // mie vite
  $q="SELECT $lId AS id"
    .($lCode!=='NULL'  ? ", $lCode AS life_code" : "")
    .($lRound!=='NULL' ? ", $lRound AS round" : "")
    .($lState!=='NULL' ? ", $lState AS state" : "")
    ." FROM $lT WHERE $lUid=? AND $lTid=? ORDER BY $lId ASC";
  $x=$pdo->prepare($q); $x->execute([$uid,$tid]); $myLives=$x->fetchAll(PDO::FETCH_ASSOC);

  // quanto ha già comprato
  $livesCount = count($myLives);
  $canBuy = true;
  if ($t['lives_max_user']!==null && $t['lives_max_user']!=='NULL') {
    $mx=(int)$t['lives_max_user']; if ($mx>0 && $livesCount >= $mx) $canBuy=false;
  }
  $canUnj = ($livesCount>0) && ($state==='APERTO');

  dbgJ([
    'ok'=>true,
    'tournament'=>[
      'id'=>$t['id'],
      'code'=>$t['code'],
      'title'=>$t['title'],
      'league'=>$t['league'],
      'season'=>$t['season'],
      'buyin'=>$t['buyin'],
      'pool_coins'=>$t['pool_coins'],
      'lives_max_user'=>$t['lives_max_user'],
      'seats_total'=>$t['seats_total'],
      'current_round'=>$t['current_round'],
      'lock_at'=>$t['lock_at'],
      'state'=>$state
    ],
    'stats'=>['lives_in_play'=>$livesInPlay],
    'me'=>['lives'=>$myLives, 'can_buy_life'=>$canBuy, 'can_unjoin'=>$canUnj]
  ], $DBG ? ['summary_sql'=>$sql] : []);
}

/* ---------- EVENTS ---------- */
if ($action==='events'){
  $tid = resolveTid($pdo,$tT,$tId,$tCode);
  $round=(int)($_GET['round'] ?? 1);
  if(!$eT) dbgJ(['ok'=>true,'events'=>[],'note'=>'no_events_table']);

  $cols = "e.$eId AS id";
  $cols.= ($eRound!=='NULL')? ", e.$eRound AS round" : ", NULL AS round";
  $cols.= ($eLock!=='NULL') ? ", e.$eLock AS lock_at" : ", NULL AS lock_at";
  $cols.= ($eHome!=='NULL') ? ", e.$eHome AS home_id" : ", NULL AS home_id";
  $cols.= ($eAway!=='NULL') ? ", e.$eAway AS away_id" : ", NULL AS away_id";
  $cols.= ($eHomeN!=='NULL')? ", e.$eHomeN AS home_name" : ", NULL AS home_name";
  $cols.= ($eAwayN!=='NULL')? ", e.$eAwayN AS away_name" : ", NULL AS away_name";

  $sql = "SELECT $cols FROM $eT e WHERE ".($eTid!=='NULL'?"e.$eTid=? AND ":"").($eRound!=='NULL'?"e.$eRound=?":"1=1")." ORDER BY e.$eId ASC";
  $st = ($eRound!=='NULL') ? $pdo->prepare($sql) : $pdo->prepare(str_replace(" AND e.$eRound=?","",$sql));
  $params = ($eRound!=='NULL') ? [$tid,$round] : [$tid];
  try{ $st->execute($params); $rows=$st->fetchAll(PDO::FETCH_ASSOC); } catch(Throwable $e){
    dbgJ(['ok'=>false,'error'=>'sql_error'], ['sql'=>$sql,'params'=>$params,'message'=>$e->getMessage()]);
  }

  // optional join teams
  if ($tmT && $tmNm!=='NULL'){
    foreach($rows as &$r){
      foreach(['home','away'] as $side){
        $idKey = $side.'_id'; $nmKey=$side.'_name'; $lgKey=$side.'_logo';
        if (!empty($r[$idKey])) {
          $q="SELECT $tmNm AS name".($tmLg!=='NULL'? ", $tmLg AS logo": "")." FROM $tmT WHERE $tmId=? LIMIT 1";
          $x=$pdo->prepare($q); $x->execute([(int)$r[$idKey]]); $a=$x->fetch(PDO::FETCH_ASSOC)?:['name'=>null,'logo'=>null];
          if (!$r[$nmKey]) $r[$nmKey]=$a['name'];
          if (array_key_exists('logo',$a)) $r[$lgKey]=$a['logo'];
        }
      }
    }
  }
  dbgJ(['ok'=>true,'events'=>$rows]);
}

/* ---------- TRENDING ---------- */
if ($action==='trending'){
  $tid = resolveTid($pdo,$tT,$tId,$tCode);
  $round=(int)($_GET['round'] ?? 1);
  if(!$pT || !$pTeamDyn) dbgJ(['ok'=>true,'total'=>0,'items'=>[],'note'=>'no_picks']);

  $teamCol = $pTeamDyn ?: pickColOrNull($pdo,$pT,['team_id','choice','team_choice','pick_team_id','team','squadra_id','scelta','teamid','teamID','team_sel']);
  if (!$teamCol) dbgJ(['ok'=>true,'total'=>0,'items'=>[],'note'=>'no_team_col']);

  $sql="SELECT p.$teamCol AS team_id, COUNT(*) cnt FROM $pT p WHERE ".($pTid!=='NULL'?"p.$pTid=? AND ":"")."p.$pRound=? GROUP BY p.$teamCol";
  $st=$pdo->prepare($sql); $st->execute([$tid,$round]); $rows=$st->fetchAll(PDO::FETCH_ASSOC);

  if ($tmT){
    foreach($rows as &$r){
      $q="SELECT $tmNm AS name".($tmLg!=='NULL'? ", $tmLg AS logo": "")." FROM $tmT WHERE $tmId=? LIMIT 1";
      $x=$pdo->prepare($q); $x->execute([(int)$r['team_id']]); $a=$x->fetch(PDO::FETCH_ASSOC)?:['name'=>null,'logo'=>null];
      $r['name']=$a['name']; if(array_key_exists('logo',$a)) $r['logo']=$a['logo'];
    }
  }
  dbgJ(['ok'=>true,'total'=>count($rows),'items'=>$rows]);
}

/* ---------- INFO SCELTE ---------- */
if ($action==='choices_info'){
  $tid = resolveTid($pdo,$tT,$tId,$tCode);
  $round=(int)($_GET['round'] ?? 1);
  if(!$pT) dbgJ(['ok'=>true,'rows'=>[],'note'=>'no_picks_table']);

  $teamCol = $pTeamDyn ?: pickColOrNull($pdo,$pT,['team_id','choice','team_choice','pick_team_id','team','squadra_id','scelta','teamid','teamID','team_sel']);
  if (!$teamCol) dbgJ(['ok'=>true,'rows'=>[],'note'=>'no_team_col']);

  $uT='users'; $uId='id'; $uNm=firstCol($pdo,$uT,['username','name','fullname','display_name'],'username');

  $cols = "p.$teamCol AS team_id, u.$uNm AS username";
  $join = "JOIN $lT l ON l.$lId = p.$pLife JOIN $uT u ON u.$uId = l.$lUid";
  if ($tmT) { $cols .= ", tm.$tmNm AS team_name"; $join = "LEFT JOIN $tmT tm ON tm.$tmId = p.$teamCol ".$join; }
  else { $cols .= ", NULL AS team_name"; }

  $where = ($pTid!=='NULL') ? "p.$pTid=?" : "l.$lTid=?";
  $sql = "SELECT $cols FROM $pT p $join
          WHERE $where AND p.$pRound=?
          ORDER BY u.$uNm ASC, p.$pId ASC";
  $st = $pdo->prepare($sql); $st->execute([$tid,$round]);
  dbgJ(['ok'=>true,'rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
}

/* ---------- BUY LIFE ---------- */
if ($action==='buy_life'){
  only_post();
  $tid = resolveTid($pdo,$tT,$tId,$tCode);
  if ($tid<=0) J(['ok'=>false,'error'=>'bad_id']);

  // dati torneo (buyin, lock, lives_max_user)
  $st=$pdo->prepare("SELECT COALESCE($tBuy,0) AS buyin"
                   .($tLock!=='NULL'? ", $tLock AS lock_at":"")
                   .($tLMax!=='NULL'? ", $tLMax AS lives_max_user":"")
                   .($tPool!=='NULL'? ", COALESCE($tPool,0) AS pool_now":"")
                   ." FROM $tT WHERE $tId=? LIMIT 1");
  $st->execute([$tid]); $t=$st->fetch(PDO::FETCH_ASSOC);
  if(!$t) J(['ok'=>false,'error'=>'not_found']);
  if (statusLabel(null,$t['lock_at']??null)!=='APERTO') J(['ok'=>false,'error'=>'closed']);
  $buyin=(float)($t['buyin'] ?? 0);

  // vite già possedute
  $x=$pdo->prepare("SELECT COUNT(*) FROM $lT WHERE $lUid=? AND $lTid=?");
  $x->execute([$uid,$tid]); $mine=(int)$x->fetchColumn();
  if (($t['lives_max_user']??null)!==null && (int)$t['lives_max_user']>0 && $mine >= (int)$t['lives_max_user']) J(['ok'=>false,'error'=>'max_lives']);

  // saldo
  $st=$pdo->prepare("SELECT COALESCE($uCoins,0) FROM $uT WHERE $uId=? LIMIT 1"); $st->execute([$uid]); $coins=(float)$st->fetchColumn();
  if ($coins < $buyin) J(['ok'=>false,'error'=>'insufficient_funds']);

  $pdo->beginTransaction();
  try{
    // addebita
    $pdo->prepare("UPDATE $uT SET $uCoins = $uCoins - ? WHERE $uId=?")->execute([$buyin,$uid]);
    // aggiorna pool se presente
    if ($tPool!=='NULL') $pdo->prepare("UPDATE $tT SET $tPool = COALESCE($tPool,0) + ? WHERE $tId=?")->execute([$buyin,$tid]);
    // crea vita
    $cols=[$lUid,$lTid]; $vals=['?','?']; $par=[$uid,$tid];
    if ($lRound!=='NULL'){ $cols[]=$lRound; $vals[]='?'; $par[]=1; }
    if ($lState!=='NULL'){ $cols[]=$lState; $vals[]="'alive'"; }
    if ($lCode!=='NULL'){
      $len = colMaxLen($pdo, $lT, $lCode) ?: 8;
      $lifeCode = uniqueCode($pdo, $lT, $lCode, max(4, min(32, $len)), '');
      $cols[]=$lCode; $vals[]='?'; $par[]=$lifeCode;
    }
    if ($lCAt!=='NULL'){ $cols[]=$lCAt; $vals[]='NOW()'; }
    $sql="INSERT INTO $lT(".implode(',',$cols).") VALUES(".implode(',',$vals).")";
    $pdo->prepare($sql)->execute($par);

    $pdo->commit();
    J(['ok'=>true,'new_balance'=>$coins-$buyin]);
  }catch(Throwable $e){
    $pdo->rollBack(); J(['ok'=>false,'error'=>'tx_failed','detail'=>$e->getMessage()]);
  }
}

/* ---------- UNJOIN ---------- */
if ($action==='unjoin'){
  only_post();
  $tid = resolveTid($pdo,$tT,$tId,$tCode);
  if ($tid<=0) J(['ok'=>false,'error'=>'bad_id']);

  // tutte le vite dell'utente in quel torneo
  $stL = $pdo->prepare("SELECT $lId FROM $lT WHERE $lUid=? AND $lTid=?");
  $stL->execute([$uid,$tid]);
  $ids = array_map('intval',$stL->fetchAll(PDO::FETCH_COLUMN));
  if (!$ids) J(['ok'=>false,'error'=>'no_lives']);

  // buyin
  $st=$pdo->prepare("SELECT COALESCE($tBuy,0) FROM $tT WHERE $tId=?"); $st->execute([$tid]); $buyin=(float)$st->fetchColumn();
  $refund = $buyin * count($ids);

  $pdo->beginTransaction();
  try{
    // cancella picks
    if ($pT && $ids){
      $in = implode(',', array_fill(0,count($ids),'?'));
      if ($pTid!=='NULL'){
        $pdo->prepare("DELETE FROM $pT WHERE $pTid=? AND $pLife IN ($in)")->execute(array_merge([$tid], $ids));
      } else {
        $pdo->prepare("DELETE FROM $pT WHERE $pLife IN ($in)")->execute($ids);
      }
    }
    // cancella vite
    $inL = implode(',', array_fill(0,count($ids),'?'));
    $pdo->prepare("DELETE FROM $lT WHERE $lId IN ($inL)")->execute($ids);

    // eventuale riga join-table
    $joinTable=null;
    foreach(['tournament_players','tournaments_players'] as $jt){
      $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
      $q->execute([$jt]); if($q->fetchColumn()){ $joinTable=$jt; break; }
    }
    if ($joinTable){
      $jUid = firstCol($pdo,$joinTable,['user_id','uid'],'user_id');
      $jTid = firstCol($pdo,$joinTable,['tournament_id','tid'],'tournament_id');
      $pdo->prepare("DELETE FROM $joinTable WHERE $jUid=? AND $jTid=?")->execute([$uid,$tid]);
    }

    // rimborso
    $pdo->prepare("UPDATE $uT SET $uCoins = $uCoins + ? WHERE $uId=?")->execute([$refund,$uid]);
    if ($tPool!=='NULL') $pdo->prepare("UPDATE $tT SET $tPool = GREATEST(COALESCE($tPool,0) - ?, 0) WHERE $tId=?")->execute([$refund,$tid]);

    $pdo->commit();
    J(['ok'=>true,'refund'=>$refund]);
  }catch(Throwable $e){
    $pdo->rollBack(); J(['ok'=>false,'error'=>'tx_failed','detail'=>$e->getMessage()]);
  }
}

/* ---------- PICK ---------- */
if ($action==='pick'){
  only_post();
  $tid = resolveTid($pdo,$tT,$tId,$tCode);
  $life=(int)($_POST['life_id'] ?? 0);
  $event=(int)($_POST['event_id'] ?? 0);
  $team=(int)($_POST['team_id'] ?? 0);
  $round=(int)($_POST['round'] ?? 1);
  if($tid<=0 || $life<=0 || $event<=0 || $team<=0) dbgJ(['ok'=>false,'error'=>'bad_params'], ['tid'=>$tid,'life'=>$life,'event'=>$event,'team'=>$team,'round'=>$round]);

  // ownership life
  $sql="SELECT $lId id ".($lState!=='NULL'? ", $lState state":"")." FROM $lT WHERE $lId=? AND $lUid=? AND $lTid=? LIMIT 1";
  $st=$pdo->prepare($sql); $st->execute([$life,$uid,$tid]); $v=$st->fetch(PDO::FETCH_ASSOC);
  if(!$v) dbgJ(['ok'=>false,'error'=>'life_not_found'], ['sql'=>$sql,'params'=>[$life,$uid,$tid]]);
  if ($lState!=='NULL' && isset($v['state']) && $v['state']!=='alive') dbgJ(['ok'=>false,'error'=>'life_not_alive']);

  // insert pick
  $cols=[$pLife,$pRound,$pEvent]; $vals=['?','?','?']; $par=[$life,$round,$event];
  if ($pTid!=='NULL'){ $cols[]=$pTid; $vals[]='?'; $par[]=$tid; }
  $teamCol = $pTeamDyn ?: pickColOrNull($pdo,$pT,['team_id','choice','team_choice','pick_team_id','team','squadra_id','scelta','teamid','teamID','team_sel']);
  if (!$teamCol) J(['ok'=>false,'error'=>'no_team_col']);
  $cols[]=$teamCol; $vals[]='?'; $par[]=$team;

  $sql="INSERT INTO $pT(".implode(',',$cols).") VALUES(".implode(',',$vals).")";
  try{ $pdo->prepare($sql)->execute($par); J(['ok'=>true]); }
  catch(Throwable $e){ J(['ok'=>false,'error'=>'insert_failed','detail'=>$e->getMessage()]); }
}

J(['ok'=>false,'error'=>'unknown_action']);
