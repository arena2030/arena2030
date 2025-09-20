<?php
// /public/dati-utente.php — Profilo utente (UI dark premium, funzioni invariate)
require_once __DIR__ . '/../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }

$uid  = (int)($_SESSION['uid'] ?? 0);
$role = $_SESSION['role'] ?? 'USER';
if ($uid <= 0 || !in_array($role, ['USER','PUNTO','ADMIN'], true)) {
  header('Location: /login.php'); exit;
}

/* ======== AJAX ENDPOINTS (identici) ======== */
function json($a){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($a); exit; }
function only_post(){ if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST'){ http_response_code(405); json(['ok'=>false,'error'=>'method']); } }
function valid_password($p){ return (bool)preg_match('/(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}/', $p ?? ''); }
function is_unique(PDO $pdo, string $col, string $val, int $exceptId){
  $st=$pdo->prepare("SELECT id FROM users WHERE $col = ? AND id <> ? LIMIT 1");
  $st->execute([$val, $exceptId]); return $st->fetchColumn() ? false : true;
}

if (isset($_GET['action'])) {
  $a = $_GET['action'];

  if ($a==='me') {
    header('Content-Type: application/json; charset=utf-8');
    $st=$pdo->prepare("SELECT id,user_code,username,nome,cognome,email,cell,COALESCE(coins,0) coins FROM users WHERE id=? LIMIT 1");
    $st->execute([$uid]); $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) json(['ok'=>false,'error'=>'not_found']);

    $st2=$pdo->prepare("SELECT url, storage_key FROM media WHERE type='avatar' AND owner_id=? ORDER BY id DESC LIMIT 1");
    $st2->execute([$uid]); $m=$st2->fetch(PDO::FETCH_ASSOC) ?: ['url'=>'','storage_key'=>''];
    $cdn = rtrim(getenv('CDN_BASE') ?: getenv('S3_CDN_BASE') ?: '', '/');
    $avatar = $m['url'] ?: ($m['storage_key'] && $cdn ? $cdn.'/'.$m['storage_key'] : '');

    json(['ok'=>true,'user'=>$u,'avatar'=>$avatar]);
  }

  if ($a==='update_contact') {
    only_post();
    $email = strtolower(trim($_POST['email'] ?? ''));
    $cell  = preg_replace('/\s+/', '', trim($_POST['cell'] ?? ''));

    $errors = [];
    if ($email==='') $errors['email']='Obbligatoria';
    if ($cell==='')  $errors['cell']='Obbligatorio';
    if ($email!=='' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email']='Formato email non valido';
    if ($errors) json(['ok'=>false,'errors'=>$errors]);

    if (!is_unique($pdo,'email',$email,$uid)) $errors['email']='Email già in uso';
    if (!is_unique($pdo,'cell',$cell,$uid))   $errors['cell']='Telefono già in uso';
    if ($errors) json(['ok'=>false,'errors'=>$errors]);

    $st=$pdo->prepare("UPDATE users SET email=?, cell=? WHERE id=?");
    $st->execute([$email,$cell,$uid]);
    json(['ok'=>true]);
  }

  if ($a==='change_password') {
    only_post();
    $old = $_POST['old_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $rep = $_POST['new_password2'] ?? '';
    if ($old==='' || $new==='' || $rep==='') json(['ok'=>false,'errors'=>['form'=>'Compila tutti i campi']]);
    if ($new !== $rep) json(['ok'=>false,'errors'=>['new_password2'=>'Le password non coincidono']]);
    if (!valid_password($new)) json(['ok'=>false,'errors'=>['new_password'=>'Password non conforme (min 8, maiuscola, minuscola, numero, simbolo)']]);
    $st=$pdo->prepare("SELECT password_hash FROM users WHERE id=?");
    $st->execute([$uid]); $hash = (string)$st->fetchColumn();
    if (!$hash || !password_verify($old, $hash)) json(['ok'=>false,'errors'=>['old_password'=>'Password attuale errata']]);
    $st=$pdo->prepare("UPDATE users SET password_hash=? WHERE id=?");
    $st->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
    json(['ok'=>true]);
  }

  http_response_code(400); json(['ok'=>false,'error'=>'unknown_action']);
}

/* ======== VIEW (UI rinnovata dark) ======== */
$page_css='/pages-css/admin-dashboard.css';
include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/header_utente.php';
?>
<style>
/* ===== Shell ===== */
.profile-wrap{ max-width: 980px; margin: 0 auto; }
.card {
  position:relative; border-radius:18px; overflow:hidden;
  background:
    radial-gradient(1200px 300px at 50% -140px, rgba(99,102,241,.12), transparent 60%),
    linear-gradient(135deg,#0e1526 0%, #0b1220 100%);
  border:1px solid #1e2a44;
  color:#e5edf7;
  box-shadow:0 22px 70px rgba(0,0,0,.45);
}

/* ===== HERO (vetrina utente) ===== */
.card-hero{
  position:relative; padding:28px 22px 24px;
  border-bottom:1px solid #1b2741;
  border-radius:16px;
  background:
    radial-gradient(1200px 360px at 50% -150px, rgba(14,165,233,.18), transparent 60%),
    linear-gradient(92deg, #102348 0%, #0a1630 100%);
}
.hero-top{
  display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:12px;
}
.hero-id{
  font-size:12px; letter-spacing:.5px; font-weight:800;
  background: rgba(30,58,138,.18);
  border:1px solid rgba(30,58,138,.55);
  color:#9fb7ff; padding:4px 10px; border-radius:9999px;
}
.hero-badges{ display:flex; gap:8px; align-items:center; }
.badge{
  font-size:12px; font-weight:800; letter-spacing:.2px;
  border-radius:9999px; padding:4px 10px; border:1px solid #223052; background:#0f1a33; color:#cbd5e1;
}

.hero-main{
  display:flex; align-items:center; gap:16px; margin-top:6px;
}
.hero-avatar{
  width:88px; height:88px; border-radius:50%; overflow:hidden; flex:0 0 88px;
  background:#0c1222; border:1px solid #253457;
  box-shadow:0 0 0 3px rgba(147,197,253,.18), 0 18px 40px rgba(0,0,0,.45);
}
.hero-avatar img{ width:100%; height:100%; object-fit:cover; display:block; }
.hero-avatar .initial{ width:100%; height:100%; display:flex; align-items:center; justify-content:center; font-weight:900; font-size:34px; color:#9fb7ff; }

.hero-texts{ min-width:0; }
.hero-username{ font-weight:900; letter-spacing:1px; font-size:22px; text-transform:uppercase; }
.hero-name{ opacity:.85; margin-top:2px; }
.hero-credits{
  margin-top:8px; display:inline-flex; align-items:center; gap:8px;
  padding:6px 10px; border-radius:12px; border:1px solid rgba(253,224,71,.35);
  background: rgba(253,224,71,.08); color:#fde047; font-weight:900; letter-spacing:.3px;
}

/* ===== Content ===== */
.content{ padding:18px; }
.sections{ display:grid; grid-template-columns: 1fr 1fr; gap:16px; }
@media (max-width:860px){ .sections{ grid-template-columns:1fr; } }

.panel{
  background: rgba(6,11,25,.55);
  border:1px solid #1d2a48; border-radius:14px; padding:14px;
  backdrop-filter: blur(6px);
}
.panel h3{ margin:2px 0 12px 0; font-size:14px; letter-spacing:.3px; font-weight:900; color:#e6eefc; }
.small-muted{ color:#8aa2c2; font-size:12px; }

/* Inputs dark */
.field{ margin-bottom:12px; }
.field .label{ display:block; font-size:12px; color:#9fb7ff; margin-bottom:6px; font-weight:700; }
.input{
  width:100%; height:38px; border-radius:10px;
  background:#0c1628; border:1px solid #1e2a44; color:#e5edf7; padding:0 12px;
}
.input:focus{ outline:none; border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,.18); }

/* Password grid */
.grid2{ display:grid; grid-template-columns:1fr 1fr; gap:12px; }
@media (max-width:860px){ .grid2{ grid-template-columns:1fr; } }

/* Actions */
.actions{ display:flex; justify-content:flex-end; gap:8px; margin-top:8px; }
.btn-prim{
  background:#2563eb; color:#fff; border:1px solid #3b82f6; border-radius:10px; padding:10px 14px; font-weight:800;
}
.btn-prim:hover{ filter:brightness(1.05); }
.btn-ghost{
  background:transparent; color:#cbd5e1; border:1px solid #334465; border-radius:10px; padding:10px 14px; font-weight:800;
}
.btn-ghost:hover{ background:#0c1628; }

/* Toast */
.toast{
  position:fixed; left:50%; bottom:24px; transform:translateX(-50%);
  background:#0b1220; color:#dbe7ff; border:1px solid #1e2a44; padding:10px 14px; border-radius:10px;
  box-shadow:0 18px 60px rgba(0,0,0,.45); font-weight:800; letter-spacing:.3px; display:none; z-index:90;
}
.toast.show{ display:block; animation:fadeInOut 2.2s ease both; }
@keyframes fadeInOut{ 0%{opacity:0; transform:translate(-50%,10px)} 10%{opacity:1; transform:translate(-50%,0)} 90%{opacity:1} 100%{opacity:0; transform:translate(-50%,10px)} }
.err{ color:#fda4af; font-size:12px; margin-top:4px; }
</style>

<main class="section">
  <div class="container">
    <div class="profile-wrap">
      <div class="card">

        <!-- HERO -->
        <div class="card-hero">
          <div class="hero-top">
            <div class="hero-id" id="uCode">ID: -</div>
            <div class="hero-badges">
              <div class="badge" id="uRole">Ruolo: <?php echo htmlspecialchars($role); ?></div>
              <div class="badge">Profilo</div>
            </div>
          </div>

          <div class="hero-main">
            <div class="hero-avatar" id="uAvatar"></div>
            <div class="hero-texts">
              <div class="hero-username" id="uUsername">@username</div>
              <div class="hero-name" id="uName">Nome Cognome</div>
              <div class="hero-credits"><span id="uCredits">0.00</span> ARENA COINS</div>
            </div>
          </div>
        </div>

        <!-- CONTENT -->
        <div class="content">
          <div class="sections">
            <!-- Contatti -->
            <div class="panel">
              <h3>Contatti</h3>
              <div class="field">
                <label class="label" for="f_email">Email</label>
                <input class="input" id="f_email" type="email" placeholder="email@example.com" />
                <div class="err" id="err_email"></div>
              </div>
              <div class="field">
                <label class="label" for="f_cell">Telefono</label>
                <input class="input" id="f_cell" type="text" placeholder="Telefono" />
                <div class="err" id="err_cell"></div>
              </div>
              <div class="actions">
                <button class="btn-ghost" id="btnSaveContacts">Salva contatti</button>
              </div>
            </div>

            <!-- Password -->
            <div class="panel">
              <h3>Cambio password</h3>
              <div class="grid2">
                <div class="field">
                  <label class="label" for="f_old">Password attuale</label>
                  <input class="input" id="f_old" type="password" autocomplete="current-password" />
                  <div class="err" id="err_old"></div>
                </div>
                <div class="field">
                  <label class="label" for="f_new">Nuova password</label>
                  <input class="input" id="f_new" type="password" autocomplete="new-password" />
                  <div class="err" id="err_new"></div>
                </div>
                <div class="field">
                  <label class="label" for="f_new2">Conferma nuova password</label>
                  <input class="input" id="f_new2" type="password" autocomplete="new-password" />
                  <div class="err" id="err_new2"></div>
                </div>
              </div>
              <div class="actions">
                <button class="btn-prim" id="btnSavePwd">Aggiorna password</button>
              </div>
            </div>
          </div>

          <div class="small-muted" style="margin-top:10px;">
            Suggerimento: usa una password lunga e unica. I dati vengono salvati immediatamente.
          </div>
        </div>

      </div>
    </div>
  </div>
</main>

<div class="toast" id="toast">Salvato!</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', ()=>{
  const $ = s => document.querySelector(s);

  function showErr(id, msg){ const el=$(id); if(el){ el.textContent = msg||''; } }
  function clearErrors(){ ['#err_email','#err_cell','#err_old','#err_new','#err_new2'].forEach(id=>showErr(id,'')); }
  function toast(msg){ const t=$('#toast'); if(!t) return; t.textContent=msg||'Salvato!'; t.classList.remove('show'); void t.offsetWidth; t.classList.add('show'); }

  // ========== LOAD ==========
  async function loadMe(){
    try{
      const r = await fetch('?action=me', { cache:'no-store' });
      const j = await r.json(); if(!j.ok) return;
      const u = j.user || {};
      $('#uCode').textContent = 'ID: ' + (u.user_code || u.id || '-');
      $('#uUsername').textContent = '@' + (u.username || '-');
      $('#uName').textContent = (u.nome || '') + ' ' + (u.cognome || '');
      $('#uCredits').textContent = Number(u.coins || 0).toFixed(2);
      // avatar
      const av = j.avatar || '';
      const box = $('#uAvatar'); box.innerHTML='';
      if (av){
        const img=document.createElement('img'); img.src=av; img.alt='Avatar'; box.appendChild(img);
      }else{
        const s=document.createElement('div'); s.className='initial'; s.textContent=(u.username||'?').charAt(0).toUpperCase(); box.appendChild(s);
      }
      // contatti
      $('#f_email').value = u.email || '';
      $('#f_cell').value  = u.cell  || '';
    }catch(e){}
  }

  // ========== SAVE CONTACTS ==========
  async function updateContact(){
    clearErrors();
    const email = $('#f_email').value.trim().toLowerCase();
    const cell  = $('#f_cell').value.trim();
    const r=await fetch('?action=update_contact',{method:'POST', body:new URLSearchParams({email,cell})});
    const j=await r.json();
    if (!j.ok){
      if (j.errors){
        if (j.errors.email) showErr('#err_email', j.errors.email);
        if (j.errors.cell)  showErr('#err_cell', j.errors.cell);
      } else { toast('Errore aggiornamento'); }
      return false;
    }
    toast('Contatti aggiornati');
    return true;
  }

  // ========== SAVE PASSWORD ==========
  async function changePassword(){
    clearErrors();
    const old=$('#f_old').value, nw=$('#f_new').value, nw2=$('#f_new2').value;
    if (!old && !nw && !nw2) { toast('Nulla da aggiornare'); return true; }
    const r=await fetch('?action=change_password',{method:'POST', body:new URLSearchParams({old_password:old,new_password:nw,new_password2:nw2})});
    const j=await r.json();
    if (!j.ok){
      if (j.errors){
        if (j.errors.old_password) showErr('#err_old', j.errors.old_password);
        if (j.errors.new_password) showErr('#err_new', j.errors.new_password);
        if (j.errors.new_password2) showErr('#err_new2', j.errors.new_password2);
        if (j.errors.form) toast(j.errors.form);
      } else { toast('Errore cambio password'); }
      return false;
    }
    $('#f_old').value=''; $('#f_new').value=''; $('#f_new2').value='';
    toast('Password aggiornata');
    return true;
  }

  // Bind bottoni
  $('#btnSaveContacts').addEventListener('click', updateContact);
  $('#btnSavePwd').addEventListener('click', changePassword);

  loadMe();
});
</script>
