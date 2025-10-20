<?php
// Login — card bianca, stile come lo screen (link reset)
$page_css = '/pages-css/login.css';

require_once __DIR__ . '/../partials/db.php'; // $pdo

// Hardening cookie sessione (prima di ogni session_start)
ini_set('session.cookie_httponly','1');
ini_set('session.cookie_samesite','Lax');
if (!empty($_SERVER['HTTPS'])) { ini_set('session.cookie_secure','1'); }

function norm_email($s){ return strtolower(trim($s)); }
function norm_username($s){ return trim($s); }

// === Utils 2FA (TOTP RFC6238) ===

// base32 encode (solo per generare il segreto)
function b32_rand_secret($len = 16){
  $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  $out=''; for($i=0;$i<$len;$i++) { $out.=$alphabet[random_int(0,31)]; }
  return $out;
}
// base32 decode (A–Z,2–7) in bytes
function b32_decode($b32){
  $b32=strtoupper(preg_replace('/[^A-Z2-7]/','',$b32));
  $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  $flipped=array_flip(str_split($alphabet));
  $buffer=0;$bits=0;$result='';
  for($i=0,$l=strlen($b32);$i<$l;$i++){
    $buffer=($buffer<<5)|$flipped[$b32[$i]];$bits+=5;
    if($bits>=8){ $bits-=8; $result.=chr(($buffer>>$bits)&0xff); }
  }
  return $result;
}
// genera codice TOTP per un dato timestep e segreto
function totp_code($secret, $timeStep = null, $digits = 6){
  if ($timeStep===null) $timeStep = floor(time()/30);
  $key = b32_decode($secret);
  $binTime = pack('N*', 0).pack('N*', $timeStep);
  $hash = hash_hmac('sha1', $binTime, $key, true);
  $offset = ord(substr($hash, -1)) & 0x0F;
  $part = (ord($hash[$offset]) & 0x7F) << 24 |
          (ord($hash[$offset+1]) & 0xFF) << 16 |
          (ord($hash[$offset+2]) & 0xFF) << 8  |
          (ord($hash[$offset+3]) & 0xFF);
  $code = $part % (10 ** $digits);
  return str_pad((string)$code, $digits, '0', STR_PAD_LEFT);
}
// verifica TOTP con finestra ±1 step
function totp_verify($secret, $code, $digits = 6, $window = 1){
  $code = preg_replace('/\D/','',$code);
  $t=floor(time()/30);
  for($w=-$window;$w<=$window;$w++){
    if (totp_code($secret, $t+$w, $digits) === $code) return true;
  }
  return false;
}
function otpauth_uri($issuer, $account, $secret){
  $label = rawurlencode($issuer.':'.$account);
  $issuerQ = rawurlencode($issuer);
  return "otpauth://totp/{$label}?secret={$secret}&issuer={$issuerQ}&period=30&algorithm=SHA1&digits=6";
}
// verifica/crea colonna totp_secret best-effort
function ensure_totp_column(PDO $pdo){
  try{
    $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='totp_secret' LIMIT 1");
    $q->execute(); if ($q->fetchColumn()) return true;
    $pdo->exec("ALTER TABLE users ADD COLUMN totp_secret VARCHAR(64) NULL");
    return true;
  }catch(Throwable $e){ return false; }
}

// --- Handler AJAX ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json; charset=utf-8');

  if (session_status() === PHP_SESSION_NONE) { session_start(); }
  $action = $_POST['__action__'] ?? '';

  // STEP 2: verifica 2FA per admin
  if ($action === 'verify_2fa') {
    $pendingUid = (int)($_SESSION['__2fa_pending_uid'] ?? 0);
    $pendingUser = $_SESSION['__2fa_pending_user'] ?? null; // array salvato allo step 1
    $code = trim($_POST['code'] ?? '');

    if ($pendingUid<=0 || !$pendingUser) {
      echo json_encode(['ok'=>false,'error'=>'no_pending_2fa']); exit;
    }
    if ($code===''){
      echo json_encode(['ok'=>false,'errors'=>['code'=>'Inserisci il codice a 6 cifre']]); exit;
    }
    // legge segreto dal DB
    $st=$pdo->prepare("SELECT id, user_code, username, email, password_hash, is_admin, role, totp_secret FROM users WHERE id=? LIMIT 1");
    $st->execute([$pendingUid]);
    $row=$st->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['totp_secret'])){
      echo json_encode(['ok'=>false,'error'=>'missing_secret']); exit;
    }
    if (!totp_verify($row['totp_secret'], $code)) {
      echo json_encode(['ok'=>false,'errors'=>['code'=>'Codice non valido o scaduto']]); exit;
    }

    // 2FA OK → completa login come prima
    session_regenerate_id(true); // prevenzione fixation
    $_SESSION['uid']       = (int)$row['id'];
    $_SESSION['user_code'] = $row['user_code'];
    $_SESSION['username']  = $row['username'];
    $_SESSION['email']     = $row['email'];
    $_SESSION['is_admin']  = isset($row['is_admin']) ? (int)$row['is_admin'] : 0;
    $_SESSION['role']      = $row['role'] ?? 'USER';

    // pulizia pendings
    unset($_SESSION['__2fa_pending_uid'], $_SESSION['__2fa_pending_user']);

    // routing
    $redirect = '/lobby.php';
    if ($_SESSION['role'] === 'ADMIN' || $_SESSION['is_admin'] === 1) {
      $redirect = '/admin/dashboard.php';
    } elseif ($_SESSION['role'] === 'PUNTO') {
      $redirect = '/punto/dashboard.php';
    }
    echo json_encode(['ok'=>true,'redirect'=>$redirect]); exit;
  }

  // STEP 1: login/password
  if ($action === 'login') {

    // Backoff minimo + contatore tentativi per IP (in sessione)
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $_SESSION['login_failures'] = $_SESSION['login_failures'] ?? [];
    $fails = (int)($_SESSION['login_failures'][$ip] ?? 0);
    usleep(min(100000 * max(0,$fails), 1000000)); // 100ms * fails, max 1s

    $id   = trim($_POST['id'] ?? '');           // email o username
    $pass = $_POST['password'] ?? '';

    if ($id === '' || $pass === '') {
      echo json_encode(['ok'=>false, 'errors'=>['id'=>'Campo obbligatorio','password'=>'Campo obbligatorio']]); exit;
    }

    $isEmail = (bool)filter_var($id, FILTER_VALIDATE_EMAIL);
    $col     = $isEmail ? 'email' : 'username';
    $val     = $isEmail ? norm_email($id) : norm_username($id);

    // includo totp_secret per capire se devo chiedere 2FA
    $stmt = $pdo->prepare("SELECT id, user_code, username, email, password_hash, is_admin, role, totp_secret FROM users WHERE $col = ? LIMIT 1");
    $stmt->execute([$val]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($pass, $user['password_hash'])) {
      $_SESSION['login_failures'][$ip] = (int)($_SESSION['login_failures'][$ip] ?? 0) + 1;
      echo json_encode(['ok'=>false, 'errors'=>['id'=>'Credenziali non valide','password'=>'Credenziali non valide']]); exit;
    }

    // reset contatore fallimenti
    $_SESSION['login_failures'][$ip] = 0;

    // Sei admin?
    $isAdmin = ((strtoupper(trim($user['role'] ?? '')) === 'ADMIN') || ((int)($user['is_admin'] ?? 0) === 1));

    // Se non admin → login normale (come prima)
    if (!$isAdmin) {
      if (session_status() === PHP_SESSION_NONE) { session_start(); }
      session_regenerate_id(true);
      $_SESSION['uid']       = (int)$user['id'];
      $_SESSION['user_code'] = $user['user_code'];
      $_SESSION['username']  = $user['username'];
      $_SESSION['email']     = $user['email'];
      $_SESSION['is_admin']  = isset($user['is_admin']) ? (int)$user['is_admin'] : 0;
      $_SESSION['role']      = $user['role'] ?? 'USER';

      $redirect = '/lobby.php';
      if ($_SESSION['role'] === 'ADMIN' || $_SESSION['is_admin'] === 1) {
        $redirect = '/admin/dashboard.php';
      } elseif ($_SESSION['role'] === 'PUNTO') {
        $redirect = '/punto/dashboard.php';
      }
      echo json_encode(['ok'=>true, 'redirect'=>$redirect]); exit;
    }

    // === ADMIN: richiedi 2FA ===

    // assicura colonna totp_secret (best-effort)
    ensure_totp_column($pdo);

    $secret = $user['totp_secret'] ?? null;

    // se non configurato → genera e salva segreto, mostra QR
    if (empty($secret)) {
      $secret = b32_rand_secret(16);
      try{
        $up=$pdo->prepare("UPDATE users SET totp_secret=? WHERE id=?");
        $up->execute([$secret, (int)$user['id']]);
      }catch(Throwable $e){ /* se fallisce, forzeremo setup ad ogni login finché non c'è colonna */ }
      $user['totp_secret'] = $secret;
    }

    // registra pending in sessione
    $_SESSION['__2fa_pending_uid']  = (int)$user['id'];
    $_SESSION['__2fa_pending_user'] = [
      'id'=>(int)$user['id'],
      'username'=>$user['username'],
      'email'=>$user['email']
    ];

    $issuer = 'Arena';
    $account = $user['email'] ?: $user['username'];
    $uri = otpauth_uri($issuer, $account, $user['totp_secret']);
    $qr  = 'https://chart.googleapis.com/chart?chs=220x220&cht=qr&chl='.rawurlencode($uri);

    echo json_encode([
      'ok'=>true,
      'require_2fa'=>true,
      'setup'=> empty($user['totp_secret']) ? true : false, // se vuoi distinguere primo setup
      'otpauth_uri'=>$uri,
      'qr_url'=>$qr
    ]);
    exit;
  }

  echo json_encode(['ok'=>false,'error'=>'bad_action']); exit;
}

// --- View ---
include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/header_guest.php';
?>
<main>
  <section class="section">
    <div class="container">
      <div class="login-card login-card--white">
        <h1 class="login-title">Accedi</h1>

        <!-- STEP 1: credenziali -->
        <form id="loginForm" novalidate>
          <div class="field">
            <label class="label" for="id">Email / Username</label>
            <input class="input light" id="id" name="id" type="text" required />
          </div>

          <div class="field">
            <label class="label" for="password">Password</label>
            <div class="pwd-wrap">
              <input class="input light" id="password" name="password" type="password" required />
              <button type="button" class="pwd-toggle" aria-label="Mostra password">👁️</button>
            </div>
            <div class="links-row">
              <a class="link-muted" href="/recupero-password.php">Hai dimenticato la password?</a>
            </div>
          </div>

          <div class="login-actions">
            <button type="submit" class="btn btn--primary btn--full">Accedi</button>
          </div>

          <input type="hidden" name="__action__" value="login">
        </form>

        <!-- STEP 2: 2FA admin -->
        <div id="twofaBox" class="twofa" style="display:none; margin-top:14px;">
          <div id="twofaSetup" style="display:none; text-align:center; margin-bottom:10px;">
            <p class="muted" style="margin-bottom:8px;">Scansiona il QR con Google Authenticator / Authy e inserisci il codice a 6 cifre.</p>
            <img id="twofaQr" src="" alt="QR 2FA" style="width:220px;height:220px;border-radius:12px;border:1px solid #e5e7eb"/>
            <p class="muted" id="twofaUri" style="word-break:break-all; font-size:12px; margin-top:6px;"></p>
          </div>
          <form id="twofaForm" novalidate>
            <div class="field">
              <label class="label" for="code">Codice 2FA</label>
              <input class="input light" id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="000000" required />
            </div>
            <div class="login-actions">
              <button type="submit" class="btn btn--primary btn--full">Verifica</button>
            </div>
            <input type="hidden" name="__action__" value="verify_2fa">
          </form>
        </div>

        <div class="login-alt">
          <span>Non hai un account?</span>
          <a class="btn btn--outline" href="/registrazione.php">Registrati</a>
        </div>
      </div>
    </div>
  </section>
</main>

<script>
(function(){
  const form = document.getElementById('loginForm');
  const pwd  = document.getElementById('password');
  const tog  = document.querySelector('.pwd-toggle');

  const twofaBox   = document.getElementById('twofaBox');
  const twofaSetup = document.getElementById('twofaSetup');
  const twofaQr    = document.getElementById('twofaQr');
  const twofaUriEl = document.getElementById('twofaUri');
  const twofaForm  = document.getElementById('twofaForm');
  const codeInput  = document.getElementById('code');

  if (tog) tog.addEventListener('click', ()=>{
    const t = pwd.getAttribute('type') === 'password' ? 'text' : 'password';
    pwd.setAttribute('type', t);
  });

  function show2FA(payload){
    form.style.display = 'none';
    twofaBox.style.display = '';
    // Se server fornisce QR/URI (prima volta), mostrali
    if (payload && payload.otpauth_uri && payload.qr_url) {
      twofaSetup.style.display = '';
      twofaQr.src = payload.qr_url;
      twofaUriEl.textContent = payload.otpauth_uri;
    } else {
      twofaSetup.style.display = 'none';
    }
    codeInput.focus();
  }

  form.addEventListener('submit', async (e)=>{
    e.preventDefault();
    const req = form.querySelectorAll('[required]');
    for (const el of req){ if (!el.checkValidity()){ el.reportValidity(); return; } }
    const fd = new FormData(form);
    try{
      const r = await fetch('<?php echo basename(__FILE__); ?>', { method:'POST', body: fd, headers:{'Accept':'application/json'} });
      const j = await r.json();
      if (j.ok && j.require_2fa){
        show2FA(j);
        return;
      }
      if (j.ok){ window.location.href = j.redirect || '/lobby.php'; return; }
      if (j.errors){
        Object.entries(j.errors).forEach(([k,msg])=>{
          const el = document.getElementById(k);
          if (el){ el.setCustomValidity(msg); el.reportValidity(); }
        });
      } else {
        alert('Errore: ' + (j.error || 'imprevisto'));
      }
    }catch(err){ alert('Errore di rete. Riprova.'); }
  });

  twofaForm.addEventListener('submit', async (e)=>{
    e.preventDefault();
    if (!codeInput.checkValidity()) { codeInput.reportValidity(); return; }
    const fd = new FormData(twofaForm);
    try{
      const r = await fetch('<?php echo basename(__FILE__); ?>', { method:'POST', body: fd, headers:{'Accept':'application/json'} });
      const j = await r.json();
      if (j.ok){ window.location.href = j.redirect || '/admin/dashboard.php'; return; }
      if (j.errors && j.errors.code){
        codeInput.setCustomValidity(j.errors.code); codeInput.reportValidity();
        setTimeout(()=>codeInput.setCustomValidity(''), 1500);
      } else {
        alert('Errore: ' + (j.error || 'imprevisto'));
      }
    }catch(err){ alert('Errore di rete. Riprova.'); }
  });
})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
