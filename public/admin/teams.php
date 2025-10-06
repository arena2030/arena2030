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

// LIST (via POST per evitare cache intermedie)
if ($a === 'list') {
  // leggi q sia da POST che da GET (compat)
  $q = trim($_POST['q'] ?? $_GET['q'] ?? '');
  $where = '';
  $args  = [];
  if ($q !== '') {
    $where = "WHERE name LIKE ? OR short_name LIKE ? OR slug LIKE ?";
    $like  = "%$q%"; $args = [$like,$like,$like];
  }

  $st = $pdo->prepare("SELECT id, name, short_name, country_code, slug, logo_url FROM teams $where ORDER BY name ASC");
  $st->execute($args);

  // no-cache FORTISSIMO
  header('Expires: 0');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Cache-Control: post-check=0, pre-check=0', false);
  header('Pragma: no-cache');

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

  // DELETE (team + media se esiste + file su R2 best-effort)
  if ($a === 'delete') {
    only_post();
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) json(['ok'=>false,'error'=>'required']);

    // prendo dati logo
    $st = $pdo->prepare("SELECT logo_key, logo_url FROM teams WHERE id=?");
    $st->execute([$id]);
    $team = $st->fetch(PDO::FETCH_ASSOC);
    if (!$team) json(['ok'=>false,'error'=>'not_found']);

    $logoKey = $team['logo_key'] ?? null;
    $logoUrl = $team['logo_url'] ?? null;

    // controlla se esiste la tabella media
    $hasMedia = (bool)$pdo->query("
      SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = 'media'
    ")->fetchColumn();

    try {
      $pdo->beginTransaction();

      // elimina la squadra
      $del = $pdo->prepare("DELETE FROM teams WHERE id=? LIMIT 1");
      $del->execute([$id]);

      // elimina record media solo se la tabella esiste
      if ($hasMedia && ($logoKey || $logoUrl)) {
        $md = $pdo->prepare("DELETE FROM media WHERE storage_key = ? OR url = ?");
        $md->execute([$logoKey ?? '', $logoUrl ?? '']);
      }

      $pdo->commit();
    } catch (Throwable $e) {
      $pdo->rollBack();
      json(['ok'=>false,'error'=>'db_delete_failed','detail'=>$e->getMessage()]);
    }

    // prova a cancellare il file su R2 (best-effort, non blocca l'ok)
    if ($logoKey) {
      $autoload = __DIR__ . '/../../vendor/autoload.php';
      if (file_exists($autoload)) {
        require_once $autoload;
        $endpoint = getenv('S3_ENDPOINT');
        $bucket   = getenv('S3_BUCKET');
        $keyId    = getenv('S3_KEY');
        $secret   = getenv('S3_SECRET');
        if ($endpoint && $bucket && $keyId && $secret) {
          try {
            $s3 = new Aws\S3\S3Client([
              'version' => 'latest',
              'region'  => 'auto',
              'endpoint'=> $endpoint,
              'use_path_style_endpoint' => true,
              'credentials' => ['key'=>$keyId,'secret'=>$secret],
            ]);
            $s3->deleteObject(['Bucket'=>$bucket,'Key'=>$logoKey]);
          } catch (Throwable $e) {
            // ignora: non blocca
          }
        }
      }
    }

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
  const fd = new URLSearchParams({ q, t: String(Date.now()) }); // anti-cache

  const r = await fetch('?action=list', {
    method: 'POST',
    body: fd,
    cache: 'no-store',
    headers: {
      'Cache-Control': 'no-cache, no-store, max-age=0',
      'Pragma': 'no-cache'
    }
  });

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
  <button type="button" class="btn btn--outline btn--sm" data-edit="${r.id}">Modifica</button>
  <button type="button" class="btn btn--outline btn--sm btn-danger" data-del="${r.id}">Elimina</button>
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

  if (btn.hasAttribute('data-edit')) {
    const id = parseInt(btn.getAttribute('data-edit') || '0', 10);
    if (!id) return;
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

    const id = parseInt(btn.getAttribute('data-del') || btn.getAttribute('data-id') || '0', 10);
    const fd = new URLSearchParams({ id });

    btn.disabled = true;
    const resp = await fetch('?action=delete', { method: 'POST', body: fd });
    const j    = await resp.json();
    btn.disabled = false;

    if (!j.ok) {
      alert('Errore eliminazione: ' + (j.error || '') + (j.detail ? '\n' + j.detail : ''));
      return;
    }

    // ✅ rimuovi subito la riga dalla UI
    const tr = btn.closest('tr');
    if (tr) tr.remove();

    // poi sincronizza con il server (loadList ha già cache-buster & no-store)
    await loadList();
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

  // 2) eventuale upload logo (via server, niente CORS)
  const file = $('#t_file').files[0];
  if (file) {
    // 2.1 carico il file in R2 passando dal server
    const up = new FormData();
    up.append('file', file);
    up.append('type', 'generic'); // path generico uploads/YYYY/MM/...

    const upRes = await fetch('/api/upload_r2.php', { method: 'POST', body: up });
    const uj = await upRes.json();
    if (!uj.ok) {
      alert('Errore upload: ' + (uj.error || ''));
      return;
    }

    // 2.2 salvo metadati e aggancio al team (update teams.logo_url/logo_key)
    const meta = new FormData();
    meta.append('type', 'team_logo');          // semantica: è un logo squadra
    meta.append('storage_key', uj.key);        // es. uploads/2025/09/uuid.png
    meta.append('url', uj.cdn_url);            // URL pubblico
    meta.append('mime', uj.mime || file.type);
    meta.append('size', uj.size || file.size);
    meta.append('etag', uj.etag || '');
    meta.append('team_id', newId);             // ID della squadra appena creata/aggiornata

    await fetch('/api/media_save.php', { method: 'POST', body: meta });

    // 2.3 (ridondante ma utile) aggiorna anche via action locale — tiene la tabella coerente
    const fd3 = new URLSearchParams({ id: newId, logo_url: uj.cdn_url, logo_key: uj.key });
    const r3  = await fetch('?action=update_logo', { method: 'POST', body: fd3 });
    const j3  = await r3.json();
    if (!j3.ok) { alert('Logo aggiornato con errore'); } // non blocco l’operazione
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
<style>
  /* ======= Stile “card eleganti” — nessun cambio logico ======= */
  .section{ padding-top:24px; }
  .container{ max-width:1100px; margin:0 auto; }
  h1{ color:#fff; font-size:26px; font-weight:900; letter-spacing:.2px; margin:0 0 12px; }

  /* Topbar (ricerca + nuovo) */
  .admin-topbar{ display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:12px; flex-wrap:wrap; }
  .admin-topbar .actions{ display:flex; gap:8px; align-items:center; }

  /* Card elegante scura */
  .card{
    position:relative; border-radius:20px; padding:18px 18px 16px;
    background:
      radial-gradient(1000px 300px at 50% -120px, rgba(99,102,241,.10), transparent 60%),
      linear-gradient(135deg,#0e1526 0%, #0b1220 100%);
    border:1px solid rgba(255,255,255,.08);
    color:#fff;
    box-shadow: 0 20px 60px rgba(0,0,0,.35);
    transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease, background .15s ease;
    overflow:hidden;
    margin-bottom:16px;
  }
  .card::before{
    content:""; position:absolute; left:0; top:0; bottom:0; width:4px;
    background:linear-gradient(180deg,#1e3a8a 0%, #0ea5e9 100%); opacity:.35;
  }
  .card:hover{ transform: translateY(-2px); box-shadow: 0 26px 80px rgba(0,0,0,.48); border-color:#21324b; }
  .card-title{ margin:0 0 8px; font-size:18px; font-weight:800; color:#e5edff; }

  /* Tabella elegante */
  .table-wrap{ overflow:auto; border-radius:12px; }
  .table{ width:100%; border-collapse:separate; border-spacing:0; }
  .table thead th{
    text-align:left; font-weight:900; font-size:12px; letter-spacing:.3px;
    color:#9fb7ff; padding:10px 12px; background:#0f172a;
    border:0; box-shadow: inset 0 -1px #1e293b; /* linea sotto header */
    white-space:nowrap;
  }
  /* Celle corpo: niente bordi; una sola linea per riga */
  .table tbody td{
    padding:12px; color:#e5e7eb; font-size:14px; border:0; background:transparent; vertical-align:middle;
  }
  .table tbody tr{
    background: linear-gradient(0deg, rgba(255,255,255,.02), rgba(255,255,255,.02));
    box-shadow: inset 0 -1px #122036;   /* separatore riga */
  }
  .table tbody tr:hover{
    background: rgba(255,255,255,.025);
    box-shadow: inset 0 -1px #122036;
  }
  .table tbody tr:last-child td{ border-bottom:0; }

  /* Colonna azioni: in linea, centrata verticalmente */
  .table td.row-actions{ white-space:nowrap; padding:10px 12px; }
  .table td.row-actions{ display:inline-flex; align-items:center; gap:10px; }
  /* leggero offset per centrare i bottoni nella riga */
  .table td.row-actions .btn,
  .table td.row-actions .btn.btn--sm{ position:relative; top:2px; height:36px; line-height:36px; padding:0 16px; border-radius:9999px; margin:0; }

  /* Grid form nel modal */
  .grid2{ display:grid; grid-template-columns:1fr 1fr; gap:16px; }
  @media (max-width:860px){ .grid2{ grid-template-columns:1fr; } }

  /* Input coerenti col dark */
  .input.light, .select.light{
    width:100%; height:38px; padding:0 12px; border-radius:10px;
    background:#0f172a; border:1px solid #1f2937; color:#fff; appearance:none;
  }

  /* Modal elegante */
  .modal[aria-hidden="true"]{ display:none; }
  .modal{ position:fixed; inset:0; z-index:60; }
  .modal-open{ overflow:hidden; }
  .modal-backdrop{ position:absolute; inset:0; background:rgba(0,0,0,.5); }
  .modal-card{
    position:relative; z-index:61; width:min(780px,96vw);
    background:#0b1220; border:1px solid #1f2937; border-radius:16px;
    margin:6vh auto 0; padding:0; box-shadow:0 16px 48px rgba(0,0,0,.5);
    max-height:86vh; display:flex; flex-direction:column;
  }
  .modal-head{ display:flex; align-items:center; gap:10px; padding:12px 16px; border-bottom:1px solid #1f2937; }
  .modal-x{ margin-left:auto; background:transparent; border:0; color:#fff; font-size:24px; cursor:pointer; }
  .modal-body{ padding:16px; overflow:auto; }
  .modal-foot{ display:flex; justify-content:flex-end; gap:8px; padding:12px 16px; border-top:1px solid #1f2937; }
</style>
