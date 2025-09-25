// /public/js/policy_guard.js
(function () {
  async function askPolicy(what, tid, round_no) {
    const body = new URLSearchParams({ action:'policy_guard', what, tid, round_no });
    if (window.__CSRF) body.set('csrf_token', window.__CSRF);
    const res = await fetch('/api/tournament_core.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        'Accept': 'application/json',
        'X-CSRF-Token': window.__CSRF || ''
      },
      credentials: 'same-origin',
      body: body.toString()
    });
    const j = await res.json().catch(() => null);
    return { ok: !!(j && j.ok), allowed: !!(j && j.allowed), msg: j && (j.popup || j.reason || 'Operazione non consentita') };
  }

  async function guardAndMaybeStop(e, what) {
    const el = e.target.closest(`[data-guard="${what}"]`);
    if (!el) return;

    const tid   = el.getAttribute('data-tid') || el.dataset.tid || '';
    const round = el.getAttribute('data-round') || el.dataset.round || '';

    if (!tid) return;

    e.preventDefault();
    const g = await askPolicy(what, tid, round ? Number(round) : undefined);
    if (!g.allowed) { alert(g.msg || 'Operazione non consentita.'); return; }

    const tag = el.tagName.toLowerCase();
    if (tag === 'a' && el.href) {
      window.location.href = el.href;
    } else if ((tag === 'button' || tag === 'input') && el.form) {
      el.form.requestSubmit ? el.form.requestSubmit(el) : el.form.submit();
    } else {
      setTimeout(() => el.dispatchEvent(new Event('click', { bubbles: true })), 0);
    }
  }

  document.addEventListener('click', (e) => {
    if (e.target.closest('[data-guard="join"]'))     return guardAndMaybeStop(e, 'join');
    if (e.target.closest('[data-guard="unjoin"]'))   return guardAndMaybeStop(e, 'unjoin');
    if (e.target.closest('[data-guard="buy_life"]')) return guardAndMaybeStop(e, 'buy_life');
    if (e.target.closest('[data-guard="pick"]'))     return guardAndMaybeStop(e, 'pick');
  });

  window.PolicyGuard = { check: (w, t, r) => askPolicy(w, t, r) };
})();
