<?php
// Registrazione — multi-step, card bianca (UI only)
$page_css = '/pages-css/registrazione.css';
include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/header_guest.php';
?>

<main>
  <section class="section">
    <div class="container">
      <div class="reg-card reg-card--white">

        <!-- Header card -->
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
              <div class="field" style="grid-column: span 2;">
                <label class="label" for="password2">Ripeti password *</label>
                <input class="input light" id="password2" type="password" required />
              </div>
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
              <div class="field">
                <label class="label" for="cittadinanza">Cittadinanza *</label>
                <input class="input light" id="cittadinanza" type="text" required />
              </div>
            </div>
          </section>

          <!-- STEP 3: Residenza -->
          <section class="step" data-step="3">
            <div class="grid2">
              <div class="field">
                <label class="label" for="nazione">Nazione di residenza *</label>
                <input class="input light" id="nazione" list="dl-nazioni" required placeholder="Italia" />
                <datalist id="dl-nazioni">
                  <option value="Italia"></option><option value="San Marino"></option><option value="Città del Vaticano"></option>
                  <option value="Francia"></option><option value="Germania"></option><option value="Spagna"></option><option value="Svizzera"></option>
                </datalist>
              </div>
              <div class="field">
                <label class="label" for="prov">Provincia (sigla) *</label>
                <input class="input light" id="prov" list="dl-prov" required maxlength="2" placeholder="RM"
                       oninput="this.value=this.value.toUpperCase()" />
                <datalist id="dl-prov">
                  <option value="RM">Roma</option><option value="MI">Milano</option><option value="NA">Napoli</option>
                  <option value="TO">Torino</option><option value="FI">Firenze</option><option value="BG">Bergamo</option>
                  <!-- (puoi estendere come prima all'elenco completo) -->
                </datalist>
              </div>
              <div class="field">
                <label class="label" for="citta">Città/Comune *</label>
                <input class="input light" id="citta" type="text" required />
              </div>
              <div class="field">
                <label class="label" for="via">Via *</label>
                <input class="input light" id="via" list="dl-tipovia" required placeholder="Via Garibaldi" />
                <datalist id="dl-tipovia">
                  <option value="Via"></option><option value="Viale"></option><option value="Piazza"></option>
                  <option value="Largo"></option><option value="Corso"></option><option value="Vicolo"></option>
                </datalist>
              </div>
              <div class="field">
                <label class="label" for="civico">Numero civico *</label>
                <input class="input light" id="civico" type="text" required />
              </div>
              <div class="field">
                <label class="label" for="cap">CAP *</label>
                <input class="input light" id="cap" type="text" required pattern="^\d{5}$" placeholder="00100" />
              </div>
            </div>
          </section>

          <!-- STEP 4: Documento -->
          <section class="step" data-step="4">
            <div class="grid2">
              <div class="field">
                <label class="label" for="tipo_doc">Tipo di documento *</label>
                <input class="input light" id="tipo_doc" list="dl-doc" required placeholder="Carta d'identità" />
                <datalist id="dl-doc">
                  <option value="Carta d'identità"></option><option value="Passaporto"></option>
                  <option value="Patente"></option><option value="Permesso di soggiorno"></option>
                </datalist>
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
                <input class="input light" id="rilasciato_da" list="dl-ente" required placeholder="Comune di … / Questura di …" />
                <datalist id="dl-ente">
                  <option value="Comune di"></option><option value="Questura di"></option>
                  <option value="Prefettura di"></option><option value="Motorizzazione Civile di"></option>
                  <option value="Consolato di"></option>
                </datalist>
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
// Mini wizard: mostra 1 step alla volta + validazioni essenziali
(function(){
  const form = document.getElementById('regForm');
  const steps = Array.from(form.querySelectorAll('.step'));
  const dots  = Array.from(document.querySelectorAll('[data-step-dot]'));
  const btnPrev = form.querySelector('[data-prev]');
  const btnNext = form.querySelector('[data-next]');
  const btnSubmit = form.querySelector('[data-submit]');
  let i = 0;

  function showStep(idx){
    steps.forEach((s, k)=>s.classList.toggle('active', k===idx));
    dots.forEach((d, k)=>d.classList.toggle('active', k<=idx));
    btnPrev.disabled = idx===0;
    btnNext.classList.toggle('hidden', idx===steps.length-1);
    btnSubmit.classList.toggle('hidden', idx!==steps.length-1);
  }

  function stepValid(idx){
    // Verifica i required visibili in questo step
    const required = steps[idx].querySelectorAll('[required]');
    for (const el of required){
      if (!el.checkValidity()) return false;
    }
    // Regole incrociate solo dove servono
    if (idx===0){
      const e1 = document.getElementById('email');
      const e2 = document.getElementById('email2');
      const p1 = document.getElementById('password');
      const p2 = document.getElementById('password2');
      if (e1.value !== e2.value) { e2.reportValidity(); return false; }
      if (p1.value !== p2.value) { p2.reportValidity(); return false; }
    }
    return true;
  }

  btnPrev.addEventListener('click', ()=>{ if(i>0){ i--; showStep(i); } });
  btnNext.addEventListener('click', ()=>{
    if (stepValid(i)){ i=Math.min(i+1, steps.length-1); showStep(i); }
    else { // forza i browser a mostrare il primo invalid
      const inv = steps[i].querySelector(':invalid'); if (inv) inv.reportValidity();
    }
  });

  form.addEventListener('submit', (e)=>{
    if (!stepValid(i)) { e.preventDefault(); const inv = steps[i].querySelector(':invalid'); if (inv) inv.reportValidity(); return; }
    // QUI poi cableremo il submit vero (AJAX o form post). Ora solo UI:
    alert('Registrazione inviata (UI demo).');
  });

  // email/password live check
  const e1 = document.getElementById('email'), e2 = document.getElementById('email2');
  const p1 = document.getElementById('password'), p2 = document.getElementById('password2');
  function matchEmail(){ e2.setCustomValidity( (e1.value && e2.value && e1.value!==e2.value) ? 'Le email non coincidono' : '' ); }
  function matchPw(){ p2.setCustomValidity( (p1.value && p2.value && p1.value!==p2.value) ? 'Le password non coincidono' : '' ); }
  [e1,e2].forEach(el=>el.addEventListener('input', matchEmail));
  [p1,p2].forEach(el=>el.addEventListener('input', matchPw));

  // start
  showStep(i);
})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
