<?php
require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['uid']) || !(($_SESSION['role'] ?? 'USER')==='ADMIN' || (int)($_SESSION['is_admin'] ?? 0)===1)) {
  header('Location: /login.php'); exit;
}

function json($a){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($a); exit; }
function only_post(){ if ($_SERVER['REQUEST_METHOD']!=='POST'){ http_response_code(405); json(['ok'=>false,'error'=>'method']); } }
function only_get(){ if ($_SERVER['REQUEST_METHOD']!=='GET'){ http_response_code(405); json(['ok'=>false,'error'=>'method']); } }

/* === Helpers === */
function genCode($len=6){ $n=random_int(0,36**$len-1); $b=strtoupper(base_convert($n,10,36)); return str_pad($b,$len,'0',STR_PAD_LEFT); }
function getFreeCode(PDO $pdo,$table,$col,$len=6){ for($i=0;$i<16;$i++){ $c=genCode($len); $st=$pdo->prepare("SELECT 1 FROM {$table} WHERE {$col}=? LIMIT 1"); $st->execute([$c]); if(!$st->fetch()) return $c; } throw new RuntimeException('code'); }

/* === NEW helpers per robustezza commissioni === */
function tableExists(PDO $pdo, string $t): bool {
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
  $q->execute([$t]); return (bool)$q->fetchColumn();
}
function columnExists(PDO $pdo, string $t, string $c): bool {
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $q->execute([$t,$c]); return (bool)$q->fetchColumn();
}

/* === AJAX === */
if (isset($_GET['action'])) {
  $a = $_GET['action'];

  /* LIST: elenco punti */
  if ($a==='list_points') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0'); header('Pragma: no-cache');
    $sql = "SELECT u.id AS user_id, u.username, u.email, u.cell AS phone, u.is_active, u.coins,
                   p.indirizzo_legale, p.rake_pct, p.point_code
            FROM users u
            JOIN points p ON p.user_id=u.id
            WHERE u.role='PUNTO'
            ORDER BY u.username ASC";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    json(['ok'=>true,'rows'=>$rows]);
  }

  /* === Commissioni – dashboard per TUTTI i punti (anche se a 0) === */
  if ($a==='list_commission_dashboard') {
    only_get();

    $curYm = $pdo->query("SELECT DATE_FORMAT(CURDATE(), '%Y-%m')")->fetchColumn();

    $hasLedger   = tableExists($pdo, 'point_commission_monthly');
    if (!$hasLedger) {
      $sql0 = "SELECT u.id AS user_id, u.username,
                      0.00 AS total_generated,
                      0.00 AS to_pay,
                      0.00 AS waiting_invoice,
                      0.00 AS current_month
               FROM users u
               JOIN points p ON p.user_id=u.id
               WHERE u.role='PUNTO'
               ORDER BY u.username ASC";
      $rows = $pdo->query($sql0)->fetchAll(PDO::FETCH_ASSOC);
      json(['ok'=>true,'rows'=>$rows,'period'=>$curYm]);
    }

    /* >>> MODIFICA: supporto sia invoice_ok_at (timestamp) sia invoice_ok (boolean) <<< */
    $hasInvAt    = columnExists($pdo,'point_commission_monthly','invoice_ok_at');
    $hasInvBool  = columnExists($pdo,'point_commission_monthly','invoice_ok');
    $hasPaidAt   = columnExists($pdo,'point_commission_monthly','paid_at');

    if     ($hasInvAt && $hasInvBool) $exprOK = "(m.invoice_ok_at IS NOT NULL OR COALESCE(m.invoice_ok,0)=1)";
    elseif ($hasInvAt)                $exprOK = "(m.invoice_ok_at IS NOT NULL)";
    elseif ($hasInvBool)              $exprOK = "(COALESCE(m.invoice_ok,0)=1)";
    else                              $exprOK = "0";

    $condNotPaid = $hasPaidAt ? "m.paid_at IS NULL AND " : "";

    $sql = "
      SELECT
        u.id AS user_id,
        u.username,
        COALESCE(SUM(m.amount_coins), 0) AS total_generated,
        COALESCE(SUM(CASE WHEN $condNotPaid m.period_ym < ? AND $exprOK THEN m.amount_coins ELSE 0 END), 0) AS to_pay,
        COALESCE(SUM(CASE WHEN $condNotPaid m.period_ym < ? AND NOT($exprOK) THEN m.amount_coins ELSE 0 END), 0) AS waiting_invoice,
        COALESCE(SUM(CASE WHEN m.period_ym = ? THEN m.amount_coins ELSE 0 END), 0) AS current_month
      FROM users u
      JOIN points p ON p.user_id = u.id
      LEFT JOIN point_commission_monthly m ON m.point_user_id = u.id
      WHERE u.role = 'PUNTO'
      GROUP BY u.id, u.username
      ORDER BY u.username ASC
    ";
    $st = $pdo->prepare($sql);
    $st->execute([$curYm,$curYm,$curYm]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    json(['ok'=>true,'rows'=>$rows,'period'=>$curYm]);
  }

  /* === NEW: Storico commissioni (modale) === */
  if ($a==='commission_history') {
    only_get();
    $uid = (int)($_GET['point_user_id'] ?? 0);
    if ($uid<=0) json(['ok'=>false,'error'=>'bad_uid']);

    $uname = '';
    $q=$pdo->prepare("SELECT username FROM users WHERE id=? AND role='PUNTO' LIMIT 1");
    $q->execute([$uid]); $uname = (string)($q->fetchColumn() ?: '');

    $curYm = $pdo->query("SELECT DATE_FORMAT(CURDATE(), '%Y-%m')")->fetchColumn();

    if (!tableExists($pdo,'point_commission_monthly')) {
      json(['ok'=>true,'username'=>$uname,'rows'=>[],'has_invoice_ok'=>false,'has_paid_at'=>false,'curYm'=>$curYm]);
    }

    /* >>> MODIFICA: seleziono sia invoice_ok_at sia invoice_ok, se esistono <<< */
    $hasInvAt    = columnExists($pdo,'point_commission_monthly','invoice_ok_at');
    $hasInvBool  = columnExists($pdo,'point_commission_monthly','invoice_ok');
    $hasPaidAt   = columnExists($pdo,'point_commission_monthly','paid_at');
    $hasCalcAt   = columnExists($pdo,'point_commission_monthly','calculated_at');

    $selInvAt    = $hasInvAt   ? "invoice_ok_at"                        : "NULL AS invoice_ok_at";
    $selInvBool  = $hasInvBool ? "COALESCE(invoice_ok,0) AS invoice_ok" : "NULL AS invoice_ok";
    $selPaid     = $hasPaidAt  ? "paid_at"                              : "NULL AS paid_at";
    $selCalc     = $hasCalcAt  ? "calculated_at"                        : "NULL AS calculated_at";

    $sql = "SELECT period_ym, amount_coins, $selInvAt, $selInvBool, $selPaid, $selCalc
            FROM point_commission_monthly
            WHERE point_user_id=?
            ORDER BY period_ym DESC";
    $st=$pdo->prepare($sql); $st->execute([$uid]); $rows=$st->fetchAll(PDO::FETCH_ASSOC);

    json(['ok'=>true,'username'=>$uname,'rows'=>$rows,'has_invoice_ok'=>($hasInvAt||$hasInvBool),'has_paid_at'=>$hasPaidAt,'curYm'=>$curYm]);
  }

  /* === NEW: Segna / Annulla “Fattura OK” === */
  if ($a==='commission_invoice_ok') {
    only_post();
    $uid = (int)($_POST['point_user_id'] ?? 0);
    $ym  = trim($_POST['period_ym'] ?? '');
    $val = (int)($_POST['value'] ?? 1); $val = $val ? 1 : 0;

    if ($uid<=0 || !preg_match('/^\d{4}-\d{2}$/',$ym)) json(['ok'=>false,'error'=>'bad_params']);
    if (!tableExists($pdo,'point_commission_monthly')) {
      json(['ok'=>false,'error'=>'schema','detail'=>'Manca la tabella point_commission_monthly']);
    }

    /* >>> MODIFICA: scrivo invoice_ok_at se c’è; altrimenti invoice_ok boolean <<< */
    $curYm     = $pdo->query("SELECT DATE_FORMAT(CURDATE(), '%Y-%m')")->fetchColumn();
    $hasInvAt  = columnExists($pdo,'point_commission_monthly','invoice_ok_at');
    $hasInvBy  = columnExists($pdo,'point_commission_monthly','invoice_ok_by');
    $hasInvBln = columnExists($pdo,'point_commission_monthly','invoice_ok');
    $hasPaidAt = columnExists($pdo,'point_commission_monthly','paid_at');

    if (!$hasInvAt && !$hasInvBln) {
      json(['ok'=>false,'error'=>'schema','detail'=>'Manca la colonna invoice_ok_at o invoice_ok']);
    }

    $adminId = (int)($_SESSION['uid'] ?? 0);
    $set=[]; $par=[];

    if ($val===1){
      if ($hasInvAt){ $set[]="invoice_ok_at=NOW()"; if($hasInvBy){ $set[]="invoice_ok_by=?"; $par[]=$adminId; } }
      if ($hasInvBln){ $set[]="invoice_ok=1"; }
    } else {
      if ($hasInvAt){ $set[]="invoice_ok_at=NULL"; if($hasInvBy){ $set[]="invoice_ok_by=NULL"; } }
      if ($hasInvBln){ $set[]="invoice_ok=0"; }
    }

    $sql = "UPDATE point_commission_monthly
            SET ".implode(', ',$set)."
            WHERE point_user_id=? AND period_ym=? AND period_ym < ?";
    $par[] = $uid; $par[]=$ym; $par[]=$curYm;
    if ($hasPaidAt) { $sql .= " AND paid_at IS NULL"; }

    $st=$pdo->prepare($sql); $st->execute($par);
    json(['ok'=>true,'updated'=>$st->rowCount()]);
  }

  /* CREATE: crea user + point (con password) */
  if ($a==='create_point') {
    only_post();

    // ====== INPUT ======
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';

    $denom    = trim($_POST['denominazione'] ?? '');
    $piva     = trim($_POST['partita_iva'] ?? '');
    $pec      = trim($_POST['pec'] ?? '');
    $indir    = trim($_POST['indirizzo_legale'] ?? '');

    $anome    = trim($_POST['admin_nome'] ?? '');
    $acogn    = trim($_POST['admin_cognome'] ?? '');
    $acf      = trim($_POST['admin_cf'] ?? '');

    $errors = [];
    if ($username==='') $errors['username']='Obbligatorio';
    if ($email==='')    $errors['email']='Obbligatorio';
    if ($phone==='')    $errors['phone']='Obbligatorio';
    if ($password==='') $errors['password']='Obbligatorio';
    foreach (['denominazione'=>$denom,'partita_iva'=>$piva,'pec'=>$pec,'indirizzo_legale'=>$indir,
              'admin_nome'=>$anome,'admin_cognome'=>$acogn,'admin_cf'=>$acf] as $k=>$v){
      if ($v==='') $errors[$k]='Obbligatorio';
    }
    if ($errors) json(['ok'=>false,'errors'=>$errors]);

    // ====== PRECHECK SCHEMA MINIMO ======
    try {
      $chk = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='cell'");
      $chk->execute(); $hasCell = (int)$chk->fetchColumn() === 1;
      if (!$hasCell) json(['ok'=>false,'error'=>'schema','detail'=>"Manca la colonna users.cell"]);

      $roleType = $pdo->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
                               WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='role'")->fetchColumn();
      if ($roleType && stripos($roleType, 'PUNTO') === false) {
        json(['ok'=>false,'error'=>'schema','detail'=>"La colonna users.role non include 'PUNTO'"]);
      }

      $needCols = ['user_id','point_code','presenter_code','denominazione','partita_iva','pec','indirizzo_legale','admin_nome','admin_cognome','admin_cf','rake_pct'];
      $placeholders = implode(',', array_fill(0,count($needCols),'?'));
      $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='points' AND COLUMN_NAME IN ($placeholders)");
      $stmt->execute($needCols);
      $cnt = (int)$stmt->fetchColumn();
      if ($cnt < count($needCols)) {
        json(['ok'=>false,'error'=>'schema','detail'=>"Tabella 'points' non allineata (colonne mancanti)"]);
      }
    } catch (Throwable $se) {
      json(['ok'=>false,'error'=>'schema_check_failed','detail'=>$se->getMessage()]);
    }

    // ====== VALIDAZIONI UNICITÀ ======
    $errors = [];

    $st=$pdo->prepare("SELECT 1 FROM users WHERE username=? LIMIT 1");
    $st->execute([$username]);
    if ($st->fetch()) { $errors['username'] = 'Username già in uso'; }

    $st=$pdo->prepare("SELECT 1 FROM users WHERE email=? LIMIT 1");
    $st->execute([$email]);
    if ($st->fetch()) { $errors['email'] = 'Email già in uso'; }

    $st=$pdo->prepare("SELECT 1 FROM users WHERE cell=? LIMIT 1");
    $st->execute([$phone]);
    if ($st->fetch()) { $errors['phone'] = 'Telefono già in uso'; }

    if (!empty($errors)) {
      json(['ok'=>false,'errors'=>$errors]);
    }

    // ====== PREPARAZIONE ======
    $user_code     = getFreeCode($pdo,'users','user_code',6);
    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    $stage = 'begin';

    $pdo->beginTransaction();
    try{
      $stage = 'insert_user';
      $insU=$pdo->prepare("INSERT INTO users 
          (user_code, username, email, cell, password_hash, role, is_active, coins, presenter_code)
          VALUES (?,?,?,?,?, 'PUNTO', 1, 0, '')");
      $insU->execute([$user_code, $username, $email, $phone, $password_hash]);
      $uid = (int)$pdo->lastInsertId();

      $stage = 'generate_codes';
      $point_code = getFreeCode($pdo,'points','point_code',6);
      $presenter_code = $point_code;

      $stage = 'update_user_presenter';
      $pdo->prepare("UPDATE users SET presenter_code=? WHERE id=?")->execute([$presenter_code,$uid]);

      $stage = 'insert_point';
      $insP=$pdo->prepare("INSERT INTO points
          (user_id, point_code, presenter_code, denominazione, partita_iva, pec, indirizzo_legale,
           admin_nome, admin_cognome, admin_cf, rake_pct)
          VALUES (?,?,?,?,?,?,?,?,?,?,0.00)");
      $insP->execute([$uid,$point_code,$presenter_code,$denom,$piva,$pec,$indir,$anome,$acogn,$acf]);

      $pdo->commit();
      json(['ok'=>true,'user_id'=>$uid,'point_code'=>$point_code]);

    }catch(PDOException $e){
      $pdo->rollBack();
      $ei = $e->errorInfo;
      json([
        'ok'      => false,
        'error'   => 'db',
        'stage'   => $stage,
        'sqlstate'=> $ei[0] ?? null,
        'errno'   => $ei[1] ?? null,
        'detail'  => $ei[2] ?? $e->getMessage()
      ]);
    }catch(Throwable $e){
      $pdo->rollBack();
      json(['ok'=>false,'error'=>'fatal','stage'=>$stage,'detail'=>$e->getMessage()]);
    }
  }

  /* TOGGLE attivo/disabilitato */
  if ($a==='toggle_active') {
    only_post();
    $uid = (int)($_POST['user_id'] ?? 0);
    $st =$pdo->prepare("UPDATE users SET is_active = CASE WHEN is_active=1 THEN 0 ELSE 1 END WHERE id=? AND role='PUNTO'");
    $st->execute([$uid]);
    $is = (int)$pdo->query("SELECT is_active FROM users WHERE id={$uid}")->fetchColumn();
    json(['ok'=>true,'is_active'=>$is]);
  }

  /* UPDATE % rake */
  if ($a==='update_rake') {
    only_post();
    $uid  = (int)($_POST['user_id'] ?? 0);
    $rake = (float)($_POST['rake_pct'] ?? 0);
    if ($rake<0 || $rake>100) json(['ok'=>false,'error'=>'rake_range']);
    $st=$pdo->prepare("UPDATE points SET rake_pct=? WHERE user_id=?");
    $st->execute([$rake,$uid]);
    json(['ok'=>true]);
  }

  /* MODIFICA SALDO (+/-) con log */
  if ($a==='balance_adjust') {
    only_post();
    $uid   = (int)($_POST['user_id'] ?? 0);
    $delta = (float)($_POST['delta'] ?? 0);
    $reason= trim($_POST['reason'] ?? '');
    if ($reason==='') json(['ok'=>false,'error'=>'reason_required']);

    $admin_id = (int)($_SESSION['uid'] ?? 0);
    $pdo->beginTransaction();
    try{
      $pdo->prepare("UPDATE users SET coins = COALESCE(coins,0) + ? WHERE id=? AND role='PUNTO'")->execute([$delta,$uid]);
      $pdo->prepare("INSERT INTO points_balance_log (user_id,delta,reason,admin_id) VALUES (?,?,?,?)")->execute([$uid,$delta,$reason,$admin_id]);
      $new = (float)$pdo->query("SELECT coins FROM users WHERE id={$uid}")->fetchColumn();
      $pdo->commit();
      json(['ok'=>true,'new_balance'=>round($new,2)]);
    }catch(Throwable $e){
      $pdo->rollBack();
      json(['ok'=>false,'error'=>'db','detail'=>$e->getMessage()]);
    }
  }

  /* DELETE punto (hard) + pulizia rete */
  if ($a==='delete_point') {
    only_post();
    $uid = (int)($_POST['user_id'] ?? 0);
    $row = $pdo->prepare("SELECT p.point_code FROM points p WHERE p.user_id=?"); $row->execute([$uid]); $pc = $row->fetchColumn();
    $pdo->beginTransaction();
    try{
      if ($pc) {
        $pdo->prepare("UPDATE users SET presenter_code=NULL WHERE presenter_code=?")->execute([$pc]);
      }
      $pdo->prepare("DELETE FROM users WHERE id=? AND role='PUNTO'")->execute([$uid]);
      $pdo->commit();
      json(['ok'=>true]);
    }catch(Throwable $e){
      $pdo->rollBack();
      json(['ok'=>false,'error'=>'db','detail'=>$e->getMessage()]);
    }
  }

  http_response_code(400); json(['ok'=>false,'error'=>'unknown_action']);
}

/* ===== VIEW ===== */
$page_css='/pages-css/admin-dashboard.css';
include __DIR__ . '/../../partials/head.php';
include __DIR__ . '/../../partials/header_admin.php';
?>

<style>
  /* card layout coerente e compatto */
  .pt-page .card{ margin-bottom:16px; }
  .pt-topbar{ display:flex; justify-content:flex-end; margin-bottom:12px; }
  .pt-actions{ display:flex; gap:8px; }
  .chip{ padding:4px 10px; border-radius:9999px; border:1px solid var(--c-border); }
  .chip.on{ border-color:#27ae60; color:#a7e3bf; }
  .chip.off{ border-color:#ff8a8a; color:#ff8a8a; }

  .table-wrap{ overflow:auto; border-radius:12px; }
  .table{ width:100%; border-collapse:separate; border-spacing:0; }
  .table thead th{
    text-align:left; font-weight:900; font-size:12px; letter-spacing:.3px;
    color:#9fb7ff; padding:10px 12px; background:#0f172a; border-bottom:1px solid #1e293b;
  }
  .table tbody td{
    padding:12px; border-bottom:1px solid #122036; color:#e5e7eb; font-size:14px;
    background:linear-gradient(0deg, rgba(255,255,255,.02), rgba(255,255,255,.02));
  }
  .table tbody tr:hover td{ background:rgba(255,255,255,.025); }
  .table tbody tr:last-child td{ border-bottom:0; }

  /* modal base */
  .modal[aria-hidden="true"]{ display:none; }
  .modal{ position:fixed; inset:0; z-index:60; }
  .modal-open{ overflow:hidden; }
  .modal-backdrop{ position:absolute; inset:0; background:rgba(0,0,0,.5); }
  .modal-card{ position:relative; z-index:61; width:min(780px,96vw);
               background:var(--c-bg); border:1px solid var(--c-border); border-radius:16px;
               margin:6vh auto 0; padding:0; box-shadow:0 16px 48px rgba(0,0,0,.5);
               max-height:86vh; display:flex; flex-direction:column; }
  .modal-head{ display:flex; align-items:center; gap:10px; padding:12px 16px; border-bottom:1px solid var(--c-border); }
  .modal-x{ margin-left:auto; background:transparent; border:0; color:#fff; font-size:24px; cursor:pointer; }
  .modal-body{ padding:16px; overflow:auto; }
  .modal-foot{ display:flex; justify-content:flex-end; gap:8px; padding:12px 16px; border-top:1px solid var(--c-border); }

  .grid2{ display:grid; grid-template-columns:1fr 1fr; gap:16px; }
  @media (max-width:860px){ .grid2{ grid-template-columns:1fr; } }
</style>

<main class="pt-page">
  <section class="section">
    <div class="container">
      <h1>Punti</h1>

      <div class="pt-topbar">
        <button class="btn btn--primary" id="btnNew">Crea punto</button>
      </div>

      <div class="card">
        <h2 class="card-title">Elenco punti</h2>
        <div class="table-wrap">
          <table class="table" id="tblPoints">
            <thead>
              <tr>
                <th>Username</th>
                <th>Indirizzo</th>
                <th>Email</th>
                <th>% Rake</th>
                <th>Saldo</th>
                <th>Stato</th>
                <th>Azioni</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      </div>

      <!-- Card Commissioni -->
      <div class="card">
        <h2 class="card-title">Commissioni</h2>
        <div class="table-wrap">
          <table class="table" id="tblComm">
            <thead>
              <tr>
                <th>Punto</th>
                <th>Generato totale (AC)</th>
                <th>Da pagare (fatt. OK) (AC)</th>
                <th>In attesa fattura (AC)</th>
                <th>Mese corrente (AC)</th>
                <th style="text-align:right;">Azioni</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      </div>

      <!-- MODAL: Create Point (wizard) -->
      <div class="modal" id="mdNew" aria-hidden="true">
        <div class="modal-backdrop" data-close></div>
        <div class="modal-card">
          <div class="modal-head">
            <h3>Crea punto</h3>
            <div class="steps-dots" style="display:flex;gap:6px;margin-left:auto;">
              <span class="dot active" data-dot="1"></span>
              <span class="dot" data-dot="2"></span>
              <span class="dot" data-dot="3"></span>
              <span class="dot" data-dot="4"></span>
            </div>
            <button class="modal-x" data-close>&times;</button>
          </div>
          <div class="modal-body scroller">
            <form id="fNew" novalidate>
<!-- STEP 1: credenziali -->
<section class="step active" data-step="1">
  <div class="grid2">
    <div class="field">
      <label class="label">Username *</label>
      <input class="input light" id="n_username" required>
      <small id="err-username" style="color:#e21b2c;"></small>
    </div>

    <div class="field">
      <label class="label">Email *</label>
      <input class="input light" id="n_email" type="email" required>
      <small id="err-email" style="color:#e21b2c;"></small>
    </div>

    <div class="field">
      <label class="label">Telefono *</label>
      <input class="input light" id="n_phone" required>
      <small id="err-phone" style="color:#e21b2c;"></small>
    </div>

    <div class="field">
      <label class="label">Password *</label>
      <input class="input light" id="n_password" type="password" required>
    </div>
  </div>
</section>

              <!-- STEP 2: dati legali -->
              <section class="step" data-step="2">
                <div class="grid2">
                  <div class="field"><label class="label">Denominazione *</label><input class="input light" id="n_denominazione" required></div>
                  <div class="field"><label class="label">Partita IVA *</label><input class="input light" id="n_piva" required></div>
                  <div class="field"><label class="label">PEC *</label><input class="input light" id="n_pec" type="email" required></div>
                  <div class="field" style="grid-column:span 2;"><label class="label">Indirizzo sede legale *</label><input class="input light" id="n_indirizzo" required></div>
                </div>
              </section>

              <!-- STEP 3: amministratore -->
              <section class="step" data-step="3">
                <div class="grid2">
                  <div class="field"><label class="label">Nome *</label><input class="input light" id="n_anome" required></div>
                  <div class="field"><label class="label">Cognome *</label><input class="input light" id="n_acogn" required></div>
                  <div class="field"><label class="label">Codice fiscale *</label><input class="input light" id="n_acf" required></div>
                </div>
              </section>

              <!-- STEP 4: riepilogo -->
              <section class="step" data-step="4">
                <p class="muted">Controlla i dati e conferma.</p>
                <div id="n_review" class="card" style="padding:12px;"></div>
              </section>

              <div style="display:flex;justify-content:space-between;margin-top:12px;">
                <button type="button" class="btn btn--outline" id="n_prev">Indietro</button>
                <div class="pt-actions">
                  <button type="button" class="btn btn--outline hidden" id="n_cancel" data-close>Annulla</button>
                  <button type="button" class="btn btn--primary" id="n_next">Avanti</button>
                  <button type="submit" class="btn btn--primary hidden" id="n_submit">Crea punto</button>
                </div>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- MODAL: modifica saldo -->
      <div class="modal" id="mdBalance" aria-hidden="true">
        <div class="modal-backdrop" data-close></div>
        <div class="modal-card" style="max-width:520px;">
          <div class="modal-head">
            <h3>Modifica saldo punto</h3>
            <button class="modal-x" data-close>&times;</button>
          </div>
          <div class="modal-body">
            <form id="fBalance" onsubmit="return false;">
              <input type="hidden" id="b_user_id">
              <div class="field"><label class="label">Importo (+ / −)</label><input class="input light" id="b_delta" type="number" step="0.01" required></div>
              <div class="field"><label class="label">Motivazione</label><input class="input light" id="b_reason" required></div>
            </form>
          </div>
          <div class="modal-foot">
            <button class="btn btn--outline" data-close>Annulla</button>
            <button class="btn btn--primary" id="b_apply">Applica</button>
          </div>
        </div>
      </div>

      <!-- === NEW: MODAL Storico Commissioni === -->
      <div class="modal" id="mdCHist" aria-hidden="true">
        <div class="modal-backdrop" data-close></div>
        <div class="modal-card">
          <div class="modal-head">
            <h3>Storico commissioni — <span id="chUser"></span></h3>
            <button class="modal-x" data-close>&times;</button>
          </div>
          <div class="modal-body">
            <div class="table-wrap">
              <table class="table" id="tblCHist">
                <thead>
                  <tr>
                    <th>Mese</th>
                    <th>Importo (AC)</th>
                    <th>Fattura</th>
                    <th>Pagata il</th>
                    <th style="text-align:right;">Azione</th>
                  </tr>
                </thead>
                <tbody></tbody>
              </table>
            </div>
          </div>
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

  function clearPointErrors(){
    ['username','email','phone'].forEach(k=>{
      const el = document.getElementById('err-'+k);
      if (el) el.textContent = '';
    });
  }
  function showPointErrors(map){
    for (const [k,msg] of Object.entries(map||{})){
      const el = document.getElementById('err-'+k);
      if (el) el.textContent = msg;
    }
  }

  /* ===== LIST ===== */
  async function loadPoints(){
    const r = await fetch('?action=list_points',{cache:'no-store',headers:{'Cache-Control':'no-cache'}});
    const j = await r.json(); if(!j.ok){ alert('Errore elenco punti'); return; }
    const tb = $('#tblPoints tbody'); tb.innerHTML='';
    j.rows.forEach(row=>{
      const tr=document.createElement('tr');
      const stateChip = `<button type="button" class="chip ${row.is_active==1?'on':'off'}" data-toggle="${row.user_id}">${row.is_active==1?'Attivo':'Disabilitato'}</button>`;
      tr.innerHTML = `
        <td><a href="/admin/point_detail.php?uid=${row.user_id}">${row.username}</a></td>
        <td>${row.indirizzo_legale||'-'}</td>
        <td>${row.email}</td>
        <td><input class="input light input--xs" type="number" step="0.01" min="0" max="100" value="${Number(row.rake_pct||0).toFixed(2)}" data-rake="${row.user_id}" style="width:100px"></td>
        <td>€ ${Number(row.coins||0).toFixed(2)}</td>
        <td>${stateChip}</td>
        <td class="actions-cell">
          <button class="btn btn--outline btn--sm" data-balance="${row.user_id}">Modifica saldo</button>
          <button class="btn btn--outline btn--sm" data-apply="${row.user_id}">Applica modifiche</button>
          <button class="btn btn--outline btn--sm btn-danger" data-del="${row.user_id}">Elimina</button>
        </td>
      `;
      tb.appendChild(tr);
    });
  }

  /* ===== Commissioni — dashboard ===== */
  async function loadCommissionDashboard(){
    let j=null;
    const tb = $('#tblComm tbody'); tb.innerHTML='';
    try{
      const r = await fetch('?action=list_commission_dashboard', {cache:'no-store'});
      try { j = await r.json(); }
      catch(parseErr){
        tb.innerHTML = '<tr><td colspan="6">Errore server (risposta non valida)</td></tr>';
        return;
      }
    }catch(err){
      tb.innerHTML = '<tr><td colspan="6">Errore rete</td></tr>';
      return;
    }

    if (!j.ok){ tb.innerHTML = '<tr><td colspan="6">Errore caricamento</td></tr>'; return; }
    if (!j.rows || j.rows.length===0){
      tb.innerHTML = '<tr><td colspan="6">Nessun punto trovato</td></tr>';
      return;
    }

    j.rows.forEach(row=>{
      const toPay = Number(row.to_pay||0);
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td><a href="#" class="c-hist" data-uid="${row.user_id}" data-uname="${row.username}">${row.username}</a></td>
        <td>${Number(row.total_generated||0).toFixed(2)}</td>
        <td>${toPay.toFixed(2)}</td>
        <td>${Number(row.waiting_invoice||0).toFixed(2)}</td>
        <td>${Number(row.current_month||0).toFixed(2)}</td>
        <td style="text-align:right;">
          <button class="btn btn--primary btn--sm" data-pay="${row.user_id}" ${toPay>0?'':'disabled'}>${toPay>0?'Paga':'Niente da pagare'}</button>
        </td>
      `;
      tb.appendChild(tr);
    });
  }

  /* ===== MODALE STORICO COMMISSIONI ===== */
  let chUid = 0;
  async function loadCHistory(){
    const u = new URL('?action=commission_history', location.href);
    u.searchParams.set('point_user_id', chUid);
    const r = await fetch(u, {cache:'no-store'});
    let j; try { j = await r.json(); } catch(_){ 
      $('#tblCHist tbody').innerHTML = '<tr><td colspan="5">Errore server</td></tr>'; 
      return; 
    }
    if (!j.ok){ $('#tblCHist tbody').innerHTML = '<tr><td colspan="5">Errore</td></tr>'; return; }

    const tb = $('#tblCHist tbody'); tb.innerHTML='';
    if (!j.rows || j.rows.length===0){
      tb.innerHTML = '<tr><td colspan="5">Nessun mese a storico</td></tr>';
      return;
    }
    j.rows.forEach(rw=>{
      /* >>> MODIFICA: fattura OK se ho timestamp o boolean = 1 <<< */
      const isOk = ((rw.invoice_ok_at !== null && rw.invoice_ok_at !== undefined) || (Number(rw.invoice_ok||0)===1));
      const canToggle = j.has_invoice_ok && (rw.period_ym < j.curYm) && (!j.has_paid_at || !rw.paid_at);
      const paidTxt = rw.paid_at ? new Date(rw.paid_at).toLocaleString() : '—';
      let btn = '';
      if (canToggle){
        btn = isOk
          ? `<button class="btn btn--outline btn--sm" data-inv="0" data-ym="${rw.period_ym}">Annulla OK</button>`
          : `<button class="btn btn--primary btn--sm" data-inv="1" data-ym="${rw.period_ym}">Fattura OK</button>`;
      }
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${rw.period_ym}</td>
        <td>${Number(rw.amount_coins||0).toFixed(2)}</td>
        <td>${isOk ? 'OK' : '—'}</td>
        <td>${paidTxt}</td>
        <td style="text-align:right;">${btn}</td>
      `;
      tb.appendChild(tr);
    });
  }

  document.addEventListener('click', async (e)=>{
    const a = e.target.closest('a.c-hist'); 
    if (!a) return;
    e.preventDefault();
    chUid = parseInt(a.getAttribute('data-uid'),10);
    $('#chUser').textContent = a.getAttribute('data-uname') || '';
    document.getElementById('mdCHist').setAttribute('aria-hidden','false');
    document.body.classList.add('modal-open');
    await loadCHistory();
  });

  $$('#mdCHist [data-close], #mdCHist .modal-backdrop').forEach(x=>x.addEventListener('click', ()=>{
    document.getElementById('mdCHist').setAttribute('aria-hidden','true');
    document.body.classList.remove('modal-open');
  }));

  // Click "Fattura OK / Annulla OK" nella modale
  $('#tblCHist').addEventListener('click', async (e)=>{
    const b = e.target.closest('button[data-inv]'); if(!b) return;
    const val = b.getAttribute('data-inv');
    const ym  = b.getAttribute('data-ym');
    try{
      const r = await fetch('?action=commission_invoice_ok', {
        method:'POST',
        body: new URLSearchParams({ point_user_id:String(chUid), period_ym:ym, value:val })
      });
      const j = await r.json();
      if (!j.ok){ alert('Operazione non riuscita: ' + (j.detail||j.error||'')); return; }
      await loadCHistory();
      await loadCommissionDashboard(); // aggiorna i totali in card
    }catch(err){
      alert('Errore rete: ' + (err && err.message ? err.message : ''));
    }
  });

  /* ===== CREATE (wizard) ===== */
  const mdNew = $('#mdNew');
  const steps = ()=> $$('.step', mdNew);
  const dots  = ()=> $$('.dot', mdNew);
  let idx=0;
  function showStep(i){
    idx = Math.max(0, Math.min(i, steps().length-1));
    steps().forEach((s,k)=>s.classList.toggle('active', k===idx));
    dots().forEach((d,k)=>d.classList.toggle('active', k<=idx));
    $('#n_prev').disabled = idx===0;
    $('#n_next').classList.toggle('hidden', idx===steps().length-1);
    $('#n_submit').classList.toggle('hidden', idx!==steps().length-1);
    $('#n_cancel').classList.toggle('hidden', idx!==steps().length-1);
    if (idx===3) {
      const rev = `
        <div><strong>Username:</strong> ${$('#n_username').value}</div>
        <div><strong>Email:</strong> ${$('#n_email').value}</div>
        <div><strong>Telefono:</strong> ${$('#n_phone').value}</div>
        <hr style="border-color:var(--c-border)">
        <div><strong>Denominazione:</strong> ${$('#n_denominazione').value}</div>
        <div><strong>P.IVA:</strong> ${$('#n_piva').value}</div>
        <div><strong>PEC:</strong> ${$('#n_pec').value}</div>
        <div><strong>Indirizzo legale:</strong> ${$('#n_indirizzo').value}</div>
        <hr style="border-color:var(--c-border)">
        <div><strong>Amministratore:</strong> ${$('#n_anome').value} ${$('#n_acogn').value} — CF: ${$('#n_acf').value}</div>
      `;
      $('#n_review').innerHTML = rev;
    }
  }
  function openNew(){
    mdNew.setAttribute('aria-hidden','false');
    document.body.classList.add('modal-open');
    idx = 0;
    clearPointErrors();
    showStep(0);
  }
  function closeNew(){
    mdNew.setAttribute('aria-hidden','true');
    document.body.classList.remove('modal-open');
    $('#fNew').reset();
    clearPointErrors();
  }

  $$('#mdNew [data-close]').forEach(b=>b.addEventListener('click', closeNew));
  $('#btnNew').addEventListener('click', openNew);
  $('#n_prev').addEventListener('click', ()=> showStep(idx-1));
  $('#n_next').addEventListener('click', ()=>{
    const inv = steps()[idx].querySelector(':invalid');
    if (inv){ inv.reportValidity(); return; }
    showStep(idx+1);
  });

  $('#fNew').addEventListener('submit', async (e)=>{
    e.preventDefault();
    const bad = mdNew.querySelector(':invalid');
    if (bad){ bad.reportValidity(); return; }

    const fd = new URLSearchParams({
      username: $('#n_username').value.trim(),
      email:    $('#n_email').value.trim(),
      phone:    $('#n_phone').value.trim(),
      password: $('#n_password').value,

      denominazione:    $('#n_denominazione').value.trim(),
      partita_iva:      $('#n_piva').value.trim(),
      pec:              $('#n_pec').value.trim(),
      indirizzo_legale: $('#n_indirizzo').value.trim(),

      admin_nome:   $('#n_anome').value.trim(),
      admin_cognome:$('#n_acogn').value.trim(),
      admin_cf:     $('#n_acf').value.trim()
    });

    const r = await fetch('?action=create_point', { method:'POST', body: fd });
    const j = await r.json();

    if (!j.ok){
      clearPointErrors();
      if (j.errors){
        showPointErrors(j.errors);
        for (const k of ['username','email','phone']){
          if (j.errors[k]){
            const input = (k==='username')? $('#n_username') : (k==='email')? $('#n_email') : $('#n_phone');
            if (input) input.focus();
            break;
          }
        }
      } else {
        let msg = 'Errore: '+(j.error||'');
        if (j.stage)  msg += '\nStage: '+j.stage;
        if (j.errno)  msg += '\nErrno: '+j.errno;
        if (j.detail) msg += '\nDetail: '+j.detail;
        alert(msg);
      }
      return;
    }

    closeNew();
    await loadPoints();
    await loadCommissionDashboard(); // refresh anche la card Commissioni
  });

  /* ===== TABELLA AZIONI ===== */
  $('#tblPoints').addEventListener('click', async (e)=>{
    const b=e.target.closest('button'); if(!b) return;

    // toggle attivo
    if (b.hasAttribute('data-toggle')){
      const uid=b.getAttribute('data-toggle');
      const r=await fetch('?action=toggle_active',{method:'POST', body:new URLSearchParams({user_id:uid})});
      const j=await r.json(); if(!j.ok){ alert('Errore toggle'); return; }
      b.classList.toggle('on', j.is_active==1);
      b.classList.toggle('off', j.is_active!=1);
      b.textContent = j.is_active==1 ? 'Attivo' : 'Disabilitato';
      return;
    }

    // apri modale saldo
    if (b.hasAttribute('data-balance')){
      const uid=b.getAttribute('data-balance');
      $('#b_user_id').value = uid;
      $('#b_delta').value=''; $('#b_reason').value='';
      document.getElementById('mdBalance').setAttribute('aria-hidden','false'); document.body.classList.add('modal-open');
      return;
    }

    // applica % rake
    if (b.hasAttribute('data-apply')){
      const uid=b.getAttribute('data-apply');
      const inp = document.querySelector(`input[data-rake="${uid}"]`);
      const val = inp ? inp.value : '0';
      const r = await fetch('?action=update_rake',{method:'POST', body:new URLSearchParams({user_id:uid, rake_pct:val})});
      const j = await r.json(); if(!j.ok){ alert('Errore update rake'); return; }
      alert('Rake aggiornata');
      return;
    }

    // elimina punto
    if (b.hasAttribute('data-del')){
      const uid=b.getAttribute('data-del');
      if (!confirm('Eliminare definitivamente il punto? Gli utenti sotto rete verranno scollegati.')) return;
      const r = await fetch('?action=delete_point',{method:'POST', body:new URLSearchParams({user_id:uid})});
      const j = await r.json(); if(!j.ok){ alert('Errore eliminazione'); return; }
      await loadPoints();
      await loadCommissionDashboard();
      return;
    }
  });

  // applica saldo
  $('#b_apply').addEventListener('click', async ()=>{
    const uid = $('#b_user_id').value;
    const delta = $('#b_delta').value;
    const reason= $('#b_reason').value.trim();
    if (!reason){ alert('Motivazione obbligatoria'); return; }
    const r=await fetch('?action=balance_adjust',{method:'POST', body:new URLSearchParams({user_id:uid, delta, reason})});
    const j=await r.json(); if(!j.ok){ alert('Errore saldo'); return; }
    document.getElementById('mdBalance').setAttribute('aria-hidden','true'); document.body.classList.remove('modal-open');
    await loadPoints();
  });

  // chiudi modale saldo
  $$('#mdBalance [data-close]').forEach(b=>b.addEventListener('click', ()=>{
    document.getElementById('mdBalance').setAttribute('aria-hidden','true'); document.body.classList.remove('modal-open');
  }));

  /* ===== Pagamento commissioni (bottone principale) ===== */
  $('#tblComm').addEventListener('click', async (e)=>{
    const b = e.target.closest('button[data-pay]'); if(!b) return;
    const uid = b.getAttribute('data-pay');
    if (!confirm('Pagare le commissioni dovute (fattura OK) per questo punto?')) return;

    try{
      /* >>> MODIFICA: chiama l’API corretta pay_commission.php?action=pay_all_ready <<< */
      const r = await fetch('/api/pay_commission.php?action=pay_all_ready', { method:'POST', body: new URLSearchParams({ point_user_id: uid }) });
      const j = await r.json();
      if (!j.ok){ alert('Pagamento non riuscito: ' + (j.detail||j.error||'')); return; }
      alert('Commissioni pagate.');
      await loadCommissionDashboard();
      await loadPoints();
    }catch(err){
      alert('Errore rete: ' + (err && err.message ? err.message : ''));
    }
  });

  // boot
  (async ()=>{
    await loadPoints();
    await loadCommissionDashboard();
  })();
});
</script>
