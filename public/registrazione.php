<?php
// Registrazione — multi-step, card bianca (UI) + controlli unicità live + salvataggio DB
$page_css = '/pages-css/registrazione.css';

// === BOOTSTRAP DB & UTILS ===================================================
require_once __DIR__ . '/../partials/db.php';      // -> $pdo
require_once __DIR__ . '/../partials/codegen.php'; // genUserCode(), getFreeUserCode()

// Log errori su /tmp e non mostrare HTML a schermo
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', '/tmp/php_errors.log');

// === NORMALIZZAZIONI ========================================================
function norm_email($s){ return strtolower(trim($s)); }
function norm_cell($s){ return preg_replace('/\s+/', '', trim($s)); }
function norm_cf($s){ return strtoupper(trim($s)); }
function norm_prov($s){ return strtoupper(trim($s)); }
function norm_username($s){ return trim($s); }

// === ENDPOINT: CHECK UNICITÀ LIVE ==========================================
// Es: /registrazione.php?check=username&value=foo
if (isset($_GET['check'], $_GET['value'])) {
  header('Content-Type: application/json; charset=utf-8');

  $field = $_GET['check'];
  $value = $_GET['value'];

  $map = [
    'username' => ['col' => 'username',       'norm' => 'norm_username'],
    'email'    => ['col' => 'email',          'norm' => 'norm_email'],
    'cell'     => ['col' => 'cell',           'norm' => 'norm_cell'],
    'cf'       => ['col' => 'codice_fiscale', 'norm' => 'norm_cf'],
  ];
  if (!isset($map[$field])) { echo json_encode(['ok'=>false,'error'=>'campo non supportato']); exit; }

  $col  = $map[$field]['col'];
  $norm = $map[$field]['norm'];
  $val  = $norm($value);

  $stmt = $pdo->prepare("SELECT 1 FROM users WHERE $col = ? LIMIT 1");
  $stmt->execute([$val]);
  $exists = (bool)$stmt->fetchColumn();

  echo json_encode(['ok'=>true, 'exists'=>$exists]); exit;
}

// === HANDLER SUBMIT REGISTRAZIONE ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['__action__'] ?? '') === 'register') {
  header('Content-Type: application/json; charset=utf-8');

  try {
    // --- Raccogli input (con name=... nel form) ---
    $u = [];
    $u['email']           = norm_email($_POST['email'] ?? '');
    $u['email2']          = norm_email($_POST['email2'] ?? '');
    $u['username']        = norm_username($_POST['username'] ?? '');
    $password_plain       = $_POST['password'] ?? '';
    $password2_plain      = $_POST['password2'] ?? '';
    $u['presenter_code']  = trim($_POST['presenter'] ?? '');

    $u['nome']            = trim($_POST['nome'] ?? '');
    $u['cognome']         = trim($_POST['cognome'] ?? '');
    $u['cf']              = norm_cf($_POST['cf'] ?? '');
    $u['cell']            = norm_cell($_POST['cell'] ?? '');
    $u['cittadinanza']    = trim($_POST['cittadinanza'] ?? '');

    $u['via']             = trim($_POST['via'] ?? '');
    $u['civico']          = trim($_POST['civico'] ?? '');
    $u['citta']           = trim($_POST['citta'] ?? '');
    $u['prov']            = norm_prov($_POST['prov'] ?? '');
    $u['cap']             = trim($_POST['cap'] ?? '');
    $u['nazione']         = trim($_POST['nazione'] ?? '');

    $u['tipo_doc']        = $_POST['tipo_doc'] ?? '';
    $u['num_doc']         = trim($_POST['num_doc'] ?? '');
    $u['rilascio']        = $_POST['rilascio'] ?? '';
    $u['scadenza']        = $_POST['scadenza'] ?? '';
    $u['rilasciato_da']   = trim($_POST['rilasciato_da'] ?? '');

    // Consensi
    $u['maggiorenne']                 = (int)!!($_POST['maggiorenne'] ?? 0);
    $u['accetta_termini']             = (int)!!($_POST['accetta_termini'] ?? 0);
    $u['accetta_trattamento_dati']    = (int)!!($_POST['accetta_trattamento_dati'] ?? 0);
    $u['accetta_privacy_gdpr']        = (int)!!($_POST['accetta_privacy_gdpr'] ?? 0);
    $u['accetta_regolamento']         = (int)!!($_POST['accetta_regolamento'] ?? 0);
    $u['accetta_condizioni_generali'] = (int)!!($_POST['accetta_condizioni_generali'] ?? 0);
    $u['consenso_marketing']          = (int)!!($_POST['consenso_marketing'] ?? 0);

    // --- Validazioni minime server-side ---
    $errors = [];
    if ($u['email'] === '' || $u['email2'] === '' || $u['email'] !== $u['email2']) {
      $errors['email2'] = 'Le email non coincidono';
    }
    if ($password_plain === '' || $password2_plain === '' || $password_plain !== $password2_plain) {
      $errors['password2'] = 'Le password non coincidono';
    }
    if (!preg_match('/(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}/', $password_plain)) {
      $errors['password'] = 'Password non conforme ai requisiti';
    }
    foreach (['maggiorenne','accetta_termini','accetta_trattamento_dati','accetta_privacy_gdpr','accetta_regolamento','accetta_condizioni_generali'] as $k) {
      if ($u[$k] !== 1) { $errors[$k] = 'Obbligatorio'; }
    }
    if ($errors) { echo json_encode(['ok'=>false,'errors'=>$errors]); exit; }

    // Prepara INSERT
    $u['password_hash'] = password_hash($password_plain, PASSWORD_DEFAULT);

    $sql = "INSERT INTO users (
      user_code, username, email, password_hash,
      nome, cognome, codice_fiscale, cittadinanza, cell,
      via, civico, citta, prov, cap, nazione,
      tipo_doc, num_doc, data_rilascio, data_scadenza, rilasciato_da,
      presenter_code, maggiorenne, accetta_termini, accetta_trattamento_dati,
      accetta_privacy_gdpr, accetta_regolamento, accetta_condizioni_generali, consenso_marketing
    ) VALUES (
      :user_code, :username, :email, :password_hash,
      :nome, :cognome, :cf, :cittadinanza, :cell,
      :via, :civico, :citta, :prov, :cap, :nazione,
      :tipo_doc, :num_doc, :rilascio, :scadenza, :rilasciato_da,
      :presenter_code, :maggiorenne, :accetta_termini, :accetta_trattamento_dati,
      :accetta_privacy_gdpr, :accetta_regolamento, :accetta_condizioni_generali, :consenso_marketing
    )";
    $stmt = $pdo->prepare($sql);

    // Retry fino a 5 volte: prima codice libero, poi INSERT; se UNIQUE scatta, rigenera.
    for ($attempt=0; $attempt<5; $attempt++) {
      $params = [
        ':user_code' => getFreeUserCode($pdo),
        ':username'  => $u['username'],
        ':email'     => $u['email'],
        ':password_hash' => $u['password_hash'],
        ':nome'      => $u['nome'],
        ':cognome'   => $u['cognome'],
        ':cf'        => $u['cf'],
        ':cittadinanza' => $u['cittadinanza'],
        ':cell'      => $u['cell'],
        ':via'       => $u['via'],
        ':civico'    => $u['civico'],
        ':citta'     => $u['citta'],
        ':prov'      => $u['prov'],
        ':cap'       => $u['cap'],
        ':nazione'   => $u['nazione'],
        ':tipo_doc'  => $u['tipo_doc'],
        ':num_doc'   => $u['num_doc'],
        ':rilascio'  => $u['rilascio'],
        ':scadenza'  => $u['scadenza'],
        ':rilasciato_da' => $u['rilasciato_da'],
        ':presenter_code' => $u['presenter_code'] ?: null,
        ':maggiorenne' => $u['maggiorenne'],
        ':accetta_termini' => $u['accetta_termini'],
        ':accetta_trattamento_dati' => $u['accetta_trattamento_dati'],
        ':accetta_privacy_gdpr' => $u['accetta_privacy_gdpr'],
        ':accetta_regolamento' => $u['accetta_regolamento'],
        ':accetta_condizioni_generali' => $u['accetta_condizioni_generali'],
        ':consenso_marketing' => $u['consenso_marketing'],
      ];

      try {
        $stmt->execute($params);
        echo json_encode(['ok'=>true]); exit;
      } catch (PDOException $e) {
        $errno = $e->errorInfo[1] ?? null;
        $msg   = $e->errorInfo[2] ?? '';
        if ($errno !== 1062) {
          echo json_encode(['ok'=>false, 'error'=>'DB error', 'detail'=>$e->getMessage()]); exit;
        }
        // Duplicati specifici → segnala subito al campo giusto
        if (stripos($msg, 'uq_username') !== false) { echo json_encode(['ok'=>false,'errors'=>['username'=>'Username già in uso']]); exit; }
        if (stripos($msg, 'uq_email') !== false)    { echo json_encode(['ok'=>false,'errors'=>['email'=>'Email già registrata']]); exit; }
        if (stripos($msg, 'uq_cell') !== false)     { echo json_encode(['ok'=>false,'errors'=>['cell'=>'Telefono già registrato']]); exit; }
        if (stripos($msg, 'uq_codicefiscale') !== false) { echo json_encode(['ok'=>false,'errors'=>['cf'=>'Codice fiscale già registrato']]); exit; }

        // Probabile collisione su user_code → rigenera e riprova
        if ($attempt === 4) { echo json_encode(['ok'=>false,'error'=>'Impossibile generare un codice utente unico, riprova.']); exit; }
        // continua il loop
      }
    }

  } catch (Throwable $ex) {
    // Qualsiasi fatal/error imprevisto: log e JSON pulito
    error_log('REG_POST_FATAL: '.$ex->getMessage());
    echo json_encode(['ok'=>false,'error'=>'fatal','detail'=>$ex->getMessage()]);
  }
  exit;
}

// === VIEW ===================================================================
include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/header_guest.php';
?>
<main>
  <section class="section">
    <div class="container">
      <div class="reg-card reg-card--white">

        <div class="reg-head">
          <h1 class="reg-title">Crea il tuo account</h1>
          <div class="reg-steps" aria-label="Avanzamento">
            <span class="dot active" data-step-dot="1"></span>
            <span class="dot" data-step-dot="2"></span>
            <span class="dot" data-step-dot="3"></span>
            <span class="dot" data-step-dot="4"></span>
            <span class="dot" data-step-dot="5"></span>
          </div>
        </div>

        <form id="regForm" novalidate>
          <!-- STEP 1: Credenziali -->
          <section class="step active" data-step="1">
            <div class="grid2">
              <div class="field">
                <label class="label" for="email">Email *</label>
                <input class="input light" id="email" name="email" type="email" required />
              </div>
              <div class="field">
                <label class="label" for="email2">Conferma email *</label>
                <input class="input light" id="email2" name="email2" type="email" required />
              </div>
              <div class="field">
                <label class="label" for="username">Username *</label>
                <input class="input light" id="username" name="username" type="text" required minlength="3" maxlength="20" />
              </div>
              <div class="field">
                <label class="label" for="password">Password *</label>
                <input class="input light" id="password" name="password" type="password" required
                       placeholder="Min 8, 1 maiuscola, 1 minuscola, 1 numero, 1 speciale"
                       pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}" />
              </div>
              <div class="field">
                <label class="label" for="password2">Ripeti password *</label>
                <input class="input light" id="password2" name="password2" type="password" required />
              </div>
              <div class="field">
                <label class="label" for="presenter">Codice presentatore (facoltativo)</label>
                <input class="input light" id="presenter" name="presenter" type="text" />
              </div>
            </div>
          </section>

          <!-- STEP 2: Anagrafica -->
          <section class="step" data-step="2">
            <div class="grid2">
              <div class="field">
                <label class="label" for="nome">Nome *</label>
                <input class="input light" id="nome" name="nome" type="text" required />
              </div>
              <div class="field">
                <label class="label" for="cognome">Cognome *</label>
                <input class="input light" id="cognome" name="cognome" type="text" required />
              </div>
              <div class="field">
                <label class="label" for="cf">Codice Fiscale *</label>
                <input class="input light" id="cf" name="cf" type="text" required pattern="[A-Z0-9]{16}"
                       placeholder="AAAAAA00A00A000A" oninput="this.value=this.value.toUpperCase()" />
              </div>
              <div class="field">
                <label class="label" for="cell">Cellulare *</label>
                <input class="input light" id="cell" name="cell" type="tel" required
                       pattern="^[+0-9][0-9\s]{6,14}$" placeholder="+39 3xx xxx xxxx" />
              </div>
              <div class="field" style="grid-column: span 2;">
                <label class="label" for="cittadinanza">Cittadinanza *</label>
                <input class="input light" id="cittadinanza" name="cittadinanza" type="text" required />
              </div>
            </div>
          </section>

          <!-- STEP 3: Residenza -->
          <section class="step" data-step="3">
            <div class="grid2">
              <div class="field">
                <label class="label" for="via">Via/Viale/Piazza *</label>
                <input class="input light" id="via" name="via" type="text" required placeholder="Via Garibaldi" />
              </div>
              <div class="field">
                <label class="label" for="civico">Numero civico *</label>
                <input class="input light" id="civico" name="civico" type="text" required />
              </div>
              <div class="field">
                <label class="label" for="citta">Città/Comune *</label>
                <input class="input light" id="citta" name="citta" type="text" required />
              </div>
              <div class="field">
                <label class="label" for="prov">Provincia (sigla) *</label>
                <input class="input light" id="prov" name="prov" type="text" required maxlength="2"
                       placeholder="RM" oninput="this.value=this.value.toUpperCase()" />
              </div>
              <div class="field">
                <label class="label" for="cap">CAP *</label>
                <input class="input light" id="cap" name="cap" type="text" required pattern="^\d{5}$" placeholder="00100" />
              </div>
              <div class="field">
                <label class="label" for="nazione">Nazione *</label>
                <input class="input light" id="nazione" name="nazione" type="text" required placeholder="Italia" />
              </div>
            </div>
          </section>

          <!-- STEP 4: Documento -->
          <section class="step" data-step="4">
            <div class="grid2">
              <div class="field">
                <label class="label" for="tipo_doc">Tipo di documento *</label>
                <select class="select light" id="tipo_doc" name="tipo_doc" required>
                  <option value="">Seleziona…</option>
                  <option value="PATENTE">Patente</option>
                  <option value="CARTA_IDENTITA">Carta d'identità</option>
                  <option value="PASSAPORTO">Passaporto</option>
                </select>
              </div>
              <div class="field">
                <label class="label" for="num_doc">Numero documento *</label>
                <input class="input light" id="num_doc" name="num_doc" type="text" required />
              </div>
              <div class="field">
                <label class="label" for="rilascio">Data rilascio *</label>
                <input class="input light" id="rilascio" name="rilascio" type="date" required />
              </div>
              <div class="field">
                <label class="label" for="scadenza">Data scadenza *</label>
                <input class="input light" id="scadenza" name="scadenza" type="date" required />
              </div>
              <div class="field" style="grid-column: span 2;">
                <label class="label" for="rilasciato_da">Rilasciato da… *</label>
                <input class="input light" id="rilasciato_da" name="rilasciato_da" type="text" required placeholder="Comune di … / Questura di …" />
              </div>
            </div>
          </section>

          <!-- STEP 5: Consensi -->
          <section class="step" data-step="5">
            <div class="consensi">
              <label class="check"><input type="checkbox" name="maggiorenne" value="1" required> Dichiaro di essere maggiorenne</label>

              <label class="check">
                <input type="checkbox" name="accetta_termini" value="1" required>
                Accetto i <a class="link" href="/termini.php" target="_blank">Termini e Condizioni</a>
              </label>

              <label class="check">
                <input type="checkbox" name="accetta_trattamento_dati" value="1" required>
                Ho preso visione e acconsento al
                <a class="link" href="/trattamento-dati.php" target="_blank">trattamento dei dati personali</a>
              </label>

              <label class="check">
                <input type="checkbox" name="accetta_privacy_gdpr" value="1" required>
                Privacy policy GDPR — <a class="link" href="/privacy.php" target="_blank">Informativa</a>
              </label>

              <label class="check">
                <input type="checkbox" name="accetta_regolamento" value="1" required>
                <a class="link" href="/regolamento.php" target="_blank">Regolamento ufficiale del concorso/operazione a premi</a>
              </label>

              <label class="check">
                <input type="checkbox" name="accetta_condizioni_generali" value="1" required>
                <a class="link" href="/condizioni-generali.php" target="_blank">Condizioni generali d’uso</a> della piattaforma Arena
              </label>

              <label class="check">
                <input type="checkbox" name="consenso_marketing" value="1">
                Consenso marketing (facoltativo)
              </label>
            </div>
          </section>

          <!-- Controls -->
          <div class="wizard-actions">
            <button type="button" class="btn btn--outline" data-prev>Indietro</button>
            <button type="button" class="btn btn--primary" data-next>Continua</button>
            <button type="submit" class="btn btn--primary hidden" data-submit>Registrati</button>
          </div>
          <input type="hidden" name="__action__" value="register">
        </form>

      </div>
    </div>
  </section>
</main>

<script>
// Wizard + validazioni + check univoci live + submit AJAX
(function(){
  const form = document.getElementById('regForm');
  const steps = [...form.querySelectorAll('.step')];
  const dots  = [...document.querySelectorAll('[data-step-dot]')];
  const btnPrev = form.querySelector('[data-prev]');
  const btnNext = form.querySelector('[data-next]');
  const btnSubmit = form.querySelector('[data-submit]');
  let i = 0;

  function showStep(idx){
    steps.forEach((s,k)=>s.classList.toggle('active', k===idx));
    dots.forEach((d,k)=>d.classList.toggle('active', k<=idx));
    btnPrev.disabled = idx===0;
    btnNext.classList.toggle('hidden', idx===steps.length-1);
    btnSubmit.classList.toggle('hidden', idx!==steps.length-1);
  }

  function stepValid(idx){
    const req = steps[idx].querySelectorAll('[required]');
    for (const el of req){ if (!el.checkValidity()) return false; }
    if (idx===0){
      const e1 = document.getElementById('email'), e2 = document.getElementById('email2');
      const p1 = document.getElementById('password'), p2 = document.getElementById('password2');
      if (e1.value !== e2.value) { e2.setCustomValidity('Le email non coincidono'); e2.reportValidity(); return false; }
      else e2.setCustomValidity('');
      if (p1.value !== p2.value) { p2.setCustomValidity('Le password non coincidono'); p2.reportValidity(); return false; }
      else p2.setCustomValidity('');
    }
    return true;
  }

  btnPrev.addEventListener('click', ()=>{ if(i>0){ i--; showStep(i); } });
  btnNext.addEventListener('click', ()=>{ 
    if (stepValid(i)){ i=Math.min(i+1, steps.length-1); showStep(i); }
    else { const inv = steps[i].querySelector(':invalid'); if (inv) inv.reportValidity(); }
  });

  // Live unique checks
  const uniqueFields = [
    {id:'username', check:'username', normalize:v=>v.trim()},
    {id:'email',    check:'email',    normalize:v=>v.trim().toLowerCase()},
    {id:'cell',     check:'cell',     normalize:v=>v.replace(/\s+/g,'')},
    {id:'cf',       check:'cf',       normalize:v=>v.trim().toUpperCase()},
  ];
  uniqueFields.forEach(({id,check,normalize})=>{
    const el = document.getElementById(id);
    const debounced = debounce(async ()=>{
      const v = normalize(el.value||'');
      if (!v) { el.setCustomValidity(''); return; }
      try{
        const r = await fetch(`<?php echo basename(__FILE__); ?>?check=${encodeURIComponent(check)}&value=${encodeURIComponent(v)}`, {cache:'no-store'});
        const j = await r.json();
        if (j.ok && j.exists){ 
          el.setCustomValidity('Valore già in uso'); 
        } else {
          el.setCustomValidity('');
        }
      }catch(e){ el.setCustomValidity('Errore di rete, riprova'); }
      el.reportValidity();
    }, 400);
    el.addEventListener('input', debounced);
    el.addEventListener('blur', ()=>debounced.flush?.());
  });

  function debounce(fn, ms){
    let t;
    const wrapped = (...args)=>{ clearTimeout(t); t=setTimeout(()=>fn(...args), ms); };
    wrapped.flush = ()=>{ if (t){ clearTimeout(t); fn(); } };
    return wrapped;
  }

// Submit AJAX (robusto: gestisce non-JSON e 500)
form.addEventListener('submit', async (e)=>{
  e.preventDefault();
  if (!stepValid(i)) { const inv = steps[i].querySelector(':invalid'); if (inv) inv.reportValidity(); return; }

  const fd = new FormData(form);
  try{
    const r = await fetch('<?php echo basename(__FILE__); ?>', {
      method:'POST',
      body: fd,
      headers:{'Accept':'application/json'}
    });

    const text = await r.text();      // leggo sempre testo
    let j = null;
    try { j = JSON.parse(text); } catch(e) {
      // Mostra testo grezzo del server (es. fatal error), primi 600 caratteri
      alert('Errore server:\n' + text.slice(0,600));
      return;
    }

    if (!r.ok) {
      alert('Errore HTTP ' + r.status + ': ' + (j.error || text.slice(0,200)));
      return;
    }

    if (j.ok){
      window.location.href = j.redirect || '/login.php';
      return;
    }
    if (j.errors){
      Object.entries(j.errors).forEach(([k,msg])=>{
        const el = document.getElementById(k);
        if (el){ el.setCustomValidity(msg); el.reportValidity(); }
      });
      return;
    }
    alert('Errore: ' + (j.error || 'imprevisto'));
  }catch(err){
    alert('Errore di rete: ' + (err && err.message ? err.message : err));
  }
});

  showStep(i);
})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
