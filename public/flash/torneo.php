<?php
require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['uid'])) { header('Location: /login.php'); exit; }

function tourByCode(PDO $pdo, string $code){
  $st=$pdo->prepare("SELECT * FROM tournament_flash WHERE code=? LIMIT 1");
  $st->execute([$code]); return $st->fetch(PDO::FETCH_ASSOC);
}

$code = trim($_GET['code'] ?? '');
$tour = $code ? tourByCode($pdo,$code) : null;
if (!$tour) { echo "<main class='section'><div class='container'><h1>Torneo Flash non trovato</h1></div></main>"; include __DIR__ . '/../../partials/footer.php'; exit; }

$page_css='/pages-css/flash.css';
include __DIR__ . '/../../partials/head.php';
include __DIR__ . '/../../partials/header.php'; // header pubblico
?>
<main>
<section class="section">
  <div class="container">
    <h1><?= htmlspecialchars($tour['name']) ?> <span class="muted">(<?= htmlspecialchars($tour['code']) ?>)</span></h1>
    <p class="muted">Seleziona le tue scelte **una volta sola** per tutti e 3 i round: una Casa, una X, una Trasferta (tutte diverse).</p>

    <div class="card">
      <div id="livesWrap" class="muted">Caricamento vite…</div>
      <form id="fPicks" class="mt-6" style="display:none">
        <div id="rounds"></div>
        <div class="mt-6">
          <button type="submit" class="btn btn--primary btn--sm">Invia scelte</button>
        </div>
      </form>
      <pre id="dbg" class="debug" style="display:none"></pre>
    </div>
  </div>
</section>
</main>
<?php include __DIR__ . '/../../partials/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', ()=>{
  const $=s=>document.querySelector(s);
  const code="<?= htmlspecialchars($tour['code']) ?>";

  async function jsonFetch(url,opts){
    const r=await fetch(url,opts||{});
    const raw=await r.text(); try{ return JSON.parse(raw); }catch(e){ console.error('[RAW]',raw); return {ok:false,error:'bad_json',raw}; }
  }

  async function loadLives(){
    const j=await jsonFetch(`/api/flash_tournament.php?action=my_lives&tid=${encodeURIComponent(code)}&debug=1`,{cache:'no-store'});
    const w=$('#livesWrap');
    if(!j.ok){ w.textContent='Errore caricamento vite'; return; }
    if(!j.lives || j.lives.length===0){
      w.textContent='Nessuna vita trovata. Per partecipare acquista il buy-in.';
      return;
    }
    w.innerHTML='';
    const lives=j.lives;

    const roundsWrap=$('#rounds');
    roundsWrap.innerHTML='';

    lives.forEach(l=>{
      const block=document.createElement('div');
      block.className='subcard';
      block.innerHTML=`<h3>Vita #${l.life_no} (stato: ${l.status})</h3>`;
      for(let r=1;r<=3;r++){
        const row=document.createElement('div');
        row.className='pick-row';
        row.innerHTML=`
          <div class="field">
            <label class="label">Round ${r}</label>
            <div class="choices" data-life="${l.id}" data-round="${r}">
              <label class="radio"><input type="radio" name="pick_${l.id}_${r}" value="HOME"><span>Casa</span></label>
              <label class="radio"><input type="radio" name="pick_${l.id}_${r}" value="DRAW"><span>Pareggio</span></label>
              <label class="radio"><input type="radio" name="pick_${l.id}_${r}" value="AWAY"><span>Trasferta</span></label>
            </div>
          </div>
        `;
        block.appendChild(row);
      }
      roundsWrap.appendChild(block);
    });

    $('#fPicks').style.display='block';
  }

  // validazione: per ogni vita, set {HOME,DRAW,AWAY} usato esattamente una volta
  function validate(){
    const errors=[];
    document.querySelectorAll('.choices').forEach(ch=>{
      const lid=Number(ch.getAttribute('data-life')); const rnd=Number(ch.getAttribute('data-round'));
    });
    // check per vita
    const livesBlocks=[...document.querySelectorAll('[data-life]')].reduce((acc,el)=>{
      const lid=Number(el.getAttribute('data-life')); (acc[lid]=acc[lid]||[]).push(el); return acc;
    }, {});
    for(const lid in livesBlocks){
      const rows=livesBlocks[lid];
      const vals=[];
      for(const row of rows){
        const r=Number(row.getAttribute('data-round'));
        const v=row.querySelector('input[type="radio"]:checked')?.value;
        if(!v){ errors.push(`Vita ${lid}: selezione mancante per round ${r}`); }
        vals.push(v||'?');
      }
      const s=vals.slice().sort().join(',');
      if (s!=='AWAY,DRAW,HOME') errors.push(`Vita ${lid}: devi usare una Casa, una X e una Trasferta (tutte diverse)`);
    }
    return errors;
  }

  $('#fPicks').addEventListener('submit', async e=>{
    e.preventDefault();
    const errs=validate();
    if (errs.length){ alert(errs.join('\n')); return; }

    // costruisci payload
    const payload=[];
    // serve mappa event_id per round => la recuperiamo inline
    const evs=[];
    for(let r=1;r<=3;r++){
      const jr=await jsonFetch(`/api/flash_tournament.php?action=list_events&tid=${encodeURIComponent(code)}&round_no=${r}`);
      if(!jr.ok||!jr.rows||jr.rows.length!==1){ alert('Eventi incompleti.'); return; }
      evs[r]=jr.rows[0];
    }

    document.querySelectorAll('.choices').forEach(ch=>{
      const lid=Number(ch.getAttribute('data-life'));
      const r=Number(ch.getAttribute('data-round'));
      const v=ch.querySelector('input[type="radio"]:checked')?.value;
      payload.push({life_id:lid, round_no:r, event_id:evs[r].id, choice:v});
    });

    const fd=new FormData(); fd.set('payload', JSON.stringify(payload));
    const j=await jsonFetch(`/api/flash_tournament.php?action=submit_picks&tid=${encodeURIComponent(code)}&debug=1`,{method:'POST',body:fd});
    if(!j.ok){ alert('Errore invio: '+(j.error||'')+'\n'+(j.detail||'')); const d=$('#dbg'); d.style.display='block'; d.textContent=JSON.stringify(j,null,2); return; }
    alert('Scelte salvate.');
  });

  loadLives();
});
</script>
