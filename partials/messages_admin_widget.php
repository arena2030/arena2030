<?php
// /public/partials/messages_admin_widget.php
if (session_status()===PHP_SESSION_NONE) { session_start(); }
if (!defined('APP_ROOT')) { define('APP_ROOT', dirname(__DIR__, 1)); }
require_once APP_ROOT . '/partials/csrf.php';
$CSRF = htmlspecialchars(csrf_token(), ENT_QUOTES);

// Solo admin
$role    = $_SESSION['role'] ?? 'USER';
$isAdmin = ($role === 'ADMIN') || ((int)($_SESSION['is_admin'] ?? 0) === 1);
if (!$isAdmin) { return; }
?>
<style>
/* ==== MSGW Admin Composer ==== */
.msgw-admin { display:inline-block; }
.msgw-admin .msgw-send-btn{
  height:34px; padding:0 14px; border-radius:9999px; font-weight:800; cursor:pointer;
  border:1px solid #3b82f6; background:#2563eb; color:#fff;
}
.msgw-admin .msgw-send-btn:hover{ filter:brightness(1.05); }

.msgw-modal{ position:fixed; inset:0; z-index:80; display:none; }
.msgw-modal[aria-hidden="false"]{ display:block; }
.msgw-backdrop{ position:absolute; inset:0; background:rgba(0,0,0,.55); }
.msgw-card{
  position:relative; z-index:81; width:min(720px, 94vw); margin:7vh auto 0;
  background:linear-gradient(135deg,#0e1526 0%, #0b1220 100%);
  border:1px solid rgba(255,255,255,.08); border-radius:16px; box-shadow:0 18px 50px rgba(0,0,0,.45);
  color:#e5e7eb; max-height:84vh; display:flex; flex-direction:column; overflow:hidden;
}
.msgw-head{ display:flex; align-items:center; gap:8px; padding:12px 14px; border-bottom:1px solid #122036; }
.msgw-title{ font-weight:900; color:#9fb7ff; letter-spacing:.3px; }
.msgw-x{ margin-left:auto; background:transparent; border:0; color:#9ca3af; font-size:22px; cursor:pointer; }

.msgw-body{ padding:12px 14px; display:grid; gap:14px; }
.msgw-field label{ display:block; font-size:12px; color:#9fb7ff; margin-bottom:6px; font-weight:800; letter-spacing:.3px; }
.msgw-input, .msgw-textarea{
  width:100%; background:#0f172a; border:1px solid #1e293b; color:#fff; border-radius:10px; padding:10px 12px;
}
.msgw-textarea{ min-height:120px; resize:vertical; }

.msgw-search-wrap{ position:relative; }
.msgw-results{
  position:absolute; left:0; right:0; top:100%; margin-top:6px; max-height:240px; overflow:auto;
  background:#0b1220; border:1px solid #223152; border-radius:12px; z-index:82;
}
.msgw-res-item{ padding:10px 12px; border-bottom:1px solid #122036; cursor:pointer; }
.msgw-res-item:last-child{ border-bottom:0; }
.msgw-res-item:hover{ background:rgba(255,255,255,.04); }

.msgw-selected{ display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.msgw-chip{
  display:inline-flex; align-items:center; gap:8px;
  padding:6px 10px; border-radius:9999px; border:1px solid #334465;
  background:#0f172a; color:#cbd5e1; font-size:12px; font-weight:800;
}
.msgw-chip .x{ background:transparent; border:0; color:#9ca3af; cursor:pointer; font-size:14px; }

.msgw-foot{ display:flex; justify-content:flex-end; gap:8px; padding:12px 14px; border-top:1px solid #122036; }
.msgw-btn{ height:34px; padding:0 14px; border-radius:9999px; font-weight:800; cursor:pointer; }
.msgw-btn-outline{ border:1px solid #374151; background:#111827; color:#e5e7eb; }
.msgw-btn-primary{ border:1px solid #3b82f6; background:#2563eb; color:#fff; }
.msgw-btn:hover{ filter:brightness(1.06); }
</style>

<div class="msgw-admin" id="msgw-admin">
  <button class="msgw-send-btn" id="msgwOpen">Messaggio</button>

  <div class="msgw-modal" id="msgwModal" aria-hidden="true">
    <div class="msgw-backdrop" data-close></div>
    <div class="msgw-card">
      <div class="msgw-head">
        <div class="msgw-title">Invia messaggio utente</div>
        <button class="msgw-x" data-close>&times;</button>
      </div>
      <div class="msgw-body">
        <div class="msgw-field">
          <label>Destinatario</label>
          <div class="msgw-selected" id="msgwSelected"></div>
          <div class="msgw-search-wrap">
            <input type="search" class="msgw-input" id="msgwSearch" placeholder="Cerca per username, email, user_code, cell…">
            <div class="msgw-results" id="msgwResults" hidden></div>
          </div>
        </div>
        <div class="msgw-field">
          <label>Messaggio</label>
          <textarea class="msgw-textarea" id="msgwText" maxlength="2000" placeholder="Scrivi il testo…"></textarea>
        </div>
      </div>
      <div class="msgw-foot">
        <button class="msgw-btn msgw-btn-outline" data-close>Annulla</button>
        <button class="msgw-btn msgw-btn-primary" id="msgwSend" disabled>Invia</button>
      </div>
    </div>
  </div>
</div>

<script>
(()=>{ 
  const $ = (s,p=document)=>p.querySelector(s);
  const $$= (s,p=document)=>[...p.querySelectorAll(s)];
  const API  = '/api/messages.php';
  const CSRF = '<?= $CSRF ?>';

  const root   = $('#msgw-admin'); if (!root) return;
  const openBtn= $('#msgwOpen', root);
  const modal  = $('#msgwModal', root);
  const results= $('#msgwResults', root);
  const search = $('#msgwSearch', root);
  const picked = $('#msgwSelected', root);
  const textEl = $('#msgwText', root);
  const sendBtn= $('#msgwSend', root);

  let selectedUser = null;
  let debounce=null; let kbIndex=-1;

  function escapeHTML(t){ return (t||'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }

  function chipHTML(u){
    const info=[u.username,u.user_code,u.email].filter(Boolean).join(' • ');
    const role=u.role?` <span class="muted">(${u.role})</span>`:'';
    return `<span class="msgw-chip" data-id="${u.id}">
      ${escapeHTML(info)}${role}
      <button class="x">&times;</button>
    </span>`;
  }

  function selectItem(it){
    selectedUser={
      id:Number(it.dataset.id||0),
      username:it.dataset.username||'',
      user_code:it.dataset.code||'',
      email:it.dataset.email||'',
      role:it.dataset.role||''
    };
    picked.innerHTML=chipHTML(selectedUser);
    results.hidden=true; results.innerHTML='';
    search.value=''; sendBtn.disabled=false; textEl.focus();
  }
  function clearSelection(){ selectedUser=null; picked.innerHTML=''; sendBtn.disabled=true; search.focus(); }

  function renderResults(rows){
    if(!rows.length){ results.innerHTML='<div class="msgw-res-item">Nessun risultato</div>'; results.hidden=false; return;}
    results.innerHTML=rows.map(u=>{
      const line=[u.username,u.user_code,u.email].filter(Boolean).join(' • ');
      return `<div class="msgw-res-item" 
        data-id="${u.id}" data-username="${escapeHTML(u.username||'')}"
        data-code="${escapeHTML(u.user_code||'')}" data-email="${escapeHTML(u.email||'')}" data-role="${escapeHTML(u.role||'')}">
        ${escapeHTML(line)} ${u.role?`<span class="muted">(${u.role})</span>`:''}
      </div>`;
    }).join('');
    results.hidden=false; kbIndex=0; highlightKB();
  }
  function highlightKB(){ $$('.msgw-res-item',results).forEach((el,i)=>el.style.background=(i===kbIndex)?'rgba(255,255,255,.06)':''); }

  async function fetchResults(q){
    const url=new URL(API,location.origin); url.searchParams.set('action','search_users'); if(q) url.searchParams.set('q',q);
    const r=await fetch(url,{cache:'no-store',credentials:'same-origin'}); const j=await r.json().catch(()=>({ok:false}));
    if(!j.ok){results.hidden=true;return;} renderResults(j.rows||[]);
  }

  // events
  openBtn.onclick=()=>{ modal.setAttribute('aria-hidden','false'); clearSelection(); textEl.value=''; fetchResults(''); setTimeout(()=>search.focus(),20); };
  modal.addEventListener('click',e=>{
    if(e.target.closest('[data-close]')) modal.setAttribute('aria-hidden','true');
    if(e.target.closest('.msgw-res-item')) selectItem(e.target.closest('.msgw-res-item'));
    if(e.target.closest('.msgw-chip .x')) clearSelection();
  });
  search.oninput=()=>{ clearTimeout(debounce); debounce=setTimeout(()=>fetchResults(search.value.trim()),250); };
  search.onkeydown=(e)=>{ const items=$$('.msgw-res-item',results); if(!items.length) return;
    if(e.key==='ArrowDown'){e.preventDefault();kbIndex=(kbIndex+1)%items.length;highlightKB();}
    if(e.key==='ArrowUp'){e.preventDefault();kbIndex=(kbIndex-1+items.length)%items.length;highlightKB();}
    if(e.key==='Enter'){e.preventDefault(); if(kbIndex>=0) selectItem(items[kbIndex]);}
  };
  sendBtn.onclick=async()=>{
    if(!selectedUser){alert('Seleziona un destinatario');return;}
    const msg=textEl.value.trim(); if(!msg){alert('Inserisci un messaggio');return;}
    const data=new URLSearchParams({recipient_user_id:String(selectedUser.id),message_text:msg,csrf_token:CSRF});
    const r=await fetch(API+'?action=send',{method:'POST',body:data,credentials:'same-origin',headers:{'Accept':'application/json','X-CSRF-Token':CSRF}});
    const j=await r.json().catch(()=>null); if(!j||!j.ok){alert('Errore invio: '+(j?.error||j?.detail||'unknown'));return;}
    modal.setAttribute('aria-hidden','true'); alert('Messaggio inviato!');
  };

  // API globale per aprire dal link in header
  window.msgwOpenComposer=()=>openBtn.click();
})();
</script>
