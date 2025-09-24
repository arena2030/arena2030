<?php
require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['uid']) || !(($_SESSION['role'] ?? 'USER')==='ADMIN' || (int)($_SESSION['is_admin'] ?? 0)===1)) {
  header('Location: /login.php'); exit;
}

/* Helpers */
function jsonOut($a){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($a); exit; }

/* View */
$page_css = '/pages-css/admin-dashboard.css';
include __DIR__ . '/../../partials/head.php';
include __DIR__ . '/../../partials/header_admin.php';
?>
<main>
  <section class="section">
    <div class="container">
      <h1>Crea tornei Flash</h1>

      <!-- link rapidi -->
      <div class="card" style="max-width:640px; margin-bottom:16px;">
        <p class="muted">Seleziona una funzione</p>
        <div style="display:flex; gap:12px; margin-top:12px;">
          <a class="btn btn--primary btn--sm" href="/admin/teams.php">Gestisci squadre</a>
          <a class="btn btn--outline btn--sm" href="/admin/crea-tornei.php">Crea torneo (standard)</a>
          <button type="button" class="btn btn--primary btn--sm" id="btnOpenWizard">Crea torneo Flash</button>
        </div>
      </div>

      <!-- wizard modal (IDENTICO ai campi del torneo normale) -->
      <div class="modal" id="wizard" aria-hidden="true">
        <div class="modal-backdrop" data-close></div>
        <div class="modal-card" style="max-width:760px;">
          <div class="modal-head">
            <h3>Nuovo torneo Flash</h3>
            <div class="steps-dots"><span class="dot active" data-dot="1"></span><span class="dot" data-dot="2"></span><span class="dot" data-dot="3"></span></div>
            <button class="modal-x" data-close>&times;</button>
          </div>
          <div class="modal-body scroller">
            <form id="wForm">
              <section class="step active" data-step="1">
                <div class="grid2">
                  <div class="field"><label class="label">Nome torneo *</label><input class="input light" id="w_name" required></div>
                  <div class="field"><label class="label">Costo vita (buy-in) *</label><input class="input light" id="w_buyin" type="number" step="0.01" min="0" required></div>
                </div>
              </section>
              <section class="step" data-step="2">
                <div class="grid2">
                  <div class="field"><label class="label">Posti disponibili</label><input class="input light" id="w_seats_max" type="number" step="1" min="0" placeholder="es. 128"></div>
                  <div class="field">
                    <label class="label">Posti</label>
                    <div class="chip-toggle" id="chipInf"><input id="w_seats_inf" type="checkbox" hidden><span class="chip">Infiniti posti</span></div>
                  </div>
                </div>
              </section>
              <section class="step" data-step="3">
                <div class="grid2">
                  <div class="field"><label class="label">Vite max / utente *</label><input class="input light" id="w_lives_max_user" type="number" step="1" min="1" required value="1"></div>
                  <div class="field"><label class="label">Montepremi garantito (opz.)</label><input class="input light" id="w_guaranteed_prize" type="number" step="0.01" min="0"></div>
                  <div class="field"><label class="label">% buy-in → montepremi</label><input class="input light" id="w_buyin_to_prize_pct" type="number" step="0.01" min="0" max="100"></div>
                  <div class="field"><label class="label">% rake sito</label><input class="input light" id="w_rake_pct" type="number" step="0.01" min="0" max="100"></div>
                </div>

                <!-- Specifici Flash (ROUND/EVENTI) -->
                <div class="grid2" style="margin-top:12px;">
                  <div class="field"><label class="label">Totale round (Flash)</label><input class="input light" id="w_total_rounds" type="number" step="1" min="1" value="3"></div>
                  <div class="field"><label class="label">Eventi per round (Flash)</label><input class="input light" id="w_events_per_round" type="number" step="1" min="1" value="1"></div>
                </div>
              </section>
            </form>

            <pre id="debug" class="flash-debug mt-4 hidden"></pre>
          </div>
          <div class="modal-foot">
            <button type="button" class="btn btn--outline btn--sm" id="wPrev">Indietro</button>
            <button type="button" class="btn btn--primary btn--sm" id="wNext">Avanti</button>
            <button type="button" class="btn btn--primary btn--sm hidden" id="wCreate">Crea torneo Flash</button>
          </div>
        </div>
      </div>
    </div>
  </section>
</main>
<?php include __DIR__ . '/../../partials/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const $=s=>document.querySelector(s);

  // wizard
  const modal = $('#wizard');
  const steps = ()=>[...document.querySelectorAll('#wizard .step')];
  const dots  = ()=>[...document.querySelectorAll('#wizard .steps-dots .dot')];
  let idx=0;

  function openWizard(){ modal.setAttribute('aria-hidden','false'); document.body.classList.add('modal-open'); setStep(0); }
  function closeWizard(){ modal.setAttribute('aria-hidden','true'); document.body.classList.remove('modal-open'); }
  function setStep(i){
    idx=Math.max(0,Math.min(i,steps().length-1));
    steps().forEach((s,k)=>s.classList.toggle('active',k===idx));
    dots().forEach((d,k)=>d.classList.toggle('active',k<=idx));
    $('#wPrev').classList.toggle('hidden',idx===0);
    $('#wNext').classList.toggle('hidden',idx===steps().length-1);
    $('#wCreate').classList.toggle('hidden',idx!==steps().length-1);
    document.querySelector('#wizard .modal-body.scroller').scrollTop=0;
  }

  document.querySelectorAll('[data-close]').forEach(b=>b.addEventListener('click', closeWizard));
  $('#btnOpenWizard').addEventListener('click', openWizard);
  $('#wPrev').addEventListener('click', ()=>setStep(idx-1));
  $('#wNext').addEventListener('click', ()=>{
    const cur=steps()[idx]; const invalid=cur.querySelector(':invalid');
    if(invalid){ invalid.reportValidity(); return; }
    setStep(idx+1);
  });

  // chip toggle "infiniti posti"
  document.querySelector('#chipInf .chip').addEventListener('click', ()=>{
    const cb = document.querySelector('#w_seats_inf');
    cb.checked = !cb.checked;
    document.querySelector('#chipInf .chip').classList.toggle('active', cb.checked);
  });

  // create flash – usa gli STESSI CAMPI del torneo normale + campi Flash
  $('#wCreate').addEventListener('click', async ()=>{
    try{
      if (!$('#w_name').value.trim() || !$('#w_buyin').value.trim() || !$('#w_lives_max_user').value.trim()){
        alert('Compila i campi obbligatori'); return;
      }
      const fd=new URLSearchParams();
      fd.append('name',$('#w_name').value.trim());
      fd.append('buyin',$('#w_buyin').value.trim());
      fd.append('seats_infinite',$('#w_seats_inf').checked ? '1':'0');
      const sm=$('#w_seats_max').value.trim(); if(!$('#w_seats_inf').checked && sm) fd.append('seats_max',sm);
      fd.append('lives_max_user',$('#w_lives_max_user').value.trim());
      const gp=$('#w_guaranteed_prize').value.trim(); if(gp) fd.append('guaranteed_prize',gp);
      const p=$('#w_buyin_to_prize_pct').value.trim(); if(p) fd.append('buyin_to_prize_pct',p);
      const rk=$('#w_rake_pct').value.trim(); if(rk) fd.append('rake_pct',rk);

      // campi Flash aggiuntivi (round/eventi)
      fd.append('total_rounds', $('#w_total_rounds').value.trim() || '1');
      fd.append('events_per_round', $('#w_events_per_round').value.trim() || '1');

      // debug
      fd.append('debug','1');

      // CHIAMATA al nuovo endpoint Flash
      const reqUrl = '/api/flash_tournament.php?action=create';
      const r=await fetch(reqUrl,{method:'POST',body:fd,credentials:'same-origin'});
      const raw=await r.text();

      let j;
      try { j = JSON.parse(raw); }
      catch(e){
        alert('Errore creazione (non JSON). Vedi console.');
        console.error('[flash_crea_torneo] RAW=', raw);
        return;
      }

      if(!j.ok){
        const lines = ['Errore creazione Torneo Flash'];
        if (j.error)   lines.push('error: '+j.error);
        if (j.message) lines.push('message: '+j.message);
        if (j.detail)  lines.push('detail: '+JSON.stringify(j.detail));
        if (j.where)   lines.push('where: '+j.where);
        if (j.file)    lines.push('file: '+j.file);
        if (j.line)    lines.push('line: '+j.line);
        alert(lines.join('\n'));
        console.error('[flash_crea_torneo] payload=', j);
        return;
      }

      closeWizard();
      alert('Torneo Flash creato. Codice: '+j.code);
      window.location.href = '/admin/flash_torneo_manage.php?code='+encodeURIComponent(j.code);
    }catch(err){
      alert('Errore imprevisto'); console.error(err);
    }
  });
});
</script>

<style>
/* chip toggle */
.chip-toggle{display:inline-block;cursor:pointer}
.chip-toggle .chip{display:inline-block;padding:6px 12px;border-radius:9999px;border:1px solid var(--c-border);background:transparent;color:var(--c-muted);font-size:14px;transition:.2s}
.chip-toggle input:checked + .chip, .chip-toggle .chip.active{border-color:#27ae60;color:#a7e3bf;background:rgba(39,174,96,.15)}
/* debug area */
.flash-debug{ background:#0f1b3a; border:1px solid #1e2b50; border-radius:8px; padding:12px; color:#fff; font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
</style>
