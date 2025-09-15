<?php
// Login — card bianca, username/email + password (AJAX)
$page_css = '/pages-css/login.css';

require_once __DIR__ . '/../partials/db.php'; // $pdo

// --- Utils ---
function norm_email($s){ return strtolower(trim($s)); }
function norm_username($s){ return trim($s); }

// --- Handler POST (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['__action__'] ?? '') === 'login') {
  header('Content-Type: application/json; charset=utf-8');

  $id   = trim($_POST['id'] ?? '');           // può essere email o username
  $pass = $_POST['password'] ?? '';

  if ($id === '' || $pass === '') {
    echo json_encode(['ok'=>false, 'errors'=>['id'=>'Campo obbligatorio','password'=>'Campo obbligatorio']]); exit;
  }

  // Determina se è email o username
  $isEmail = (bool)filter_var($id, FILTER_VALIDATE_EMAIL);
  $col     = $isEmail ? 'email' : 'username';
  $val     = $isEmail ? norm_email($id) : norm_username($id);

  $stmt = $pdo->prepare("SELECT id, user_code, username, email, password_hash FROM users WHERE $col = ? LIMIT 1");
  $stmt->execute([$val]);
  $user = $stmt->fetch();

  // Per sicurezza: confronto costante
  if (!$user || !password_verify($pass, $user['password_hash'])) {
    echo json_encode(['ok'=>false, 'errors'=>['id'=>'Credenziali non valide','password'=>'Credenziali non valide']]); exit;
  }

  // Login OK → apri sessione
  if (session_status() === PHP_SESSION_NONE) { session_start(); }
  $_SESSION['uid']       = (int)$user['id'];
  $_SESSION['user_code'] = $user['user_code'];
  $_SESSION['username']  = $user['username'];
  $_SESSION['email']     = $user['email'];

  echo json_encode(['ok'=>true, 'redirect'=>'/index.php']); // cambia destinazione se vuoi
  exit;
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
        <form id="loginForm" novalidate>
          <div class="field">
            <label class="label" for="id">Email o Username *</label>
            <input class="input light" id="id" name="id" type="text" required />
          </div>
          <div class="field">
            <label class="label" for="password">Password *</label>
            <input class="input light" id="password" name="password" type="password" required />
          </div>

          <div class="login-actions">
            <button type="submit" class="btn btn--primary">Accedi</button>
            <a class="btn btn--outline" href="/registrazione.php">Registrati</a>
          </div>

          <input type="hidden" name="__action__" value="login">
        </form>
      </div>
    </div>
  </section>
</main>

<script>
(function(){
  const form = document.getElementById('loginForm');

  form.addEventListener('submit', async (e)=>{
    e.preventDefault();
    // HTML5 validity
    const req = form.querySelectorAll('[required]');
    for (const el of req){ if (!el.checkValidity()){ el.reportValidity(); return; } }

    const fd = new FormData(form);
    try{
      const r = await fetch('<?php echo basename(__FILE__); ?>', {
        method:'POST', body: fd, headers:{'Accept':'application/json'}
      });
      const j = await r.json();
      if (j.ok){
        window.location.href = j.redirect || '/';
      } else if (j.errors){
        // Mostra errori inline
        Object.entries(j.errors).forEach(([k,msg])=>{
          const el = document.getElementById(k);
          if (el){ el.setCustomValidity(msg); el.reportValidity(); }
        });
      } else {
        alert('Errore: ' + (j.error || 'imprevisto'));
      }
    }catch(err){
      alert('Errore di rete. Riprova.');
    }
  });
})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
