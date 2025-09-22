<?php
// /public/partials/messages_user_widget.php
// Widget utente: icona + pannello lettura messaggi (solo lettura + azioni: segna letto / archivia)
if (session_status()===PHP_SESSION_NONE) { session_start(); }
if (!defined('APP_ROOT')) {
  define('APP_ROOT', dirname(__DIR__, 1));
}
require_once APP_ROOT . '/partials/csrf.php';
$CSRF = htmlspecialchars(csrf_token(), ENT_QUOTES);
?>
<style>
/* ==== MSGW User Widget (scoped) ==== */
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
  color:#e5e7eb; overflow:hidden; z-index:60;
}
.msgw-head{ display:flex; align-items:center; gap:8px; padding:10px 12px; border-bottom:1px solid #122036; }
.msgw-title{ font-weight:900; color:#9fb7ff; letter-spacing:.3px; }
.msgw-x{ margin-left:auto; background:transparent; border:0; color:#9ca3af; font-size:20px; cursor:pointer; }
.msgw-body{ max-height:60vh; overflow:auto; }

.msgw-item{ padding:12px; border-bottom:1px solid #122036; display:grid; grid-template-columns:1fr auto; gap:8px; }
.msgw-item:last-child{ border-bottom:0; }
.msgw-meta{ color:#9ca3af; font-size:12px; }
.msgw-text{ white-space:pre-wrap; line-height:1.35; word-break:break-word; }
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
.msgw-btn-primary{
  border-color:#3b82f6; background:#2563eb; color:#fff;
}
.msgw-empty{ padding:16px; color:#9ca3af; font-size:14px; }
</style>

<div id="msgw-user" class="msgw">
  <button id="msgwBtn" class="msgw-btn" title="Messaggi" aria-label="Messaggi" type="button">
    <!-- icona busta -->
    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <path d="M3 7.5A2.5 2.5 0 0 1 5.5 5h13A2.5 2.5 0 0 1 21 7.5v9A2.5 2.5 0 0 1 18.5 19h-13A2.5 2.5 0 0 1 3 16.5v-9Z" stroke="currentColor" stroke-width="1.5"/>
      <path d="M4 7l7.4 5.2a2 2 0 0 0 2.2 0L21 7" stroke="currentColor" stroke-width="1.5"/>
    </svg>
    <span id="msgwDot" class="msgw-dot" hidden></span>
  </button>

  <div id="msgwPanel" class="msgw-panel" role="dialog" aria-modal="true" aria-labelledby="msgwTitle" hidden>
    <div class="msgw-head">
      <div class="msgw-title" id="msgwTitle">Messaggi</div>
      <button class="msgw-x" id="msgwClose" aria-label="Chiudi" type="button">&times;</button>
    </div>
    <div class="msgw-body" id="msgwList">
      <div class="msgw-empty">Caricamento…</div>
    </div>
  </div>
</div>

<script>
(()=>{ // IIFE per isolamento
  const $ = (s,p=document)=>p.querySelector(s);
  const $$ = (s,p=document)=>[...p.querySelectorAll(s)];
  const API = '/api/messages.php';
  const CSRF = '<?= $CSRF ?>';

  const root  = $('#msgw-user');
  if (!root) return;
  const btn   = $('#msgwBtn', root);
  const dot   = $('#msgwDot', root);
  const panel = $('#msgwPanel', root);
  const list  = $('#msgwList', root);
  const close = $('#msgwClose', root);

  let opened = false;
  let pollTimer = null;

  function fmtDate(s){
    try{ const d = new Date(s); return d.toLocaleString(); }catch(_){ return s; }
  }
  function escapeHTML(t){
    return (t||'').replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m]));
  }

  async function countUnread(){
    try{
      const r = await fetch(API+'?action=count_unread', {cache:'no-store', credentials:'same-origin'});
      const j = await r.json();
      if (!j || !j.ok) return;
      const n = Number(j.count||0);
      if (n>0) dot.removeAttribute('hidden'); else dot.setAttribute('hidden','');
    }catch(_){ /* noop */ }
  }

  async function loadList(){
    list.innerHTML = `<div class="msgw-empty">Caricamento…</div>`;
    try{
      const r = await fetch(API+'?action=list&limit=50', {cache:'no-store', credentials:'same-origin'});
      const j = await r.json();
      if (!j || !j.ok) throw new Error('list');

      const rows = Array.isArray(j.rows) ? j.rows : [];
      if (rows.length===0){
        list.innerHTML = `<div class="msgw-empty">Non hai messaggi.</div>`;
        return;
      }
      list.innerHTML = '';
      rows.forEach(m=>{
        const st = m.status==='new' ? '<span class="msgw-pill new">Nuovo</span>' : '';
        const it = document.createElement('div');
        it.className = 'msgw-item';
        it.innerHTML = `
          <div>
            <div class="msgw-meta">${escapeHTML(m.sender_username||'Admin')} • ${fmtDate(m.created_at)} ${st}</div>
            <div class="msgw-text">${escapeHTML(m.message_text||'')}</div>
          </div>
          <div class="msgw-actions">
            ${m.status==='new' ? `<button class="msgw-btn-sm msgw-btn-primary" data-act="read" data-id="${m.id}" type="button">Segna letto</button>` : ''}
            <button class="msgw-btn-sm" data-act="archive" data-id="${m.id}" type="button">Archivia</button>
          </div>
        `;
        list.appendChild(it);
      });
    }catch(e){
      list.innerHTML = `<div class="msgw-empty">Errore caricamento.</div>`;
      console.error('[messages list]', e);
    }
  }

  async function doPost(action, payload){
    const data = new URLSearchParams(payload||{});
    data.set('csrf_token', CSRF);
    const r = await fetch(API+'?action='+encodeURIComponent(action), {
      method:'POST', body:data, credentials:'same-origin',
      headers:{ 'Accept':'application/json', 'X-CSRF-Token': CSRF }
    });
    let j=null, raw='';
    try{ j=await r.json(); }catch(_){ try{ raw=await r.text(); }catch(e){} }
    if (!j || j.ok!==true) throw new Error((j && (j.error||j.detail)) || raw || 'Errore');
    return j;
  }

  function openPanel(){
    if (opened) return;
    opened = true;
    panel.hidden = false;
    // metti focus dentro per evitare warning aria-hidden
    panel.querySelector('.msgw-x')?.focus({preventScroll:true});
    loadList();
  }
  function closePanel(){
    if (!opened) return;
    opened = false;
    panel.hidden = true;
    btn.focus({preventScroll:true});
  }

  // ——— events
  btn.addEventListener('click', ()=>{ opened ? closePanel() : openPanel(); });
  close.addEventListener('click', closePanel);
  document.addEventListener('click', (e)=>{
    if (!root.contains(e.target)) closePanel();
  });
  document.addEventListener('keydown', (e)=>{
    if (e.key==='Escape' && !panel.hidden) closePanel();
  });

  // azioni su lista
  list.addEventListener('click', async (e)=>{
    const b = e.target.closest('[data-act]'); if(!b) return;
    const id = Number(b.getAttribute('data-id')||0);
    const act= String(b.getAttribute('data-act')||'');
    try{
      if (act==='read'){ await doPost('mark_read', {message_id:String(id)}); }
      if (act==='archive'){ await doPost('archive', {message_id:String(id)}); }
      await loadList();
      await countUnread();
    }catch(err){
      alert('Errore: '+ (err && err.message ? err.message : ''));
    }
  });

  // avvio: badge non letti + polling
  countUnread();
  pollTimer = setInterval(countUnread, 30000);
})();
</script>
