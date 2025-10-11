/*! Arena Mobile JS — leggero e robusto.
    Fix immediati:
    - Inietta l’hamburger a destra in .hdr__bar se manca (guest e utente)
    - Mantiene i fix già fatti: tabelle→card, avatar click, marcature torneo flash
*/
(function(){
  'use strict';

  var MQL = '(max-width: 768px)';

  function onReady(fn){
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
  }
  function q(s,r){ return (r||document).querySelector(s); }
  function qa(s,r){ return Array.prototype.slice.call((r||document).querySelectorAll(s)); }
  function txt(el){ return (el && (el.textContent||'').trim()) || ''; }
  function isMobile(){ try{ return window.matchMedia ? window.matchMedia(MQL).matches : (window.innerWidth||0)<=768; }catch(_){ return (window.innerWidth||0)<=768; } }
  function debounce(fn,ms){ var t=null; return function(){ var a=arguments; clearTimeout(t); t=setTimeout(function(){ fn.apply(null,a); }, ms||80); }; }

  /* ---------- identificatori pagina ---------- */
  function ensurePageData(){
    var b=document.body; if(!b) return;
    if(!b.dataset.page){
      var pg = (location.pathname.split('/').pop() || '').replace(/\.php$/,'') || 'index';
      b.dataset.page = pg;
    }
    if(!b.dataset.path){ b.dataset.path = location.pathname.replace(/^\/+/,''); }
  }

  /* ---------- hamburger: inietta se manca, sempre a destra ---------- */
  function ensureHamburger(){
    if (!isMobile()) return;
    var bar = q('.hdr__bar'); if (!bar) return;

    // se esiste già un trigger, basta
    if (q('#mbl-trigger', bar) || q('.hdr__burger', bar) || q('.hdr__menu-btn', bar)) return;

    var btn = document.createElement('button');
    btn.id = 'mbl-trigger';
    btn.type = 'button';
    btn.setAttribute('aria-label','Apri menu');
    btn.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';

    // posiziona DOPO il gruppo azioni (guest:.hdr__nav | utente:.hdr__right); fallback: append alla fine
    var grp = q('.hdr__right', bar) || q('.hdr__nav', bar);
    if (grp) grp.insertAdjacentElement('afterend', btn);
    else bar.appendChild(btn);

    // se esiste un opener già previsto dal progetto, usalo
    var extOpen = q('[data-open="menu"], .js-open-menu, .open-menu, [data-drawer-open]');
    if (extOpen){
      btn.addEventListener('click', function(){ extOpen.click(); });
    }
    // altrimenti lascia il bottone come “segnaposto” finché non useremo/aggiorneremo il drawer
  }

  /* ---------- Tabelle → card (aggiunge data-th) ---------- */
  function labelTables(){
    if (!isMobile()) return;
    qa('table, .table').forEach(function(tb){
      var th = qa('thead th', tb).map(function(x){ return txt(x); });
      if (!th.length) return;
      qa('tbody tr', tb).forEach(function(tr){
        qa('td', tr).forEach(function(td,i){
          if (!td.hasAttribute('data-th')) td.setAttribute('data-th', th[i] || '');
        });
      });
    });
  }

  /* ---------- Avatar: click = stessa pagina desktop ---------- */
  function bindAvatarClick(){
    var usr = q('.hdr__usr'); if (!usr) return;
    if (usr._mblBound) return;
    var link = usr.querySelector('a[href]') || q('a[href*="dati-utente"]') || q('a[href*="profilo"]');
    if (link){
      usr.style.cursor='pointer';
      usr.addEventListener('click', function(){ location.href = link.href; });
      usr._mblBound = true;
    }
  }

  /* ---------- Bridge “Lista movimenti” ---------- */
  function bindMovimentiBridge(){
    qa('a').forEach(function(a){
      if(a._mblMov) return;
      var t = txt(a).toLowerCase();
      if (t.indexOf('moviment') !== -1){
        a.addEventListener('click', function(ev){
          if (!isMobile()) return;
          var trigger = q('[data-open="movimenti"], [data-modal="movimenti"], .js-open-movimenti, .open-movimenti');
          if (trigger){ ev.preventDefault(); trigger.click(); }
          else if (!/\/movimenti\.php$/.test(a.getAttribute('href')||'')){
            a.setAttribute('href','/movimenti.php'); /* fallback pagina */
          }
        });
        a._mblMov = true;
      }
    });
  }

  /* ---------- Torneo Flash: marcature CSS-only ---------- */
  function markFlashHero(){
    if (!/\/flash\/torneo\.php/i.test(location.pathname) || !isMobile()) return;

    var buy = qa('a,button').find(function(b){ return /acquista/i.test(txt(b)); });
    var hero = buy ? (buy.closest('.card, .tcard, .panel, .box, [class*="card"]') || buy.closest('section')) : null;
    if (!hero) return;

    var labs = ['Montepremi','Partecipanti','Vite','Lock'];
    var kpis = [];
    qa('*', hero).forEach(function(el){
      var t = txt(el).toLowerCase();
      if(!t) return;
      if (labs.some(function(L){ return t.indexOf(L.toLowerCase())===0; })){
        var box = el.closest('div'); if(box && kpis.indexOf(box)===-1) kpis.push(box);
      }
    });
    if (kpis.length){
      var parent = kpis[0].parentElement;
      if (parent){ parent.classList.add('mbl-kpi-grid'); kpis.forEach(function(b){ b.classList.add('mbl-kpi'); }); }
    }
    [buy].concat( qa('a,button', hero).filter(function(b){ return /disiscr/i.test(txt(b)); }) )
        .filter(Boolean).forEach(function(b){ b.classList.add('mbl-fix-static'); });
  }

  function markFlashEvents(){
    if (!/\/flash\/torneo\.php/i.test(location.pathname) || !isMobile()) return;

    var all = qa('a,button').filter(function(x){ return /^(casa|pareggio|trasferta)$/i.test(txt(x)); });
    if (!all.length) return;

    function findEventContainerFromButton(btn){
      var p = btn.parentElement;
      while(p && p!==document.body){
        var count = qa('a,button', p).filter(function(x){ return /^(casa|pareggio|trasferta)$/i.test(txt(x)); }).length;
        if (count >= 2) return p;
        p = p.parentElement;
      }
      return null;
    }

    all.forEach(function(b){
      var cont = findEventContainerFromButton(b); if(!cont) return;
      cont.classList.add('mbl-event');
      b.classList.add('mbl-bet');
      var oval = qa(':scope > *', cont).find(function(el){
        if (el === b) return false;
        if (el.tagName === 'A' || el.tagName === 'BUTTON') return false;
        if (qa('img', el).length >= 1) return true;
        if (txt(el).length >= 5) return true;
        return false;
      });
      if (oval) oval.classList.add('mbl-oval');
    });
  }

  /* ---------- init ---------- */
  function init(){
    ensurePageData();
    ensureHamburger();          // <— aggiunto
    bindAvatarClick();
    bindMovimentiBridge();
    labelTables();
    markFlashHero();
    markFlashEvents();
  }

  onReady(init);
  window.addEventListener('resize', debounce(function(){
    ensurePageData();
    ensureHamburger();          // <— anche su resize (login/logout)
    labelTables();
    markFlashHero();
    markFlashEvents();
  }, 180));

})();
