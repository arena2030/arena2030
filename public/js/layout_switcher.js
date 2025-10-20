/*!
 * LayoutSwitcher v2 – toggla classi sul body, niente load/unload di CSS/JS
 * Emette 'layout:change' con { mode: 'mobile' | 'desktop' }
 */
(function () {
  const MOBILE_MAX = 768; // px
  let mode = null;

  function apply(next) {
    if (next === mode) return;
    mode = next;
    document.body.classList.toggle('is-mobile',  next === 'mobile');
    document.body.classList.toggle('is-desktop', next === 'desktop');
    window.dispatchEvent(new CustomEvent('layout:change', { detail: { mode: next }}));
  }

  function detect() {
    apply(window.innerWidth <= MOBILE_MAX ? 'mobile' : 'desktop');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', detect, { once: true });
  } else {
    detect();
  }
  window.addEventListener('resize', detect);
  window.addEventListener('orientationchange', detect);
})();
