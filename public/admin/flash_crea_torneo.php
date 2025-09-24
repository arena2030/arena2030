<?php
require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }
$role = strtoupper((string)($_SESSION['role'] ?? 'USER'));
if (empty($_SESSION['uid']) || (!in_array($role,['ADMIN','PUNTO']) && (int)($_SESSION['is_admin'] ?? 0)!==1)) {
  header('Location: /login.php'); exit;
}
$page_css='/pages-css/flash.css';
include __DIR__.'/../../partials/head.php';
include __DIR__.'/../../partials/header_admin.php';
?>
<main class="section">
  <div class="container card">
    <h1>Crea Torneo Flash</h1>
    <div class="grid2">
      <div class="field">
        <label class="label">Nome</label>
        <input class="input light" id="name" placeholder="Torneo Flash">
      </div>
      <div class="field">
        <label class="label">Montepremi (opzionale)</label>
        <input class="input light" id="pool" type="number" min="0" step="0.01" value="0">
      </div>
      <div class="field">
        <label class="label">Totale round</label>
        <input class="input light" id="rounds" type="number" min="1" value="3">
      </div>
      <div class="field">
        <label class="label">Eventi per round</label>
        <input class="input light" id="epr" type="number" min="1" value="1">
      </div>
    </div>
    <div class="mt-4">
      <button id="btnCreate" class="btn btn--primary btn--sm">Crea Torneo Flash</button>
    </div>

    <pre id="debug" class="flash-debug mt-4 hidden"></pre>
  </div>
</main>
<script>
document.getElementById('btnCreate').addEventListener('click', async ()=>{
  const name = document.getElementById('name').value.trim() || 'Torneo Flash';
  const pool = document.getElementById('pool').value.trim();
  const rounds = document.getElementById('rounds').value.trim();
  const epr = document.getElementById('epr').value.trim();

  const fd = new URLSearchParams({name, prize_pool:pool, total_rounds:rounds, events_per_round:epr, debug:'1'});
  const r = await fetch('/api/flash_tournament.php?action=create', {method:'POST', body:fd, credentials:'same-origin'});
  const raw = await r.text();
  try{
    const j = JSON.parse(raw);
    if (!j.ok){ throw j; }
    alert('Creato! Codice: '+j.code);
    window.location.href = '/admin/flash_torneo_manage.php?code='+encodeURIComponent(j.code);
  }catch(e){
    const dbg = document.getElementById('debug');
    dbg.classList.remove('hidden');
    dbg.textContent = '[flash_crea_torneo.php] ERRORE:\n' + (e.message || JSON.stringify(e, null, 2)) + '\nRAW:\n' + raw;
  }
});
</script>
<?php include __DIR__.'/../../partials/footer.php'; ?>
