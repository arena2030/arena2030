/*! Arena Mobile JS — leggero e robusto.
    - Aggiunge data-page/data-path sul <body> se mancanti
    - Etichetta KPI e Eventi in Torneo Flash (SOLO classi, nessuno spostamento DOM)
    - Tabelle → imposta data-th sulle celle per vista “card”
    - Avatar header cliccabile verso pagina profilo (stesso link del desktop)
    - Bridge “Lista movimenti”: prova modal, fallback pagina /movimenti.php
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

  /* ---------- 0) Identificatore pagina ---------- */
  function ensurePageData(){
    var b=document.body; if(!b) return;
    if(!b.dataset.page){
      var pg = (location.pathname.split('/').pop() || '').replace(/\.php$/,'') || 'index';
      b.dataset.page = pg;
    }
    if(!b.dataset.path){
      b.dataset.path = location.pathname.replace(/^\/+/,'');  // es: "flash/torneo.php"
    }
  }

  /* ---------- 1) Tabelle → card (aggiunge data-th) ---------- */
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

  /* ---------- 2) Header avatar: click = stessa pagina desktop ---------- */
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

  /* ---------- 3) Bridge “Lista movimenti” nel menu (se c’è) ---------- */
  function bindMovimentiBridge(){
    // cerchiamo link con testo “movimenti”
    qa('a').forEach(function(a){
      if(a._mblMov) return;
      var t = txt(a).toLowerCase();
      if (t.indexOf('moviment') !== -1){
        a.addEventListener('click', function(ev){
          // Non blocco la navigazione se non siamo in mobile
          if (!isMobile()) return;
          // provo ad aprire modal desktop se esiste
          var trigger = q('[data-open="movimenti"], [data-modal="movimenti"], .js-open-movimenti, .open-movimenti');
          if (trigger){
            ev.preventDefault(); trigger.click();
          } else {
            // fallback pagina
            if (!/\/movimenti\.php$/.test(a.getAttribute('href')||'')){
              a.setAttribute('href','/movimenti.php');
            }
          }
        });
        a._mblMov = true;
      }
    });
  }

  /* ---------- 4) Torneo Flash: solo “marcatura” per il CSS ---------- */
  function markFlashHero(){
    if (!/\/flash\/torneo\.php/i.test(location.pathname)) return;
    if (!isMobile()) return;

    // trova la card "hero": il bottone “Acquista” è l’ancora più stabile
    var buy = qa('a,button').find(function(b){ return /acquista/i.test(txt(b)); });
    var hero = buy ? (buy.closest('.card, .tcard, .panel, .box, [class*="card"]') || buy.closest('section')) : null;
    if (!hero) return;

    // cerca 4 KPI tipici leggendo le label
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

    // i bottoni in hero vengono solo “neutralizzati” da float/absolute via CSS (.mbl-fix-static)
    [buy].concat( qa('a,button', hero).filter(function(b){ return /disiscr/i.test(txt(b)); }) )
        .filter(Boolean).forEach(function(b){ b.classList.add('mbl-fix-static'); });

    // saldo pill/clock ecc. rimangono — non spostiamo nodi
  }

  function markFlashEvents(){
    if (!/\/flash\/torneo\.php/i.test(location.pathname)) return;
    if (!isMobile()) return;

    // Trova i bottoni Casa/Pareggio/Trasferta, marca i loro container come .mbl-event
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
      b.classList.add('mbl-bet');       // i tre pulsanti
      // prova a individuare “l’ovale” (prima parte non-bottone)
      var oval = qa(':scope > *', cont).find(function(el){
        if (el === b) return false;
        if (el.tagName === 'A' || el.tagName === 'BUTTON') return false;
        if (qa('img', el).length >= 1) return true;          // spesso ci sono i loghi
        if (txt(el).length >= 5) return true;
        return false;
      });
      if (oval) oval.classList.add('mbl-oval');
    });
  }

  /* ---------- init ---------- */
  function init(){
    ensurePageData();
    bindAvatarClick();
    bindMovimentiBridge();
    labelTables();
    markFlashHero();
    markFlashEvents();
  }

  onReady(init);
  window.addEventListener('resize', debounce(function(){
    ensurePageData();               // dataset aggiornati
    labelTables();                  // rietichetta se necessario
    markFlashHero();
    markFlashEvents();
  }, 200));

})();
