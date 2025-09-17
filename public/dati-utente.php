<?php
// /public/dati-utente.php — Profilo utente (card bianca + biglietto da visita)
require_once __DIR__ . '/../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }

$uid = (int)($_SESSION['uid'] ?? 0);
$role = $_SESSION['role'] ?? 'USER';
if ($uid <= 0 || !in_array($role, ['USER','PUNTO','ADMIN'], true)) {
  header('Location: /login.php'); exit;
}

// ======== AJAX ENDPOINTS ========
function json($a){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($a); exit; }
function only_post(){ if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST'){ http_response_code(405); json(['ok'=>false,'error'=>'method']); } }

// Password policy (come registrazione)
function valid_password($p){
  return (bool)preg_match('/(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}/', $p ?? '');
}

// Unicità email/cell
function is_unique(PDO $pdo, string $col, string $val, int $exceptId){
  $st=$pdo->prepare("SELECT id FROM users WHERE $col = ? AND id <> ? LIMIT 1");
  $st->execute([$val, $exceptId]);
  return $st->fetchColumn() ? false : true;
}

if (isset($_GET['action'])) {
  $a = $_GET['action'];

  // Dettagli utente
  if ($a==='me') {
    header('Content-Type: application/json; charset=utf-8');
    $st=$pdo->prepare("SELECT id,user_code,username,nome,cognome,email,cell,COALESCE(coins,0) coins FROM users WHERE id=? LIMIT 1");
    $st->execute([$uid]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) json(['ok'=>false,'error'=>'not_found']);

    // avatar (ultimo media type=avatar)
    $st2=$pdo->prepare("SELECT url, storage_key FROM media WHERE type='avatar' AND owner_id=? ORDER BY id DESC LIMIT 1");
    $st2->execute([$uid]); $m=$st2->fetch(PDO::FETCH_ASSOC) ?: ['url'=>'','storage_key'=>''];
    $cdn = rtrim(getenv('CDN_BASE') ?: getenv('S3_CDN_BASE') ?: '', '/');
    $avatar = $m['url'] ?: ($m['storage_key'] && $cdn ? $cdn.'/'.$m['storage_key'] : '');

    json(['ok'=>true,'user'=>$u,'avatar'=>$avatar]);
  }

  // Aggiorna email/cell
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

  // Cambio password
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

// ======== VIEW ========
$page_css='/pages-css/admin-dashboard.css';
include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/header_utente.php';
?>
<style>
  .profile-wrap{ max-width: 860px; margin: 0 auto; }
  .card-white{
    background:#fff; color:#111; border-radius:16px; box-shadow:0 10px 40px rgba(0,0,0,.25);
    overflow:hidden;
  }
  .card-hero{
    background: linear-gradient(90deg, #2f80ff, #00c2ff);
    color:#fff; padding:20px; text-align:center; position:relative;
  }
  .hero-id{ position:absolute; left:16px; top:12px; font-weight:700; opacity:.9; }
  .hero-avatar{
    width:100px; height:100px; border-radius:50%; border:3px solid rgba(255,255,255,.8);
    margin:8px auto 10px auto; overflow:hidden; background:rgba(255,255,255,.2); display:flex; align-items:center; justify-content:center;
    font-size:40px; font-weight:800;
  }
  .hero-avatar img{ width:100%; height:100%; object-fit:cover; display:block; }
  .hero-username{ font-weight:800; font-size:20px; letter-spacing:.5px; }
  .hero-name{ opacity:.95; }

  .content{ padding:20px; }
  .grid2{ display:grid; grid-template-columns:1fr 1fr; gap:16px; }
  @media (max-width:800px){ .grid2{ grid-template-columns:1fr; } }

  .section-title{ margin:6px 0 10px; font-weight:700; }
  .divider{ border-top:1px solid #e8e8e8; margin:16px 0; }
  .muted{ color:#777; }

  .form-actions{ display:flex; justify-content:center; margin-top:16px; }
</style>

<main class="section">
  <div class="container">
    <div class="profile-wrap">
      <div class="card-white">

        <!-- HERO blu sfumato -->
        <div class="card-hero" id="hero">
          <div class="hero-id" id="uCode">ID: -</div>
          <div class="hero-avatar" id="uAvatar"><!-- img/inziale --></div>
          <div class="hero-username" id="uUsername">@username</div>
          <div class="hero-name" id="uName">Nome Cognome</div>
        </div>

        <!-- Contenuti bianchi -->
        <div class="content">
          <div class="section-title">Contatti</div>
          <div class="grid2">
            <div class="field">
              <label class="label">Email</label>
              <input class="input light" id="f_email" type="email" placeholder="email@example.com" required />
              <small class="muted" id="err_email"></small>
            </div>
            <div class="field">
              <label class="label">Telefono</label>
              <input class="input light" id="f_cell" type="text" placeholder="Telefono" required />
              <small class="muted" id="err_cell"></small>
            </div>
          </div>

          <div class="divider"></div>

          <div class="section-title">Cambio password</div>
          <div class="grid2">
            <div class="field">
              <label class="label">Password attuale</label>
              <input class="input light" id="f_old" type="password" required />
              <small class="muted" id="err_old"></small>
            </div>
            <div class="field">
              <label class="label">Nuova password</label>
              <input class="input light" id="f_new" type="password" required />
              <small class="muted" id="err_new"></small>
            </div>
            <div class="field">
              <label class="label">Conferma nuova password</label>
              <input class="input light" id="f_new2" type="password" required />
              <small class="muted" id="err_new2"></small>
            </div>
          </div>

          <div class="form-actions">
            <button class="btn btn--primary" id="btnSave">Aggiorna</button>
          </div>
        </div>

      </div>
    </div>
  </div>
</main>

<?php include __DIR__ . '/../partials/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', ()=>{
  const $=s=>document.querySelector(s);

  function showErr(id,msg){ const el=$(id); if(el){ el.textContent=msg||''; el.style.color= msg ? '#e21b2c' : '#777'; } }
  function clearErrors(){ ['#err_email','#err_cell','#err_old','#err_new','#err_new2'].forEach(id=>showErr(id,'')); }

  async function loadMe(){
    try{
      const r=await fetch('?action=me',{cache:'no-store'}); const j=await r.json();
      if(!j.ok) return;
      const u=j.user||{};
      $('#uCode').textContent = 'ID: ' + (u.user_code || u.id || '-');
      $('#uUsername').textContent = '@' + (u.username || '-');
      $('#uName').textContent = (u.nome||'') + ' ' + (u.cognome||'');
      $('#f_email').value = u.email || '';
      $('#f_cell').value  = u.cell  || '';
      const av = j.avatar || '';
      const avBox = $('#uAvatar');
      avBox.innerHTML = '';
      if (av){
        const img=document.createElement('img'); img.src=av; img.alt='Avatar'; avBox.appendChild(img);
      } else {
        const span=document.createElement('span'); span.textContent=(u.username||'?').charAt(0).toUpperCase(); avBox.appendChild(span);
      }
    }catch(e){}
  }

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
      } else { alert('Errore aggiornamento contatti'); }
      return false;
    }
    return true;
  }

  async function changePassword(){
    clearErrors();
    const old=$('#f_old').value, nw=$('#f_new').value, nw2=$('#f_new2').value;
    if (!old && !nw && !nw2) return true; // non sta cambiando password
    const r=await fetch('?action=change_password',{method:'POST', body:new URLSearchParams({old_password:old,new_password:nw,new_password2:nw2})});
    const j=await r.json();
    if (!j.ok){
      if (j.errors){
        if (j.errors.old_password) showErr('#err_old', j.errors.old_password);
        if (j.errors.new_password) showErr('#err_new', j.errors.new_password);
        if (j.errors.new_password2) showErr('#err_new2', j.errors.new_password2);
        if (j.errors.form) alert(j.errors.form);
      } else { alert('Errore cambio password'); }
      return false;
    }
    // reset campi se ok
    $('#f_old').value=''; $('#f_new').value=''; $('#f_new2').value='';
    return true;
  }

  $('#btnSave').addEventListener('click', async ()=>{
    const ok1 = await updateContact();
    if (!ok1) return;
    const ok2 = await changePassword();
    if (!ok2) return;
    alert('Dati aggiornati');
  });

  loadMe();
});
</script>
