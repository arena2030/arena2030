// /public/partials/balance.js
(() => {
  const STATE = { timer: null, busy: false, last: null };

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
      if (j && j.ok) {
        setBalance(Number(j.coins || 0));
        STATE.last = Date.now();
      }
    } catch (_) { /* ignora */ }
    STATE.busy = false;
  }

  function startPolling() {
    if (STATE.timer) clearInterval(STATE.timer);
    STATE.timer = setInterval(fetchBalance, 10000); // ogni 10s
  }

  // Aggiorna subito all’avvio
  document.addEventListener('DOMContentLoaded', () => {
    fetchBalance();
    startPolling();
  });

  // Aggiorna quando la tab torna visibile
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') fetchBalance();
  });

  // Aggiorna su eventi applicativi
  window.addEventListener('balance:dirty', fetchBalance);      // evento nostro
  document.addEventListener('refresh-balance', fetchBalance);  // tuo evento esistente (↻)

  // Esponi un helper globale per comodità (opzionale)
  window.Balance = { refresh: fetchBalance };
})();
