<?php
require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['uid']) || !(($_SESSION['role'] ?? 'USER')==='ADMIN' || (int)($_SESSION['is_admin'] ?? 0)===1)) {
  header('Location: /login.php'); exit;
}

function json($a){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($a); exit; }
function only_post(){ if ($_SERVER['REQUEST_METHOD']!=='POST'){ http_response_code(405); json(['ok'=>false,'error'=>'method']); } }

/* === Helpers === */
function genCode($len=6){ $n=random_int(0,36**$len-1); $b=strtoupper(base_convert($n,10,36)); return str_pad($b,$len,'0',STR_PAD_LEFT); }
function getFreeCode(PDO $pdo,$table,$col,$len=6){ for($i=0;$i<20;$i++){ $c=genCode($len); $st=$pdo->prepare("SELECT 1 FROM `$table` WHERE `$col`=? LIMIT 1"); $st->execute([$c]); if(!$st->fetch()) return $c; } throw new RuntimeException('code'); }
function getSortClause(array $whitelist, $sort, $dir, $default="1"){
  $s = $whitelist[$sort] ?? $default; $d = strtolower($dir)==='desc' ? 'DESC' : 'ASC'; return "$s $d";
}
function cdnBase(): string {
  $cdn = getenv('S3_CDN_BASE'); if ($cdn) return rtrim($cdn,'/');
  $endpoint = getenv('S3_ENDPOINT'); $bucket = getenv('S3_BUCKET');
  if ($endpoint && $bucket) return rtrim($endpoint,'/').'/'.$bucket;
  return ''; // nessun CDN
}
function userNameCols(PDO $pdo): array {
  static $cols = null;
  if ($cols===null){
    $arr = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users'")->fetchAll(PDO::FETCH_COLUMN);
    $fn = null; foreach (['nome','first_name','name'] as $c){ if (in_array($c,$arr,true)) { $fn=$c; break; } }
    $ln = null; foreach (['cognome','last_name','surname'] as $c){ if (in_array($c,$arr,true)) { $ln=$c; break; } }
    $cols = [$fn,$ln];
  }
  return $cols;
}

/**
 * Verifica se una colonna esiste nella tabella `media` (cache in RAM).
 */
function mediaColExists(PDO $pdo, string $col): bool {
  static $cache = null;
  if ($cache === null) {
    $cache = $pdo->query(
      "SELECT COLUMN_NAME
         FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE()
          AND TABLE_NAME='media'"
    )->fetchAll(PDO::FETCH_COLUMN);
    $cache = array_map('strtolower', $cache);
  }
  return in_array(strtolower($col), $cache, true);
}

/**
 * Inserisce una riga in `media` per un premio (solo le colonne che ESISTONO).
 * Ritorna l'id inserito se la tabella ha PK auto_increment `id`; altrimenti null.
 */
function mediaInsertForPrize(PDO $pdo, string $storageKey, int $prizeId, int $ownerId=0): ?int {
  // URL (se hai il CDN configurato)
  $cdn = cdnBase();
  $url = $cdn ? ($cdn . '/' . $storageKey) : '';

  // MIME inferito dall’estensione (fallback sensato per NOT NULL)
  $ext  = strtolower(pathinfo($storageKey, PATHINFO_EXTENSION));
  $mime = match($ext) {
    'jpg','jpeg' => 'image/jpeg',
    'png'        => 'image/png',
    'webp'       => 'image/webp',
    default      => 'application/octet-stream',
  };

  $cols = ['storage_key'];  $vals = ['?'];   $par = [$storageKey];

  // url (spesso NOT NULL)
  if (mediaColExists($pdo,'url')) {
    $cols[] = 'url';  $vals[] = '?';  $par[] = $url;
  }

  // mime (qui l’errore 1364: lo valorizziamo sempre se la colonna esiste)
  if (mediaColExists($pdo,'mime')) {
    $cols[] = 'mime';  $vals[] = '?';  $par[] = $mime;
  }

  // opzionali “di servizio”
  if (mediaColExists($pdo,'type'))      { $cols[]='type';      $vals[]='?';   $par[]='prize'; }
  if (mediaColExists($pdo,'owner_id'))  { $cols[]='owner_id';  $vals[]='?';   $par[]=$ownerId; }
  if (mediaColExists($pdo,'prize_id'))  { $cols[]='prize_id';  $vals[]='?';   $par[]=$prizeId; }
  if (mediaColExists($pdo,'created_at')){ $cols[]='created_at';$vals[]='NOW()'; }

  // se lo schema ha una colonna per la dimensione, mettiamo 0 come default
  if (mediaColExists($pdo,'size'))      { $cols[]='size';      $vals[]='?';   $par[] = 0; }
  if (mediaColExists($pdo,'filesize'))  { $cols[]='filesize';  $vals[]='?';   $par[] = 0; }

  $sql = "INSERT INTO media (".implode(',',$cols).") VALUES (".implode(',',$vals).")";
  $st  = $pdo->prepare($sql);
  $st->execute($par);

  return mediaColExists($pdo,'id') ? (int)$pdo->lastInsertId() : null;
}

/* === AJAX === */
if (isset($_GET['action'])) {
  $a = $_GET['action'];

  /* =============== PRIZES =============== */

  if ($a==='list_prizes') {
    $search = trim($_GET['search'] ?? '');
    $sort   = $_GET['sort'] ?? 'created';
    $dir    = $_GET['dir']  ?? 'desc';

    $order = getSortClause([
      'code'    => 'p.prize_code',
      'name'    => 'p.name',
      'coins'   => 'p.amount_coins',
      'status'  => 'p.is_enabled',
      'created' => 'p.created_at'
    ], $sort, $dir, 'p.created_at DESC');

    $w = []; $p = [];
    if ($search !== '') { $w[] = 'p.name LIKE ?'; $p[] = "%$search%"; }
    $where = $w ? ('WHERE '.implode(' AND ',$w)) : '';

$sql = "SELECT
          p.id,
          p.prize_code,
          p.name,
          p.description,
          p.amount_coins,
          p.is_enabled,
          p.created_at,

          /* 1) se collegato via FK uso quello */
          m.storage_key AS image_key,

          /* 2) fallback: prendo l’ultima media del premio */
          COALESCE(
            m.url,
            (SELECT mm.url
               FROM media mm
              WHERE (mm.prize_id = p.id OR mm.owner_id = p.id)
                AND (mm.type = 'prize' OR mm.type IS NULL)
              ORDER BY
                CASE WHEN mm.created_at IS NOT NULL THEN 0 ELSE 1 END,
                mm.created_at DESC,
                CASE WHEN mm.id IS NOT NULL THEN 0 ELSE 1 END,
                mm.id DESC
              LIMIT 1)
          ) AS image_url,

          /* 3) fallback per storage_key se la url non c’è */
          COALESCE(
            m.storage_key,
            (SELECT mm.storage_key
               FROM media mm
              WHERE (mm.prize_id = p.id OR mm.owner_id = p.id)
                AND (mm.type = 'prize' OR mm.type IS NULL)
              ORDER BY
                CASE WHEN mm.created_at IS NOT NULL THEN 0 ELSE 1 END,
                mm.created_at DESC,
                CASE WHEN mm.id IS NOT NULL THEN 0 ELSE 1 END,
                mm.id DESC
              LIMIT 1)
          ) AS image_key_fallback

        FROM prizes p
        LEFT JOIN media m ON m.id = p.image_media_id
        $where
        ORDER BY $order";

$st = $pdo->prepare($sql);
$st->execute($p);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

/* === Composer URL finale (immagine sempre visibile) === */
$cdn      = cdnBase();
$endpoint = getenv('S3_ENDPOINT');
$bucket   = getenv('S3_BUCKET');

foreach ($rows as &$r) {
  // 1) se c’è url già salvata in media.url la uso
  if (!empty($r['image_url'])) {
    $r['image_src'] = $r['image_url'];
    continue;
  }
  // 2) altrimenti provo con la storage_key (con CDN o endpoint)
  $k = $r['image_key'] ?: ($r['image_key_fallback'] ?? '');
  if ($k) {
    if ($cdn) {
      $r['image_src'] = rtrim($cdn, '/') . '/' . ltrim($k, '/');
    } elseif ($endpoint && $bucket) {
      $r['image_src'] = rtrim($endpoint, '/') . '/' . $bucket . '/' . ltrim($k, '/');
    } else {
      $r['image_src'] = '';
    }
  } else {
    $r['image_src'] = '';
  }
}
unset($r);

/* unico return */
json(['ok'=>true,'rows'=>$rows]);

  if ($a==='create_prize') {
    only_post();
    $name   = trim($_POST['name'] ?? '');
    $descr  = trim($_POST['description'] ?? '');
    $amount = (float)($_POST['amount_coins'] ?? 0);
    $imgKey = trim($_POST['image_storage_key'] ?? '');

    if ($name==='')   json(['ok'=>false,'error'=>'name_required']);
    if ($amount<0)    json(['ok'=>false,'error'=>'amount_invalid']);

    $pdo->beginTransaction();
    try {
      $prize_code = getFreeCode($pdo,'prizes','prize_code',6);
      $st = $pdo->prepare("INSERT INTO prizes (prize_code,name,description,amount_coins,is_enabled) VALUES (?,?,?,?,1)");
      $st->execute([$prize_code,$name,$descr,$amount]);
      $pid = (int)$pdo->lastInsertId();

$media_id = null;
if ($imgKey !== '') {
  $adminOwner = (int)($_SESSION['uid'] ?? 0);      // id admin loggato
  $media_id   = mediaInsertForPrize($pdo, $imgKey, $pid, $adminOwner);
  if ($media_id !== null && $media_id > 0) {
    $pdo->prepare("UPDATE prizes SET image_media_id=? WHERE id=?")->execute([$media_id,$pid]);
  }
}
      $pdo->commit();
      json(['ok'=>true,'id'=>$pid,'prize_code'=>$prize_code]);
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      json(['ok'=>false,'error'=>'db','detail'=>$e->getMessage()]);
    }
  }

  if ($a==='update_prize') {
    only_post();
    $pid    = (int)($_POST['prize_id'] ?? 0);
    if ($pid<=0) json(['ok'=>false,'error'=>'bad_id']);

    $name   = isset($_POST['name']) ? trim($_POST['name']) : null;
    $descr  = isset($_POST['description']) ? trim($_POST['description']) : null;
    $amount = isset($_POST['amount_coins']) ? (float)$_POST['amount_coins'] : null;
    $imgKey = trim($_POST['image_storage_key'] ?? '');

    $pdo->beginTransaction();
    try {
      if ($name!==null || $descr!==null || $amount!==null) {
        $sets=[]; $p=[];
        if ($name!==null)   { $sets[]='name=?';          $p[]=$name; }
        if ($descr!==null)  { $sets[]='description=?';   $p[]=$descr; }
        if ($amount!==null) { $sets[]='amount_coins=?';  $p[]=$amount; }
        if ($sets) {
          $p[]=$pid;
          $pdo->prepare("UPDATE prizes SET ".implode(',',$sets)." WHERE id=?")->execute($p);
        }
      }
if ($imgKey !== '') {
  $adminOwner = (int)($_SESSION['uid'] ?? 0);
  $media_id   = mediaInsertForPrize($pdo, $imgKey, $pid, $adminOwner);
  if ($media_id !== null && $media_id > 0) {
    $pdo->prepare("UPDATE prizes SET image_media_id=? WHERE id=?")->execute([$media_id,$pid]);
  }
}
      $pdo->commit();
      json(['ok'=>true]);
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      json(['ok'=>false,'error'=>'db','detail'=>$e->getMessage()]);
    }
  }

  if ($a==='toggle_prize') {
    only_post();
    $pid = (int)($_POST['prize_id'] ?? 0);
    if ($pid<=0) json(['ok'=>false,'error'=>'bad_id']);
    $pdo->prepare("UPDATE prizes SET is_enabled = CASE WHEN is_enabled=1 THEN 0 ELSE 1 END WHERE id=?")->execute([$pid]);
    $is = (int)$pdo->query("SELECT is_enabled FROM prizes WHERE id={$pid}")->fetchColumn();
    json(['ok'=>true,'is_enabled'=>$is]);
  }

  if ($a==='delete_prize') {
    only_post();
    $pid = (int)($_POST['prize_id'] ?? 0);
    if ($pid<=0) json(['ok'=>false,'error'=>'bad_id']);
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM prize_requests WHERE prize_id={$pid}")->fetchColumn();
    if ($cnt>0) json(['ok'=>false,'error'=>'has_requests']);
    try{
      $pdo->prepare("DELETE FROM prizes WHERE id=?")->execute([$pid]);
      json(['ok'=>true]);
    }catch(PDOException $e){
      json(['ok'=>false,'error'=>'db','detail'=>$e->getMessage()]);
    }
  }

  /* =============== REQUESTS =============== */

  if ($a==='list_requests') {
    $status = $_GET['status'] ?? 'requested'; // requested | accepted | rejected
    $search = trim($_GET['search'] ?? '');
    $sort   = $_GET['sort'] ?? 'requested_at';
    $dir    = $_GET['dir']  ?? 'desc';

    $allowedSort = [
      'req_code'     => 'r.req_code',
      'requested_at' => 'r.requested_at',
      'decided_at'   => 'r.decided_at',
      'username'     => 'u.username',
      'prize'        => 'p.name',
      'coins'        => 'p.amount_coins',
      'status'       => 'r.status'
    ];
    $order = getSortClause($allowedSort,$sort,$dir,'r.requested_at DESC');

    $w = []; $p = [];
    if (in_array($status,['requested','accepted','rejected'],true)) { $w[]='r.status=?'; $p[]=$status; }
    if ($search!==''){ $w[]='u.username LIKE ?'; $p[]="%$search%"; }
    $where = $w ? ('WHERE '.implode(' AND ',$w)) : '';

    $sql = "SELECT r.id, r.req_code, r.user_id, u.username, u.email, u.cell,
                   r.prize_id, p.prize_code, p.name AS prize_name, p.amount_coins,
                   r.status, r.requested_at, r.decided_at
            FROM prize_requests r
            JOIN users  u ON u.id = r.user_id
            JOIN prizes p ON p.id = r.prize_id
            $where
            ORDER BY $order";
    $st = $pdo->prepare($sql); $st->execute($p);
    json(['ok'=>true,'rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
  }

  if ($a==='decide_request') {
    only_post();
    $req_id = (int)($_POST['req_id'] ?? 0);
    $decision = $_POST['decision'] ?? '';
    $reason = trim($_POST['reason_admin'] ?? '');
    if (!in_array($decision,['accept','reject'],true)) json(['ok'=>false,'error'=>'bad_decision']);
    if ($reason==='') json(['ok'=>false,'error'=>'reason_required']);

    $admin_id = (int)($_SESSION['uid'] ?? 0);

    $pdo->beginTransaction();
    try {
      $row = $pdo->prepare("SELECT r.*, p.amount_coins, p.prize_code FROM prize_requests r JOIN prizes p ON p.id=r.prize_id WHERE r.id=? FOR UPDATE");
      $row->execute([$req_id]);
      $r = $row->fetch(PDO::FETCH_ASSOC);
      if (!$r) { $pdo->rollBack(); json(['ok'=>false,'error'=>'not_found']); }
      if ($r['status']!=='requested'){ $pdo->rollBack(); json(['ok'=>false,'error'=>'already_decided']); }

      if ($decision==='reject') {
        // rimborso coins
        $pdo->prepare("UPDATE users SET coins = COALESCE(coins,0) + ? WHERE id=?")->execute([(float)$r['amount_coins'], (int)$r['user_id']]);
        // log
        $pdo->prepare("INSERT INTO points_balance_log (user_id, delta, reason, admin_id) VALUES (?,?,?,?)")
            ->execute([(int)$r['user_id'], (float)$r['amount_coins'], 'PRIZE_REFUND '.$r['req_code'].' PRIZE:'.$r['prize_code'], $admin_id]);
        // aggiorna richiesta
        $pdo->prepare("UPDATE prize_requests SET status='rejected', reason_admin=?, decided_at=NOW() WHERE id=?")->execute([$reason,$req_id]);

      } else {
        // accepted: nessun movimento (già scalati alla richiesta)
        $pdo->prepare("UPDATE prize_requests SET status='accepted', reason_admin=?, decided_at=NOW() WHERE id=?")->execute([$reason,$req_id]);
      }

      $pdo->commit();
      json(['ok'=>true]);
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      json(['ok'=>false,'error'=>'db','detail'=>$e->getMessage()]);
    }
  }

  if ($a==='user_detail') {
    $uid = (int)($_GET['user_id'] ?? 0);
    $req = (int)($_GET['req_id'] ?? 0);
    if ($uid<=0) json(['ok'=>false,'error'=>'bad_id']);

    [$fn,$ln] = userNameCols($pdo);
    $sql = "SELECT id, username, email, cell".
           ($fn ? ", `$fn` AS nome" : ", NULL AS nome").
           ($ln ? ", `$ln` AS cognome" : ", NULL AS cognome").
           " FROM users WHERE id=?";
    $st = $pdo->prepare($sql); $st->execute([$uid]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) json(['ok'=>false,'error'=>'user_not_found']);

    $reqData = null;
    if ($req>0){
      $r = $pdo->prepare("SELECT ship_stato, ship_citta, ship_comune, ship_provincia, ship_via, ship_civico, ship_cap FROM prize_requests WHERE id=? AND user_id=?");
      $r->execute([$req,$uid]); $reqData = $r->fetch(PDO::FETCH_ASSOC);
    }
    json(['ok'=>true,'user'=>$u,'shipping'=>$reqData]);
  }

  http_response_code(400); json(['ok'=>false,'error'=>'unknown_action']);
}

/* ===== VIEW ===== */
$page_css='/pages-css/admin-dashboard.css';
$CDN_BASE = cdnBase();
include __DIR__ . '/../../partials/head.php';
include __DIR__ . '/../../partials/header_admin.php';
?>
<style>
  .prz-page .card{ margin-bottom:16px; }
  .prz-topbar{ display:flex; align-items:center; gap:10px; justify-content:space-between; margin-bottom:12px; }
  .searchbox{ min-width:260px; }
  .chip{ padding:4px 10px; border-radius:9999px; border:1px solid var(--c-border); }
  .chip.on{ border-color:#27ae60; color:#a7e3bf; }
  .chip.off{ border-color:#ff8a8a; color:#ff8a8a; }
  .table th.sortable{ cursor:pointer; user-select:none; }
  .table th.sortable .arrow{ opacity:.5; font-size:10px; }
  .img-thumb{ width:52px; height:52px; object-fit:cover; border-radius:8px; border:1px solid var(--c-border); }
  .modal[aria-hidden="true"]{ display:none; } .modal{ position:fixed; inset:0; z-index:60; }
  .modal-open{ overflow:hidden; }
  .modal-backdrop{ position:absolute; inset:0; background:rgba(0,0,0,.5); }
  .modal-card{ position:relative; z-index:61; width:min(820px,96vw); background:var(--c-bg); border:1px solid var(--c-border); border-radius:16px; margin:6vh auto 0; padding:0; box-shadow:0 16px 48px rgba(0,0,0,.5); max-height:86vh; display:flex; flex-direction:column; }
  .modal-head{ display:flex; align-items:center; gap:10px; padding:12px 16px; border-bottom:1px solid var(--c-border); }
  .modal-x{ margin-left:auto; background:transparent; border:0; color:#fff; font-size:24px; cursor:pointer; }
  .modal-body{ padding:16px; overflow:auto; }
  .modal-foot{ display:flex; justify-content:flex-end; gap:8px; padding:12px 16px; border-top:1px solid var(--c-border); }
  .grid2{ display:grid; grid-template-columns:1fr 1fr; gap:16px; } @media (max-width:860px){ .grid2{ grid-template-columns:1fr; } }
</style>

<main class="prz-page">
  <section class="section">
    <div class="container">
      <h1>Premi</h1>

      <!-- ====== Premi disponibili ====== -->
      <div class="card">
        <div class="prz-topbar">
          <h2 class="card-title">Premi disponibili</h2>
          <div style="display:flex;gap:8px;align-items:center;">
            <input type="search" class="input light searchbox" id="qPrize" placeholder="Cerca premio…">
            <button class="btn btn--primary" id="btnNewPrize">Nuovo premio</button>
          </div>
        </div>
        <div class="table-wrap">
          <table class="table" id="tblPrizes">
            <thead>
              <tr>
                <th class="sortable" data-sort="code">Codice <span class="arrow">↕</span></th>
                <th>Foto</th>
                <th class="sortable" data-sort="name">Nome <span class="arrow">↕</span></th>
                <th class="sortable" data-sort="coins">Arena Coins <span class="arrow">↕</span></th>
                <th class="sortable" data-sort="status">Stato <span class="arrow">↕</span></th>
                <th class="sortable" data-sort="created">Creato il <span class="arrow">↕</span></th>
                <th>Azioni</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      </div>

      <!-- ====== Premi richiesti (pendenti) ====== -->
      <div class="card">
        <div class="prz-topbar">
          <h2 class="card-title">Premi richiesti</h2>
          <input type="search" class="input light searchbox" id="qReq" placeholder="Cerca per username…">
        </div>
        <div class="table-wrap">
          <table class="table" id="tblReq">
            <thead>
              <tr>
                <th class="sortable" data-sort="req_code">Codice richiesta <span class="arrow">↕</span></th>
                <th class="sortable" data-sort="username">User <span class="arrow">↕</span></th>
                <th class="sortable" data-sort="prize">Premio <span class="arrow">↕</span></th>
                <th class="sortable" data-sort="coins">Arena Coins <span class="arrow">↕</span></th>
                <th class="sortable" data-sort="requested_at">Richiesto il <span class="arrow">↕</span></th>
                <th>Azioni</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      </div>

      <!-- ====== Storico richieste (accettate/rifiutate) ====== -->
      <div class="card">
        <div class="prz-topbar">
          <h2 class="card-title">Storico richieste</h2>
          <input type="search" class="input light searchbox" id="qHist" placeholder="Cerca per username…">
        </div>
        <div class="table-wrap">
          <table class="table" id="tblHist">
            <thead>
              <tr>
                <th class="sortable" data-sort="req_code">Codice richiesta <span class="arrow">↕</span></th>
                <th class="sortable" data-sort="username">User <span class="arrow">↕</span></th>
                <th class="sortable" data-sort="prize">Premio <span class="arrow">↕</span></th>
                <th class="sortable" data-sort="coins">Arena Coins <span class="arrow">↕</span></th>
                <th class="sortable" data-sort="requested_at">Richiesto il <span class="arrow">↕</span></th>
                <th class="sortable" data-sort="status">Esito <span class="arrow">↕</span></th>
                <th>Motivo</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      </div>

      <!-- ===== Modale Crea/Modifica Premio ===== -->
      <div class="modal" id="mdPrize" aria-hidden="true">
        <div class="modal-backdrop" data-close></div>
        <div class="modal-card">
          <div class="modal-head">
            <h3 id="mdPrizeTitle">Nuovo premio</h3>
            <button class="modal-x" data-close>&times;</button>
          </div>
          <div class="modal-body">
            <form id="fPrize" novalidate>
              <input type="hidden" id="prize_id">
              <div class="grid2">
                <div class="field">
                  <label class="label">Nome *</label>
                  <input class="input light" id="p_name" required>
                </div>
                <div class="field">
                  <label class="label">Arena Coins *</label>
                  <input class="input light" id="p_amount" type="number" step="0.01" min="0" required>
                </div>
                <div class="field" style="grid-column:span 2;">
                  <label class="label">Descrizione</label>
                  <textarea class="input light" id="p_descr" rows="3"></textarea>
                </div>
                <div class="field">
                  <label class="label">Immagine prodotto</label>
                  <input type="file" id="p_image" accept="image/*">
                  <input type="hidden" id="p_image_key">
                  <div style="margin-top:8px;"><img id="p_preview" class="img-thumb" alt="" style="display:none;"></div>
                </div>
              </div>
            </form>
          </div>
          <div class="modal-foot">
            <button class="btn btn--outline" data-close>Annulla</button>
            <button class="btn btn--primary" id="p_save">Salva</button>
          </div>
        </div>
      </div>

      <!-- ===== Modale Motivazione decisione ===== -->
      <div class="modal" id="mdReason" aria-hidden="true">
        <div class="modal-backdrop" data-close></div>
        <div class="modal-card" style="max-width:620px;">
          <div class="modal-head">
            <h3 id="mdReasonTitle">Motivazione</h3>
            <button class="modal-x" data-close>&times;</button>
          </div>
          <div class="modal-body">
            <form id="fReason">
              <input type="hidden" id="r_req_id">
              <input type="hidden" id="r_decision">
              <div class="field">
                <label class="label">Inserisci motivazione *</label>
                <textarea class="input light" id="r_text" rows="4" required></textarea>
              </div>
            </form>
          </div>
          <div class="modal-foot">
            <button class="btn btn--outline" data-close>Annulla</button>
            <button class="btn btn--primary" id="r_save">Conferma</button>
          </div>
        </div>
      </div>

      <!-- ===== Modale Dettaglio utente ===== -->
      <div class="modal" id="mdUser" aria-hidden="true">
        <div class="modal-backdrop" data-close></div>
        <div class="modal-card" style="max-width:680px;">
          <div class="modal-head">
            <h3>Dettaglio utente</h3>
            <button class="modal-x" data-close>&times;</button>
          </div>
          <div class="modal-body" id="userDetail"></div>
          <div class="modal-foot">
            <button class="btn btn--primary" data-close>Chiudi</button>
          </div>
        </div>
      </div>

    </div>
  </section>
</main>

<?php include __DIR__ . '/../../partials/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', ()=>{
  const $ = s=>document.querySelector(s);
  const $$= (s,p=document)=>[...p.querySelectorAll(s)];
  const CDN_BASE = <?= json_encode($CDN_BASE) ?>;

  /* ====== Helpers Modal ====== */
  function openModal(id){ $(id).setAttribute('aria-hidden','false'); document.body.classList.add('modal-open'); }
  function closeModal(id){ $(id).setAttribute('aria-hidden','true'); document.body.classList.remove('modal-open'); }

  /* ====== PRIZES ====== */
  let prizeSort='created', prizeDir='desc', prizeSearch='';
  async function loadPrizes(){
    const u = new URL('?action=list_prizes', location.href);
    u.searchParams.set('sort', prizeSort);
    u.searchParams.set('dir', prizeDir);
    if (prizeSearch) u.searchParams.set('search', prizeSearch);
    const r = await fetch(u, {cache:'no-store'}); const j = await r.json();
    const tb = $('#tblPrizes tbody'); tb.innerHTML='';
    if (!j.ok) { tb.innerHTML = '<tr><td colspan="7">Errore caricamento</td></tr>'; return; }
    j.rows.forEach(row=>{
const src = (row.image_src && row.image_src.trim())
          ? row.image_src.trim()
          : (row.image_url && row.image_url.trim())
              ? row.image_url.trim()
              : (row.image_key ? (CDN_BASE ? (CDN_BASE + '/' + row.image_key) : '') : '');

const img = src
  ? `<img class="img-thumb" src="${src}" alt="">`
  : '<div class="img-thumb" style="display:inline-block;background:#222;"></div>';
      const stateChip = `<button type="button" class="chip ${row.is_enabled==1?'on':'off'}" data-toggle="${row.id}">${row.is_enabled==1?'Abilitato':'Disabilitato'}</button>`;
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td><code>${row.prize_code}</code></td>
        <td>${img}</td>
        <td>${row.name}</td>
        <td>${Number(row.amount_coins).toFixed(2)}</td>
        <td>${stateChip}</td>
        <td>${new Date(row.created_at).toLocaleString()}</td>
        <td class="actions-cell">
          <button class="btn btn--outline btn--sm" data-edit="${row.id}" data-name="${row.name}" data-descr="${row.description||''}" data-amount="${row.amount_coins}" data-img="${row.image_key||''}">Modifica</button>
          <button class="btn btn--outline btn--sm btn-danger" data-del="${row.id}">Elimina</button>
        </td>`;
      tb.appendChild(tr);
    });
  }

  // sort clicks
  $('#tblPrizes thead').addEventListener('click', (e)=>{
    const th = e.target.closest('[data-sort]'); if (!th) return;
    const s = th.getAttribute('data-sort');
    if (prizeSort===s) prizeDir = (prizeDir==='asc'?'desc':'asc'); else { prizeSort=s; prizeDir='asc'; }
    loadPrizes();
  });
  $('#qPrize').addEventListener('input', (e)=>{ prizeSearch = e.target.value.trim(); loadPrizes(); });

  // toggle prize
  $('#tblPrizes').addEventListener('click', async (e)=>{
    const b = e.target.closest('button'); if (!b) return;
    if (b.hasAttribute('data-toggle')){
      const id=b.getAttribute('data-toggle');
      const r=await fetch('?action=toggle_prize',{method:'POST', body:new URLSearchParams({prize_id:id})});
      const j=await r.json(); if(!j.ok){ alert('Errore toggle'); return; }
      b.classList.toggle('on', j.is_enabled==1);
      b.classList.toggle('off', j.is_enabled!=1);
      b.textContent = j.is_enabled==1 ? 'Abilitato' : 'Disabilitato';
      return;
    }
    if (b.hasAttribute('data-edit')){
      const id   = b.getAttribute('data-edit');
      const name = b.getAttribute('data-name');
      const descr= b.getAttribute('data-descr') || '';
      const amt  = b.getAttribute('data-amount');
      const img  = b.getAttribute('data-img') || '';
      $('#prize_id').value = id;
      $('#p_name').value   = name;
      $('#p_descr').value  = descr;
      $('#p_amount').value = amt;
      $('#p_image').value  = '';
      $('#p_image_key').value = '';
      const prev = $('#p_preview');
      if (img && CDN_BASE){ prev.src = CDN_BASE + '/' + img; prev.style.display='inline-block'; } else { prev.style.display='none'; }
      $('#mdPrizeTitle').textContent = 'Modifica premio';
      openModal('#mdPrize'); return;
    }
    if (b.hasAttribute('data-del')){
      const id=b.getAttribute('data-del');
      if (!confirm('Eliminare definitivamente il premio?')) return;
      const r=await fetch('?action=delete_prize',{method:'POST', body:new URLSearchParams({prize_id:id})});
      const j=await r.json(); if(!j.ok){ alert(j.error==='has_requests'?'Impossibile: esistono richieste collegate':'Errore eliminazione'); return; }
      loadPrizes(); return;
    }
  });

  // new prize
  $('#btnNewPrize').addEventListener('click', ()=>{
    $('#prize_id').value='';
    $('#p_name').value=''; $('#p_amount').value=''; $('#p_descr').value='';
    $('#p_image').value=''; $('#p_image_key').value='';
    $('#p_preview').style.display='none';
    $('#mdPrizeTitle').textContent='Nuovo premio';
    openModal('#mdPrize');
  });

  /* === Upload immagine premio (server-side, no CORS) === */
document.getElementById('p_image').addEventListener('change', async (e) => {
  const f = e.target.files && e.target.files[0];
  if (!f) return;

  // guard-rails
  if (!/^image\//.test(f.type)) { alert('Seleziona un\'immagine'); e.target.value=''; return; }
  if (f.size > 8 * 1024 * 1024) { alert('Immagine troppo grande (max 8 MB)'); e.target.value=''; return; }

  try{
    const fd = new FormData();
    fd.append('type', 'prize');
    fd.append('file', f, f.name);

    const rsp = await fetch('/api/upload_r2.php', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    });
    const j = await rsp.json();
    if (!j || !j.ok || !j.key) throw new Error('upload');

    // Salva la storage key per create/update (il PHP crea la row in media)
    document.getElementById('p_image_key').value = j.key;

    // Anteprima
    const img = document.getElementById('p_preview');
    img.src = j.url || URL.createObjectURL(f);
    img.style.display = 'block';
  } catch(err){
    console.error('[prize image upload]', err);
    alert('Upload immagine fallito');
    document.getElementById('p_image_key').value = '';
  }
});
  
// save prize (con debug robusto, blocco completo e bilanciato)
$('#p_save').addEventListener('click', async ()=>{
  try{
    const id  = $('#prize_id').value.trim();
    const nm  = $('#p_name').value.trim();
    const amt = $('#p_amount').value.trim();
    const ds  = $('#p_descr').value.trim();
    const ik  = $('#p_image_key').value.trim();

    if (!nm || amt===''){
      alert('Nome e Arena Coins sono obbligatori');
      return;
    }

    const data = new URLSearchParams({
      name: nm,
      amount_coins: amt,
      description: ds
    });
    if (ik) data.append('image_storage_key', ik);

    let url = '?action=create_prize';
    if (id){
      url = '?action=update_prize';
      data.append('prize_id', id);
    }

    const r = await fetch(url, { method:'POST', body:data });
    // NB: gestisci eventuale parsing error senza far esplodere tutto
    let j = null;
    try {
      j = await r.json();
    } catch(parseErr){
      console.error('save prize parse error:', parseErr);
      const txt = await r.text().catch(()=> '');
      alert('Errore salvataggio (risposta non JSON): ' + txt.slice(0,200));
      return;
    }

    if (!j || !j.ok){
      console.error('save prize error:', j);
      alert('Errore salvataggio: ' + (j && (j.detail || j.error) ? (j.detail || j.error) : ''));
      return;
    }

    // chiudi modale e ricarica lista
    closeModal('#mdPrize');
    loadPrizes();
  }catch(err){
    console.error('save prize fatal:', err);
    alert('Errore salvataggio (eccezione): ' + (err && err.message ? err.message : ''));
  }
});

  // close modals
  $$('#mdPrize [data-close], #mdReason [data-close], #mdUser [data-close]').forEach(b=>b.addEventListener('click', e=>{
    closeModal('#'+b.closest('.modal').id);
  }));
  $$('#mdPrize .modal-backdrop, #mdReason .modal-backdrop, #mdUser .modal-backdrop').forEach(b=>b.addEventListener('click', e=>{
    closeModal('#'+b.closest('.modal').id);
  }));

  /* ====== REQUESTS (requested) ====== */
  let reqSort='requested_at', reqDir='desc', reqSearch='';
  async function loadRequests(){
    const u = new URL('?action=list_requests', location.href);
    u.searchParams.set('status','requested');
    u.searchParams.set('sort', reqSort);
    u.searchParams.set('dir', reqDir);
    if (reqSearch) u.searchParams.set('search', reqSearch);
    const r = await fetch(u); const j = await r.json();
    const tb = $('#tblReq tbody'); tb.innerHTML='';
    if (!j.ok){ tb.innerHTML='<tr><td colspan="6">Errore</td></tr>'; return; }
    j.rows.forEach(row=>{
      const tr=document.createElement('tr');
      tr.innerHTML = `
        <td><code>${row.req_code}</code></td>
        <td><a href="#" data-user="${row.user_id}" data-req="${row.id}">${row.username}</a></td>
        <td><span title="${row.prize_code}">${row.prize_name}</span></td>
        <td>${Number(row.amount_coins).toFixed(2)}</td>
        <td>${new Date(row.requested_at).toLocaleString()}</td>
        <td class="actions-cell">
          <button class="btn btn--primary btn--sm" data-accept="${row.id}">Accetta</button>
          <button class="btn btn--outline btn--sm btn-danger" data-reject="${row.id}">Rifiuta</button>
        </td>`;
      tb.appendChild(tr);
    });
  }
  $('#tblReq thead').addEventListener('click', (e)=>{
    const th=e.target.closest('[data-sort]'); if(!th) return;
    const s=th.getAttribute('data-sort');
    if (reqSort===s) reqDir=(reqDir==='asc'?'desc':'asc'); else{ reqSort=s; reqDir='asc'; }
    loadRequests();
  });
  $('#qReq').addEventListener('input', e=>{ reqSearch=e.target.value.trim(); loadRequests(); });

  // open user detail
  $('#tblReq').addEventListener('click', async (e)=>{
    const a=e.target.closest('a[data-user]'); if (a){
      e.preventDefault();
      const uid=a.getAttribute('data-user'); const rid=a.getAttribute('data-req');
      const u= new URL('?action=user_detail', location.href); u.searchParams.set('user_id',uid); u.searchParams.set('req_id',rid);
      const r=await fetch(u); const j=await r.json(); if(!j.ok){ alert('Errore caricamento utente'); return; }
      const udiv = $('#userDetail');
      const udata = j.user || {};
      const sh = j.shipping || {};
      udiv.innerHTML = `
        <h4>Utente</h4>
        <div><strong>Username:</strong> ${udata.username||'-'}</div>
        <div><strong>Nome:</strong> ${udata.nome||'-'} <strong>Cognome:</strong> ${udata.cognome||'-'}</div>
        <div><strong>Email:</strong> ${udata.email||'-'} — <strong>Telefono:</strong> ${udata.cell||'-'}</div>
        <hr style="border-color:var(--c-border)">
        <h4>Indirizzo spedizione</h4>
        <div>${sh.ship_via||'-'} ${sh.ship_civico||''}</div>
        <div>${sh.ship_cap||''} ${sh.ship_citta||''} (${sh.ship_provincia||''})</div>
        <div>${sh.ship_comune||''} — ${sh.ship_stato||''}</div>
      `;
      openModal('#mdUser'); return;
    }
    const b=e.target.closest('button'); if(!b) return;
    if (b.hasAttribute('data-accept') || b.hasAttribute('data-reject')){
      const req_id = b.getAttribute('data-accept') || b.getAttribute('data-reject');
      $('#r_req_id').value = req_id;
      $('#r_decision').value = b.hasAttribute('data-accept') ? 'accept' : 'reject';
      $('#r_text').value = '';
      $('#mdReasonTitle').textContent = b.hasAttribute('data-accept') ? 'Motivazione accettazione' : 'Motivazione rifiuto';
      openModal('#mdReason');
      return;
    }
  });

  // salva decisione
  $('#r_save').addEventListener('click', async ()=>{
    const id = $('#r_req_id').value;
    const dc = $('#r_decision').value;
    const rs = $('#r_text').value.trim();
    if (!rs){ alert('Motivazione obbligatoria'); return; }
    const r = await fetch('?action=decide_request',{method:'POST', body:new URLSearchParams({req_id:id, decision:dc, reason_admin:rs})});
    const j = await r.json(); if(!j.ok){ alert('Errore salvataggio decisione'); return; }
    closeModal('#mdReason'); loadRequests(); loadHistory();
  });

  /* ====== HISTORY (accepted/rejected) ====== */
  let histSort='decided_at', histDir='desc', histSearch='';
  async function loadHistory(){
    // riuso list_requests con status accepted e rejected? Facciamo due chiamate e uniamo? Meglio: due call e append.
    // Per semplicità usiamo due chiamate e le uniamo in client.
    const datasets = [];
    for (const st of ['accepted','rejected']){
      const u=new URL('?action=list_requests', location.href);
      u.searchParams.set('status', st);
      u.searchParams.set('sort', histSort);
      u.searchParams.set('dir', histDir);
      if (histSearch) u.searchParams.set('search', histSearch);
      const r=await fetch(u); const j=await r.json(); if (j.ok) datasets.push(...j.rows);
    }
    const tb = $('#tblHist tbody'); tb.innerHTML='';
    // Ordina lato client secondo histSort/histDir (perché sopra abbiamo due dataset)
    const key = histSort;
    datasets.sort((a,b)=>{
      let va, vb;
      if (key==='username'){ va=a.username||''; vb=b.username||''; }
      else if (key==='coins'){ va=+a.amount_coins; vb=+b.amount_coins; }
      else if (key==='prize'){ va=a.prize_name||''; vb=b.prize_name||''; }
      else if (key==='req_code'){ va=a.req_code||''; vb=b.req_code||''; }
      else if (key==='requested_at'){ va=new Date(a.requested_at); vb=new Date(b.requested_at); }
      else if (key==='status'){ va=a.status; vb=b.status; }
      else { va=new Date(a.decided_at||a.requested_at); vb=new Date(b.decided_at||b.requested_at); }
      if (va<vb) return histDir==='asc'?-1:1;
      if (va>vb) return histDir==='asc'?1:-1;
      return 0;
    });
    datasets.forEach(row=>{
      const tr=document.createElement('tr');
      tr.innerHTML = `
        <td><code>${row.req_code}</code></td>
        <td><a href="#" data-user="${row.user_id}" data-req="${row.id}">${row.username}</a></td>
        <td><span title="${row.prize_code}">${row.prize_name}</span></td>
        <td>${Number(row.amount_coins).toFixed(2)}</td>
        <td>${new Date(row.requested_at).toLocaleString()}</td>
        <td>${row.status==='accepted' ? 'Accettato' : 'Rifiutato'}</td>
        <td><span title="Motivazione">${row.reason_admin?row.reason_admin:'-'}</span></td>`;
      tb.appendChild(tr);
    });
  }
  $('#tblHist thead').addEventListener('click', (e)=>{
    const th=e.target.closest('[data-sort]'); if(!th) return;
    const s=th.getAttribute('data-sort');
    if (histSort===s) histDir=(histDir==='asc'?'desc':'asc'); else{ histSort=s; histDir='asc'; }
    loadHistory();
  });
  $('#qHist').addEventListener('input', e=>{ histSearch=e.target.value.trim(); loadHistory(); });

  // inizializza
  loadPrizes(); loadRequests(); loadHistory();
});
</script>
