/*!
 * LayoutSwitcher v1
 * Gestisce lo switch dinamico mobile ↔ desktop (header, CSS, JS)
 */
(function(){
  const MOBILE_MAX = 768; // px breakpoint
  const head = document.head || document.getElementsByTagName('head')[0];

  let currentMode = null; // 'mobile' | 'desktop'
  let mobileCSS = null;
  let mobileJS  = null;
  let desktopCSS = null;
  let desktopJS  = null;

  // helper: crea link/script
  function loadCSS(href){
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = href;
    link.dataset.dynamic = '1';
    head.appendChild(link);
    return link;
  }
  function loadJS(src){
    const s = document.createElement('script');
    s.src = src;
    s.defer = true;
    s.dataset.dynamic = '1';
    head.appendChild(s);
    return s;
  }
  function removeDynamic(type){
    document.querySelectorAll(type+'[data-dynamic="1"]').forEach(el=>el.remove());
  }

  function activate(mode){
    if (mode === currentMode) return;
    currentMode = mode;
    console.debug('[LayoutSwitcher] mode →', mode);

    // rimuovi precedenti
    removeDynamic('link');
    removeDynamic('script');

    if (mode === 'mobile'){
      mobileCSS = loadCSS('/assets/css/mobile/header-user.mobile.css');
      mobileJS  = loadJS('/assets/js/mobile/header-user.mobile.js');
      document.body.classList.add('is-mobile');
      document.body.classList.remove('is-desktop');
    } else {
      desktopCSS = loadCSS('/assets/css/style.css'); // globale desktop
      desktopJS  = null; // se hai uno script desktop, lo carichi qui
      document.body.classList.add('is-desktop');
      document.body.classList.remove('is-mobile');
    }

    // evento globale (altri script possono reagire)
    const ev = new CustomEvent('layout:change', { detail: { mode }});
    window.dispatchEvent(ev);
  }

  function check(){
    const w = window.innerWidth;
    if (w <= MOBILE_MAX) activate('mobile');
    else activate('desktop');
  }

  window.addEventListener('resize', check);
  document.addEventListener('DOMContentLoaded', check);
})();
