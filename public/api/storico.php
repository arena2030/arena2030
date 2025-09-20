<?php
// /public/api/storico.php
declare(strict_types=1);
require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');

$uid  = (int)($_SESSION['uid'] ?? 0);
$role = $_SESSION['role'] ?? '';
if ($uid<=0 || !in_array($role,['USER','PUNTO','ADMIN'],true)){
  http_response_code(401); echo json_encode(['ok'=>false,'error'=>'auth']); exit;
}

function colExists(PDO $pdo,string $t,string $c):bool{
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $q->execute([$t,$c]); return (bool)$q->fetchColumn();
}

// ==== ACTIONS ====
$act = $_GET['action'] ?? '';

// ---------- LIST ----------
if ($act==='list'){
  $page  = max(1,(int)($_GET['page'] ?? 1));
  $limit = max(1,min(50,(int)($_GET['limit'] ?? 6)));
  $q     = trim((string)($_GET['q'] ?? ''));

  // colonne flessibili
  $tT='tournaments';
  $cId = colExists($pdo,$tT,'id')?'id':'id';
  $cCode = colExists($pdo,$tT,'code')?'code':(colExists($pdo,$tT,'tour_code')?'tour_code':(colExists($pdo,$tT,'short_id')?'short_id':'NULL'));
  $cTitle= colExists($pdo,$tT,'title')?'title':(colExists($pdo,$tT,'name')?'name':'NULL');
  $cStat = colExists($pdo,$tT,'status')?'status':(colExists($pdo,$tT,'state')?'state':'NULL');
  $cRound= colExists($pdo,$tT,'current_round')?'current_round':(colExists($pdo,$tT,'round_current')?'round_current':'NULL');

  // totals semplici (vite acquistate e vive) se tabella vite presente
  $hasLives = (bool)$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tournament_lives'")->fetchColumn();
  $livesTotSql = $hasLives ? "(SELECT COUNT(*) FROM tournament_lives l WHERE l.tournament_id=t.$cId)" : "0";
  $livesAliveSql = $hasLives
      ? "(SELECT COUNT(*) FROM tournament_lives l WHERE l.tournament_id=t.$cId AND LOWER(COALESCE(l.status,l.state,''))='alive')"
      : "0";

  $where = "1=1 AND LOWER(COALESCE($cStat,'')) IN ('closed','ended','finished','chiuso','terminato','published','live','aperto','open')";
  $par = [];
  if ($q!==''){
    $where .= " AND (".($cCode!=='NULL'?"t.$cCode LIKE ?":"0")." OR ".($cTitle!=='NULL'?"t.$cTitle LIKE ?":"0").")";
    $par[]="%$q%"; $par[]="%$q%";
  }

// 1) Conteggio totale (usa i parametri di $where)
$stTot = $pdo->prepare("SELECT COUNT(*) FROM $tT t WHERE $where");
foreach ($par as $i => $v) {
  // i è 0-based, bind vuole 1-based
  $stTot->bindValue($i+1, $v, PDO::PARAM_STR);
}
$stTot->execute();
$total = (int)$stTot->fetchColumn();

// 2) Query lista (identica alla tua, con LIMIT/OFFSET bindati)
$sqlSel = "SELECT t.$cId AS id"
        . ($cCode!=='NULL'  ? ", t.$cCode  AS code"   : "")
        . ($cTitle!=='NULL' ? ", t.$cTitle AS title"  : "")
        . ($cStat!=='NULL'  ? ", t.$cStat  AS status" : "")
        . ($cRound!=='NULL' ? ", t.$cRound AS round_max" : "")
        . ", $livesTotSql AS lives_total, $livesAliveSql AS lives_alive
           FROM $tT t
           WHERE $where
           ORDER BY t.$cId DESC
           LIMIT ? OFFSET ?";

$st = $pdo->prepare($sqlSel);

// bind dei parametri del WHERE
$idx = 1;
foreach ($par as $v) {
  $st->bindValue($idx++, $v, PDO::PARAM_STR);
}

// bind di LIMIT e OFFSET (INT!)
$st->bindValue($idx++, (int)$limit, PDO::PARAM_INT);
$st->bindValue($idx++, (int)(($page - 1) * $limit), PDO::PARAM_INT);

$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// (il resto invariato: calc pages, echo json, ecc.)

  // winners (best effort): se esiste colonna winner_user_id prendilo; altrimenti 0
  if (colExists($pdo,$tT,'winner_user_id')){
    $uT='users'; $uId=colExists($pdo,$uT,'id')?'id':'id'; $uNm=colExists($pdo,$uT,'username')?'username':(colExists($pdo,$uT,'name')?'name':'username');
    foreach($rows as &$r){
      $w = $pdo->prepare("SELECT $uId AS id, $uNm AS username FROM $uT WHERE $uId = (SELECT winner_user_id FROM $tT WHERE $cId=?)");
      $w->execute([(int)$r['id']]); $wu=$w->fetch(PDO::FETCH_ASSOC);
      $r['winners'] = $wu ? [ ['username'=>$wu['username']] ] : [];
      $r['wins'] = count($r['winners']);
    }
  } else {
    foreach($rows as &$r){ $r['winners']=[]; $r['wins']=0; }
  }

  echo json_encode([
    'ok'=>true,'items'=>$rows,'total'=>$total,
    'page'=>$page,'pages'=>max(1, (int)ceil($total/$limit)),'limit'=>$limit
  ]);
  exit;
}

// ---------- ROUND EVENTS ----------
if ($act==='round'){
  $id  = (int)($_GET['id'] ?? 0);
  $tid = trim((string)($_GET['tid'] ?? ''));
  $round = max(1,(int)($_GET['round'] ?? 1));

  // tabella eventi
  $eT='tournament_events';
  $eId  = colExists($pdo,$eT,'id')?'id':'id';
  $eTid = colExists($pdo,$eT,'tournament_id')?'tournament_id':'tournament_id';
  $eR   = colExists($pdo,$eT,'round')?'round':(colExists($pdo,$eT,'rnd')?'rnd':'round');
  $eH   = colExists($pdo,$eT,'home_team_id')?'home_team_id':'home_team_id';
  $eA   = colExists($pdo,$eT,'away_team_id')?'away_team_id':'away_team_id';

  // join teams (opzionale)
  $tTms='teams';
  $hasTeams = (bool)$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='teams'")->fetchColumn();

  $sql = "SELECT e.$eId AS id, e.$eH AS home_id, e.$eA AS away_id,
                 ".($hasTeams?"th.name AS home_name, ta.name AS away_name, th.logo AS home_logo, ta.logo AS away_logo,":" ' ' AS home_name, ' ' AS away_name, NULL AS home_logo, NULL AS away_logo,")."
                 NULL AS home_score, NULL AS away_score, NULL AS winner_team_id
          FROM $eT e
          ".($hasTeams?"LEFT JOIN $tTms th ON th.id=e.$eH LEFT JOIN $tTms ta ON ta.id=e.$eA":"")."
          WHERE e.$eTid ".($id>0?"= ?":"IN (SELECT id FROM tournaments WHERE ".(colExists($pdo,'tournaments','code')?'code':(colExists($pdo,'tournaments','tour_code')?'tour_code':'short_id'))."=?)")."
            AND e.$eR = ?
          ORDER BY e.$eId ASC";
  $st=$pdo->prepare($sql); $st->execute([$id>0?$id:$tid, $round]);
  echo json_encode(['ok'=>true,'events'=>$st->fetchAll(PDO::FETCH_ASSOC)]); exit;
}

// ---------- CHOICES ----------
if ($act==='choices'){
  $id  = (int)($_GET['id'] ?? 0);
  $tid = trim((string)($_GET['tid'] ?? ''));
  $round = max(1,(int)($_GET['round'] ?? 1));

  $pT='tournament_picks'; $lT='tournament_lives'; $uT='users';
  $pLife = colExists($pdo,$pT,'life_id')?'life_id':'life_id';
  $pEid  = colExists($pdo,$pT,'event_id')?'event_id':'event_id';
  $pRid  = colExists($pdo,$pT,'round')?'round':(colExists($pdo,$pT,'rnd')?'rnd':'round');
  $pTid  = colExists($pdo,$pT,'tournament_id')?'tournament_id':null;
  $pTm   = colExists($pdo,$pT,'team_id')?'team_id':'team_id';

  $lId=colExists($pdo,$lT,'id')?'id':'id';
  $lUid=colExists($pdo,$lT,'user_id')?'user_id':(colExists($pdo,$lT,'uid')?'uid':'user_id');
  $lTid = colExists($pdo,$lT,'tournament_id')?'tournament_id':'tournament_id';

  $uId=colExists($pdo,$uT,'id')?'id':'id';
  $uNm=colExists($pdo,$uT,'username')?'username':(colExists($pdo,$uT,'name')?'name':'username');
  $uAv=colExists($pdo,$uT,'avatar')?'avatar':(colExists($pdo,$uT,'avatar_url')?'avatar_url':(colExists($pdo,$uT,'photo')?'photo':(colExists($pdo,$uT,'picture')?'picture':'NULL')));

  $where = "p.$pRid=? AND l.$lId=p.$pLife AND u.$uId=l.$lUid ".($pTid?" AND p.$pTid=":" AND l.$lTid=");
  $val   = [$round, $id>0?$id:$tid];

  $sql="SELECT p.$pTm AS team_id, u.$uNm AS username, ".($uAv!=='NULL'?"u.$uAv":"NULL")." AS avatar
        FROM $pT p JOIN $lT l ON l.$lId=p.$pLife JOIN $uT u ON u.$uId=l.$lUid
        WHERE $where
        ORDER BY u.$uNm ASC";
  $st=$pdo->prepare($sql); $st->execute(array_merge([$round],[$id>0?$id:$tid]));
  echo json_encode(['ok'=>true,'rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]); exit;
}

http_response_code(400);
echo json_encode(['ok'=>false,'error'=>'bad_action']);
