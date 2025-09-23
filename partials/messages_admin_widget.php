<?php
// /public/partials/messages_admin_widget.php
if (session_status()===PHP_SESSION_NONE) { session_start(); }
if (!defined('APP_ROOT')) { define('APP_ROOT', dirname(__DIR__, 1)); }
require_once APP_ROOT . '/partials/csrf.php';
$CSRF = htmlspecialchars(csrf_token(), ENT_QUOTES);

$role    = $_SESSION['role'] ?? 'USER';
$isAdmin = ($role === 'ADMIN') || ((int)($_SESSION['is_admin'] ?? 0) === 1);
if (!$isAdmin) { return; }
?>
<style>
/* ==== Admin Message Composer (scoped) ==== */
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
.msgw-res-item:hover{ background:rgba(255,255,255,.06); }

.msgw-selected{ display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.msgw-chip{
  display:inline-flex; align-items:center; gap:8px;
  padding:6px 10px; border-radius:9999px; border:1px solid #334465; background:#0f172a; color:#cbd5e1; font-size:12px; font-weight:800;
}
.msgw-chip .x{ background:transparent; border:0; color:#9ca3af; cursor:pointer; font-size:14px; }

.msgw-foot{ display:flex; justify-content:flex-end; gap:8px; padding:12px 14px; border-top:1px solid #122036; }
.msgw-btn{ height:34px; padding:0 14px; border-radius:9999px; font-weight:800; cursor:pointer; }
.msgw-btn-outline{ border:1px solid #374151; background:#111827; color:#e5e7eb; }
.msgw-btn-primary{ border:1px solid #3b82f6; background:#2563eb; color:#fff; }
.msgw-btn:hover{ filter:brightness(1.06); }
</style>

<!-- SOLO MODALE (nessun pulsante flottante) -->
<div class="msgw-modal" id="msgwModal" aria-hidden="true">
  <div class="msgw-backdrop" data-close></div>
  <div class="msgw-card">
    <div class="msgw-head">
      <div class="msgw-title">Invia messaggio a utente/punto</div>
      <button class="msgw-x" data-close aria-label="Chiudi">&times;</button>
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

<script>
(()=>{ // IIFE
  const $  = (s,p=document)=>p.querySelector(s);
  const $$ = (s,p=document)=>[...p.querySelectorAll(s)];
  const API  = '/api/messages.php';
  const CSRF = '<?= $CSRF ?>';

  const modal    = $('#msgwModal');
  const results  = $('#msgwResults');
  const search   = $('#msgwSearch');
  const picked   = $('#msgwSelected');
  const textEl   = $('#msgwText');
  const sendBtn  = $('#msgwSend');

  let selectedUser = null;
  let debounce = null;
  let kbIndex = -1;

  function escapeHTML(t){ return (t||'').replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m])); }
  function chipHTML(u){
    const line = [u.username, u.user_code, u.email].filter(Boolean).join(' • ');
    const role = u.role ? ` <span class="muted" style="margin-left:6px;">(${u.role})</span>` : '';
    return `<span class="msgw-chip" data-id="${u.id}">
      ${escapeHTML(line)}${role}
      <button class="x" aria-label="Rimuovi">&times;</button>
    </span>`;
  }
  function enableSend(ok){ sendBtn.disabled = !ok; }

  function openM(){
    modal.setAttribute('aria-hidden', 'false');
    // reset
    selectedUser = null;
    picked.innerHTML = '';
    search.value = '';
    textEl.value = '';
    results.hidden = true; results.innerHTML = '';
    kbIndex = -1;
    enableSend(false);
    // focus e suggerimenti iniziali (top N)
    setTimeout(()=>search.focus(), 20);
    fetchResults('');
  }
  function closeM(){ modal.setAttribute('aria-hidden', 'true'); }

  function renderResults(rows){
    if (!rows || rows.length === 0) {
      results.innerHTML = `<div class="msgw-res-item">Nessun risultato</div>`;
      results.hidden = false;
      kbIndex = -1;
      return;
    }
    results.innerHTML = rows.map(u=>{
      const line = [u.username||'', u.user_code||'', u.email||''].filter(Boolean).join(' • ');
      const role = u.role ? ` <span class="muted" style="margin-left:6px;">(${u.role})</span>` : '';
      return `<div class="msgw-res-item"
                 data-id="${u.id}"
                 data-username="${escapeHTML(u.username||'')}"
                 data-code="${escapeHTML(u.user_code||'')}"
                 data-email="${escapeHTML(u.email||'')}"
                 data-role="${escapeHTML(u.role||'')}">
                ${escapeHTML(line)}${role}
              </div>`;
    }).join('');
    results.hidden = false;
    kbIndex = 0;
    highlightKB();
  }

  function highlightKB(){
    $$('.msgw-res-item', results).forEach((el,i)=>{
      el.style.background = (i===kbIndex) ? 'rgba(255,255,255,.06)' : '';
    });
  }
  function moveKB(dir){
    const items = $$('.msgw-res-item', results);
    if (!items.length) return;
    kbIndex = (kbIndex + dir + items.length) % items.length;
    highlightKB();
    const it = items[kbIndex];
    const r = it.getBoundingClientRect();
    const R = results.getBoundingClientRect();
    if (r.top < R.top) it.scrollIntoView({block:'nearest'});
    if (r.bottom > R.bottom) it.scrollIntoView({block:'nearest'});
  }
  function selectItem(it){
    const id = Number(it.getAttribute('data-id')||0);
    selectedUser = {
      id,
      username: it.getAttribute('data-username')||'',
      user_code: it.getAttribute('data-code')||'',
      email: it.getAttribute('data-email')||'',
      role: it.getAttribute('data-role')||''
    };
    picked.innerHTML = chipHTML(selectedUser);
    results.hidden = true; results.innerHTML = '';
    search.value = '';
    enableSend(!!id);
    textEl.focus();
  }
  function clearSelection(){
    selectedUser = null;
    picked.innerHTML = '';
    enableSend(false);
    search.focus();
  }

  async function fetchResults(q){
    try{
      const url = new URL(API, location.origin);
      url.searchParams.set('action','search_users');
      if (q) url.searchParams.set('q', q);
      const r = await fetch(url.toString(), {cache:'no-store', credentials:'same-origin'});
      const j = await r.json();
      if (!j || !j.ok) { results.hidden = true; return; }
      const rows = Array.isArray(j.rows) ? j.rows.slice(0, 50) : [];
      renderResults(rows);
    }catch(e){
      console.warn('[search_users]', e);
      results.hidden = true;
    }
  }

  // Interazioni modale
  modal.addEventListener('click', (e)=>{
    if (e.target.hasAttribute('data-close') || e.target.closest('[data-close]')) { closeM(); return; }
    const it = e.target.closest('.msgw-res-item'); if (it) return selectItem(it);
    const rm = e.target.closest('.msgw-chip .x');  if (rm) return clearSelection();
  });
  document.addEventListener('keydown', (e)=>{
    if (e.key === 'Escape' && modal.getAttribute('aria-hidden') === 'false') closeM();
  });

  // Ricerca + tastiera
  search.addEventListener('focus', ()=>{
    if (results.hidden && !search.value.trim()) fetchResults('');
  });
  search.addEventListener('input', ()=>{
    clearTimeout(debounce);
    const q = search.value.trim();
    debounce = setTimeout(()=> fetchResults(q), 220);
  });
  search.addEventListener('keydown', (e)=>{
    if (results.hidden) return;
    const items = $$('.msgw-res-item', results);
    if (!items.length) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); moveKB(1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); moveKB(-1); }
    else if (e.key === 'Enter') {
      e.preventDefault();
      if (kbIndex >= 0 && kbIndex < items.length) selectItem(items[kbIndex]);
    }
  });

  // Invia
  $('#msgwSend').addEventListener('click', async ()=>{
    if (!selectedUser) { alert('Seleziona un destinatario dall’elenco.'); search.focus(); return; }
    const msg = (textEl.value||'').trim();
    if (!msg) { alert('Inserisci un messaggio.'); textEl.focus(); return; }

    try{
      const data = new URLSearchParams({
        recipient_user_id: String(selectedUser.id),
        message_text: msg,
        csrf_token: CSRF
      });
      const r = await fetch(API + '?action=send', {
        method:'POST',
        body: data,
        credentials:'same-origin',
        headers:{ 'Accept':'application/json', 'X-CSRF-Token': CSRF }
      });
      const j = await r.json().catch(()=> null);
      if (!j || j.ok !== true) {
        const err = (j && (j.error || j.detail)) || 'Errore';
        alert('Invio fallito: ' + err);
        return;
      }
      closeM();
      alert('Messaggio inviato!');
    } catch(e){
      console.error('[send message]', e);
      alert('Errore invio.');
    }
  });

  // API globale per aprire la modale dal link “Messaggi” nell’header admin
  window.msgwOpenComposer = openM;
})();
</script>
