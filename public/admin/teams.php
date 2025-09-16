<?php
require_once __DIR__ . '/../../partials/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['uid']) || !(($_SESSION['role'] ?? 'USER') === 'ADMIN' || (int)($_SESSION['is_admin'] ?? 0) === 1)) {
  header('Location: /login.php'); exit;
}

function json($a){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($a); exit; }
function only_post(){ if ($_SERVER['REQUEST_METHOD']!=='POST'){ http_response_code(405); json(['ok'=>false,'error'=>'method']); } }

if (isset($_GET['action'])) {
  $a = $_GET['action'];

  // LIST
  if ($a === 'list') {
    $q = trim($_GET['q'] ?? '');
    $where = '';
    $args = [];
    if ($q !== '') {
      $where = "WHERE name LIKE ? OR short_name LIKE ? OR slug LIKE ?";
      $like = "%$q%"; $args = [$like,$like,$like];
    }
    $st = $pdo->prepare("SELECT id, name, short_name, country_code, slug, logo_url FROM teams $where ORDER BY name ASC");
    $st->execute($args);
    json(['ok'=>true,'rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
  }

  // CREATE
  if ($a === 'create') {
    only_post();
    $name = trim($_POST['name'] ?? '');
    $short= trim($_POST['short_name'] ?? '');
    $cc   = strtoupper(trim($_POST['country_code'] ?? ''));
    $slug = trim($_POST['slug'] ?? '');
    if ($name==='' || $slug==='') json(['ok'=>false,'error'=>'required']);

    try{
      $st = $pdo->prepare("INSERT INTO teams(name, short_name, country_code, slug) VALUES (?,?,?,?)");
      $st->execute([$name,$short,$cc ?: null,$slug]);
      json(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]);
    }catch(PDOException $e){
      $msg = $e->errorInfo[2] ?? $e->getMessage();
      if (strpos($msg,'uq_slug')!==false) json(['ok'=>false,'errors'=>['slug'=>'Slug già in uso']]);
      if (strpos($msg,'uq_name_country')!==false) json(['ok'=>false,'errors'=>['name'=>'Nome già presente per questo paese']]);
      json(['ok'=>false,'error'=>'db','detail'=>$msg]);
    }
  }

  // UPDATE base (no logo)
  if ($a === 'update') {
    only_post();
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $short= trim($_POST['short_name'] ?? '');
    $cc   = strtoupper(trim($_POST['country_code'] ?? ''));
    $slug = trim($_POST['slug'] ?? '');
    if (!$id || $name==='' || $slug==='') json(['ok'=>false,'error'=>'required']);

    try{
      $st = $pdo->prepare("UPDATE teams SET name=?, short_name=?, country_code=?, slug=? WHERE id=?");
      $st->execute([$name, $short ?: null, $cc ?: null, $slug, $id]);
      json(['ok'=>true]);
    }catch(PDOException $e){
      $msg = $e->errorInfo[2] ?? $e->getMessage();
      if (strpos($msg,'uq_slug')!==false) json(['ok'=>false,'errors'=>['slug'=>'Slug già in uso']]);
      if (strpos($msg,'uq_name_country')!==false) json(['ok'=>false,'errors'=>['name'=>'Nome già presente per questo paese']]);
      json(['ok'=>false,'error'=>'db','detail'=>$msg]);
    }
  }

  // UPDATE logo
  if ($a === 'update_logo') {
    only_post();
    $id  = (int)($_POST['id'] ?? 0);
    $url = trim($_POST['logo_url'] ?? '');
    $key = trim($_POST['logo_key'] ?? '');
    if (!$id || $url==='') json(['ok'=>false,'error'=>'required']);
    $st = $pdo->prepare("UPDATE teams SET logo_url=?, logo_key=? WHERE id=?");
    $st->execute([$url, $key ?: null, $id]);
    json(['ok'=>true]);
  }

  // DELETE
  if ($a === 'delete') {
    only_post();
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) json(['ok'=>false,'error'=>'required']);
    $st = $pdo->prepare("DELETE FROM teams WHERE id=? LIMIT 1");
    $st->execute([$id]);
    json(['ok'=>true]);
  }

  http_response_code(400); json(['ok'=>false,'error'=>'unknown_action']);
}

$page_css = '/pages-css/admin-dashboard.css';
include __DIR__ . '/../../partials/head.php';
include __DIR__ . '/../../partials/header_admin.php';
?>
<main>
  <section class="section">
    <div class="container">
      <h1>Gestione squadre</h1>

      <!-- Barra azioni -->
      <div class="admin-topbar">
        <div class="actions">
          <input id="q" class="input light" type="search" placeholder="Cerca (nome, slug)" />
          <button id="btnSearch" class="btn btn--outline btn--sm">Cerca</button>
        </div>
        <div>
          <button id="btnNew" class="btn btn--primary btn--sm">Aggiungi squadra</button>
        </div>
      </div>

      <!-- Tabella -->
      <div class="card">
        <h2 class="card-title">Squadre</h2>
        <div class="table-wrap">
          <table class="table" id="tbl">
            <thead>
              <tr>
                <th>ID</th>
                <th>Nome</th>
                <th>Breve</th>
                <th>Paese</th>
                <th>Slug</th>
                <th>Logo</th>
                <th>Azioni</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      </div>

      <!-- Modale add/edit -->
      <div class="modal" id="modal" aria-hidden="true">
        <div class="modal-backdrop" data-close></div>
        <div class="modal-card" style="max-width:720px;">
          <div class="modal-head">
            <h3 id="modalTitle">Nuova squadra</h3>
            <button class="modal-x" data-close>&times;</button>
          </div>
          <div class="modal-body scroller">
            <form id="fTeam" class="grid2">
              <input type="hidden" id="t_id">
              <div class="field"><label class="label">Nome *</label><input class="input light" id="t_name" required></div>
              <div class="field"><label class="label">Breve</label><input class="input light" id="t_short"></div>
              <div class="field"><label class="label">Paese (IT/GB/ES/DE/FR)</label><input class="input light" id="t_cc" maxlength="2"></div>
              <div class="field"><label class="label">Slug *</label><input class="input light" id="t_slug" required placeholder="ac-milan"></div>
              <div class="field" style="grid-column: span 2;">
                <label class="label">Logo (opzionale)</label>
                <input type="file" id="t_file" accept=".svg,.webp,.png,.jpg,.jpeg">
                <div id="t_logo_prev" style="margin-top:8px;"></div>
              </div>
            </form>
          </div>
          <div class="modal-foot">
            <button class="btn btn--outline" data-close>Annulla</button>
            <button class="btn btn--primary" id="btnSave">Salva</button>
          </div>
        </div>
      </div>

    </div>
  </section>
</main>
<?php include __DIR__ . '/../../partials/footer.php'; ?>

<script>
const $  = (s,p=document)=>p.querySelector(s);
const $$ = (s,p=document)=>[...p.querySelectorAll(s)];
function escapeHtml(s){ return (s??'').toString().replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m])); }

let rows = [];
async function loadList(){
  const q = $('#q').value.trim();
  const r = await fetch('?action=list&q='+encodeURIComponent(q), {cache:'no-store'});
  const j = await r.json();
  if (!j.ok) { alert('Errore caricamento'); return; }
  rows = j.rows || [];
  renderTable();
}
function renderTable(){
  const tb = $('#tbl tbody');
  tb.innerHTML = '';
  rows.forEach(r=>{
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${r.id}</td>
      <td>${escapeHtml(r.name)}</td>
      <td>${escapeHtml(r.short_name||'')}</td>
      <td>${escapeHtml(r.country_code||'')}</td>
      <td>${escapeHtml(r.slug)}</td>
      <td>${r.logo_url ? `<img src="${escapeHtml(r.logo_url)}" alt="" width="40" height="40" style="background:#fff;border-radius:8px;padding:2px;">` : '-'}</td>
      <td class="row-actions">
        <button class="btn btn--outline btn--sm" data-edit="${r.id}">Modifica</button>
        <button class="btn btn--outline btn--sm btn-danger" data-del="${r.id}">Elimina</button>
      </td>
    `;
    tb.appendChild(tr);
  });
}

/* Search */
$('#btnSearch').addEventListener('click', loadList);
$('#q').addEventListener('keydown', e=>{ if(e.key==='Enter'){ e.preventDefault(); loadList(); } });

/* New */
$('#btnNew').addEventListener('click', ()=>{
  $('#modalTitle').textContent = 'Nuova squadra';
  $('#t_id').value = '';
  $('#fTeam').reset();
  $('#t_logo_prev').innerHTML = '';
  openModal();
});

/* Edit/Delete */
$('#tbl').addEventListener('click', async e=>{
  const btn = e.target.closest('button');
  if (!btn) return;
  const id = parseInt(btn.getAttribute('data-edit')||btn.getAttribute('data-del')||'0',10);
  if (!id) return;
  if (btn.hasAttribute('data-edit')) {
    const r = rows.find(x=>x.id==id);
    if (!r) return;
    $('#modalTitle').textContent = 'Modifica squadra';
    $('#t_id').value = r.id;
    $('#t_name').value = r.name||'';
    $('#t_short').value = r.short_name||'';
    $('#t_cc').value = r.country_code||'';
    $('#t_slug').value = r.slug||'';
    $('#t_logo_prev').innerHTML = r.logo_url ? `<img src="${escapeHtml(r.logo_url)}" width="80" height="80" style="background:#fff;border-radius:12px;padding:4px;">` : '';
    openModal();
  } else {
    if (!confirm('Eliminare la squadra?')) return;
    const fd = new URLSearchParams({id});
    const resp = await fetch('?action=delete',{method:'POST', body:fd});
    const j = await resp.json();
    if (!j.ok) { alert('Errore eliminazione'); return; }
    loadList();
  }
});

/* Salva (create/update + logo opzionale) */
$('#btnSave').addEventListener('click', async ()=>{
  const id    = $('#t_id').value.trim();
  const name  = $('#t_name').value.trim();
  const short = $('#t_short').value.trim();
  const cc    = $('#t_cc').value.trim().toUpperCase();
  const slug  = $('#t_slug').value.trim();
  if (!name || !slug){ alert('Nome e slug sono obbligatori'); return; }

  // 1) salva base
  let newId = id ? parseInt(id,10) : 0;
  if (!id){ // create
    const fd = new URLSearchParams({name, short_name:short, country_code:cc, slug});
    const r  = await fetch('?action=create',{method:'POST', body:fd});
    const j  = await r.json();
    if (!j.ok){ return showErrors(j); }
    newId = j.id;
  } else {   // update
    const fd = new URLSearchParams({id, name, short_name:short, country_code:cc, slug});
    const r  = await fetch('?action=update',{method:'POST', body:fd});
    const j  = await r.json();
    if (!j.ok){ return showErrors(j); }
    newId = parseInt(id,10);
  }

  // 2) eventuale upload logo
  const file = $('#t_file').files[0];
  if (file){
    // presign (type=team_logo). Se usi i LEAGUE_CODE puoi aggiungerli, qui usiamo solo slug.
    const fd1 = new FormData();
    fd1.append('mime', file.type || 'application/octet-stream');
    fd1.append('type', 'team_logo');
    fd1.append('slug', slug);
    // Se vuoi una struttura teams/{LEAGUE_CODE}/{slug}/logo.ext passa anche league: fd1.append('league','SERIE_A');
    const p = await fetch('/api/presign.php',{method:'POST', body:fd1}).then(r=>r.json());
    if (!p.ok){ alert('Errore presign'); return; }

    // PUT verso lo storage
    await fetch(p.url, {method:'PUT', headers: p.headers||{}, body:file});

    // salva metadati su media (opzionale)
    const fd2 = new FormData();
    fd2.append('owner_id', 0);
    fd2.append('type','team_logo');
    fd2.append('storage_key', p.key);
    fd2.append('url', p.cdn_url);
    fd2.append('mime', file.type);
    await fetch('/api/media_save.php',{method:'POST', body:fd2});

    // aggiorna team.logo_url/logo_key
    const fd3 = new URLSearchParams({id:newId, logo_url:p.cdn_url, logo_key:p.key});
    const r3  = await fetch('?action=update_logo',{method:'POST', body:fd3});
    const j3  = await r3.json();
    if (!j3.ok){ alert('Logo aggiornato con errore'); } // non blocco
  }

  closeModal();
  loadList();
});

function showErrors(j){
  if (j.errors){
    const first = Object.values(j.errors)[0];
    alert(first);
  } else {
    alert('Errore: '+(j.error||''));
  }
}

function openModal(){ $('#modal').setAttribute('aria-hidden','false'); document.body.classList.add('modal-open'); }
function closeModal(){ $('#modal').setAttribute('aria-hidden','true'); document.body.classList.remove('modal-open'); }
$$('[data-close]').forEach(el=>el.addEventListener('click', closeModal));

/* Init */
loadList();
</script>
