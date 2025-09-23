<?php
// /public/partials/messages_user_widget.php
if (session_status()===PHP_SESSION_NONE) { session_start(); }
if (!defined('APP_ROOT')) { define('APP_ROOT', dirname(__DIR__, 1)); }
require_once APP_ROOT . '/partials/csrf.php';
$CSRF = htmlspecialchars(csrf_token(), ENT_QUOTES);

// Solo se utente/punto loggato (no admin necessario)
$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) { return; }
?>
<style>
/* ==== User Messages Widget (scoped) ==== */
.msgw { position: relative; display:inline-block; }
.msgw-btn{
  position:relative; display:inline-flex; align-items:center; justify-content:center;
  width:38px; height:38px; border-radius:10px; border:1px solid rgba(255,255,255,.12);
  background:#0f172a; color:#e5e7eb; cursor:pointer;
}
.msgw-btn:hover{ filter:brightness(1.06); }
.msgw-btn svg{ width:18px; height:18px; }

.msgw-dot{
  position:absolute; top:-2px; right:-2px; width:10px; height:10px;
  border-radius:9999px; background:#fde047; box-shadow:0 0 0 0 rgba(253,224,71,.9);
  animation: msgwblink 1.4s ease-in-out infinite;
}
@keyframes msgwblink{ 0%{ box-shadow:0 0 0 0 rgba(253,224,71,.9);} 70%{ box-shadow:0 0 0 6px rgba(253,224,71,0);} 100%{ box-shadow:0 0 0 0 rgba(253,224,71,0);} }

.msgw-panel{
  position:absolute; right:0; top:44px; width:min(420px, 92vw);
  background:linear-gradient(135deg,#0e1526 0%, #0b1220 100%);
  border:1px solid rgba(255,255,255,.08); border-radius:14px; box-shadow:0 18px 50px rgba(0,0,0,.45);
  color:#e5e7eb; overflow:hidden; z-index:86;
}
.msgw-head{ display:flex; align-items:center; gap:8px; padding:10px 12px; border-bottom:1px solid #122036; }
.msgw-title{ font-weight:900; color:#9fb7ff; letter-spacing:.3px; }
.msgw-x{ margin-left:auto; background:transparent; border:0; color:#9ca3af; font-size:20px; cursor:pointer; }
.msgw-body{ max-height:60vh; overflow:auto; }

.msgw-item{ padding:12px; border-bottom:1px solid #122036; display:grid; grid-template-columns:1fr auto; gap:8px; }
.msgw-item:last-child{ border-bottom:0; }
.msgw-meta{ color:#9ca3af; font-size:12px; }
.msgw-text{ white-space:pre-wrap; line-height:1.35; }
.msgw-actions{ display:flex; gap:8px; align-items:center; }
.msgw-pill{
  display:inline-flex; align-items:center; gap:6px;
  padding:4px 10px; border-radius:9999px; font-size:11px; font-weight:800; line-height:1;
  border:1px solid #334465; background:#0f172a; color:#cbd5e1;
}
.msgw-pill.new{ border-color:rgba(253,224,71,.45); color:#fde68a; background:rgba(161,98,7,.18); }

.msgw-btn-sm{
  height:30px; padding:0 12px; border-radius:9999px; font-weight:800; cursor:pointer;
  border:1px solid #374151; background:#111827; color:#e5e7eb;
}
.msgw-btn-sm:hover{ filter:brightness(1.06); }
.msgw-btn-primary{ border-color:#3b82f6; background:#2563eb; color:#fff; }
.msgw-empty{ padding:16px; color:#9ca3af; font-size:14px; }
</style>

<div id="msgw-user" class="msgw">
  <button id="msgwBtn" class="msgw-btn" title="Messaggi" aria-label="Messaggi">
    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <path d="M3 7.5A2.5 2.5 0 0 1 5.5 5h13A2.5 2.5 0 0 1 21 7.5v9A2.5 2.5 0 0 1 18.5 19h-13A2.5 2.5 0 0 1 3 16.5v-9Z" stroke="currentColor" stroke-width="1.5"/>
      <path d="M4 7l7.4 5.2a2 2 0 0 0 2.2 0L21 7" stroke="currentColor" stroke-width="1.5"/>
    </svg>
    <span id="msgwDot" class="msgw-dot" hidden></span>
  </button>

  <div id="msgwPanel" class="msgw-panel" hidden>
    <div class="msgw-head">
      <div class="msgw-title">Messaggi</div>
      <button class="msgw-x" id="msgwClose" aria-label="Chiudi">&times;</button>
    </div>
    <div class="msgw-body" id="msgwList">
      <div class="msgw-empty">Caricamento…</div>
    </div>
  </div>
</div>

<script>
(()=>{ // IIFE
  const $  = (s,p=document)=>p.querySelector(s);
  const $$ = (s,p=document)=>[...p.querySelectorAll(s)];
  const API  = '/api/messages.php';
  const CSRF = '<?= $CSRF ?>';

  const root  = $('#msgw-user');
  const btn   = $('#msgwBtn', root);
  const dot   = $('#msgwDot', root);
  const panel = $('#msgwPanel', root);
  const list  = $('#msgwList', root);
  const close = $('#msgwClose', root);

  let opened = false;
  let pollTimer = null;

  function fmtDate(s){ try{ const d=new Date(s); return d.toLocaleString(); }catch(_){ return s; } }
  function escapeHTML(t){ return (t||'').replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m])); }

  async function countUnread(){
    try{
      const r = await fetch(API+'?action=count_unread', {cache:'no-store', credentials:'same-origin'});
      const j = await r.json();
      const n = Number(j && j.ok ? j.count : 0);
      if (n > 0) dot.hidden = false; else dot.hidden = true;
    }catch(_){}
  }

  async function loadList(){
    list.innerHTML = `<div class="msgw-empty">Caricamento…</div>`;
    try{
      const r = await fetch(API+'?action=list&limit=50', {cache:'no-store', credentials:'same-origin'});
      const j = await r.json();
      if (!j || !j.ok) throw new Error('list');

      const rows = Array.isArray(j.rows) ? j.rows : [];
      if (!rows.length){
        list.innerHTML = `<div class="msgw-empty">Non hai messaggi.</div>`;
        return;
      }

      list.innerHTML = '';
      rows.forEach(m=>{
        const isNew = String(m.is_read) === '0' || m.is_read === 0 || m.is_read === false;
        const pill  = isNew ? '<span class="msgw-pill new">Nuovo</span>' : '';
        const it = document.createElement('div');
        it.className = 'msgw-item';
        it.innerHTML = `
          <div>
            <div class="msgw-meta">${escapeHTML(m.sender_username||'Admin')} • ${fmtDate(m.created_at)} ${pill}</div>
            <div class="msgw-text">${escapeHTML(m.body||'')}</div>
          </div>
          <div class="msgw-actions">
            ${isNew ? `<button class="msgw-btn-sm msgw-btn-primary" data-act="read" data-id="${m.id}">Segna letto</button>` : ''}
            <button class="msgw-btn-sm" data-act="archive" data-id="${m.id}">Archivia</button>
          </div>
        `;
        list.appendChild(it);
      });
    }catch(e){
      console.error('[messages list]', e);
      list.innerHTML = `<div class="msgw-empty">Errore caricamento.</div>`;
    }
  }

  async function postDo(action, payload){
    const data = new URLSearchParams(payload||{});
    data.set('csrf_token', CSRF);
    const r = await fetch(API+'?action='+encodeURIComponent(action), {
      method:'POST',
      body:data,
      credentials:'same-origin',
      headers:{ 'Accept':'application/json', 'X-CSRF-Token': CSRF }
    });
    const j = await r.json().catch(()=> null);
    if (!j || j.ok !== true) throw new Error((j && (j.error||j.detail)) || 'Errore');
    return j;
  }

  function openPanel(){ if (opened) return; opened = true; panel.hidden = false; loadList(); }
  function closePanel(){ if (!opened) return; opened = false; panel.hidden = true; }

  btn.addEventListener('click', ()=> opened ? closePanel() : openPanel());
  close.addEventListener('click', closePanel);
  document.addEventListener('click', (e)=>{ if (!root.contains(e.target)) closePanel(); });

  list.addEventListener('click', async (e)=>{
    const b = e.target.closest('[data-act]'); if(!b) return;
    const id = Number(b.getAttribute('data-id')||0);
    const act= b.getAttribute('data-act')||'';
    try{
      if (act==='read')    await postDo('mark_read', {message_id:String(id)});
      if (act==='archive') await postDo('archive',   {message_id:String(id)});
      await loadList();
      await countUnread();
    }catch(err){
      alert('Errore: ' + (err && err.message ? err.message : ''));
    }
  });

  // Avvio: stato del pallino e polling periodico
  countUnread();
  pollTimer = setInterval(countUnread, 30000);
})();
</script>
