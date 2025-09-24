<?php
require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['uid']) || !(($_SESSION['role'] ?? 'USER')==='ADMIN' || (int)($_SESSION['is_admin'] ?? 0)===1)) {
  header('Location: /login.php'); exit;
}

$page_css='/pages-css/flash.css';
include __DIR__ . '/../../partials/head.php';
include __DIR__ . '/../../partials/header_admin.php';
?>
<main>
  <section class="section">
    <div class="container">
      <h1>Crea Torneo Flash</h1>

      <div class="card" style="max-width:760px;">
        <form id="fCreate">
          <div class="grid2">
            <div class="field">
              <label class="label">Nome torneo *</label>
              <input class="input light" name="name" required>
            </div>
            <div class="field">
              <label class="label">Costo vita (buy-in) *</label>
              <input class="input light" name="buyin" type="number" step="0.01" min="0" required>
            </div>
          </div>

          <div class="grid2">
            <div class="field">
              <label class="label">Posti disponibili</label>
              <input class="input light" name="seats_max" type="number" step="1" min="0" placeholder="es. 128">
            </div>
            <div class="field">
              <label class="label">Posti</label>
              <div class="chip-toggle">
                <input id="seats_inf" name="seats_infinite" type="checkbox" hidden>
                <span class="chip">Infiniti posti</span>
              </div>
            </div>
          </div>

          <div class="grid2">
            <div class="field">
              <label class="label">Vite max / utente *</label>
              <input class="input light" name="lives_max_user" type="number" step="1" min="1" required value="1">
            </div>
            <div class="field">
              <label class="label">Montepremi garantito (opz.)</label>
              <input class="input light" name="guaranteed_prize" type="number" step="0.01" min="0">
            </div>
            <div class="field">
              <label class="label">% buy-in → montepremi</label>
              <input class="input light" name="buyin_to_prize_pct" type="number" step="0.01" min="0" max="100">
            </div>
            <div class="field">
              <label class="label">% rake sito</label>
              <input class="input light" name="rake_pct" type="number" step="0.01" min="0" max="100">
            </div>
          </div>

          <div class="mt-6">
            <button type="submit" class="btn btn--primary btn--sm">Crea</button>
            <a class="btn btn--outline btn--sm" href="/admin/crea-tornei.php">Indietro</a>
          </div>
        </form>
        <pre id="dbg" class="debug" style="display:none"></pre>
      </div>
    </div>
  </section>
</main>
<?php include __DIR__ . '/../../partials/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', ()=>{
  const $=s=>document.querySelector(s);
  document.querySelector('.chip-toggle .chip').addEventListener('click', ()=>{
    const cb=$('#seats_inf'); cb.checked=!cb.checked;
    document.querySelector('.chip-toggle .chip').classList.toggle('active', cb.checked);
  });

  async function jsonFetch(url, opts){
    const r=await fetch(url, opts||{});
    const raw=await r.text();
    try{ return JSON.parse(raw); }catch(e){ console.error('[RAW]',raw); return {ok:false,error:'bad_json',raw}; }
  }

  $('#fCreate').addEventListener('submit', async e=>{
    e.preventDefault();
    const fd=new FormData(e.currentTarget);
    if ($('#seats_inf').checked) fd.set('seats_infinite','1'); else fd.set('seats_infinite','0');
    const j=await jsonFetch('/api/flash_tournament.php?action=create',{method:'POST',body:fd,credentials:'same-origin'});
    if(!j.ok){ alert('Errore: '+(j.error||'')); const d=$('#dbg'); d.style.display='block'; d.textContent=JSON.stringify(j,null,2); return; }
    alert('Creato. Codice: '+j.code);
    window.location.href = '/admin/flash_torneo_manage.php?code=' + encodeURIComponent(j.code);
  });
});
</script>
