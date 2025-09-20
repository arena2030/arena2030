<?php
// /public/api/storico.php
declare(strict_types=1);

ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log','/tmp/php_errors.log');

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
  try{
    $page  = max(1,(int)($_GET['page'] ?? 1));
    $limit = max(1,min(50,(int)($_GET['limit'] ?? 6)));
    $q     = trim((string)($_GET['q'] ?? ''));

    $tT='tournaments';

    // Colonne flessibili su tournaments
    $cId    = 'id';
    $cCode  = colExists($pdo,$tT,'code')       ? 'code'
            : (colExists($pdo,$tT,'tour_code') ? 'tour_code'
            : (colExists($pdo,$tT,'short_id')  ? 'short_id'  : 'NULL'));
    $cTitle = colExists($pdo,$tT,'title')      ? 'title'
            : (colExists($pdo,$tT,'name')      ? 'name'      : 'NULL');
    $cStat  = colExists($pdo,$tT,'status')     ? 'status'
            : (colExists($pdo,$tT,'state')     ? 'state'     : 'NULL');
    $cRound = colExists($pdo,$tT,'current_round') ? 'current_round'
            : (colExists($pdo,$tT,'round_current') ? 'round_current' : 'NULL');

    // Presenza tabella vite e colonne stato
    $hasLives = (bool)$pdo->query(
      "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
       WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tournament_lives'"
    )->fetchColumn();

    $livesTotSql = $hasLives
      ? "(SELECT COUNT(*) FROM tournament_lives l WHERE l.tournament_id=t.$cId)"
      : "0";

    $lStatusCol = null;
    if ($hasLives) {
      if (colExists($pdo,'tournament_lives','status'))      $lStatusCol = 'status';
      elseif (colExists($pdo,'tournament_lives','state'))   $lStatusCol = 'state';
    }

    $livesAliveSql = ($hasLives && $lStatusCol)
      ? "(SELECT COUNT(*) FROM tournament_lives l
           WHERE l.tournament_id=t.$cId AND LOWER(l.$lStatusCol)='alive')"
      : "0";

    // WHERE: solo ricerca testuale, nessun filtro di stato
    $whereParts = [];
    $params     = [];

    if ($q !== '') {
      $like = [];
      if ($cCode  !== 'NULL') { $like[] = "t.$cCode  LIKE ?"; $params[]="%$q%"; }
      if ($cTitle !== 'NULL') { $like[] = "t.$cTitle LIKE ?"; $params[]="%$q%"; }
      if ($like) $whereParts[] = '(' . implode(' OR ', $like) . ')';
    }
    $where = $whereParts ? ('WHERE '.implode(' AND ', $whereParts)) : '';

    // COUNT(*) total
    $sqlCount = "SELECT COUNT(*) FROM $tT t $where";
    $stTot = $pdo->prepare($sqlCount);
    foreach ($params as $i => $v) $stTot->bindValue($i+1, $v, PDO::PARAM_STR);
    $stTot->execute();
    $total = (int)$stTot->fetchColumn();

    // SELECT paginata: usa NULL dove la colonna non esiste
    $offset = (int)(($page - 1) * $limit);
    $lim    = (int)$limit;

    $sqlSel = "SELECT
                 t.$cId AS id"
            . ($cCode  !== 'NULL' ? ", t.$cCode  AS code"      : ", NULL AS code")
            . ($cTitle !== 'NULL' ? ", t.$cTitle AS title"     : ", NULL AS title")
            . ($cStat  !== 'NULL' ? ", t.$cStat  AS status"    : ", NULL AS status")
            . ($cRound !== 'NULL' ? ", t.$cRound AS round_max" : ", NULL AS round_max")
            . ", $livesTotSql   AS lives_total
               , $livesAliveSql AS lives_alive
               FROM $tT t
               $where
               ORDER BY t.$cId DESC
               LIMIT $lim OFFSET $offset";

    $st = $pdo->prepare($sqlSel);
    foreach ($params as $i => $v) $st->bindValue($i+1, $v, PDO::PARAM_STR);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // winners “best effort” (non esplodere se non c’è la colonna)
    if (colExists($pdo,$tT,'winner_user_id')){
      $uT='users'; $uId='id';
      $uNm = colExists($pdo,$uT,'username') ? 'username'
           : (colExists($pdo,$uT,'name') ? 'name' : 'username');
      foreach($rows as &$r){
        $w = $pdo->prepare(
          "SELECT $uId AS id, $uNm AS username
             FROM $uT
            WHERE $uId = (SELECT winner_user_id FROM $tT WHERE $cId=?)"
        );
        $w->execute([(int)$r['id']]);
        $wu = $w->fetch(PDO::FETCH_ASSOC);
        $r['winners'] = $wu ? [ ['username'=>$wu['username']] ] : [];
        $r['wins']    = count($r['winners']);
      }
    } else {
      foreach($rows as &$r){ $r['winners']=[]; $r['wins']=0; }
    }

    echo json_encode([
      'ok'=>true,
      'items'=>$rows,
      'total'=>$total,
      'page'=>$page,
      'pages'=>max(1, (int)ceil($total/$limit)),
      'limit'=>$limit
    ]);
    exit;

  } catch(Throwable $e){
    error_log('[storico.php:list] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'db_error','detail'=>$e->getMessage()]); exit;
  }
}

// ---------- ROUND EVENTS ----------
if ($act==='round'){
  try{
    $id    = (int)($_GET['id'] ?? 0);
    $tid   = trim((string)($_GET['tid'] ?? ''));
    $round = max(1,(int)($_GET['round'] ?? 1));

    $eT   = 'tournament_events';
    $eId  = 'id';
    $eTid = 'tournament_id';
    $eR   = colExists($pdo,$eT,'round') ? 'round' : (colExists($pdo,$eT,'rnd') ? 'rnd' : 'round');

    // helper pick colonna esistente
    $pick = function(array $cands) use ($pdo, $eT){
      foreach ($cands as $c) { if (colExists($pdo,$eT,$c)) return $c; }
      return null;
    };

    // id squadre
    $eH = $pick(['home_team_id','home_id','team_home_id','home','home_team']) ?: 'home_team_id';
    $eA = $pick(['away_team_id','away_id','team_away_id','away','away_team']) ?: 'away_team_id';

    // punteggi opzionali (non li usiamo, ma li esponiamo se presenti)
    $eHs = $pick(['home_score','h_score','home_goals','score_home','score_h']);
    $eAs = $pick(['away_score','a_score','away_goals','score_away','score_a']);

    // winner id / esito testuale / codice esito
    $eWinIdCol      = $pick(['winner_team_id','win_team_id','winner_id','team_winner_id']);
    $eStatusTextCol = $pick(['status_text','status_label','state_text','state_label','result_text','outcome_text','esito_text']);
    $eResCodeCol    = $pick(['result_code','winner','result','outcome','status','state','esito']);

    // join teams se esiste
    $hasTeams = (bool)$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='teams'")->fetchColumn();
    $tTms = 'teams';

    // colonna code in tournaments
    $codeCol = colExists($pdo,'tournaments','code') ? 'code'
              : (colExists($pdo,'tournaments','tour_code') ? 'tour_code' : 'short_id');

    // SELECT dinamica
    $sel = [
      "e.$eId AS id",
      "e.$eH AS home_id",
      "e.$eA AS away_id",
    ];

    if ($hasTeams){
      $nameCol = colExists($pdo,$tTms,'name') ? 'name' : (colExists($pdo,$tTms,'title') ? 'title' : 'name');
      $logoCol = colExists($pdo,$tTms,'logo') ? 'logo' : (colExists($pdo,$tTms,'image') ? 'image' : null);
      $sel[] = "th.$nameCol AS home_name";
      $sel[] = "ta.$nameCol AS away_name";
      $sel[] = $logoCol ? "th.$logoCol AS home_logo" : "NULL AS home_logo";
      $sel[] = $logoCol ? "ta.$logoCol AS away_logo" : "NULL AS away_logo";
    } else {
      $sel[] = "' ' AS home_name";
      $sel[] = "' ' AS away_name";
      $sel[] = "NULL AS home_logo";
      $sel[] = "NULL AS away_logo";
    }

    $sel[] = $eHs ? "e.$eHs AS home_score" : "NULL AS home_score";
    $sel[] = $eAs ? "e.$eAs AS away_score" : "NULL AS away_score";
    $sel[] = $eWinIdCol      ? "e.$eWinIdCol AS winner_team_id" : "NULL AS winner_team_id";
    $sel[] = $eStatusTextCol ? "e.$eStatusTextCol AS status_text" : "NULL AS status_text";
    $sel[] = $eResCodeCol    ? "e.$eResCodeCol AS result_code" : "NULL AS result_code";

    $sql = "SELECT ".implode(", ", $sel)."
            FROM $eT e
            ".($hasTeams ? "LEFT JOIN $tTms th ON th.id = e.$eH
                           LEFT JOIN $tTms ta ON ta.id = e.$eA" : "")."
            WHERE e.$eTid ".($id>0 ? "= ?" : "IN (SELECT id FROM tournaments WHERE $codeCol = ?)")."
              AND e.$eR = ?
            ORDER BY e.$eId ASC";

    $st = $pdo->prepare($sql);
    $st->execute([$id>0 ? $id : $tid, $round]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // normalizza (winner/testo) per sicurezza
    $MAP = [
      'VOID'=>'Annullata','CANCELED'=>'Annullata','CANCELLED'=>'Annullata',
      'ABANDONED'=>'Sospesa','SUSPENDED'=>'Sospesa','INTERRUPTED'=>'Sospesa',
      'POSTPONED'=>'Rinviata','RINVIATA'=>'Rinviata',
      'DRAW'=>'Pareggio','D'=>'Pareggio','X'=>'Pareggio',
      'HOME'=>'Casa','H'=>'Casa','HOME_WIN'=>'Casa',
      'AWAY'=>'Trasferta','A'=>'Trasferta','AWAY_WIN'=>'Trasferta',
    ];

    foreach ($rows as &$r){
      $code = strtoupper(trim((string)($r['result_code'] ?? '')));
      $txt  = trim((string)($r['status_text'] ?? ''));

      if (empty($r['winner_team_id'])) {
        if (in_array($code, ['HOME','H','HOME_WIN'], true)) {
          $r['winner_team_id'] = (int)$r['home_id'];
        } elseif (in_array($code, ['AWAY','A','AWAY_WIN'], true)) {
          $r['winner_team_id'] = (int)$r['away_id'];
        } else {
          $r['winner_team_id'] = 0;
        }
      }

      if ($txt === '' && $code !== '') {
        $txt = $MAP[$code] ?? '';
      }

      $r['status_text'] = $txt;
      $r['result_code'] = $code;
    }
    unset($r);

    echo json_encode(['ok'=>true,'events'=>$rows]); exit;

  } catch(Throwable $e){
    error_log('[storico.php:round] '.$e->getMessage().' @ '.$e->getFile().':'.$e->Line());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'db_error','detail'=>$e->getMessage()]); exit;
  }
}

// ---------- CHOICES ----------
if ($act==='choices'){
  try{
    $id  = (int)($_GET['id'] ?? 0);
    $tid = trim((string)($_GET['tid'] ?? ''));
    $round = max(1,(int)($_GET['round'] ?? 1));

    $pT='tournament_picks'; $lT='tournament_lives'; $uT='users';
    $pLife = 'life_id';
    $pEid  = 'event_id';
    $pRid  = colExists($pdo,$pT,'round') ? 'round' : (colExists($pdo,$pT,'rnd') ? 'rnd' : 'round');
    $pTid  = colExists($pdo,$pT,'tournament_id') ? 'tournament_id' : null;
    $pTm   = 'team_id';

    $lId  = 'id';
    $lUid = colExists($pdo,$lT,'user_id') ? 'user_id' : (colExists($pdo,$lT,'uid') ? 'uid' : 'user_id');
    $lTid = 'tournament_id';

    $uId='id';
    $uNm= colExists($pdo,$uT,'username') ? 'username' : (colExists($pdo,$uT,'name') ? 'name' : 'username');
    $uAv= colExists($pdo,$uT,'avatar') ? 'avatar' : (colExists($pdo,$uT,'avatar_url') ? 'avatar_url' : (colExists($pdo,$uT,'photo') ? 'photo' : (colExists($pdo,$uT,'picture') ? 'picture' : 'NULL')));

    $where = "p.$pRid=? AND l.$lId=p.$pLife AND u.$uId=l.$lUid ".($pTid ? " AND p.$pTid=?" : " AND l.$lTid=?");

    $sql="SELECT p.$pTm AS team_id, u.$uNm AS username, ".($uAv!=='NULL'?"u.$uAv":"NULL")." AS avatar
          FROM $pT p JOIN $lT l ON l.$lId=p.$pLife JOIN $uT u ON u.$uId=l.$lUid
          WHERE $where
          ORDER BY u.$uNm ASC";
    $st=$pdo->prepare($sql);
    $st->execute([$round, ($id>0?$id:$tid)]);
    echo json_encode(['ok'=>true,'rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]); exit;
  } catch(Throwable $e){
    error_log('[storico.php:choices] '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'db_error','detail'=>$e->getMessage()]); exit;
  }
}

http_response_code(400);
echo json_encode(['ok'=>false,'error'=>'bad_action']);
