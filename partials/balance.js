// /public/partials/balance.js
(() => {
  let busy = false;

  function setBalance(val) {
    const num = Number(val);
    if (Number.isFinite(num)) {
      const txt = num.toFixed(2);
      document.querySelectorAll('[data-balance-amount]').forEach(el => { el.textContent = txt; });
    }
  }

  async function fetchBalance() {
    if (busy) return;
    busy = true;
    try {
      // no-cache + cookie di sessione
      const r = await fetch('/api/balance.php?t=' + Date.now(), {
        cache: 'no-store',
        credentials: 'same-origin'
      });

      const txt = await r.text(); // intercetta HTML/redirect
      let j;
      try { j = JSON.parse(txt); } catch (e) {
        console.warn('[balance] risposta non JSON:', txt.slice(0, 200));
        busy = false; return;
      }

      if (!j || !j.ok) {
        console.warn('[balance] errore API:', j);
        busy = false; return;
      }

      setBalance(j.coins);
    } catch (e) {
      console.warn('[balance] fetch error:', e);
    }
    busy = false;
  }

  // aggiorna subito, poi ogni 10s, poi al click ↻ e quando il tab torna visibile
  document.addEventListener('DOMContentLoaded', () => {
    fetchBalance();                      // subito
    setInterval(fetchBalance, 10000);    // polling
    document.addEventListener('refresh-balance', fetchBalance);
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible') fetchBalance();
    });
  });

  // opzionale da console: window.Balance.refresh()
  window.Balance = { refresh: fetchBalance };
})();
