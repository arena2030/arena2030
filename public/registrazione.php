<?php
// Registrazione — multi-step, card bianca (UI only, niente Google)
$page_css = '/pages-css/registrazione.css';
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

        <form id="regForm" onsubmit="return false;" novalidate>
          <!-- STEP 1: Credenziali -->
          <section class="step active" data-step="1">
            <div class="grid2">
              <div class="field">
                <label class="label" for="email">Email *</label>
                <input class="input light" id="email" type="email" required />
              </div>
              <div class="field">
                <label class="label" for="email2">Conferma email *</label>
                <input class="input light" id="email2" type="email" required />
              </div>
              <div class="field">
                <label class="label" for="username">Username *</label>
                <input class="input light" id="username" type="text" required minlength="3" maxlength="20" />
              </div>
              <div class="field">
                <label class="label" for="password">Password *</label>
                <input class="input light" id="password" type="password" required
                       placeholder="Min 8, 1 maiuscola, 1 minuscola, 1 numero, 1 speciale"
                       pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}" />
              </div>
    <div class="field">
  <label class="label" for="password2">Ripeti password *</label>
  <input class="input light" id="password2" type="password" required />
</div>

<div class="field">
  <label class="label" for="presenter">Codice presentatore (facoltativo)</label>
  <input class="input light" id="presenter" type="text" />
</div>
          </section>

          <!-- STEP 2: Anagrafica -->
          <section class="step" data-step="2">
            <div class="grid2">
              <div class="field">
                <label class="label" for="nome">Nome *</label>
                <input class="input light" id="nome" type="text" required />
              </div>
              <div class="field">
                <label class="label" for="cognome">Cognome *</label>
                <input class="input light" id="cognome" type="text" required />
              </div>
              <div class="field">
                <label class="label" for="cf">Codice Fiscale *</label>
                <input class="input light" id="cf" type="text" required pattern="[A-Z0-9]{16}"
                       placeholder="AAAAAA00A00A000A" oninput="this.value=this.value.toUpperCase()" />
              </div>
              <div class="field">
                <label class="label" for="cell">Cellulare *</label>
                <input class="input light" id="cell" type="tel" required
                       pattern="^[+0-9][0-9\s]{6,14}$" placeholder="+39 3xx xxx xxxx" />
              </div>
              <div class="field" style="grid-column: span 2;">
                <label class="label" for="cittadinanza">Cittadinanza *</label>
                <input class="input light" id="cittadinanza" type="text" required />
              </div>
            </div>
          </section>

          <!-- STEP 3: Residenza (inserimento manuale) -->
          <section class="step" data-step="3">
            <div class="grid2">
              <div class="field">
                <label class="label" for="via">Via *</label>
                <input class="input light" id="via" type="text" required placeholder="Via Garibaldi" />
              </div>
              <div class="field">
                <label class="label" for="civico">Numero civico *</label>
                <input class="input light" id="civico" type="text" required />
              </div>
              <div class="field">
                <label class="label" for="citta">Città/Comune *</label>
                <input class="input light" id="citta" type="text" required />
              </div>
              <div class="field">
                <label class="label" for="prov">Provincia (sigla) *</label>
                <input class="input light" id="prov" type="text" required maxlength="2"
                       placeholder="RM" oninput="this.value=this.value.toUpperCase()" />
              </div>
              <div class="field">
                <label class="label" for="cap">CAP *</label>
                <input class="input light" id="cap" type="text" required pattern="^\d{5}$" placeholder="00100" />
              </div>
              <div class="field">
                <label class="label" for="nazione">Nazione *</label>
                <input class="input light" id="nazione" type="text" required placeholder="Italia" />
              </div>
            </div>
          </section>

          <!-- STEP 4: Documento -->
          <section class="step" data-step="4">
            <div class="grid2">
              <div class="field">
                <label class="label" for="tipo_doc">Tipo di documento *</label>
                <select class="select light" id="tipo_doc" required>
                  <option value="">Seleziona…</option>
                  <option value="PATENTE">Patente</option>
                  <option value="CARTA_IDENTITA">Carta d'identità</option>
                  <option value="PASSAPORTO">Passaporto</option>
                </select>
              </div>
              <div class="field">
                <label class="label" for="num_doc">Numero documento *</label>
                <input class="input light" id="num_doc" type="text" required />
              </div>
              <div class="field">
                <label class="label" for="rilascio">Data rilascio *</label>
                <input class="input light" id="rilascio" type="date" required />
              </div>
              <div class="field">
                <label class="label" for="scadenza">Data scadenza *</label>
                <input class="input light" id="scadenza" type="date" required />
              </div>
              <div class="field" style="grid-column: span 2;">
                <label class="label" for="rilasciato_da">Rilasciato da… *</label>
                <input class="input light" id="rilasciato_da" type="text" required placeholder="Comune di … / Questura di …" />
              </div>
            </div>
          </section>

          <!-- STEP 5: Consensi -->
          <section class="step" data-step="5">
            <div class="consensi">
              <label class="check"><input type="checkbox" required> Dichiaro di essere maggiorenne</label>
              <label class="check"><input type="checkbox" required> Accetto i <a class="link" href="/termini.php" target="_blank">Termini e Condizioni</a></label>
              <label class="check"><input type="checkbox" required> Ho preso visione e acconsento al trattamento dei dati personali</label>
              <label class="check"><input type="checkbox" required> Privacy policy GDPR — <a class="link" href="/privacy.php" target="_blank">Informativa</a></label>
              <label class="check"><input type="checkbox" required> Regolamento ufficiale del concorso/operazione a premi</label>
              <label class="check"><input type="checkbox" required> Condizioni generali d’uso della piattaforma Arena</label>
              <label class="check"><input type="checkbox"> Consenso marketing (facoltativo)</label>
            </div>
          </section>

          <!-- Controls -->
          <div class="wizard-actions">
            <button type="button" class="btn btn--outline" data-prev>Indietro</button>
            <button type="button" class="btn btn--primary" data-next>Continua</button>
            <button type="submit" class="btn btn--primary hidden" data-submit>Registrati</button>
          </div>
        </form>

      </div>
    </div>
  </section>
</main>

<script>
// Wizard + validazioni base
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
      if (e1.value !== e2.value) { e2.reportValidity(); return false; }
      if (p1.value !== p2.value) { p2.reportValidity(); return false; }
    }
    return true;
  }

  btnPrev.addEventListener('click', ()=>{ if(i>0){ i--; showStep(i); } });
  btnNext.addEventListener('click', ()=>{ 
    if (stepValid(i)){ i=Math.min(i+1, steps.length-1); showStep(i); }
    else { const inv = steps[i].querySelector(':invalid'); if (inv) inv.reportValidity(); }
  });

  form.addEventListener('submit', (e)=>{
    if (!stepValid(i)) { e.preventDefault(); const inv = steps[i].querySelector(':invalid'); if (inv) inv.reportValidity(); return; }
    alert('Registrazione inviata (UI demo).');
  });

  // live check email/pw
  const e1 = document.getElementById('email'), e2 = document.getElementById('email2');
  const p1 = document.getElementById('password'), p2 = document.getElementById('password2');
  function matchEmail(){ e2.setCustomValidity((e1.value && e2.value && e1.value!==e2.value)?'Le email non coincidono':''); }
  function matchPw(){ p2.setCustomValidity((p1.value && p2.value && p1.value!==p2.value)?'Le password non coincidono':''); }
  [e1,e2].forEach(el=>el.addEventListener('input', matchEmail));
  [p1,p2].forEach(el=>el.addEventListener('input', matchPw));

  showStep(i);
})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
