<?php
require_once __DIR__ . '/../../partials/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 🔐 Solo ADMIN
if (empty($_SESSION['uid']) || !(($_SESSION['role'] ?? 'USER') === 'ADMIN' || (int)($_SESSION['is_admin'] ?? 0) === 1)) {
  header('Location: /login.php'); exit;
}

/* ========================================================================
   ENDPOINTS AJAX (JSON/CSV) — stessi file, prima dell'output HTML
   ======================================================================== */
function json($arr){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($arr); exit; }
function only_post(){ if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); json(['ok'=>false,'error'=>'method']); } }
function safe_decimal($s){ return number_format((float)preg_replace('/[^0-9.\-]/','',$s), 2, '.', ''); }

if (isset($_GET['action'])) {
  $action = $_GET['action'];

  // sicurezza: tutte le azioni richiedono admin (già verificato sopra)

  /* ---- LISTA PLAYERS (JSON) ---- */
  if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');

    $q    = trim($_GET['q'] ?? '');
    $sort = $_GET['sort'] ?? 'id';        // nome|cognome|coins|id
    $dir  = strtolower($_GET['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per  = min(200, max(10, (int)($_GET['per'] ?? 50)));
    $off  = ($page-1)*$per;

    $allowed = ['id','nome','cognome','coins'];
    if (!in_array($sort,$allowed,true)) $sort='id';

    $where = "WHERE role='USER'";
    $params = [];
    if ($q !== '') {
      $where .= " AND (username LIKE ? OR nome LIKE ? OR cognome LIKE ?)";
      $like = "%$q%";
      $params = [$like,$like,$like];
    }

    $sql = "SELECT SQL_CALC_FOUND_ROWS
              id, user_code, username, nome, cognome, is_active, coins, presenter_code
            FROM users
            $where
            ORDER BY $sort $dir
            LIMIT $per OFFSET $off";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $total = (int)$pdo->query("SELECT FOUND_ROWS()")->fetchColumn();

    json(['ok'=>true, 'rows'=>$rows, 'total'=>$total, 'page'=>$page, 'per'=>$per]);
  }

  /* ---- DETTAGLI UTENTE (per popup) ---- */
  if ($action === 'details') {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) json(['ok'=>false,'error'=>'not_found']);
    unset($u['password_hash']); // non lo mandiamo al client
    json(['ok'=>true,'user'=>$u]);
  }

  /* ---- TOGGLE STATO ATTIVO/INATTIVO ---- */
  if ($action === 'toggle_active') {
    only_post();
    $id = (int)($_POST['id'] ?? 0);
    $st = $pdo->prepare("UPDATE users SET is_active = CASE WHEN is_active=1 THEN 0 ELSE 1 END WHERE id=?");
    $st->execute([$id]);
    $st2 = $pdo->prepare("SELECT is_active FROM users WHERE id=?");
    $st2->execute([$id]);
    $is_active = (int)$st2->fetchColumn();
    json(['ok'=>true,'is_active'=>$is_active]);
  }

  /* ---- UPDATE INLINE (saldo) ---- */
  if ($action === 'update_inline') {
    only_post();
    $id    = (int)($_POST['id'] ?? 0);
    $coins = safe_decimal($_POST['coins'] ?? '0');

    $st = $pdo->prepare("UPDATE users SET coins=? WHERE id=?");
    $st->execute([$coins, $id]);
    json(['ok'=>true]);
  }

  /* ---- UPDATE COMPLETO (popup) + reset password ---- */
  if ($action === 'update_user') {
    only_post();
    $id = (int)($_POST['id'] ?? 0);

    // Raccolta campi modificabili
    $data = [
      'username'        => trim($_POST['username'] ?? ''),
      'email'           => strtolower(trim($_POST['email'] ?? '')),
      'cell'            => preg_replace('/\s+/', '', trim($_POST['cell'] ?? '')),
      'codice_fiscale'  => strtoupper(trim($_POST['codice_fiscale'] ?? '')),
      'nome'            => trim($_POST['nome'] ?? ''),
      'cognome'         => trim($_POST['cognome'] ?? ''),
      'cittadinanza'    => trim($_POST['cittadinanza'] ?? ''),
      'via'             => trim($_POST['via'] ?? ''),
      'civico'          => trim($_POST['civico'] ?? ''),
      'citta'           => trim($_POST['citta'] ?? ''),
      'prov'            => strtoupper(trim($_POST['prov'] ?? '')),
      'cap'             => trim($_POST['cap'] ?? ''),
      'nazione'         => trim($_POST['nazione'] ?? ''),
      'tipo_doc'        => $_POST['tipo_doc'] ?? '',
      'num_doc'         => trim($_POST['num_doc'] ?? ''),
      'data_rilascio'   => $_POST['data_rilascio'] ?? '',
      'data_scadenza'   => $_POST['data_scadenza'] ?? '',
      'rilasciato_da'   => trim($_POST['rilasciato_da'] ?? ''),
      'presenter_code'  => trim($_POST['presenter_code'] ?? ''),
      'is_active'       => isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1,
      'coins'           => safe_decimal($_POST['coins'] ?? '0'),
    ];
    $new_password = $_POST['new_password'] ?? '';

    // Pre-check unicità (username/email/cell/cf) — MySQL 5.x safe
    $uniq = [
      ['username','uq_username'],
      ['email','uq_email'],
      ['cell','uq_cell'],
      ['codice_fiscale','uq_codicefiscale'],
    ];
    foreach ($uniq as [$col,$name]) {
      $st = $pdo->prepare("SELECT id FROM users WHERE $col = ? AND id <> ? LIMIT 1");
      $st->execute([$data[$col], $id]);
      if ($st->fetchColumn()) {
        json(['ok'=>false,'errors'=>[$col => ucfirst(str_replace('_',' ',$col)) . ' già presente']]);
      }
    }

    // Build UPDATE
    $set = [];
    $params = [];
    foreach ($data as $k=>$v){ $set[] = "$k=?"; $params[] = $v; }
    if ($new_password !== '') {
      // Validazione minima password (stessa della registrazione)
      if (!preg_match('/(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}/', $new_password)) {
        json(['ok'=>false,'errors'=>['new_password'=>'Password non conforme ai requisiti']]);
      }
      $set[] = "password_hash=?";
      $params[] = password_hash($new_password, PASSWORD_DEFAULT);
    }
    $params[] = $id;

    $sql = "UPDATE users SET ".implode(',',$set)." WHERE id=?";
    try{
      $st = $pdo->prepare($sql);
      $st->execute($params);
      json(['ok'=>true]);
    }catch(PDOException $e){
      $msg = $e->errorInfo[2] ?? $e->getMessage();
      // fallback in caso arrivi lo stesso 1062 da UNIQUE
      if (strpos($msg,'uq_username')!==false) json(['ok'=>false,'errors'=>['username'=>'Username già presente']]);
      if (strpos($msg,'uq_email')!==false)    json(['ok'=>false,'errors'=>['email'=>'Email già presente']]);
      if (strpos($msg,'uq_cell')!==false)     json(['ok'=>false,'errors'=>['cell'=>'Telefono già presente']]);
      if (strpos($msg,'uq_codicefiscale')!==false) json(['ok'=>false,'errors'=>['codice_fiscale'=>'Codice fiscale già presente']]);
      json(['ok'=>false,'error'=>'db','detail'=>$msg]);
    }
  }

  /* ---- DELETE USER (no admin) ---- */
  if ($action === 'delete_user') {
    only_post();
    $id = (int)($_POST['id'] ?? 0);
    // non permettere di cancellare ADMIN
    $st = $pdo->prepare("SELECT role FROM users WHERE id=?");
    $st->execute([$id]);
    $role = $st->fetchColumn();
    if (!$role) json(['ok'=>false,'error'=>'not_found']);
    if ($role === 'ADMIN') json(['ok'=>false,'error'=>'forbidden']);

    $st = $pdo->prepare("DELETE FROM users WHERE id=? LIMIT 1");
    $st->execute([$id]);
    json(['ok'=>true]);
  }

  /* ---- EXPORT CSV (tutti gli utenti) ---- */
  if ($action === 'export_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="users_export.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id','user_code','username','nome','cognome','email','cell','coins','is_active','presenter_code','role','created_at']);
    $q = $pdo->query("SELECT id,user_code,username,nome,cognome,email,cell,coins,is_active,presenter_code,role,created_at FROM users ORDER BY id ASC");
    while($r = $q->fetch(PDO::FETCH_NUM)) fputcsv($out,$r);
    fclose($out);
    exit;
  }

  // qualsiasi altra action
  http_response_code(400);
  json(['ok'=>false,'error'=>'unknown_action']);
}

/* ========================================================================
   VIEW
   ======================================================================== */
$page_css = '/pages-css/admin-dashboard.css';
include __DIR__ . '/../../partials/head.php';
include __DIR__ . '/../../partials/header_admin.php';

// contatore totale utenti
$total_users = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
?>
<main>
  <section class="section">
    <div class="container">
      <div class="admin-topbar">
        <div class="counter">Totale utenti: <strong><?= $total_users ?></strong></div>
        <div class="actions">
          <input id="search" class="input light" type="search" placeholder="Cerca (user, nome, cognome)" />
          <button id="btnSearch" class="btn btn--outline btn--sm">Cerca</button>
          <a class="btn btn--outline btn--sm" href="?action=export_csv">Esporta CSV</a>
        </div>
      </div>

      <div class="card">
        <h2 class="card-title">Players</h2>
        <div class="table-wrap">
          <table class="table" id="playersTbl">
            <thead>
              <tr>
                <th>ID utente</th>
                <th>User</th>
                <th class="th-sort" data-sort="nome">Nome</th>
                <th class="th-sort" data-sort="cognome">Cognome</th>
                <th>Stato</th>
                <th class="th-sort" data-sort="coins">Arena Coins</th>
                <th>Punto</th>
                <th>Azioni</th>
              </tr>
            </thead>
            <tbody>
              <!-- riempito via JS -->
            </tbody>
          </table>
        </div>
        <div class="table-foot">
          <span id="rowsInfo"></span>
        </div>
      </div>
    </div>
  </section>
</main>

<!-- Modal Dettagli/Modifica (a pagine) -->
<div class="modal" id="userModal" aria-hidden="true">
  <div class="modal-backdrop" data-close></div>
  <div class="modal-card">
    <div class="modal-head">
      <h3 id="modalTitle">Dettagli utente</h3>
      <div class="steps-dots">
        <span class="dot active" data-dot="1"></span>
        <span class="dot" data-dot="2"></span>
        <span class="dot" data-dot="3"></span>
      </div>
      <button class="modal-x" data-close>&times;</button>
    </div>

    <!-- contenitore scrollabile con altezza massima -->
    <div class="modal-body scroller">
      <form id="userForm">

        <!-- STEP 1: credenziali e contatti -->
        <section class="step active" data-step="1">
          <div class="grid2">
            <input type="hidden" name="id" id="u_id" />
            <div class="field"><label class="label">Username</label><input class="input light" name="username" id="u_username" required /></div>
            <div class="field"><label class="label">Email</label><input class="input light" name="email" id="u_email" type="email" required /></div>
            <div class="field"><label class="label">Cellulare</label><input class="input light" name="cell" id="u_cell" required /></div>
            <div class="field"><label class="label">Codice Fiscale</label><input class="input light" name="codice_fiscale" id="u_cf" required /></div>
            <div class="field" style="grid-column: span 2;">
              <label class="label">Reset password (opzionale)</label>
              <input class="input light" name="new_password" id="u_new_password" type="password" placeholder="Nuova password (opzionale)" />
            </div>
          </div>
        </section>

        <!-- STEP 2: anagrafica e residenza -->
        <section class="step" data-step="2">
          <div class="grid2">
            <div class="field"><label class="label">Nome</label><input class="input light" name="nome" id="u_nome" required /></div>
            <div class="field"><label class="label">Cognome</label><input class="input light" name="cognome" id="u_cognome" required /></div>
            <div class="field"><label class="label">Via</label><input class="input light" name="via" id="u_via" required /></div>
            <div class="field"><label class="label">N. Civico</label><input class="input light" name="civico" id="u_civico" required /></div>
            <div class="field"><label class="label">Città</label><input class="input light" name="citta" id="u_citta" required /></div>
            <div class="field"><label class="label">Provincia</label><input class="input light" name="prov" id="u_prov" maxlength="2" required /></div>
            <div class="field"><label class="label">CAP</label><input class="input light" name="cap" id="u_cap" required /></div>
            <div class="field"><label class="label">Nazione</label><input class="input light" name="nazione" id="u_nazione" required /></div>
          </div>
        </section>

        <!-- STEP 3: documento, stato, punto, coins -->
        <section class="step" data-step="3">
          <div class="grid2">
            <div class="field">
              <label class="label">Tipo Documento</label>
              <select class="select light" name="tipo_doc" id="u_tipo_doc" required>
                <option value="PATENTE">Patente</option>
                <option value="CARTA_IDENTITA">Carta d'identità</option>
                <option value="PASSAPORTO">Passaporto</option>
              </select>
            </div>
            <div class="field"><label class="label">Numero documento</label><input class="input light" name="num_doc" id="u_num_doc" required /></div>
            <div class="field"><label class="label">Data rilascio</label><input class="input light" name="data_rilascio" id="u_rilascio" type="date" required /></div>
            <div class="field"><label class="label">Data scadenza</label><input class="input light" name="data_scadenza" id="u_scadenza" type="date" required /></div>
            <div class="field" style="grid-column: span 2;"><label class="label">Rilasciato da…</label><input class="input light" name="rilasciato_da" id="u_rilasciato_da" required /></div>

            <div class="field"><label class="label">Punto (presenter)</label><input class="input light" name="presenter_code" id="u_presenter" /></div>
            <div class="field">
              <label class="label">Stato</label>
              <select class="select light" name="is_active" id="u_is_active">
                <option value="1">Attivo</option>
                <option value="0">Inattivo</option>
              </select>
            </div>
            <div class="field"><label class="label">Arena Coins</label><input class="input light" name="coins" id="u_coins" /></div>
          </div>
        </section>

      </form>
    </div>

    <div class="modal-foot">
      <button class="btn btn--outline" id="mPrev">Indietro</button>
      <button class="btn btn--primary" id="mNext">Avanti</button>
      <button class="btn btn--primary hidden" id="btnApplyUser">Applica modifiche</button>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../partials/footer.php'; ?>

<script>
// ====== Stato locale per lista/sort/paging ======
let state = { q:'', sort:'id', dir:'asc', page:1, per:50 };

// ====== Helpers UI ======
const $ = (s, p=document)=>p.querySelector(s);
const $$ = (s, p=document)=>[...p.querySelectorAll(s)];

function toast(msg){ alert(msg); } // semplice; in futuro mettiamo snackbar

// ====== Carica tabella ======
async function loadTable(){
  const params = new URLSearchParams({action:'list', q:state.q, sort:state.sort, dir:state.dir, page:state.page, per:state.per});
  const r = await fetch('?'+params.toString(), {cache:'no-store'});
  const j = await r.json();
  if(!j.ok){ toast('Errore caricamento'); return; }

  const tb = $('#playersTbl tbody');
  tb.innerHTML = '';
  j.rows.forEach(row=>{
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${row.user_code}</td>
      <td><a href="#" class="link-user" data-id="${row.id}">${escapeHtml(row.username)}</a></td>
      <td>${escapeHtml(row.nome)}</td>
      <td>${escapeHtml(row.cognome)}</td>
      <td>
        <button class="chip ${row.is_active==1?'chip--ok':'chip--off'}" data-act="toggle" data-id="${row.id}">
          ${row.is_active==1?'Attivo':'Inattivo'}
        </button>
      </td>
      <td>
        <div class="coins-edit">
          <input type="text" class="input light input--xs" value="${row.coins}" data-id="${row.id}" data-field="coins" />
        </div>
      </td>
      <td>${row.presenter_code ? escapeHtml(row.presenter_code) : '-'}</td>
    <td class="row-actions">
  <a class="btn btn--outline btn--sm" href="/admin/movimenti.php?uid=${row.id}">Movimenti</a>
  <button class="btn btn--outline btn--sm" data-act="save" data-id="${row.id}">Applica</button>
  <button class="btn btn--outline btn--sm btn-danger" data-act="delete" data-id="${row.id}">Elimina</button>
</td>
    `;
    tb.appendChild(tr);
  });

  $('#rowsInfo').textContent = `${j.rows.length} / ${j.total} mostrati`;
}
function escapeHtml(s){ return (s??'').toString().replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m])); }

// ====== Sorting ======
$$('.th-sort').forEach(th=>{
  th.addEventListener('click', ()=>{
    const key = th.getAttribute('data-sort');
    if (state.sort===key){ state.dir = (state.dir==='asc'?'desc':'asc'); }
    else { state.sort = key; state.dir='asc'; }
    loadTable();
  });
});

// ====== Search ======
$('#btnSearch').addEventListener('click', ()=>{ state.q = $('#search').value.trim(); state.page=1; loadTable(); });
$('#search').addEventListener('keydown', (e)=>{ if(e.key==='Enter'){ e.preventDefault(); $('#btnSearch').click(); } });

// ====== Delegazione eventi tabella ======
$('#playersTbl').addEventListener('click', async (e)=>{
  const btn = e.target.closest('button, a');
  if (!btn) return;

  // open modal on username click
  if (btn.matches('.link-user')){
    e.preventDefault();
    const id = btn.getAttribute('data-id');
    openModal(id);
    return;
  }

  const id = parseInt(btn.getAttribute('data-id'),10);
  const act = btn.getAttribute('data-act');

  if (act === 'toggle'){
    const r = await fetch('?action=toggle_active', {method:'POST', body:new URLSearchParams({id})});
    const j = await r.json();
    if (!j.ok) { toast('Errore cambio stato'); return; }
    loadTable();
  }

  if (act === 'save'){
    const input = $('#playersTbl input[data-id="'+id+'"][data-field="coins"]');
    const coins = input ? input.value : '0';
    const r = await fetch('?action=update_inline', {method:'POST', body:new URLSearchParams({id, coins})});
    const j = await r.json();
    if (!j.ok){ toast('Errore salvataggio'); return; }
    loadTable();
  }

  if (act === 'delete'){
    if (!confirm('Eliminare utente?')) return;
    const r = await fetch('?action=delete_user', {method:'POST', body:new URLSearchParams({id})});
    const j = await r.json();
    if (!j.ok){ toast(j.error==='forbidden'?'Non puoi eliminare un ADMIN':'Errore eliminazione'); return; }
    loadTable();
  }
});

const modal = $('#userModal');
const steps = () => $$('.step', modal);
const dots  = () => $$('.steps-dots .dot', modal);
let stepIdx = 0;

function setStep(i){
  stepIdx = Math.max(0, Math.min(i, steps().length-1));
  steps().forEach((s,idx)=>s.classList.toggle('active', idx===stepIdx));
  dots().forEach((d,idx)=>d.classList.toggle('active', idx<=stepIdx));
  $('#mPrev').classList.toggle('hidden', stepIdx===0);
  $('#mNext').classList.toggle('hidden', stepIdx===steps().length-1);
  $('#btnApplyUser').classList.toggle('hidden', stepIdx!==steps().length-1);
  // scroll to top of modal body each step
  $('.modal-body.scroller', modal).scrollTop = 0;
}

$$('[data-close]').forEach(el=>el.addEventListener('click', closeModal));

async function openModal(id){
  const r = await fetch('?action=details&id='+encodeURIComponent(id), {cache:'no-store'});
  const j = await r.json();
  if (!j.ok){ toast('Utente non trovato'); return; }
  fillForm(j.user);
  modal.setAttribute('aria-hidden','false');
  document.body.classList.add('modal-open');
  setStep(0);
}
function closeModal(){
  modal.setAttribute('aria-hidden','true');
  document.body.classList.remove('modal-open');
  $('#userForm').reset();
}
function fillForm(u){
  $('#u_id').value = u.id;
  $('#u_username').value = u.username||'';
  $('#u_email').value = u.email||'';
  $('#u_cell').value = u.cell||'';
  $('#u_cf').value = u.codice_fiscale||'';
  $('#u_nome').value = u.nome||'';
  $('#u_cognome').value = u.cognome||'';
  $('#u_via').value = u.via||'';
  $('#u_civico').value = u.civico||'';
  $('#u_citta').value = u.citta||'';
  $('#u_prov').value = u.prov||'';
  $('#u_cap').value = u.cap||'';
  $('#u_nazione').value = u.nazione||'';
  $('#u_tipo_doc').value = u.tipo_doc||'PATENTE';
  $('#u_num_doc').value = u.num_doc||'';
  $('#u_rilascio').value = u.data_rilascio||'';
  $('#u_scadenza').value = u.data_scadenza||'';
  $('#u_rilasciato_da').value = u.rilasciato_da||'';
  $('#u_presenter').value = u.presenter_code||'';
  $('#u_is_active').value = String(u.is_active||1);
  $('#u_coins').value = u.coins||'0.00';
  $('#u_new_password').value = '';
}

// Navigazione step
$('#mPrev').addEventListener('click', ()=> setStep(stepIdx-1));
$('#mNext').addEventListener('click', ()=>{
  // validazioni minime per ogni step
  const current = steps()[stepIdx];
  const invalid = current.querySelector(':invalid');
  if (invalid){ invalid.reportValidity(); return; }
  setStep(stepIdx+1);
});

// Salva modifiche
$('#btnApplyUser').addEventListener('click', async ()=>{
  const invalid = $('#userForm').querySelector(':invalid');
  if (invalid){ invalid.reportValidity(); return; }
  const fd = new FormData($('#userForm'));
  const r = await fetch('?action=update_user', {method:'POST', body:fd});
  const j = await r.json();
  if (j.ok){ closeModal(); loadTable(); return; }
  if (j.errors){
    const [k,msg] = Object.entries(j.errors)[0];
    toast(msg); return;
  }
  toast('Errore salvataggio');
});

// ====== Init ======
loadTable();
</script>
