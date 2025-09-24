<?php
require_once __DIR__ . '/../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['uid'])) { header('Location: /login.php'); exit; }
$code = trim($_GET['code'] ?? '');
if ($code===''){ echo '<main class="section"><div class="container"><h1>Codice mancante</h1></div></main>'; exit; }
$page_css='/pages-css/flash.css';
include __DIR__.'/../partials/head.php';
include __DIR__.'/../partials/header.php';
?>
<main class="section">
  <div class="container card">
    <h1>Torneo Flash <small class="muted" id="tcode"></small></h1>

    <div id="livesWrap" class="mt-4"></div>
    <div id="roundsWrap" class="mt-4"></div>

    <button id="btnSave" class="btn btn--primary btn--sm mt-4">Salva tutte le scelte</button>
    <pre id="debug" class="flash-debug mt-4 hidden"></pre>
  </div>
</main>
<script>
const code = <?= json_encode($code) ?>;
document.getElementById('tcode').textContent = '('+code+')';
const dbg = document.getElementById('debug');

function showErr(where, raw, json){
  dbg.classList.remove('hidden');
  dbg.textContent = `[${where}] ERRORE:\n` + (json ? JSON.stringify(json,null,2) : raw);
}

async function jfetch(url, opts){
  const resp = await fetch(url, opts || {});
  const raw = await resp.text();
  try { return JSON.parse(raw); }
  catch(e){ showErr('jfetch', raw, null); return {ok:false,error:'bad_json',raw}; }
}

async function load(){
  // torneo + struttura
  const t = await jfetch('/api/flash_tournament.php?action=list_events&tid='+encodeURIComponent(code)+'&round_no=1&debug=1'); // test first round for existence
  if (!t.ok){ showErr('tournament_structure','',t); return; }

  // lives dell'utente
  const l = await jfetch('/api/flash_tournament.php?action=my_lives&tid='+encodeURIComponent(code)+'&debug=1');
  if (!l.ok){ showErr('my_lives','',l); return; }
  const lives = l.lives || [];
  const livesWrap = document.getElementById('livesWrap');
  livesWrap.innerHTML = '<h3>Le tue vite</h3>' + lives.map(v=>`<span class="chip ${v.status==='alive'?'ok':'ko'}">Vita ${v.life_no} — ${v.status}</span>`).join(' ');

  // round list (carichiamo round per round, minimal)
  const roundsWrap = document.getElementById('roundsWrap');
  roundsWrap.innerHTML = '';
  // per stimare tot round → leggiamo intestazione
  const info = await fetch('/api/flash_tournament.php?action=publish&tid='+encodeURIComponent(code), {method:'HEAD'}).catch(()=>null);
  // fallback: chiediamo 1..10; in reale potremmo avere un endpoint info. Qui carichiamo 1..8 safe:
  const MAXR=8;
  for (let r=1;r<=MAXR;r++){
    const ev = await jfetch('/api/flash_tournament.php?action=list_events&tid='+encodeURIComponent(code)+'&round_no='+r+'&debug=1');
    if (!ev.ok || !ev.rows || ev.rows.length===0) break;
    const block = document.createElement('div');
    block.className='flash-round';
    block.innerHTML = `<h3>Round ${r}</h3>`;
    for (const e of ev.rows){
      const row = document.createElement('div');
      row.className='flash-ev';
      row.innerHTML = `
        <div class="muted">${e.event_code}</div>
        <label><input type="radio" name="r${r}" value="${e.id}|${e.home_team_id}"> ${e.home_name}</label>
        <label><input type="radio" name="r${r}" value="${e.id}|${e.away_team_id}"> ${e.away_name}</label>
      `;
      block.appendChild(row);
    }
    roundsWrap.appendChild(block);
  }
}

document.getElementById('btnSave').addEventListener('click', async ()=>{
  // costruisci payload per ogni vita alive
  const lives = await jfetch('/api/flash_tournament.php?action=my_lives&tid='+encodeURIComponent(code)+'&debug=1');
  if (!lives.ok){ showErr('save.my_lives','',lives); return; }

  const radios = [...document.querySelectorAll('input[type=radio]:checked')];
  if (radios.length===0){ alert('Seleziona almeno una squadra.'); return; }

  const payload=[];
  for (const r of radios){
    const [eventId, teamId] = r.value.split('|').map(Number);
    const roundNo = Number(r.name.replace('r',''));
    for (const life of lives.lives){
      if (life.status!=='alive') continue;
      payload.push({life_id: life.id, round_no: roundNo, event_id: eventId, team_id: teamId});
    }
  }

  const fd = new URLSearchParams(); fd.set('payload', JSON.stringify(payload)); fd.set('debug','1');
  const j = await jfetch('/api/flash_tournament.php?action=submit_picks&tid='+encodeURIComponent(code), {method:'POST', body:fd});
  if (!j.ok){ showErr('submit_picks','',j); return; }
  alert('Scelte salvate: '+j.saved);
});

load();
</script>
<?php include __DIR__.'/../partials/footer.php'; ?>
