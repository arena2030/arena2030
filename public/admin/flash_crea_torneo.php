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
<style>
  /* ======= Stile “card eleganti” — nessun cambio logico ======= */
  .section{ padding-top:24px; }
  .container{ max-width:1100px; margin:0 auto; }
  h1{ color:#fff; font-size:26px; font-weight:900; letter-spacing:.2px; margin:0 0 12px; }

  /* Card elegante scura */
  .card{
    position:relative; border-radius:20px; padding:18px;
    background:
      radial-gradient(1000px 300px at 50% -120px, rgba(99,102,241,.10), transparent 60%),
      linear-gradient(135deg,#0e1526 0%, #0b1220 100%);
    border:1px solid rgba(255,255,255,.08);
    color:#fff;
    box-shadow: 0 20px 60px rgba(0,0,0,.35);
    transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease, background .15s ease;
    overflow:hidden;
    margin-bottom:16px;
  }
  .card::before{
    content:""; position:absolute; left:0; top:0; bottom:0; width:4px;
    background:linear-gradient(180deg,#1e3a8a 0%, #0ea5e9 100%); opacity:.35;
  }
  .card:hover{ transform: translateY(-2px); box-shadow: 0 26px 80px rgba(0,0,0,.48); border-color:#21324b; }

  /* Form layout */
  .grid2{ display:grid; grid-template-columns:1fr 1fr; gap:16px; }
  @media (max-width:860px){ .grid2{ grid-template-columns:1fr; } }
  .field{ display:flex; flex-direction:column; gap:6px; }
  .label{ color:#9fb7ff; font-size:12px; font-weight:700; letter-spacing:.3px; }

  /* Inputs coerenti al dark */
  .input.light, .select.light{
    width:100%; height:38px; padding:0 12px; border-radius:10px;
    background:#0f172a; border:1px solid #1f2937; color:#fff; appearance:none;
  }
  .input.light:focus, .select.light:focus{
    outline:0; border-color:#334155; box-shadow:0 0 0 3px rgba(59,130,246,.15);
  }

  /* Toggle “Infiniti posti” */
  .chip-toggle{ display:inline-block; cursor:pointer; }
  .chip-toggle .chip{
    display:inline-block; padding:6px 12px; border-radius:9999px;
    border:1px solid var(--c-border,#243244); background:transparent;
    color:#aeb9c9; font-size:14px; transition:.2s;
  }
  .chip-toggle input:checked + .chip,
  .chip-toggle .chip.active{
    border-color:#27ae60; color:#a7e3bf; background:rgba(39,174,96,.15);
  }

  /* Spaziature utili */
  .mt-6{ margin-top:16px; }

  /* Debug box */
  .debug{
    background:#0b1220; border:1px solid #1f2937; border-radius:12px;
    padding:12px; color:#9ca3af; overflow:auto;
  }
</style>
