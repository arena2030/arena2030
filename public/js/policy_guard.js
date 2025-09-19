// /public/js/policy_guard.js
(function () {
  async function askPolicy(what, tid, round) {
    const url = `/api/tournament_core.php?action=policy_guard&what=${encodeURIComponent(what)}&tid=${encodeURIComponent(tid)}${round?`&round=${encodeURIComponent(round)}`:''}`;
    const res = await fetch(url, { cache: 'no-store', credentials: 'same-origin' });
    const j = await res.json().catch(() => null);
    return { ok: !!(j && j.ok), allowed: !!(j && j.allowed), msg: j && (j.popup || j.reason || 'Operazione non consentita') };
  }

  async function guardAndMaybeStop(e, what) {
    const el = e.target.closest(`[data-guard="${what}"]`);
    if (!el) return;

    const tid   = el.getAttribute('data-tid') || el.dataset.tid || '';
    const round = el.getAttribute('data-round') || el.dataset.round || '';

    if (!tid) return; // niente tournament id/code → non blocchiamo

    e.preventDefault();
    const g = await askPolicy(what, tid, round ? Number(round) : undefined);
    if (!g.allowed) { alert(g.msg || 'Operazione non consentita.'); return; }

    // Sblocco l'azione originale (click "vero").
    // Se il bottone aveva un href o submit, lo re‑innesco in modo neutro:
    const tag = el.tagName.toLowerCase();
    if (tag === 'a' && el.href) {
      window.location.href = el.href;
    } else if ((tag === 'button' || tag === 'input') && el.form) {
      // submit del form
      el.form.requestSubmit ? el.form.requestSubmit(el) : el.form.submit();
    } else {
      // dispatch un click sintetico "pulito"
      setTimeout(() => el.dispatchEvent(new Event('click', { bubbles: true })), 0);
    }
  }

  document.addEventListener('click', (e) => {
    if (e.target.closest('[data-guard="join"]'))     return guardAndMaybeStop(e, 'join');
    if (e.target.closest('[data-guard="unjoin"]'))   return guardAndMaybeStop(e, 'unjoin');
    if (e.target.closest('[data-guard="buy_life"]')) return guardAndMaybeStop(e, 'buy_life');
    if (e.target.closest('[data-guard="pick"]'))     return guardAndMaybeStop(e, 'pick');
  });

  // Espongo utility globali opzionali
  window.PolicyGuard = {
    check: (w, t, r) => askPolicy(w, t, r),
  };
})();
