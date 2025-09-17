// /public/partials/balance.js
// Aggiorna la pillola del saldo leggendo dal DB: subito, ogni 10s, al click ↻ e quando la tab torna visibile.
(() => {
  if (window.__BAL_PILL_INIT__) return; // evita doppie inizializzazioni
  window.__BAL_PILL_INIT__ = true;

  let busy = false;
  const INTERVAL_MS = 10000; // cambia qui se vuoi un polling diverso

  function setBalance(val){
    const num = Number(val);
    if (!Number.isFinite(num)) return;
    const txt = num.toFixed(2);
    document.querySelectorAll('[data-balance-amount]').forEach(el => el.textContent = txt);
  }

  async function fetchBalance(){
    if (busy) return;
    busy = true;
    try {
      const r = await fetch('/api/balance.php?t=' + Date.now(), {
        cache: 'no-store',
        credentials: 'same-origin'
      });
      const txt = await r.text();
      let j;
      try { j = JSON.parse(txt); }
      catch (e) { console.warn('[balance] non JSON:', txt.slice(0,200)); busy=false; return; }

      if (!j || !j.ok) { console.warn('[balance] errore API:', j); busy=false; return; }
      setBalance(j.coins);
    } catch (e) {
      console.warn('[balance] fetch error:', e);
    }
    busy = false;
  }

  // avvio
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', fetchBalance);
  } else {
    fetchBalance();
  }

  // polling
  window.__BAL_PILL_TIMER__ && clearInterval(window.__BAL_PILL_TIMER__);
  window.__BAL_PILL_TIMER__ = setInterval(fetchBalance, INTERVAL_MS);

  // refresh manuale (↻) e al ritorno in tab
  document.addEventListener('refresh-balance', fetchBalance);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') fetchBalance();
  });

  // helper opzionale
  window.Balance = { refresh: fetchBalance };
})();
