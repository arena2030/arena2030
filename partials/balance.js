// /public/partials/balance.js
(() => {
  let busy = false;

  function setBalance(val) {
    if (typeof val !== 'number') return;
    const txt = val.toFixed(2);
    document.querySelectorAll('[data-balance-amount]')
      .forEach(el => el.textContent = txt);
  }

  async function fetchBalance() {
    if (busy) return;
    busy = true;
    try {
      const r = await fetch('/api/balance.php', {
        cache: 'no-store',
        credentials: 'same-origin'
      });
      const j = await r.json().catch(()=>null);
      if (j && j.ok && typeof j.coins === 'number') {
        setBalance(j.coins);
      }
    } catch(e) {
      console.error('balance fetch error', e);
    }
    busy = false;
  }

  document.addEventListener('DOMContentLoaded', () => {
    // aggiorna subito al load
    fetchBalance();

    // aggiorna ogni 10 secondi
    setInterval(fetchBalance, 10000);

    // aggiorna quando clicchi sul tasto ↻
    document.addEventListener('refresh-balance', fetchBalance);

    // aggiorna anche quando torni sulla tab
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible') fetchBalance();
    });
  });

  // helper globale se ti serve forzare manualmente
  window.Balance = { refresh: fetchBalance };
})();
