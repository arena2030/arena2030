<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/csrf.php';
$csrf     = csrf_token();

$username = trim($_SESSION['username'] ?? 'Utente');
$coins    = (float)($_SESSION['coins'] ?? 0);
$uid      = (int)($_SESSION['uid'] ?? 0);

/* Avatar dall’ultima immagine in media (type='avatar') */
$avatarUrl = '';
try {
  $dbPath = __DIR__ . '/db.php';
  if (file_exists($dbPath) && $uid > 0) {
    require_once $dbPath;
    $st = $pdo->prepare("SELECT url, storage_key FROM media WHERE type='avatar' AND owner_id=? ORDER BY id DESC LIMIT 1");
    $st->execute([$uid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r) {
      $url = trim($r['url'] ?? '');
      $key = trim($r['storage_key'] ?? '');
      if ($url !== '') $avatarUrl = $url;
      else {
        $cdn = rtrim(getenv('CDN_BASE') ?: getenv('S3_CDN_BASE') ?: '', '/');
        if (!$cdn) {
          $endpoint = getenv('S3_ENDPOINT'); $bucket = getenv('S3_BUCKET');
          if ($endpoint && $bucket) $cdn = rtrim($endpoint,'/').'/'.$bucket;
        }
        if ($cdn && $key) $avatarUrl = $cdn . '/' . $key;
      }
    }
  }
} catch (Throwable $e) { /* ignore */ }

$initial = strtoupper(mb_substr($username ?: 'U', 0, 1, 'UTF-8'));
?>
<style>
/* =================
   HEADER + SUBMENU (come header Punto “guest-like”)
   ================= */
.hdr{
  background: var(--c-bg);
  border-bottom: 1px solid var(--c-border);
}

/* Barra principale */
.hdr__bar{
  height: 64px;
  display:flex; align-items:center; justify-content:space-between;
}
.hdr__brand{ font-weight:700; display:flex;align-items:center;gap:10px; color:#fff; }
.hdr__nav{ display:flex; gap:16px; }
.hdr__link{ padding:10px 12px; border-radius:8px; }
.hdr__link:hover{ background: rgba(255,255,255,.06); }

/* Blocco destro allineato */
.hdr__right{
  display:flex; align-items:center; gap:16px; height:64px;
}

/* Bottoni pill (Ricarica / Logout) */
.btn-pill{
  height:32px; padding:0 16px;
  display:inline-flex; align-items:center; justify-content:center;
  border-radius:9999px; font-weight:600; cursor:pointer; text-decoration:none;
}
.btn-pill--outline{ border:1px solid var(--c-border); color:#fff; background:transparent; }
.btn-pill--outline:hover{ background:rgba(255,255,255,.06); }

/* Pillola saldo */
.pill-balance{
  height:32px; line-height:32px; padding:0 12px;
  display:inline-flex; align-items:center; gap:6px;
  border-radius:9999px; background:rgba(255,255,255,0.05);
  color:#fff; font-weight:600; font-size:14px;
  border:1px solid transparent;
}
.pill-balance .ac{opacity:.95}
.pill-balance .refresh{color:#9fb7ff;text-decoration:none;font-weight:700;}
.pill-balance .refresh:hover{color:#fff}

/* Avatar */
.avatar-btn{
  width:32px; height:32px; border-radius:50%;
  border:1px solid #2f80ff; overflow:hidden;
  display:flex; align-items:center; justify-content:center;
  background:transparent; color:#fff; font-weight:700; cursor:pointer;
}
.avatar-btn img{ width:100%; height:100%; object-fit:cover; }

/* Username */
.hdr__usr{ color:#fff; height:32px; display:flex; align-items:center; }

/* Submenu leggermente più chiaro (identico a specifica) */
.subhdr { background: var(--c-bg-2); border-top: 1px solid var(--c-border); }
.subhdr .container{ display:flex; justify-content:center; align-items:center; height:40px; padding:0; }
.subhdr__menu{ display:flex; justify-content:center; align-items:center; gap:20px; list-style:none; margin:0; padding:0; }
.subhdr__link{ color:#fff; padding:8px 12px; text-decoration:none; cursor:pointer; }
.subhdr__link:hover{ text-decoration:none; background:none; color:#fff; }

/* Modale avatar (come punto) */
.modal[aria-hidden="true"]{ display:none; } .modal{ position:fixed; inset:0; z-index:70; }
.modal-open{ overflow:hidden; }
.modal-backdrop{ position:absolute; inset:0; background:rgba(0,0,0,.5); }
.modal-card{ position:relative; z-index:71; width:min(560px,96vw);
             background:var(--c-bg); border:1px solid var(--c-border); border-radius:16px;
             margin:8vh auto 0; padding:0; box-shadow:0 16px 48px rgba(0,0,0,.5); }
.modal-head{ display:flex; align-items:center; gap:10px; padding:12px 16px; border-bottom:1px solid var(--c-border); }
.modal-x{ margin-left:auto; background:transparent; border:0; color:#fff; font-size:24px; cursor:pointer; }
.modal-body{ padding:16px; }
.modal-foot{ display:flex; justify-content:flex-end; gap:8px; padding:12px 16px; border-top:1px solid var(--c-border); }

.avatar-zoom{ width:min(280px,70vw); height:min(280px,70vw); border-radius:16px; border:1px solid var(--c-border);
              overflow:hidden; background:#111; margin:0 auto 12px auto; display:flex; align-items:center; justify-content:center; }
.avatar-zoom img{ width:100%; height:100%; object-fit:cover; display:block; }
.avatar-zoom .initial{ width:100%; height:100%; display:flex; align-items:center; justify-content:center;
                       font-size:64px; font-weight:800; color:#eaeaea; background:linear-gradient(135deg,#243249,#101623); }
</style>

<header class="hdr">
  <div class="container hdr__bar">
    <!-- SX: Logo + ARENA -->
    <a href="/punto/dashboard.php" class="hdr__brand">
      <!-- usa il tuo logo se esiste -->
      <img id="brandLogo" class="brand-logo" src="/assets/logo.svg" alt="Arena" width="24" height="24"
           onerror="this.style.display='none';" />
      <span>ARENA</span>
    </a>

    <!-- DX: [Ricarica] [Saldo] [Avatar] [Username] [Logout] -->
    <div class="hdr__right" aria-label="Menu utente">
      <a href="/ricarica.php" class="btn btn--primary btn--sm">Ricarica</a>

   <div class="pill-balance" title="Arena Coins">
  <span aria-hidden="true">C.</span>
  <span class="ac" data-balance-amount>0.00</span>
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

      <span class="hdr__usr" aria-label="Utente"><?= htmlspecialchars($username) ?></span>

      <a href="/logout.php" class="btn-pill btn-pill--outline">Logout</a>
    </div>
  </div>

  <!-- SUBHEADER UTENTE -->
  <nav class="subhdr" aria-label="Sezioni utente">
    <div class="container">
      <ul class="subhdr__menu">
        <li><a class="subhdr__link" href="/lobby.php">Lobby</a></li>
        <li><a class="subhdr__link" href="/storico.php">Storico tornei</a></li>
        <li><a class="subhdr__link" href="/premi.php">Premi</a></li>
        <li><a class="subhdr__link" href="/movimenti.php">Lista movimenti</a></li>
        <li><a class="subhdr__link" href="/dati-utente.php">Dati utente</a></li>
      </ul>
    </div>
  </nav>
</header>

<!-- MODALE Avatar (riuso la stessa logica del Punto) -->
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
  const $ = s=>document.querySelector(s);
  const $$= (s,p=document)=>[...p.querySelectorAll(s)];

  function openM(){ $('#mdAvatar').setAttribute('aria-hidden','false'); document.body.classList.add('modal-open'); }
  function closeM(){ $('#mdAvatar').setAttribute('aria-hidden','true'); document.body.classList.remove('modal-open'); }
  $('#btnAvatar')?.addEventListener('click', (e)=>{ e.preventDefault(); openM(); });
  $$('#mdAvatar [data-close], #mdAvatar .modal-backdrop').forEach(el=>el.addEventListener('click', closeM));

  const fileInput = $('#avFile');
  const pickBtn   = $('#avPick');
  const saveBtn   = $('#avSave');
  const prevImg   = $('#avPreview');
  const prevInit  = $('#avInitial');
  const smallImg  = $('#avatarImg');
  const smallInit = $('#avatarInitial');
  const hint      = $('#avHint');
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

  function readImageSize(file){
    return new Promise(resolve=>{
      if (!file || !file.type || !file.type.startsWith('image/')) return resolve({width:0,height:0});
      const img = new Image();
      img.onload = ()=> resolve({width: img.naturalWidth || 0, height: img.naturalHeight || 0});
      img.onerror= ()=> resolve({width:0,height:0});
      img.src = URL.createObjectURL(file);
    });
  }

  async function uploadToR2(file){
    const fd = new FormData();
    fd.append('file', file);
    fd.append('type', 'avatar');
    fd.append('owner_id', '<?= $uid ?>');
    const rsp = await fetch('/api/upload_r2.php', { method:'POST', body: fd, credentials:'same-origin' });
    const txt = await rsp.text(); let j;
    try{ j = JSON.parse(txt); }catch(e){ throw new Error('upload_r2 non JSON: '+txt.slice(0,160)); }
    if (!j.ok) throw new Error(j.detail || j.error || 'upload_failed');
    const dims = await readImageSize(file);
    return {
      storage_key: j.key, url: j.cdn_url, etag: j.etag || '',
      mime: file.type || 'image/jpeg', size: file.size || 0,
      width: dims.width || 0, height: dims.height || 0
    };
  }

  async function saveMedia(meta){
    const fd = new URLSearchParams({
      type:'avatar', owner_id:'<?= $uid ?>',
      storage_key: meta.storage_key, url: meta.url, etag: meta.etag,
      mime: meta.mime, width:String(meta.width||0), height:String(meta.height||0), size:String(meta.size||0),
      csrf_token: '<?= htmlspecialchars($csrf, ENT_QUOTES) ?>'
    });
    const r = await fetch('/api/media_save.php', { method:'POST', body: fd, credentials:'same-origin' });
    const txt = await r.text(); let j; try{ j=JSON.parse(txt);}catch(e){ throw new Error('media_save non JSON: '+txt.slice(0,160)); }
    if (!j.ok) throw new Error(j.detail || j.error || 'media_save_failed');
    return j;
  }

  saveBtn.addEventListener('click', async ()=>{
    if (!selectedFile) { alert('Seleziona una foto prima di confermare.'); return; }
    pickBtn.disabled = true; saveBtn.disabled = true; hint.textContent = 'Caricamento...';
    try{
      const meta = await uploadToR2(selectedFile);
      await saveMedia(meta);
      if (smallInit) smallInit.style.display='none';
      if (smallImg) { smallImg.src = meta.url; smallImg.style.display='block'; }
      hint.textContent = 'Avatar aggiornato.';
    }catch(err){
      console.error(err); alert('Upload non riuscito. Dettagli: '+(err?.message||''));
    }finally{ pickBtn.disabled=false; saveBtn.disabled=false; }
  });

  document.addEventListener('refresh-balance', async ()=>{
    try{
      const r=await fetch('/punto/premi.php?action=me',{cache:'no-store'});
      const j=await r.json();
      if (j.ok && j.me) { const el=document.querySelector('[data-balance-amount]'); if (el) el.textContent=Number(j.me.coins||0).toFixed(2); }
    }catch(e){}
  });
});
</script>
<script src="/partials/balance.js?v=1" defer></script>
