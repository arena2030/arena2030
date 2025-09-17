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
function getFreeCode(PDO $pdo,$table,$col,$len=6){ for($i=0;$i<16;$i++){ $c=genCode($len); $st=$pdo->prepare("SELECT 1 FROM {$table} WHERE {$col}=? LIMIT 1"); $st->execute([$c]); if(!$st->fetch()) return $c; } throw new RuntimeException('code'); }

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

  // ====== PRECHECK SCHEMA MINIMO (difetti tipici) ======
  try {
    // users.cell deve esistere
    $chk = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                          WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='cell'");
    $chk->execute(); $hasCell = (int)$chk->fetchColumn() === 1;
    if (!$hasCell) json(['ok'=>false,'error'=>'schema','detail'=>"Manca la colonna users.cell"]);

    // users.role deve includere PUNTO
    $roleType = $pdo->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
                             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='role'")->fetchColumn();
    if ($roleType && stripos($roleType, 'PUNTO') === false) {
      json(['ok'=>false,'error'=>'schema','detail'=>"La colonna users.role non include 'PUNTO'"]);
    }

    // tabella points presente con colonne base
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
    json(['ok'=>false,'errors'=>$errors]); // esci qui con dettagli campo->errore
  }

  // ====== PREPARAZIONE ======
  $user_code     = getFreeCode($pdo,'users','user_code',6); // <-- NEW: serve perché users.user_code è NOT NULL
  $password_hash = password_hash($password, PASSWORD_DEFAULT);
  $stage = 'begin';

  $pdo->beginTransaction();
  try{
    $stage = 'insert_user';
    // crea utente punto (SCRIVE SU users.cell + user_code)
    $insU=$pdo->prepare("INSERT INTO users 
        (user_code, username, email, cell, password_hash, role, is_active, coins, presenter_code)
        VALUES (?,?,?,?,?, 'PUNTO', 1, 0, '')");
    $insU->execute([$user_code, $username, $email, $phone, $password_hash]);
    $uid = (int)$pdo->lastInsertId();

    $stage = 'generate_codes';
    // genera point_code univoco (6 char) e presenter_code
    $point_code = getFreeCode($pdo,'points','point_code',6);
    $presenter_code = $point_code;

    $stage = 'update_user_presenter';
    // aggiorna presenter_code utente
    $pdo->prepare("UPDATE users SET presenter_code=? WHERE id=?")->execute([$presenter_code,$uid]);

    $stage = 'insert_point';
    // crea riga points
    $insP=$pdo->prepare("INSERT INTO points
        (user_id, point_code, presenter_code, denominazione, partita_iva, pec, indirizzo_legale,
         admin_nome, admin_cognome, admin_cf, rake_pct)
        VALUES (?,?,?,?,?,?,?,?,?,?,0.00)");
    $insP->execute([$uid,$point_code,$presenter_code,$denom,$piva,$pec,$indir,$anome,$acogn,$acf]);

    $pdo->commit();
    json(['ok'=>true,'user_id'=>$uid,'point_code'=>$point_code]);

  }catch(PDOException $e){
    $pdo->rollBack();
    $ei = $e->errorInfo; // [SQLSTATE, errno, message]
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
    // risali al point_code
    $row = $pdo->prepare("SELECT p.point_code FROM points p WHERE p.user_id=?"); $row->execute([$uid]); $pc = $row->fetchColumn();
    $pdo->beginTransaction();
    try{
      if ($pc) {
        $pdo->prepare("UPDATE users SET presenter_code=NULL WHERE presenter_code=?")->execute([$pc]);
      }
      // elimina utente (cascade su points, balance_log)
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

  /* modal base (riusa tua modale) */
  .modal[aria-hidden="true"]{ display:none; }
  .modal{ position:fixed; inset:0; z-index:60; }
  .modal-open{ overflow:hidden; }
  .modal-backdrop{ position:absolute; inset:0; background:rgba(0,0,0,.5); }
  .modal-card{ position:relative; z-index:61; width:min(720px,96vw);
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
  </div> <!-- ← CHIUSURA grid2 -->
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
      // riepilogo
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
  clearPointErrors();         // ← qui, dentro openNew
  showStep(0);
}

function closeNew(){
  mdNew.setAttribute('aria-hidden','true');
  document.body.classList.remove('modal-open');
  $('#fNew').reset();
  clearPointErrors();         // ← facoltativo: pulisci errori quando chiudi
}

  $$('#mdNew [data-close]').forEach(b=>b.addEventListener('click', closeNew));
  $('#btnNew').addEventListener('click', openNew);
  $('#n_prev').addEventListener('click', ()=> showStep(idx-1));
  $('#n_next').addEventListener('click', ()=>{
    // validazione minimale step corrente
    const inv = steps()[idx].querySelector(':invalid');
    if (inv){ inv.reportValidity(); return; }
    showStep(idx+1);
  });

$('#fNew').addEventListener('submit', async (e)=>{
  e.preventDefault();

  // validazione finale
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
      // metti a fuoco il primo campo con errore
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
    return; // <-- esci qui in caso di errore
  }

  // successo
  closeNew();
  await loadPoints();
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

  loadPoints();
});
</script>
