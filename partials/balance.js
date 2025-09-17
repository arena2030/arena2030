// /public/partials/balance.js
(() => {
  const STATE = { timer: null, busy: false };

  function setBalance(val) {
    const txt = (typeof val === 'number') ? val.toFixed(2) : String(val || '0.00');
    document.querySelectorAll('[data-balance-amount]').forEach(el => { el.textContent = txt; });
  }

  async function fetchBalance() {
    if (STATE.busy) return;
    STATE.busy = true;
    try {
      const r = await fetch('/api/balance.php', { cache:'no-store', credentials:'same-origin' });
      const j = await r.json().catch(()=>({ok:false}));
      if (j && j.ok) setBalance(Number(j.coins || 0));
    } catch (_) { /* ignora */ }
    STATE.busy = false;
  }

  function startPolling() {
    if (STATE.timer) clearInterval(STATE.timer);
    STATE.timer = setInterval(fetchBalance, 10000); // ogni 10s
  }

  // === Wrapper per fetch ===
  const origFetch = window.fetch;
  window.fetch = async function(url, opts) {
    const res = await origFetch(url, opts);

    // Normalizza URL per confronti
    const u = (typeof url === 'string') ? url : (url.url || '');
    const watched = [
      '/api/prize_request.php',
      '/api/media_save.php',
      '/api/upload_r2.php',
      '/api/join_tournament.php',
      '/api/ricarica.php',
      '/api/balance_adjust.php'
      // aggiungi qui altri endpoint che modificano il saldo
    ];

    if (watched.some(x => u.includes(x))) {
      // aspetta che la risposta sia ok prima di refresh
      try {
        const clone = res.clone();
        const j = await clone.json().catch(()=>null);
        if (j && j.ok) {
          fetchBalance();
        }
      } catch (_) {}
    }
    return res;
  };

  // Avvio
  document.addEventListener('DOMContentLoaded', () => {
    fetchBalance();    // aggiorna subito
    startPolling();    // e poi ogni 10s
  });

  // Quando torni visibile, aggiorna
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') fetchBalance();
  });

  // Esponi helper globale
  window.Balance = { refresh: fetchBalance };
})();
