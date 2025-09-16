<?php
require_once __DIR__ . '/../../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['uid']) || !(($_SESSION['role'] ?? 'USER')==='ADMIN' || (int)($_SESSION['is_admin'] ?? 0)===1)) {
  header('Location: /login.php'); exit;
}
function json($a){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($a); exit; }
function only_post(){ if ($_SERVER['REQUEST_METHOD']!=='POST'){ http_response_code(405); json(['ok'=>false,'error'=>'method']); } }

$uid = (int)($_GET['uid'] ?? 0);

/* === AJAX === */
if (isset($_GET['action'])) {
  $a = $_GET['action'];

  if ($a==='detail') {
    $st=$pdo->prepare("SELECT u.id AS user_id, u.username, u.email, u.cell AS phone, u.is_active, u.coins, u.presenter_code,
                              p.point_code, p.denominazione, p.partita_iva, p.pec, p.indirizzo_legale,
                              p.admin_nome, p.admin_cognome, p.admin_cf, p.rake_pct, p.created_at
                       FROM users u
                       JOIN points p ON p.user_id=u.id
                       WHERE u.id=? AND u.role='PUNTO' LIMIT 1");
    $st->execute([$uid]); $row=$st->fetch(PDO::FETCH_ASSOC);
    if (!$row) json(['ok'=>false,'error'=>'not_found']);

    // ultimi movimenti (ultimi 50)
    $mov=$pdo->prepare("SELECT delta, reason, admin_id, created_at FROM points_balance_log WHERE user_id=? ORDER BY id DESC LIMIT 50");
    $mov->execute([$uid]); $movs=$mov->fetchAll(PDO::FETCH_ASSOC);

    json(['ok'=>true,'point'=>$row,'movements'=>$movs]);
  }

  if ($a==='toggle_active') {
    only_post();
    $st=$pdo->prepare("UPDATE users SET is_active=CASE WHEN is_active=1 THEN 0 ELSE 1 END WHERE id=? AND role='PUNTO'");
    $st->execute([$uid]);
    $is=(int)$pdo->query("SELECT is_active FROM users WHERE id={$uid}")->fetchColumn();
    json(['ok'=>true,'is_active'=>$is]);
  }

  if ($a==='reset_password') {
    only_post();
    $pw = $_POST['password'] ?? '';
    if (strlen($pw)<8) json(['ok'=>false,'error'=>'weak_password']);
    $hash = password_hash($pw,PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE users SET password_hash=? WHERE id=? AND role='PUNTO'")->execute([$hash,$uid]);
    json(['ok'=>true]);
  }

  http_response_code(400); json(['ok'=>false,'error'=>'unknown_action']);
}

/* === View === */
$page_css='/pages-css/admin-dashboard.css';
include __DIR__ . '/../../partials/head.php';
include __DIR__ . '/../../partials/header_admin.php';
?>
<main class="section">
  <section>
    <div class="container">
      <h1>Anagrafica punto</h1>

      <div class="card">
        <h2 class="card-title">Dati punto</h2>
        <div id="ptInfo" class="grid2"></div>

        <div style="display:flex; gap:8px; margin-top:12px;">
          <button class="btn btn--outline" id="btnToggle">Attiva/Disabilita</button>
        </div>

        <hr style="border-color:var(--c-border); margin:16px 0">
        <h3>Reset password</h3>
        <div class="grid2">
          <div class="field"><label class="label">Nuova password</label><input class="input light" id="rpw" type="password" placeholder="Nuova password (min 8)"></div>
          <div class="field" style="display:flex; align-items:end;"><button class="btn btn--primary" id="btnResetPw">Imposta</button></div>
        </div>
      </div>

      <div class="card">
        <h2 class="card-title">Movimenti saldo (ultimi 50)</h2>
        <div class="table-wrap">
          <table class="table" id="tblMov">
            <thead><tr><th>Data</th><th>Delta</th><th>Motivo</th><th>Admin</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>

    </div>
  </section>
</main>
<?php include __DIR__ . '/../../partials/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', async ()=>{
  const $ = s=>document.querySelector(s);
  const uid = "<?= (int)$_GET['uid'] ?>";

  async function load(){
    const r = await fetch(`?action=detail&uid=${encodeURIComponent(uid)}`,{cache:'no-store'});
    const j = await r.json(); if(!j.ok){ alert('Punto non trovato'); return; }

    const p = j.point;
    $('#ptInfo').innerHTML = `
      <div><div class="muted">Username</div><div><strong>${p.username}</strong></div></div>
      <div><div class="muted">Email</div><div>${p.email}</div></div>
      <div><div class="muted">Telefono</div><div>${p.phone||'-'}</div></div>
      <div><div class="muted">Stato</div><div>${p.is_active==1?'<span class="chip on">Attivo</span>':'<span class="chip off">Disabilitato</span>'}</div></div>
      <div><div class="muted">Denominazione</div><div>${p.denominazione}</div></div>
      <div><div class="muted">P.IVA</div><div>${p.partita_iva}</div></div>
      <div><div class="muted">PEC</div><div>${p.pec}</div></div>
      <div><div class="muted">Sede legale</div><div>${p.indirizzo_legale}</div></div>
      <div><div class="muted">Amministratore</div><div>${p.admin_nome} ${p.admin_cognome} — CF: ${p.admin_cf}</div></div>
      <div><div class="muted">% Rake</div><div>${Number(p.rake_pct||0).toFixed(2)}%</div></div>
      <div><div class="muted">Point code</div><div>${p.point_code}</div></div>
      <div><div class="muted">Presenter code</div><div>${p.presenter_code}</div></div>
    `;

    const tb = document.querySelector('#tblMov tbody'); tb.innerHTML='';
    (j.movements||[]).forEach(m=>{
      const tr=document.createElement('tr');
      tr.innerHTML = `
        <td>${new Date(m.created_at.replace(' ','T')).toLocaleString()}</td>
        <td>${(m.delta>=0?'+':'')+Number(m.delta).toFixed(2)}</td>
        <td>${m.reason}</td>
        <td>${m.admin_id}</td>
      `;
      tb.appendChild(tr);
    });
  }

  // toggle stato
  document.getElementById('btnToggle').addEventListener('click', async ()=>{
    const r=await fetch(`?action=toggle_active&uid=${encodeURIComponent(uid)}`,{method:'POST'});
    const j=await r.json(); if(!j.ok){ alert('Errore toggle'); return; }
    await load();
  });

  // reset password
  document.getElementById('btnResetPw').addEventListener('click', async ()=>{
    const pw = document.getElementById('rpw').value;
    if (!pw || pw.length<8){ alert('Password troppo corta'); return; }
    const r=await fetch(`?action=reset_password&uid=${encodeURIComponent(uid)}`,{method:'POST', body:new URLSearchParams({password:pw})});
    const j=await r.json(); if(!j.ok){ alert('Errore reset'); return; }
    alert('Password aggiornata');
    document.getElementById('rpw').value='';
  });

  await load();
});
</script>

<style>
  .chip{ padding:4px 10px; border-radius:9999px; border:1px solid var(--c-border); }
  .chip.on{ border-color:#27ae60; color:#a7e3bf; }
  .chip.off{ border-color:#ff8a8a; color:#ff8a8a; }
</style>
