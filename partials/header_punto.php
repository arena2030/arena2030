<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/csrf.php';   // <-- AGGIUNTO
$csrf     = csrf_token();                             // <-- AGGIUNTO
$username = trim($_SESSION['username'] ?? 'Punto');
$coins    = (float)($_SESSION['coins'] ?? 0);
$initial  = strtoupper(mb_substr($username !== '' ? $username : 'P', 0, 1, 'UTF-8'));
$uid      = (int)($_SESSION['uid'] ?? 0);

/* --- Recupera eventuale avatar già presente (media.type='avatar') --- */
$avatarUrl = '';
try {
  // db.php è nella stessa cartella dei partials
  $dbPath = __DIR__ . '/db.php';
  if (file_exists($dbPath) && $uid > 0) {
    require_once $dbPath;

    // CDN base: prima CDN_BASE (quella che usi nell’upload), poi S3_CDN_BASE, poi endpoint/bucket
    $cdn = rtrim(getenv('CDN_BASE') ?: getenv('S3_CDN_BASE') ?: '', '/');
    if (!$cdn) {
      $endpoint = getenv('S3_ENDPOINT'); $bucket = getenv('S3_BUCKET');
      if ($endpoint && $bucket) $cdn = rtrim($endpoint,'/').'/'.$bucket;
    }

    // Prendi anche 'url' oltre a 'storage_key'
    $st = $pdo->prepare("SELECT url, storage_key FROM media WHERE type='avatar' AND owner_id=? ORDER BY id DESC LIMIT 1");
    $st->execute([$uid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if ($row) {
      $url = trim($row['url'] ?? '');
      $key = trim($row['storage_key'] ?? '');
      if ($url !== '') {
        $avatarUrl = $url;                    // usa la URL salvata
      } elseif ($key !== '' && $cdn !== '') {
        $avatarUrl = $cdn . '/' . $key;       // fallback: ricostruisci dalla chiave
      }
    }
  }
} catch (Throwable $e) {
  // ignora: header deve sempre renderizzare
}
?>
<style>
.hdr{border-bottom:1px solid var(--c-border);}

/* Barra principale: stessa altezza del guest (56px) */
.hdr__bar{
  display:flex;justify-content:space-between;align-items:center;
  height:56px; padding:0; /* niente padding verticale extra */
}

/* Brand come guest: gap 10px, logo 24px con fallback blu */
.hdr__brand{
  color:#fff;text-decoration:none;font-weight:700;letter-spacing:.5px;
  display:flex;align-items:center;gap:10px;
}
.brand-logo{display:block;width:24px;height:24px}
.brand-fallback{
  width:24px;height:24px;border-radius:6px;
  display:inline-flex;align-items:center;justify-content:center;
  background:linear-gradient(135deg,#2f80ff,#00c2ff);
  color:#fff;font-weight:800; font-size:13px; letter-spacing:0;
}

/* Destra: elementi compatti come nel guest */
.hdr__right{--btnH:32px; display:flex;align-items:center;gap:16px}

/* Pillola saldo: sottile, blu scuro trasparente */
.pill-balance{
  height:var(--btnH);
  display:inline-flex;align-items:center;gap:6px;
  padding:0 12px;border-radius:9999px;
  background:rgba(255,255,255,0.05); /* come il guest */
  color:#fff;font-weight:600; line-height:var(--btnH);
  border:1px solid transparent; /* look pulito */
}
.pill-balance .ac{opacity:.95}
.pill-balance .refresh{color:#9fb7ff;text-decoration:none;font-weight:700;display:inline-flex;align-items:center}
.pill-balance .refresh:hover{color:#fff}

/* Avatar: bordo blu come il guest, senza gradient */
.avatar-btn{
  width:var(--btnH); height:var(--btnH);
  border-radius:50%; border:1px solid #2f80ff;
  display:inline-flex; align-items:center; justify-content:center;
  background:transparent;
  color:#eaeaea; font-size:13px; font-weight:700;
  cursor:pointer; overflow:hidden;
}
.avatar-btn img{ width:100%; height:100%; object-fit:cover; display:block; }

/* Username chiaro come guest */
.hdr__usr{ color:#fff; }

/* Subheader — identica al guest: blu scuro, centrata, slim */
.subhdr{
  /* blu scuro pieno, come la barra guest */
  background: #0f1726;                /* <- se vuoi ancora più scuro usa #0c1322 */
  border-top: 1px solid rgba(255,255,255,0.06);
}
.subhdr .container{
  display:flex;
  justify-content:center;             /* voci al centro */
  align-items:center;
  height: 40px;                       /* altezza visiva slim (guest-like) */
  padding: 0;                         /* niente padding extra */
}
.subhdr__menu{
  display:flex;
  gap:14px;
  margin:0; padding:0;
  list-style:none;
  align-items:center;
}
.subhdr__link{
  color:#ddd;
  text-decoration:none;
  padding: 4px 10px;                  /* link compatti */
  border-radius:6px;
}
.subhdr__link:hover{
  background: rgba(255,255,255,0.06);
  color:#fff;
}

/* Modale avatar (invariata, solo ripulita) */
.modal[aria-hidden="true"]{ display:none; } 
.modal{ position:fixed; inset:0; z-index:70; }
.modal-open{ overflow:hidden; }
.modal-backdrop{ position:absolute; inset:0; background:rgba(0,0,0,.5); }
.modal-card{ position:relative; z-index:71; width:min(560px,96vw);
             background:var(--c-bg); border:1px solid var(--c-border); border-radius:16px;
             margin:8vh auto 0; padding:0; box-shadow:0 16px 48px rgba(0,0,0,.5); }
.modal-head{ display:flex; align-items:center; gap:10px; padding:12px 16px; border-bottom:1px solid var(--c-border); }
.modal-x{ margin-left:auto; background:transparent; border:0; color:#fff; font-size:24px; cursor:pointer; }
.modal-body{ padding:16px; }
.modal-foot{ display:flex; justify-content:flex-end; gap:8px; padding:12px 16px; border-top:1px solid var(--c-border); }

.avatar-zoom{
  width:min(280px,70vw); height:min(280px,70vw);
  border-radius:16px; border:1px solid var(--c-border);
  overflow:hidden; background:#111; margin:0 auto 12px auto;
  display:flex; align-items:center; justify-content:center;
}
.avatar-zoom img{ width:100%; height:100%; object-fit:cover; display:block; }
.avatar-zoom .initial{
  width:100%; height:100%; display:flex; align-items:center; justify-content:center;
  font-size:64px; font-weight:800; color:#eaeaea; background:linear-gradient(135deg,#243249,#101623);
}
</style>

<header class="hdr">
  <div class="container hdr__bar">
    <!-- SX: logo + ARENA -->
 <a href="/punto/dashboard.php" class="hdr__brand">
  <img id="brandLogo" class="brand-logo" src="/assets/logo.svg" alt="Arena" width="28" height="28"
       onerror="this.style.display='none';document.getElementById('brandFallback').style.display='inline-flex';" />
  <span id="brandFallback" class="brand-fallback" aria-hidden="true" style="display:none;">A</span>
  <span>ARENA</span>
</a>

    <!-- DX: [pill saldo] [avatar] [username] [logout] -->
    <div class="hdr__right" aria-label="Menu punto">
      <div class="pill-balance" title="Arena Coins">
        <span aria-hidden="true">C.</span>
        <span class="ac" data-balance-amount><?= htmlspecialchars(number_format($coins, 2, '.', '')) ?></span>
        <a href="#" class="refresh" title="Aggiorna saldo"
           onclick="document.dispatchEvent(new CustomEvent('refresh-balance'));return false;">↻</a>
      </div>

      <button type="button" class="avatar-btn" id="btnAvatar" title="Modifica avatar">
        <?php if ($avatarUrl): ?>
          <img id="avatarImg" src="<?= htmlspecialchars($avatarUrl) ?>" alt="Avatar">
        <?php else: ?>
          <span id="avatarInitial"><?= htmlspecialchars($initial) ?></span>
        <?php endif; ?>
      </button>

      <span class="hdr__usr" aria-label="Operatore punto"><?= htmlspecialchars($username) ?></span>

      <a href="/logout.php" class="btn btn--outline btn--sm">Logout</a>
    </div>
  </div>

  <!-- SUBHEADER (come volevi) -->
  <nav class="subhdr" aria-label="Navigazione secondaria punto">
    <div class="container">
      <ul class="subhdr__menu">
        <li><a class="subhdr__link" href="/punto/dashboard.php">Players</a></li>
        <li><a class="subhdr__link" href="/punto/commissioni.php">Commissioni</a></li>
        <li><a class="subhdr__link" href="/punto/premi.php">Premi</a></li>
      </ul>
    </div>
  </nav>
</header>

<!-- MODALE Avatar -->
<div class="modal" id="mdAvatar" aria-hidden="true">
  <div class="modal-backdrop" data-close></div>
  <div class="modal-card">
    <div class="modal-head">
      <h3>Avatar</h3>
      <button class="modal-x" data-close>&times;</button>
    </div>
    <div class="modal-body">
      <div class="avatar-zoom">
        <?php if ($avatarUrl): ?>
          <img id="avPreview" src="<?= htmlspecialchars($avatarUrl) ?>" alt="Anteprima avatar">
        <?php else: ?>
          <div id="avInitial" class="initial"><?= htmlspecialchars($initial) ?></div>
          <img id="avPreview" src="" alt="Anteprima avatar" style="display:none;">
        <?php endif; ?>
      </div>
      <div style="display:flex; gap:8px; justify-content:center;">
        <input type="file" id="avFile" accept="image/*" style="display:none;">
        <button class="btn btn--outline" type="button" id="avPick">Carica foto</button>
        <button class="btn btn--primary" type="button" id="avSave">Conferma</button>
      </div>
      <p id="avHint" class="muted" style="text-align:center;margin-top:8px;"></p>
    </div>
    <div class="modal-foot">
      <button class="btn btn--outline" data-close>Annulla</button>
      <button class="btn btn--primary" data-close>Chiudi</button>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', ()=>{
  const $  = s=>document.querySelector(s);
  const $$ = (s,p=document)=>[...p.querySelectorAll(s)];

  // === Modal helpers
  function openM(){ $('#mdAvatar').setAttribute('aria-hidden','false'); document.body.classList.add('modal-open'); }
  function closeM(){ $('#mdAvatar').setAttribute('aria-hidden','true'); document.body.classList.remove('modal-open'); }

  // === Bind aperture/chiusura modale
  $('#btnAvatar')?.addEventListener('click', (e)=>{ e.preventDefault(); openM(); });
  $$('#mdAvatar [data-close], #mdAvatar .modal-backdrop').forEach(el=>el.addEventListener('click', closeM));

  // === Elementi UI
  const fileInput = $('#avFile');
  const pickBtn   = $('#avPick');
  const saveBtn   = $('#avSave');
  const prevImg   = $('#avPreview');
  const prevInit  = $('#avInitial');
  const smallImg  = $('#avatarImg');     // avatar piccolo nella topbar (se esiste)
  const smallInit = $('#avatarInitial'); // iniziale nella topbar (se non c'è img)
  const hint      = $('#avHint');

  // === Scelta file + anteprima
  let selectedFile = null;
  pickBtn.addEventListener('click', ()=> fileInput.click());
  fileInput.addEventListener('change', ()=>{
    selectedFile = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
    if (!selectedFile) return;
    const url = URL.createObjectURL(selectedFile);
    if (prevInit) prevInit.style.display='none';
    prevImg.src = url; prevImg.style.display='block';
    hint.textContent = selectedFile.name;
  });

  // === Utility: leggere width/height lato client (opzionale)
  function readImageSize(file){
    return new Promise(resolve=>{
      if (!file || !file.type || !file.type.startsWith('image/')) return resolve({width:0,height:0});
      const img = new Image();
      img.onload = ()=> resolve({width: img.naturalWidth || img.width || 0, height: img.naturalHeight || img.height || 0});
      img.onerror= ()=> resolve({width:0,height:0});
      img.src = URL.createObjectURL(file);
    });
  }

  // === UPLOAD SU R2 usando il TUO endpoint ESISTENTE (/public/api/upload_r2.php)
  async function uploadToR2(file){
    const fd = new FormData();
    fd.append('file', file);
    fd.append('type', 'avatar');             // <- come da tuo PHP
    fd.append('owner_id', '<?= (int)($_SESSION['uid'] ?? 0) ?>');

    const rsp = await fetch('/api/upload_r2.php', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    });

    const txt = await rsp.text();            // intercetto eventuale HTML error
    let j;
    try { j = JSON.parse(txt); } catch(e) {
      throw new Error('upload_r2 non JSON: ' + txt.slice(0,160));
    }
    if (!j.ok) throw new Error(j.detail || j.error || 'upload_failed');

    // Il tuo endpoint ritorna: key, cdn_url, etag, size, mime
    const dims = await readImageSize(file);
    return {
      storage_key: j.key,                   // <- come vuole media_save.php
      url:         j.cdn_url,
      etag:        j.etag || '',
      mime:        j.mime || file.type || 'image/jpeg',
      size:        j.size || file.size || 0,
      width:       dims.width || 0,
      height:      dims.height || 0
    };
  }

  // === SALVATAGGIO METADATI usando il TUO endpoint ESISTENTE (/public/api/media_save.php)
  async function saveMedia(meta){
    const fd = new URLSearchParams({
      type:        'avatar',                              // <- allinea al tuo PHP
      owner_id:    '<?= (int)($_SESSION['uid'] ?? 0) ?>', // <- id utente/punto
      storage_key: meta.storage_key,                      // <- OBBLIGATORIO nel tuo PHP
      url:         meta.url,                              // <- OBBLIGATORIO nel tuo PHP
      mime:        meta.mime,                             // <- OBBLIGATORIO nel tuo PHP
      width:       String(meta.width || 0),               // opzionale
      height:      String(meta.height || 0),              // opzionale
      size:        String(meta.size || 0),                // opzionale
      etag:        meta.etag || ''
    });

    const r = await fetch('/api/media_save.php', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    });

    const txt = await r.text();
    let j;
    try { j = JSON.parse(txt); } catch(e) {
      throw new Error('media_save non JSON: ' + txt.slice(0,160));
    }
    if (!j.ok) throw new Error(j.detail || j.error || 'media_save_failed');
    return j;
  }

  // === Conferma: upload + save + refresh avatar in header
  saveBtn.addEventListener('click', async ()=>{
    if (!selectedFile) { alert('Seleziona una foto prima di confermare.'); return; }
    pickBtn.disabled = true; saveBtn.disabled = true; hint.textContent = 'Caricamento...';

    try{
      const meta = await uploadToR2(selectedFile);
      await saveMedia(meta);

      // aggiorna avatar piccolo nella topbar
      if (smallInit) smallInit.style.display='none';
      if (smallImg) {
        smallImg.src = meta.url;
        smallImg.style.display='block';
      } else {
        const img = document.createElement('img');
        img.id = 'avatarImg';
        img.src = meta.url;
        img.alt = 'Avatar';
        const btn = document.getElementById('btnAvatar');
        btn.innerHTML = '';
        btn.appendChild(img);
      }

      hint.textContent = 'Avatar aggiornato.';
    } catch (err){
      console.error(err);
      alert('Upload non riuscito. Dettagli: ' + (err && err.message ? err.message : ''));
    } finally {
      pickBtn.disabled = false; saveBtn.disabled = false;
    }
  });

  // (facoltativo) refresh saldo, se in futuro esponi un endpoint
  document.addEventListener('refresh-balance', async ()=>{
    try{
      const r = await fetch('/punto/premi.php?action=me', { cache:'no-store' });
      const j = await r.json();
      if (j.ok && j.me) {
        const el = document.querySelector('[data-balance-amount]');
        if (el) el.textContent = Number(j.me.coins||0).toFixed(2);
      }
    }catch(e){}
  });
});
</script>
