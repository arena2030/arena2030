/*! ArenaNotice v1 – popup vincitori/rimborso
    - Mostra un popup quando un torneo si chiude (SOLO, SPLIT, REFUND)
    - Funziona globalmente (al login/cambio pagina/focus) e on-demand (checkNow)
    - Idempotente (mai doppioni) via localStorage + best-effort server
    - Endpoint richiesto: /api/tournament_final.php?action=user_notice|notice_seen
*/
(function (global) {
  const LS_PREFIX = 'arena_notice_v1:';
  const DEFAULT_ENDPOINT = '/api/tournament_final.php';
  const TITLE_DEFAULT = 'Notifica torneo';

  let endpoint = DEFAULT_ENDPOINT;
  let inFlight = false;

  function lsSeenKey(key) { return `${LS_PREFIX}${key}`; }
  function markSeenLocal(key) { try { localStorage.setItem(lsSeenKey(key), '1'); } catch(_) {} }
  function isSeenLocal(key) { try { return localStorage.getItem(lsSeenKey(key)) === '1'; } catch(_) { return false; } }

  // Popup: usa showAlert(title, html) se presente; altrimenti fallback leggero
  function showPopup(message, title) {
    if (typeof global.showAlert === 'function') {
      global.showAlert(title || TITLE_DEFAULT, message || '');
      return;
    }
    let wrap = document.getElementById('__an_modal');
    if (!wrap) {
      wrap = document.createElement('div');
      wrap.id = '__an_modal';
      wrap.innerHTML = `
        <div id="__an_backdrop" style="position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9998"></div>
        <div id="__an_card" style="position:fixed;left:50%;bottom:16px;transform:translateX(-50%);
             max-width:560px;width:calc(100vw - 32px);background:#0b1220;border:1px solid #22314e;border-radius:14px;
             box-shadow:0 18px 50px rgba(0,0,0,.55);z-index:9999;color:#fff;font:14px/1.45 system-ui,Segoe UI,Roboto,Arial">
          <div style="padding:10px 12px;border-bottom:1px solid #22314e;font-weight:800">${title || TITLE_DEFAULT}</div>
          <div id="__an_body" style="padding:14px 12px;"></div>
          <div style="padding:10px 12px;border-top:1px solid #22314e;display:flex;justify-content:flex-end">
            <button id="__an_ok" style="height:36px;padding:0 14px;border-radius:8px;border:1px solid #375796;background:#13233f;color:#fff;cursor:pointer">Ok</button>
          </div>
        </div>`;
      document.body.appendChild(wrap);
      wrap.querySelector('#__an_backdrop').addEventListener('click', () => wrap.remove());
      wrap.querySelector('#__an_ok').addEventListener('click', () => wrap.remove());
    }
    wrap.querySelector('#__an_body').innerHTML = message || '';
  }

  async function markSeenServer(key) {
    try {
      const u = new URL(endpoint, location.origin);
      u.searchParams.set('action', 'notice_seen');
      if (key) u.searchParams.set('key', key);
      await fetch(u.toString(), { credentials: 'include' });
    } catch (_) {}
  }

  async function fetchAndShow(params) {
    if (inFlight) return;
    inFlight = true;
    try {
      const u = new URL(endpoint, location.origin);
      u.searchParams.set('action', 'user_notice');
      // opzionali: limita a torneo specifico (id/tid/code)
      if (params && params.id)   u.searchParams.set('id', String(params.id));
      if (params && params.tid)  u.searchParams.set('tid', String(params.tid).toUpperCase());
      if (params && params.code) u.searchParams.set('code', String(params.code).toUpperCase());

      const r = await fetch(u.toString(), { credentials: 'include', headers: { 'Accept': 'application/json' } });
      let j = null; try { j = await r.json(); } catch(_) {}
      if (!j || !j.ok || !j.show || !j.message || !j.notice_key) return;

      if (isSeenLocal(j.notice_key)) return; // mai doppioni sul client

      showPopup(j.message, TITLE_DEFAULT);
      markSeenLocal(j.notice_key);
      markSeenServer(j.notice_key);
    } catch (_) {
      // ignora: non deve mai rompere le pagine
    } finally {
      inFlight = false;
    }
  }

  function onVisibility() { if (document.visibilityState === 'visible') fetchAndShow(); }

  const ArenaNotice = {
    init(cfg = {}) { endpoint = (cfg && cfg.endpoint) ? cfg.endpoint : DEFAULT_ENDPOINT; return this; },
    attachGlobalHooks() {
      document.addEventListener('visibilitychange', onVisibility);
      global.addEventListener('focus', fetchAndShow, { passive: true });
      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => fetchAndShow());
      } else {
        setTimeout(fetchAndShow, 0);
      }
      return this;
    },
    checkNow(params = {}) { return fetchAndShow(params); }, // per uso immediato nelle pagine torneo
    _resetLocalSeen(){ try { Object.keys(localStorage).forEach(k => { if (k.startsWith(LS_PREFIX)) localStorage.removeItem(k); }); } catch(_) {} }
  };

  global.ArenaNotice = ArenaNotice;
})(window);
