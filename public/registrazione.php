<?php
// Registrazione — UI only con suggerimenti datalist
$page_css = '/pages-css/registrazione.css';
include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/header_guest.php';
?>

<main>
  <section class="section">
    <div class="container">
      <div class="reg-card">

        <h1 class="reg-title">Crea il tuo account</h1>

        <form class="reg-form" onsubmit="return false;" novalidate>
          <!-- Anagrafica -->
          <div class="field">
            <label class="label" for="nome">Nome *</label>
            <input class="input" id="nome" name="nome" type="text" required />
          </div>
          <div class="field">
            <label class="label" for="cognome">Cognome *</label>
            <input class="input" id="cognome" name="cognome" type="text" required />
          </div>

          <div class="field">
            <label class="label" for="cf">Codice Fiscale *</label>
            <input class="input" id="cf" name="cf" type="text" required
                   pattern="[A-Z0-9]{16}" placeholder="AAAAAA00A00A000A"
                   oninput="this.value=this.value.toUpperCase()" />
          </div>
          <div class="field">
            <label class="label" for="cittadinanza">Cittadinanza *</label>
            <input class="input" id="cittadinanza" name="cittadinanza" type="text" required />
          </div>

          <!-- Contatti -->
          <div class="field">
            <label class="label" for="email">Email *</label>
            <input class="input" id="email" name="email" type="email" required />
          </div>
          <div class="field">
            <label class="label" for="email2">Conferma email *</label>
            <input class="input" id="email2" name="email2" type="email" required />
          </div>

          <div class="field">
            <label class="label" for="cell">Cellulare *</label>
            <input class="input" id="cell" name="cell" type="tel" required
                   pattern="^[+0-9][0-9\s]{6,14}$" placeholder="+39 3xx xxx xxxx" />
          </div>

          <div class="field">
            <label class="label" for="nazione">Nazione di residenza *</label>
            <input class="input" id="nazione" name="nazione" list="dl-nazioni" required placeholder="Italia" />
            <datalist id="dl-nazioni">
              <option value="Italia"></option>
              <option value="San Marino"></option>
              <option value="Città del Vaticano"></option>
              <option value="Austria"></option>
              <option value="Francia"></option>
              <option value="Germania"></option>
              <option value="Spagna"></option>
              <option value="Svizzera"></option>
              <option value="Regno Unito"></option>
              <option value="Stati Uniti"></option>
              <option value="Canada"></option>
              <option value="Brasile"></option>
              <option value="Romania"></option>
              <option value="Albania"></option>
              <option value="Ucraina"></option>
              <option value="Marocco"></option>
              <option value="Tunisia"></option>
              <option value="Cina"></option>
              <option value="India"></option>
              <option value="Pakistan"></option>
              <!-- (puoi estendere la lista quando vuoi) -->
            </datalist>
          </div>

          <!-- Residenza -->
          <div class="field">
            <label class="label" for="citta">Città/Comune di residenza *</label>
            <input class="input" id="citta" name="citta" type="text" required />
          </div>

          <div class="field">
            <label class="label" for="prov">Provincia di residenza (sigla) *</label>
            <input class="input" id="prov" name="prov" list="dl-prov" required maxlength="2"
                   placeholder="RM" oninput="this.value=this.value.toUpperCase()" />
            <datalist id="dl-prov">
              <!-- Sigle province italiane -->
              <option value="AG">Agrigento</option><option value="AL">Alessandria</option>
              <option value="AN">Ancona</option><option value="AO">Aosta</option>
              <option value="AQ">L'Aquila</option><option value="AR">Arezzo</option>
              <option value="AP">Ascoli Piceno</option><option value="AT">Asti</option>
              <option value="AV">Avellino</option><option value="BA">Bari</option>
              <option value="BT">Barletta-Andria-Trani</option><option value="BL">Belluno</option>
              <option value="BN">Benevento</option><option value="BG">Bergamo</option>
              <option value="BI">Biella</option><option value="BO">Bologna</option>
              <option value="BZ">Bolzano</option><option value="BS">Brescia</option>
              <option value="BR">Brindisi</option><option value="CA">Cagliari</option>
              <option value="CL">Caltanissetta</option><option value="CB">Campobasso</option>
              <option value="CE">Caserta</option><option value="CT">Catania</option>
              <option value="CZ">Catanzaro</option><option value="CH">Chieti</option>
              <option value="CO">Como</option><option value="CS">Cosenza</option>
              <option value="CR">Cremona</option><option value="KR">Crotone</option>
              <option value="CN">Cuneo</option><option value="EN">Enna</option>
              <option value="FM">Fermo</option><option value="FE">Ferrara</option>
              <option value="FI">Firenze</option><option value="FG">Foggia</option>
              <option value="FC">Forlì-Cesena</option><option value="FR">Frosinone</option>
              <option value="GE">Genova</option><option value="GO">Gorizia</option>
              <option value="GR">Grosseto</option><option value="IM">Imperia</option>
              <option value="IS">Isernia</option><option value="SP">La Spezia</option>
              <option value="LT">Latina</option><option value="LE">Lecce</option>
              <option value="LC">Lecco</option><option value="LI">Livorno</option>
              <option value="LO">Lodi</option><option value="LU">Lucca</option>
              <option value="MC">Macerata</option><option value="MN">Mantova</option>
              <option value="MS">Massa-Carrara</option><option value="MT">Matera</option>
              <option value="ME">Messina</option><option value="MI">Milano</option>
              <option value="MO">Modena</option><option value="MB">Monza e Brianza</option>
              <option value="NA">Napoli</option><option value="NO">Novara</option>
              <option value="NU">Nuoro</option><option value="OR">Oristano</option>
              <option value="PD">Padova</option><option value="PA">Palermo</option>
              <option value="PR">Parma</option><option value="PV">Pavia</option>
              <option value="PG">Perugia</option><option value="PU">Pesaro e Urbino</option>
              <option value="PE">Pescara</option><option value="PC">Piacenza</option>
              <option value="PI">Pisa</option><option value="PT">Pistoia</option>
              <option value="PN">Pordenone</option><option value="PZ">Potenza</option>
              <option value="PO">Prato</option><option value="RG">Ragusa</option>
              <option value="RA">Ravenna</option><option value="RC">Reggio Calabria</option>
              <option value="RE">Reggio Emilia</option><option value="RI">Rieti</option>
              <option value="RN">Rimini</option><option value="RM">Roma</option>
              <option value="RO">Rovigo</option><option value="SA">Salerno</option>
              <option value="SS">Sassari</option><option value="SV">Savona</option>
              <option value="SI">Siena</option><option value="SR">Siracusa</option>
              <option value="SO">Sondrio</option><option value="SU">Sud Sardegna</option>
              <option value="TA">Taranto</option><option value="TE">Teramo</option>
              <option value="TR">Terni</option><option value="TO">Torino</option>
              <option value="TP">Trapani</option><option value="TN">Trento</option>
              <option value="TV">Treviso</option><option value="TS">Trieste</option>
              <option value="UD">Udine</option><option value="VA">Varese</option>
              <option value="VE">Venezia</option><option value="VB">Verbano-Cusio-Ossola</option>
              <option value="VC">Vercelli</option><option value="VR">Verona</option>
              <option value="VV">Vibo Valentia</option><option value="VI">Vicenza</option>
              <option value="VT">Viterbo</option>
            </datalist>
          </div>

          <div class="field">
            <label class="label" for="via">Via di residenza *</label>
            <input class="input" id="via" name="via" list="dl-tipovia" required placeholder="Via Garibaldi" />
            <datalist id="dl-tipovia">
              <option value="Via"></option>
              <option value="Viale"></option>
              <option value="Piazza"></option>
              <option value="Largo"></option>
              <option value="Corso"></option>
              <option value="Vicolo"></option>
              <option value="Strada"></option>
              <option value="Piazzale"></option>
            </datalist>
          </div>

          <div class="field">
            <label class="label" for="civico">Numero civico *</label>
            <input class="input" id="civico" name="civico" type="text" required />
          </div>

          <div class="field">
            <label class="label" for="cap">CAP *</label>
            <input class="input" id="cap" name="cap" type="text" required pattern="^\d{5}$" placeholder="00100" />
          </div>
          <div class="field"></div>

          <!-- Documento -->
          <div class="field">
            <label class="label" for="tipo_doc">Tipo di documento *</label>
            <input class="input" id="tipo_doc" name="tipo_doc" list="dl-doc" required placeholder="Carta d'identità" />
            <datalist id="dl-doc">
              <option value="Carta d'identità"></option>
              <option value="Passaporto"></option>
              <option value="Patente"></option>
              <option value="Permesso di soggiorno"></option>
            </datalist>
          </div>
          <div class="field">
            <label class="label" for="num_doc">Numero documento *</label>
            <input class="input" id="num_doc" name="num_doc" type="text" required />
          </div>

          <div class="field">
            <label class="label" for="rilascio">Data rilascio *</label>
            <input class="input" id="rilascio" name="rilascio" type="date" required />
          </div>
          <div class="field">
            <label class="label" for="scadenza">Data scadenza *</label>
            <input class="input" id="scadenza" name="scadenza" type="date" required />
          </div>

          <div class="field" style="grid-column: span 2;">
            <label class="label" for="rilasciato_da">Rilasciato da… *</label>
            <input class="input" id="rilasciato_da" name="rilasciato_da" list="dl-ente" required placeholder="Comune di … / Questura di …" />
            <datalist id="dl-ente">
              <option value="Comune di"></option>
              <option value="Questura di"></option>
              <option value="Prefettura di"></option>
              <option value="Motorizzazione Civile di"></option>
              <option value="Consolato di"></option>
            </datalist>
          </div>

          <!-- Credenziali -->
          <div class="field">
            <label class="label" for="username">Username *</label>
            <input class="input" id="username" name="username" type="text" required minlength="3" maxlength="20" />
          </div>
          <div class="field">
            <label class="label" for="password">Password *</label>
            <input class="input" id="password" name="password" type="password" required
                   pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}"
                   placeholder="Min 8, 1 maiuscola, 1 minuscola, 1 numero, 1 speciale" />
          </div>
          <div class="field" style="grid-column: span 2;">
            <label class="label" for="password2">Ripeti password *</label>
            <input class="input" id="password2" name="password2" type="password" required />
          </div>

          <!-- Consensi -->
          <div class="consensi">
            <label class="check"><input type="checkbox" required> Dichiaro di essere maggiorenne</label>
            <label class="check"><input type="checkbox" required> Accetto i <a class="link" href="/termini.php" target="_blank">Termini e Condizioni</a></label>
            <label class="check"><input type="checkbox" required> Ho preso visione e acconsento al trattamento dei dati personali</label>
            <label class="check"><input type="checkbox" required> Privacy policy GDPR — <a class="link" href="/privacy.php" target="_blank">Informativa</a></label>
            <label class="check"><input type="checkbox" required> Regolamento ufficiale del concorso/operazione a premi</label>
            <label class="check"><input type="checkbox" required> Condizioni generali d’uso della piattaforma Arena</label>
            <label class="check"><input type="checkbox"> Consenso marketing (facoltativo)</label>
          </div>

          <div class="actions">
            <button class="btn btn--primary" type="submit">Registrati</button>
            <a class="btn btn--outline" href="/login.php">Accedi</a>
          </div>
        </form>

      </div>
    </div>
  </section>
</main>

<script>
  // Validazione minima: email/password coincidono
  (function() {
    const email = document.getElementById('email');
    const email2 = document.getElementById('email2');
    const pw1 = document.getElementById('password');
    const pw2 = document.getElementById('password2');

    function matchEmail(){ 
      email2.setCustomValidity( (email.value && email2.value && email.value !== email2.value) ? 'Le email non coincidono' : '' );
    }
    function matchPw(){ 
      pw2.setCustomValidity( (pw1.value && pw2.value && pw1.value !== pw2.value) ? 'Le password non coincidono' : '' );
    }
    email.addEventListener('input', matchEmail);
    email2.addEventListener('input', matchEmail);
    pw1.addEventListener('input', matchPw);
    pw2.addEventListener('input', matchPw);
  })();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
