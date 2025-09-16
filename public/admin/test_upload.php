<?php
// Test upload R2 — carica logo/ avatar/ premio via presign
$page_css = '/pages-css/admin-dashboard.css';
include __DIR__ . '/../../partials/head.php';
include __DIR__ . '/../../partials/header_admin.php';
?>
<main>
  <section class="section">
    <div class="container">
      <h1>Test upload R2</h1>
      <div class="card" style="max-width:760px;">
        <form id="upForm" onsubmit="return false;" class="grid2">
          <div class="field">
            <label class="label">Tipo</label>
            <select class="select light" id="type">
              <option value="team_logo">team_logo</option>
              <option value="avatar">avatar</option>
              <option value="prize">prize</option>
              <option value="generic">generic</option>
            </select>
          </div>

          <div class="field" id="f_league">
            <label class="label">League (es. SERIE_A)</label>
            <input class="input light" id="league" placeholder="SERIE_A">
          </div>
          <div class="field" id="f_slug">
            <label class="label">Slug squadra (es. ac-milan)</label>
            <input class="input light" id="slug" placeholder="ac-milan">
          </div>

          <div class="field" id="f_owner">
            <label class="label">Owner ID (per avatar)</label>
            <input class="input light" id="owner_id" type="number" placeholder="123">
          </div>

          <div class="field" id="f_prize">
            <label class="label">Prize ID (per prize)</label>
            <input class="input light" id="prize_id" type="number" placeholder="45">
          </div>

          <div class="field" style="grid-column: span 2;">
            <label class="label">File (SVG/PNG/JPG/WebP)</label>
            <input id="file" type="file" accept=".svg,.png,.jpg,.jpeg,.webp" required>
          </div>

          <div class="field" style="grid-column: span 2;">
            <button class="btn btn--primary" id="btnGo">Carica</button>
          </div>
        </form>

        <div id="out" class="mt-6"></div>
      </div>
    </div>
  </section>
</main>
<?php include __DIR__ . '/../../partials/footer.php'; ?>

<script>
const $ = s => document.querySelector(s);

function toggleFields(){
  const t = $('#type').value;
  $('#f_league').style.display = (t==='team_logo') ? '' : 'none';
  $('#f_slug').style.display   = (t==='team_logo') ? '' : 'none';
  $('#f_owner').style.display  = (t==='avatar')    ? '' : 'none';
  $('#f_prize').style.display  = (t==='prize')     ? '' : 'none';
}
$('#type').addEventListener('change', toggleFields);
toggleFields();

$('#btnGo').addEventListener('click', async ()=>{
  const type = $('#type').value;
  const file = $('#file').files[0];
  if (!file){ alert('Seleziona un file'); return; }

  // 1) chiedo il presign al backend
  const fd1 = new FormData();
  fd1.append('type', type);
  fd1.append('mime', file.type || 'application/octet-stream');
  if (type==='team_logo'){
    fd1.append('league', $('#league').value.trim());
    fd1.append('slug',   $('#slug').value.trim());
  }
  if (type==='avatar'){
    fd1.append('owner_id', $('#owner_id').value.trim());
  }
  if (type==='prize'){
    fd1.append('prize_id', $('#prize_id').value.trim());
  }

  let p;
  try{
    const r = await fetch('/api/presign.php', { method:'POST', body: fd1 });
    p = await r.json();
    if (!p.ok){ throw new Error(p.error || 'presign failed'); }
  }catch(e){
    $('#out').innerHTML = `<pre style="color:#f77">Errore presign: ${e.message}</pre>`;
    return;
  }

  // 2) PUT diretto su R2 (carico il file al link firmato)
  try{
    const r2 = await fetch(p.url, { method:'PUT', headers: p.headers||{}, body: file });
    if (!r2.ok) throw new Error('PUT su R2 non ok: '+r2.status);
  }catch(e){
    $('#out').innerHTML = `<pre style="color:#f77">Errore upload su R2: ${e.message}</pre>`;
    return;
  }

  // 3) Mostro i risultati (URL pubblico)
  $('#out').innerHTML = `
    <div class="card">
      <p><strong>OK!</strong></p>
      <p><code>key:</code> ${p.key}</p>
      <p><code>cdn_url:</code> <a href="${p.cdn_url}" target="_blank">${p.cdn_url}</a></p>
      <div style="margin-top:10px;">
        <img src="${p.cdn_url}" alt="preview" style="max-width:240px;background:#fff;padding:6px;border-radius:8px">
      </div>
    </div>`;
});
</script>
