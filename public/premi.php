<?php
// collegati alla connessione DB e avvio sessione
require_once __DIR__ . '/../partials/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/partials/csrf.php';
$CSRF = htmlspecialchars(csrf_token(), ENT_QUOTES);

if (empty($_SESSION['uid']) || (($_SESSION['role'] ?? 'USER')!=='USER' && ($_SESSION['role'] ?? '')!=='PUNTO')) {
  header('Location: /login.php'); exit;
}

function json($a){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($a); exit; }
function only_get(){ if (($_SERVER['REQUEST_METHOD'] ?? '')!=='GET'){ http_response_code(405); json(['ok'=>false,'error'=>'method']); } }

if (isset($_GET['action'])){
  $a = $_GET['action'];

  if ($a==='me'){ only_get();
    $uid=(int)$_SESSION['uid'];
    $st=$pdo->prepare("SELECT username, COALESCE(coins,0) coins FROM users WHERE id=?");
    $st->execute([$uid]); $me=$st->fetch(PDO::FETCH_ASSOC) ?: ['username'=>'','coins'=>0];
    json(['ok'=>true,'me'=>$me]);
  }

  if ($a==='list_prizes'){ 
    only_get();

    $search = trim($_GET['search'] ?? '');
    $sort   = $_GET['sort'] ?? 'created';
    $dir    = strtolower($_GET['dir'] ?? 'desc')==='asc' ? 'ASC' : 'DESC';

    // ordinamento: created | name | coins
    $order  = $sort==='name'
                ? "p.name $dir"
                : ($sort==='coins'
                    ? "p.amount_coins $dir"
                    : "p.created_at $dir");

    // Mostra TUTTI i premi (abilitati e disabilitati). Filtro opzionale solo per ricerca.
    $par   = [];
    $where = '';
    if ($search !== '') {
      $where = 'WHERE p.name LIKE ?';
      $par[] = "%$search%";
    }

    $sql = "SELECT
              p.id,
              p.prize_code,
              p.name,
              p.description,
              p.amount_coins,
              p.is_enabled,
              p.created_at,
              m.storage_key AS image_key
            FROM prizes p
            LEFT JOIN media m ON m.id = p.image_media_id
            $where
            ORDER BY $order";

    $st = $pdo->prepare($sql);
    $st->execute($par);
    json(['ok'=>true,'rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
  }

  http_response_code(400); json(['ok'=>false,'error'=>'unknown_action']);
}

$page_css='/pages-css/admin-dashboard.css';
include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/header_utente.php';
// CDN per immagini
$CDN_BASE = rtrim(getenv('CDN_BASE') ?: getenv('S3_CDN_BASE') ?: '', '/');
?>
<style>
/* ===== Layout header pagina (coerente con Storico) ===== */
.section{ padding-top:24px; }
.hwrap{ max_width:1100px; margin:0 auto; }
.hwrap h1{ color:#fff; font-size:26px; font-weight:900; letter-spacing:.2px; margin:0 0 12px 0; }

/* ===== Card “dark premium” (come Storico tornei) ===== */
.card{
  position:relative; border-radius:20px; padding:18px 18px 16px;
  background:
    radial-gradient(1000px 300px at 50% -120px, rgba(99,102,241,.10), transparent 60%),
    linear-gradient(135deg,#0e1526 0%, #0b1220 100%);
  border:1px solid rgba(255,255,255,.08);
  color:#fff;
  box-shadow: 0 20px 60px rgba(0,0,0,.35);
  transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease, background .15s ease;
  overflow:hidden;
}
.card::before{
  content:""; position:absolute; left:0; top:0; bottom:0; width:4px;
  background:linear-gradient(180deg,#1e3a8a 0%, #0ea5e9 100%); opacity:.35;
}
.card:hover{ transform: translateY(-2px); box-shadow: 0 26px 80px rgba(0,0,0,.48); border-color:#21324b; }

/* topbar della card */
.topbar{
  display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:12px;
}
.topbar-left{ display:flex; gap:12px; align-items:center; }

/* saldo pill gialla */
.saldo{
  display:inline-flex; align-items:center; gap:8px;
  padding:6px 10px; border-radius:12px;
  border:1px solid rgba(253,224,71,.35);
  background:rgba(253,224,71,.08);
  color:#fde047; font-weight:900; letter-spacing:.3px;
}
.saldo .ac{ opacity:.9; font-weight:800; }

/* search */
.searchbox{
  height:36px; padding:0 12px; min-width:260px;
  border-radius:10px; background:#0f172a; border:1px solid #1f2937; color:#fff;
}

/* ===== Tabella dark dentro la card ===== */
.table-wrap{ overflow:auto; border-radius:12px; }
.table{ width:100%; border-collapse:separate; border-spacing:0; }
.table thead th{
  text-align:left; font-weight:900; font-size:12px; letter-spacing:.3px;
  color:#9fb7ff; padding:10px 12px; background:#0f172a; border-bottom:1px solid #1e293b;
}
.table thead th.sortable{ cursor:pointer; user-select:none; }
.table thead th .arrow{ opacity:.5; font-size:10px; }
.table tbody td{
  padding:12px; border-bottom:1px solid #122036; color:#e5e7eb; font-size:14px;
  background:linear-gradient(0deg, rgba(255,255,255,.02), rgba(255,255,255,.02));
}
.table tbody tr:hover td{ background:rgba(255,255,255,.025); }
.table tbody tr:last-child td{ border-bottom:0; }

/* thumb immagine — media */
.img-thumb{
  width:56px; height:56px; object-fit:cover;
  border-radius:10px; border:1px solid #223152; background:#0d1326; display:block;
}

/* pill di stato */
.pill{
  display:inline-flex; align-items:center; gap:6px;
  padding:4px 10px; border-radius:9999px; font-size:12px; font-weight:800; line-height:1;
  border:1px solid #334465; background:#0f172a; color:#cbd5e1;
}
.pill.ok{ border-color:rgba(52,211,153,.45); color:#d1fae5; background:rgba(6,78,59,.25); }
.pill.off{ border-color:rgba(239,68,68,.45); color:#fecaca; background:rgba(68,16,16,.25); }

.muted{ color:#9ca3af; font-size:12px; }
.muted-sm{ color:#9ca3af; font-size:12px; }

/* modal */
.modal[aria-hidden="true"]{ display:none; } .modal{ position:fixed; inset:0; z-index:60; }
.modal-open{ overflow:hidden; }
.modal-backdrop{ position:absolute; inset:0; background:rgba(0,0,0,.5); }
.modal-card{ position:relative; z-index:61; width:min(760px,96vw); background:var(--c-bg); border:1px solid var(--c-border); border-radius:16px; margin:6vh auto 0; padding:0; box-shadow:0 16px 48px rgba(0,0,0,.5); max-height:86vh; display:flex; flex-direction:column; }
.modal-head{ display:flex; align-items:center; gap:10px; padding:12px 16px; border-bottom:1px solid var(--c-border); }
.modal-x{ margin-left:auto; background:transparent; border:0; color:#fff; font-size:24px; cursor:pointer; }
.modal-body{ padding:16px; overflow:auto; }
.modal-foot{ display:flex; justify-content:flex-end; gap:8px; padding:12px 16px; border-top:1px solid var(--c-border); }

/* griglie form */
.grid2{ display:grid; grid-template-columns:1fr 1fr; gap:16px; } 
@media (max-width:860px){ .grid2{ grid-template-columns:1fr; } }

/* input */
.field .label{ display:block; margin-bottom:6px; font-weight:800; font-size:12px; color:#9fb7ff; }
.input.light, .select.light{
  width:100%; height:38px; padding:0 12px; border-radius:10px;
  background:#0f172a; border:1px solid #1f2937; color:#fff;
}

/* bottoni tavola */
.pr-page .table button.btn.btn--primary.btn--sm{
  height:34px; padding:0 14px; border-radius:9999px; font-weight:800;
  border:1px solid #3b82f6; background:#2563eb; color:#fff;
}
.pr-page .table button.btn.btn--primary.btn--sm:hover{ filter:brightness(1.05); }
.pr-page .table button.btn--disabled{
  height:34px; padding:0 14px; border-radius:9999px; font-weight:800;
  border:1px solid #374151; background:#1f2937; color:#9ca3af; cursor:not-allowed;
}

/* step wizard interno al modal */
.step[aria-hidden="true"]{ display:none !IMPORTANT; }
.step{ display:block; }
.step:not(.active){ display:none; }

/* separatore sottile */
.hr{ height:1px; background:#142036; margin:10px 0; }

/* badge riepilogo */
.badge{ display:inline-block; padding:2px 8px; border:1px solid #24324d; border-radius:9999px; font-size:12px; color:#cbd5e1; }

/* checkbox rotondo elegante */
.check{
  display:inline-flex; align-items:center; gap:8px; cursor:pointer; user-select:none;
}
.check input[type="checkbox"]{
  appearance:none; width:18px; height:18px; border-radius:50%;
  border:2px solid #334155; background:#0f172a; outline:none; position:relative;
  transition:.15s border-color ease;
}
.check input[type="checkbox"]:checked{
  border-color:#fde047; background:#fde047;
  box-shadow:0 0 10px rgba(253,224,71,.35);
}
  .hidden{ display:none !important; }

  /* === PREMI — Card layout mobile (table → cards) ==================== */
@media (max-width: 768px){

  /* Nascondo intestazioni su mobile */
  #tblPrizes thead{ display:none; }

  /* Corpo come griglia di card */
  #tblPrizes{
    display:block;
    border-collapse: separate; /* evito eredità indesiderate */
  }
  #tblPrizes tbody{
    display:grid;
    gap:12px;
    margin-top:6px;
  }

  /* Ogni riga → card */
  #tblPrizes tbody tr{
    display:grid;
    grid-template-columns: 1fr; /* una colonna */
    grid-template-areas:
      "pic"
      "name"
      "desc"
      "meta"
      "cta";
    gap:10px;
    padding:12px;
    border-radius:16px;
    background:
      radial-gradient(1000px 300px at 50% -120px, rgba(99,102,241,.10), transparent 60%),
      linear-gradient(135deg,#0e1526 0%, #0b1220 100%);
    border:1px solid rgba(255,255,255,.08);
    box-shadow: 0 16px 50px rgba(0,0,0,.35);
  }

  /* Colonne della riga (ordine fisso: 1 Codice | 2 Foto | 3 Nome | 4 Desc | 5 Stato | 6 Coins | 7 Azione) */
  #tblPrizes tbody tr td{ display:block; padding:0; border:0; background:transparent; color:#e5e7eb; }

  /* Foto */
  #tblPrizes tbody tr td:nth-child(2){
    grid-area: pic;
    display:flex; align-items:center; justify-content:center;
  }
  #tblPrizes tbody tr td:nth-child(2) img{
    width:72px; height:72px; border-radius:12px; object-fit:cover;
    border:1px solid #223152; background:#0d1326; display:block;
  }

  /* Nome (forte) + Codice come sottotitolo */
  #tblPrizes tbody tr td:nth-child(3){
    grid-area: name;
    font-weight:900; font-size:16px; line-height:1.15;
  }
  #tblPrizes tbody tr td:nth-child(1)::before{
    content: "Codice "; font-weight:800; color:#9fb7ff; margin-right:6px;
  }
  #tblPrizes tbody tr td:nth-child(1){
    margin-top:2px; opacity:.9; font-size:12px;
  }

  /* Descrizione */
  #tblPrizes tbody tr td:nth-child(4){
    grid-area: desc;
    font-size:13px; opacity:.9;
  }

  /* Meta (Stato + Coins) come pillole su una riga */
  #tblPrizes tbody tr td:nth-child(5),
  #tblPrizes tbody tr td:nth-child(6){
    display:inline-flex; align-items:center; gap:8px;
    padding:0; margin:0;
  }
  #tblPrizes tbody tr td:nth-child(5)::before{    /* Stato: */
    content:"Stato "; font-weight:800; color:#9fb7ff; margin-right:6px;
  }
  #tblPrizes tbody tr td:nth-child(6)::before{    /* Arena Coins: */
    content:"AC "; font-weight:800; color:#9fb7ff; margin-right:6px;
  }
  #tblPrizes tbody tr td:nth-child(5) span,
  #tblPrizes tbody tr td:nth-child(6) span,
  #tblPrizes tbody tr td:nth-child(6){
    display:inline-flex; align-items:center; justify-content:center;
    height:28px; padding:0 10px; border-radius:9999px;
    background:#172554; border:1px solid #1e3a8a; font-weight:800; font-size:12px;
  }
  /* Riga meta (stato + coins) impilata correttamente */
  #tblPrizes tbody tr{
    /* creo una riga unica per meta raggruppando le cellette 5 e 6 in un contenitore fittizio */
  }
  /* uso un wrapper virtuale: display:contents su un container “grid area” */
  #tblPrizes tbody tr td:nth-child(5),
  #tblPrizes tbody tr td:nth-child(6){
    grid-area: meta;
  }
  #tblPrizes tbody tr td:nth-child(5){ margin-right:8px; }

  /* CTA (Richiedi) a tutta larghezza in basso */
  #tblPrizes tbody tr td:nth-child(7){
    grid-area: cta;
  }
  #tblPrizes tbody tr td:nth-child(7) .btn,
  #tblPrizes tbody tr td:nth-child(7) button,
  #tblPrizes tbody tr td:nth-child(7) a{
    width:100%; height:40px; border-radius:9999px; font-weight:900;
    display:inline-flex; align-items:center; justify-content:center;
  }

  /* Tabella/container host, bordo invisibile dentro card */
  #tblPrizes tbody tr td:not(:last-child){ margin-bottom:2px; }
}
/* === PREMI — Card mobile come bozza (codice sx, stato dx, foto, titolo, descr, AC, CTA) === */
@media (max-width:768px){

  /* Nascondo header tabella */
  #tblPrizes thead{ display:none; }

  /* Host come lista di card */
  #tblPrizes{ display:block; border-collapse:separate; }
  #tblPrizes tbody{ display:grid; gap:14px; }

  /* Ogni riga -> card */
  #tblPrizes tbody tr{
    display:grid;
    grid-template-columns: 1fr 1fr;                   /* top: code | status */
    grid-template-areas:
      "code   status"
      "pic    pic"
      "name   name"
      "desc   desc"
      "price  price"
      "cta    cta";
    gap:10px;
    padding:14px;
    border-radius:16px;
    background:
      radial-gradient(1000px 300px at 50% -120px, rgba(99,102,241,.10), transparent 60%),
      linear-gradient(135deg,#0e1526 0%, #0b1220 100%);
    border:1px solid rgba(255,255,255,.08);
    box-shadow: 0 16px 50px rgba(0,0,0,.35);
  }
  #tblPrizes tbody tr td{ display:block; padding:0; border:0; background:transparent; color:#e5e7eb; }

  /* --- CODE (col 1) pill sx ------------------------------------------------ */
  #tblPrizes tbody tr td:nth-child(1){
    grid-area: code;
    display:inline-flex; align-items:center; gap:8px;
    white-space:nowrap; font-weight:800; font-size:12px;
  }
  #tblPrizes tbody tr td:nth-child(1)::before{
    content:"Codice"; font-weight:900; color:#9fb7ff; margin-right:6px;
  }
  #tblPrizes tbody tr td:nth-child(1){
    height:28px; padding:0 10px; border-radius:9999px;
    background:#172554; border:1px solid #1e3a8a;
    align-items:center;
    width:max-content;
  }

  /* --- STATUS (col 5) pill dx --------------------------------------------- */
  #tblPrizes tbody tr td:nth-child(5){
    grid-area: status;
    justify-self:end;
    display:inline-flex; align-items:center; height:28px; padding:0 12px;
    border-radius:9999px; font-weight:900; font-size:12px; letter-spacing:.3px;
    background:#0f172a; border:1px solid #273347; color:#cbd5e1;
    text-transform:uppercase;
  }

  /* --- FOTO (col 2) centrale ---------------------------------------------- */
  #tblPrizes tbody tr td:nth-child(2){
    grid-area: pic; display:flex; justify-content:center; align-items:center;
  }
  #tblPrizes tbody tr td:nth-child(2) img{
    width:96px; height:96px; border-radius:14px; object-fit:cover;
    border:1px solid #223152; background:#0d1326; display:block;
  }

  /* --- NOME (col 3) -------------------------------------------------------- */
  #tblPrizes tbody tr td:nth-child(3){
    grid-area: name; text-align:center; font-weight:900; font-size:18px; line-height:1.2;
  }

  /* --- DESCRIZIONE (col 4) ------------------------------------------------- */
  #tblPrizes tbody tr td:nth-child(4){
    grid-area: desc; text-align:center; font-size:13px; opacity:.9;
  }

  /* --- PREZZO / AC (col 6) come pillola ----------------------------------- */
  #tblPrizes tbody tr td:nth-child(6){
    grid-area: price;
    display:flex; justify-content:center;
  }
  #tblPrizes tbody tr td:nth-child(6)::before{
    content:"AC"; font-weight:900; color:#9fb7ff; margin-right:10px;
    display:inline-flex; align-items:center; height:28px; padding:0 10px;
    border-radius:9999px; background:#172554; border:1px solid #1e3a8a;
  }
  /* il numero (contenuto della cella) lo metto in una pillola uguale */
  #tblPrizes tbody tr td:nth-child(6){
    align-items:center;
  }
  #tblPrizes tbody tr td:nth-child(6) span,
  #tblPrizes tbody tr td:nth-child(6){   /* se il valore è stampato “nudo” */
    font-weight:900; font-size:13px;
  }

  /* --- CTA (col 7) in basso full width ------------------------------------ */
  #tblPrizes tbody tr td:nth-child(7){
    grid-area: cta;
  }
  #tblPrizes tbody tr td:nth-child(7) .btn,
  #tblPrizes tbody tr td:nth-child(7) button,
  #tblPrizes tbody tr td:nth-child(7) a{
    width:100%; height:42px; border-radius:9999px; font-weight:900;
    display:inline-flex; align-items:center; justify-content:center;
  }

  /* piccole rifiniture */
  #tblPrizes tbody tr td:not(:last-child){ margin-bottom:2px; }
}
/* === PREMI — Finiture card mobile: codice/stato/foto/titolo/prezzo ================= */
@media (max-width: 768px){

  /* 1) CODE pill → più piccola e più a sinistra */
  #tblPrizes tbody tr td:nth-child(1){
    grid-area: code;
    justify-self: start;
    margin-left: -4px;             /* leggermente più a sinistra */
    display: inline-flex;
    align-items: center;
    height: 26px;
    padding: 0 8px;
    border-radius: 9999px;
    background: #172554;
    border: 1px solid #1e3a8a;
    font-weight: 800; font-size: 11px; white-space: nowrap;
  }
  /* etichetta "Codice" più discreta */
  #tblPrizes tbody tr td:nth-child(1)::before{
    content: "Codice ";
    color: #9fb7ff; font-weight: 900; margin-right: 6px;
  }

  /* 2) STATUS pill → solo testo (ABILITATO / DISABILITATO) a destra */
  #tblPrizes tbody tr td:nth-child(5){
    grid-area: status;
    justify-self: end;
    display: inline-flex; align-items: center;
    height: 28px; padding: 0 12px;
    border-radius: 9999px;
    background: #0f172a; border: 1px solid #273347;
    color: #cbd5e1; font-weight: 900; font-size: 12px;
    text-transform: uppercase; letter-spacing: .3px;
  }
  /* assicuro che non compaia "STATO" */
  #tblPrizes tbody tr td:nth-child(5)::before{ content: unset !important; }

  /* 3) FOTO centrale + TITOLO sotto (mai sovrapposti) */
  #tblPrizes tbody tr td:nth-child(2){
    grid-area: pic; display:flex; justify-content:center; align-items:center;
    margin-top: 4px;                /* respiro dalla riga pillole */
  }
  #tblPrizes tbody tr td:nth-child(2) img{
    width: 96px; height: 96px; border-radius: 14px; object-fit: cover;
    border: 1px solid #223152; background: #0d1326; display: block;
  }

  #tblPrizes tbody tr td:nth-child(3){
    grid-area: name;
    text-align: center;
    font-weight: 900; font-size: 18px; line-height: 1.2;
    margin-top: 6px;                /* forza il titolo SOTTO la foto */
  }

  /* 4) DESCRIZIONE (come prima) */
  #tblPrizes tbody tr td:nth-child(4){
    grid-area: desc; text-align: center; font-size: 13px; opacity: .9;
  }

  /* 5) PREZZO — unica pillola "AC 20.00" centrata (niente doppio ovale) */
  #tblPrizes tbody tr td:nth-child(6){
    grid-area: price;
    display: inline-flex; align-items: center; justify-content: center;
    height: 34px; padding: 0 14px;
    border-radius: 9999px;
    background: #172554; border: 1px solid #1e3a8a;
    font-weight: 900; font-size: 13px; color: #e5e7eb;
    margin: 6px auto 0;             /* leggermente più su */
  }
  /* testo "AC " dentro la stesssa pillola (non crea un ovale separato) */
  #tblPrizes tbody tr td:nth-child(6)::before{
    content: "AC ";
    color: #cbd5e1; font-weight: 900; margin-right: 6px;
  }

  /* 6) CTA Richiedi invariata: full width in basso */
  #tblPrizes tbody tr td:nth-child(7){
    grid-area: cta;
    margin-top: 6px;
  }
  #tblPrizes tbody tr td:nth-child(7) .btn,
  #tblPrizes tbody tr td:nth-child(7) button,
  #tblPrizes tbody tr td:nth-child(7) a{
    width: 100%; height: 42px; border-radius: 9999px; font-weight: 900;
    display: inline-flex; align-items: center; justify-content: center;
  }
}

  /* === PREMI — rifiniture card mobile (rettangolare + fix doppie pillole) === */
@media (max-width:768px){

  /* Card più compatta (meno alta) */
  #tblPrizes tbody tr{
    gap: 8px;                 /* era 10-14 → più compatto */
    padding: 12px;            /* era 14 → più compatto */
    border-radius: 16px;
  }

  /* CODICE: più piccolo e ancora più a sinistra */
  #tblPrizes tbody tr td:nth-child(1){
    justify-self: start;
    margin-left: -6px;        /* più a sinistra */
    height: 22px;             /* più bassa */
    padding: 0 8px;
    font-size: 10.5px;        /* più piccola */
  }

  /* STATO: solo scritta, a destra. Elimina ovali “fantasma” */
  #tblPrizes tbody tr td:nth-child(5){
    justify-self: end;
    height: 24px;
    padding: 0 12px;
    border-radius: 9999px;
    background: #0f172a !important;
    border: 1px solid #273347 !important;
    color: #cbd5e1; font-weight: 900; font-size: 11.5px;
    text-transform: uppercase; letter-spacing:.3px;
  }
  /* se la cella stato contiene tag/pillole interne, le azzero per evitare la “seconda” pillola */
  #tblPrizes tbody tr td:nth-child(5) > *{
    background: transparent !important;
    border: none !important;
    padding: 0 !important;
    box-shadow: none !important;
  }
  /* niente prefisso "Stato" */
  #tblPrizes tbody tr td:nth-child(5)::before{ content: none !important; }

  /* FOTO: invariata, ma con piccolo respiro sopra */
  #tblPrizes tbody tr td:nth-child(2){ margin-top: 4px; }
  #tblPrizes tbody tr td:nth-child(2) img{ width: 92px; height: 92px; }

  /* TITOLO: forzo SOTTO la foto con più spazio */
  #tblPrizes tbody tr td:nth-child(3){
    margin-top: 10px;         /* più giù rispetto alla foto */
    font-size: 17px;
  }

  /* DESCRIZIONE: ok così, lasciata com’è */

  /* PREZZO: UNICA pillola "AC 20.00" (niente ovale dentro ovale) */
  #tblPrizes tbody tr td:nth-child(6){
    display: inline-flex; align-items: center; justify-content: center;
    height: 34px; padding: 0 14px;
    border-radius: 9999px;
    background: #172554 !important; 
    border: 1px solid #1e3a8a !important;
    font-weight: 900; font-size: 13px; color: #e5e7eb;
    margin: 4px auto 0;
    gap: 6px;                 /* spazio tra “AC” e valore */
  }
  /* rimuovo la pillola separata che avevamo messo prima */
  #tblPrizes tbody tr td:nth-child(6)::before{
    content: "AC";            /* stessa pillola: solo testo, senza stile proprio */
    background: transparent !important;
    border: none !important;
    padding: 0 !important;
    margin: 0 !important;
    color: #cbd5e1; font-weight: 900;
  }
  /* se il valore è in uno span o testo nudo lo tengo accanto */
  #tblPrizes tbody tr td:nth-child(6) > span{ display:inline; }

  /* CTA invariata ma con un filo meno spazio sopra */
  #tblPrizes tbody tr td:nth-child(7){ margin-top: 6px; }
}

  /* === PREMI — Compattazione card mobile (aria sotto foto + prezzo/CTA più su) === */
@media (max-width:768px){

  /* Card un po’ più bassa */
  #tblPrizes tbody tr{
    gap: 6px !important;            /* meno spazio verticale tra le righe della card */
    padding: 10px 12px 10px !important;  /* riduco padding top/bottom */
  }

  /* Foto: un filo di respiro sopra, niente spinta verso il titolo */
  #tblPrizes tbody tr td:nth-child(2){
    margin-top: 2px !important;
  }
  #tblPrizes tbody tr td:nth-child(2) img{
    width: 92px; height: 92px;      /* resta grande ma non enorme */
  }

  /* TITOLO: scende sotto la foto con più aria */
  #tblPrizes tbody tr td:nth-child(3){
    margin-top: 16px !important;    /* aria tra foto e titolo */
    margin-bottom: 2px !important;
    font-size: 17px !important;
    line-height: 1.25 !important;
  }

  /* DESCRIZIONE: resta dov’è, ma con piccolo margine inferiore */
  #tblPrizes tbody tr td:nth-child(4){
    margin-bottom: 6px !important;
    font-size: 13px !important;
  }

  /* PREZZO: un’unica pillola “AC 20.00”, più vicina alla descrizione */
  #tblPrizes tbody tr td:nth-child(6){
    margin-top: 2px !important;     /* sale verso la descrizione */
    height: 32px !important;
    padding: 0 12px !important;
    border-radius: 9999px !important;
    background: #172554 !important;
    border: 1px solid #1e3a8a !important;
    display: inline-flex !important; align-items: center !important; justify-content: center !important;
    gap: 6px !important;
  }
  /* niente ovale interno: “AC ” fa parte della stessa pillola */
  #tblPrizes tbody tr td:nth-child(6)::before{
    content: "AC" !important;
    background: transparent !important; border: none !important; padding: 0 !important; margin: 0 !important;
    color: #cbd5e1 !important; font-weight: 900 !important;
  }

  /* CTA Richiedi: più vicina al prezzo e un filo più bassa */
  #tblPrizes tbody tr td:nth-child(7){
    margin-top: 6px !important;     /* sale */
  }
  #tblPrizes tbody tr td:nth-child(7) .btn,
  #tblPrizes tbody tr td:nth-child(7) button,
  #tblPrizes tbody tr td:nth-child(7) a{
    height: 40px !important;        /* compatta */
  }

  /* (rimane il fix stato/codice della patch precedente) */
  #tblPrizes tbody tr td:nth-child(5) > *{ 
    background: transparent !important; border: 0 !important; padding: 0 !important; box-shadow:none !important;
  }
}
/* === PREMI — Card mobile compatta con spazi equidistanti ============ */
@media (max-width:768px){

  /* ritmo verticale unico per tutti i blocchi della card */
  #tblPrizes tbody tr{ --pv: 10px; }                 /* spazio verticale di base */

  /* Card più rettangolare (meno alta) */
  #tblPrizes tbody tr{
    gap: 6px !important;
    padding: 10px 12px 12px !important;               /* top/bottom più stretti */
    border-radius: 16px;
  }

  /* Codice: piccolo e più a sinistra (manteniamo il fix) */
  #tblPrizes tbody tr td:nth-child(1){
    justify-self: start;
    margin-left: -6px; height: 22px; padding:0 8px;
    font-size:10.5px; border-radius:9999px;
    background:#172554; border:1px solid #1e3a8a;
    display:inline-flex; align-items:center;
  }
  #tblPrizes tbody tr td:nth-child(1)::before{
    content:"Codice "; font-weight:900; color:#9fb7ff; margin-right:6px;
  }

  /* Stato: UNA sola pillola a destra (niente pillole/linee interne) */
  #tblPrizes tbody tr td:nth-child(5){
    justify-self:end;
    height:24px; padding:0 12px; border-radius:9999px;
    background:#0f172a !important; border:1px solid #273347 !important;
    color:#cbd5e1; font-weight:900; font-size:11.5px; text-transform:uppercase;
    letter-spacing:.3px;
  }
  #tblPrizes tbody tr td:nth-child(5)::before{ content:none !important; }
  #tblPrizes tbody tr td:nth-child(5) > *{
    background:transparent !important; border:0 !important; padding:0 !important; box-shadow:none !important;
  }

  /* Foto al centro con un filo di respiro sopra */
  #tblPrizes tbody tr td:nth-child(2){
    grid-area: pic; display:flex; justify-content:center; align-items:center;
    margin-top: calc(var(--pv) - 6px) !important;
  }
  #tblPrizes tbody tr td:nth-child(2) img{
    width: 90px; height: 90px; border-radius:14px; object-fit:cover;
    border:1px solid #223152; background:#0d1326; display:block;
  }

  /* Titolo: SOTTO la foto con aria (equidistanza) */
  #tblPrizes tbody tr td:nth-child(3){
    grid-area: name; text-align:center;
    font-weight:900; font-size:17px; line-height:1.25;
    margin-top: var(--pv) !important;                 /* ↓ spazio foto→titolo */
    margin-bottom: 0 !important;
  }

  /* Descrizione: rimane, ma con ritmo costante */
  #tblPrizes tbody tr td:nth-child(4){
    grid-area: desc; text-align:center; font-size:13px; opacity:.9;
    margin-top: var(--pv) !important;                 /* ↓ spazio titolo→descr */
    margin-bottom: 0 !important;
  }

  /* Prezzo: UNICA pillola “AC 20.00” centrata (stop doppio ovale) */
  #tblPrizes tbody tr td:nth-child(6){
    grid-area: price;
    display:inline-flex; align-items:center; justify-content:center;
    height:32px; padding:0 14px; gap:6px;
    border-radius:9999px;
    background:#172554 !important; border:1px solid #1e3a8a !important;
    font-weight:900; font-size:13px; color:#e5e7eb;
    margin: var(--pv) auto 0 !important;              /* ↓ spazio descr→prezzo */
  }
  #tblPrizes tbody tr td:nth-child(6)::before{
    content:"AC"; color:#cbd5e1; font-weight:900;     /* “AC” nella stessa pillola */
    background:transparent !important; border:0 !important; padding:0 !important; margin:0 !important;
  }
  #tblPrizes tbody tr td:nth-child(6) > span{ display:inline; }

  /* CTA Richiedi: più su, ritmo uguale, card più bassa */
  #tblPrizes tbody tr td:nth-child(7){
    grid-area: cta; margin-top: var(--pv) !important; /* ↓ spazio prezzo→CTA */
  }
  #tblPrizes tbody tr td:nth-child(7) .btn,
  #tblPrizes tbody tr td:nth-child(7) button,
  #tblPrizes tbody tr td:nth-child(7) a{
    width:100%; height:40px; border-radius:9999px; font-weight:900;
    display:inline-flex; align-items:center; justify-content:center;
  }
}  

  /* === PREMI — Fix definitivo spaziatura card mobile ================== */
@media (max-width:768px){

  /* ritmo verticale unico, facile da regolare */
  #tblPrizes tbody tr{ --rh: 12px; }         /* rhythm: distanza standard */

  /* Card più bassa e senza cuscinetti inutili */
  #tblPrizes tbody tr{
    gap: 0 !important;                        /* niente gap “di griglia” */
    padding: 10px 12px 12px !important;
  }

  /* FOTO */
  #tblPrizes tbody tr td:nth-child(2){
    margin-top: calc(var(--rh) - 6px) !important;
  }
  #tblPrizes tbody tr td:nth-child(2) img{
    width: 90px; height: 90px; display:block;
    margin: 0 auto;                           /* davvero centrata */
  }

  /* TITOLO — STACCO NETTO DALLA FOTO, poi poco spazio sotto */
  #tblPrizes tbody tr td:nth-child(3){
    margin-top: calc(var(--rh) + 8px) !important;   /* ↑ aria dalla foto */
    margin-bottom: 6px !important;                  /* ↓ vicino alla descrizione */
    text-align:center; font-weight:900; font-size:17px; line-height:1.25;
  }

  /* DESCRIZIONE — poco spazio sopra e sotto */
  #tblPrizes tbody tr td:nth-child(4){
    margin-top: 0 !important;                       /* attaccata al titolo */
    margin-bottom: 8px !important;                  /* vicino al prezzo */
    text-align:center; font-size:13px; opacity:.9;
  }

  /* PREZZO — UN’UNICA PILLOLA; vicinissimo alla descrizione */
  #tblPrizes tbody tr td:nth-child(6){
    margin-top: 0 !important;                       /* attaccata alla descrizione */
    margin-bottom: 8px !important;                  /* vicino alla CTA */
    display:inline-flex; align-items:center; justify-content:center;
    gap: 6px !important; height:32px !important; padding:0 14px !important;
    border-radius:9999px; background:#172554 !important; border:1px solid #1e3a8a !important;
    font-weight:900; font-size:13px; color:#e5e7eb;
  }
  #tblPrizes tbody tr td:nth-child(6)::before{
    content:"AC"; color:#cbd5e1; font-weight:900;
    background:transparent !important; border:0 !important; padding:0 !important; margin:0 !important;
  }

  /* CTA — sale ancora un filo */
  #tblPrizes tbody tr td:nth-child(7){
    margin-top: 0 !important;                      /* attaccata al prezzo */
  }
  #tblPrizes tbody tr td:nth-child(7) .btn,
  #tblPrizes tbody tr td:nth-child(7) button,
  #tblPrizes tbody tr td:nth-child(7) a{
    height: 40px !important;
  }

  /* CODE pill (sx) e STATO (dx) restano piccoli, senza “doppi ovali” */
  #tblPrizes tbody tr td:nth-child(1){
    justify-self:start; margin-left:-6px; height:22px; padding:0 8px;
    font-size:10.5px; border-radius:9999px; background:#172554; border:1px solid #1e3a8a;
    display:inline-flex; align-items:center;
  }
  #tblPrizes tbody tr td:nth-child(1)::before{
    content:"Codice "; font-weight:900; color:#9fb7ff; margin-right:6px;
  }
  #tblPrizes tbody tr td:nth-child(5){
    justify-self:end; height:24px; padding:0 12px; border-radius:9999px;
    background:#0f172a !important; border:1px solid #273347 !important;
    color:#cbd5e1; font-weight:900; font-size:11.5px; text-transform:uppercase; letter-spacing:.3px;
  }
  #tblPrizes tbody tr td:nth-child(5)::before{ content:none !important; }
  #tblPrizes tbody tr td:nth-child(5) > *{
    background:transparent !important; border:0 !important; padding:0 !important; box-shadow:none !important;
  }
}
  /* === PREMI — mobile: titolo più giù, prezzo più su, card compatta ====== */
@media (max-width:768px){

  /* ritmo verticale unico: più compatta ma regolare */
  #tblPrizes tbody tr{ --sp: 12px; }   /* spazio standard tra i blocchi */

  /* card un po’ più bassa */
  #tblPrizes tbody tr{
    gap: 0 !important;
    padding: 10px 12px 10px !important;
  }

  /* FOTO (lasciamo com’è, solo un filo di respiro sopra) */
  #tblPrizes tbody tr td:nth-child(2){ margin-top: 2px !important; }
  #tblPrizes tbody tr td:nth-child(2) img{ width: 90px; height: 90px; }

  /* TITOLO: STACCO netto dalla foto */
  #tblPrizes tbody tr td:nth-child(3){
    margin-top: calc(var(--sp) + 10px) !important;  /* <<< PIÙ GIÙ */
    margin-bottom: 6px !important;                  /* vicino alla descrizione */
    font-size: 17px !important; line-height: 1.25 !important;
    text-align: center;
  }

  /* DESCRIZIONE: poco spazio sopra/sotto, così il prezzo SALE */
  #tblPrizes tbody tr td:nth-child(4){
    margin-top: 0 !important;                       /* attaccata al titolo */
    margin-bottom: 6px !important;                  /* prezzo subito sotto */
    text-align: center; font-size: 13px; opacity: .9;
  }

  /* PREZZO: UN’UNICA pillola “AC 20.00” vicinissima alla descrizione */
  #tblPrizes tbody tr td:nth-child(6){
    margin-top: 2px !important;                     /* <<< PIÙ SU */
    margin-bottom: 8px !important;                  /* vicino alla CTA */
    display: inline-flex !important; align-items: center !important; justify-content: center !important;
    gap: 6px !important; height: 32px !important; padding: 0 14px !important;
    border-radius: 9999px !important;
    background: #172554 !important; border: 1px solid #1e3a8a !important;
    font-weight: 900; font-size: 13px; color: #e5e7eb;
  }
  /* “AC” dentro la stessa pillola (no secondo ovale) */
  #tblPrizes tbody tr td:nth-child(6)::before{
    content: "AC"; color:#cbd5e1; font-weight:900;
    background: transparent !important; border: 0 !important; padding: 0 !important; margin: 0 !important;
  }

  /* CTA: poco spazio dal prezzo, card più bassa */
  #tblPrizes tbody tr td:nth-child(7){ margin-top: 6px !important; }
  #tblPrizes tbody tr td:nth-child(7) .btn,
  #tblPrizes tbody tr td:nth-child(7) button,
  #tblPrizes tbody tr td:nth-child(7) a{
    height: 40px !important;
  }

  /* (riconfermo i fix di codice/stato per sicurezza) */
  #tblPrizes tbody tr td:nth-child(1){ margin-left:-6px; height:22px; padding:0 8px; font-size:10.5px; }
  #tblPrizes tbody tr td:nth-child(5){
    height:24px; padding:0 12px; background:#0f172a !important; border:1px solid #273347 !important;
  }
  #tblPrizes tbody tr td:nth-child(5) > *{ background:transparent !important; border:0 !important; padding:0 !important; }
  #tblPrizes tbody tr td:nth-child(5)::before{ content:none !important; }
}
  /* === PREMI — mobile: titolo più in basso, prezzo più in alto (deciso) === */
@media (max-width:768px){
  /* Titolo (colonna 3) — più distante dalla foto */
  #tblPrizes tbody tr td:nth-child(3){
    margin-top: 28px !important;   /* era ~6/16 → molto più giù */
    margin-bottom: 6px !important;
  }

  /* Descrizione (colonna 4) — pochissimo spazio sotto */
  #tblPrizes tbody tr td:nth-child(4){
    margin-bottom: 4px !important; /* stringe il “buco” con il prezzo */
  }

  /* Prezzo (colonna 6) — molto più su, quasi attaccato alla descrizione */
  #tblPrizes tbody tr td:nth-child(6){
    margin-top: -4px !important;   /* spinge la pillola verso l’alto */
    margin-bottom: 8px !important; /* resta vicino alla CTA */
  }
}
</style>

<main class="pr-page">
  <section class="section">
    <div class="container hwrap">
      <h1>Premi</h1>

      <div class="card">
        <div class="topbar">
          <div class="topbar-left">
            <span class="saldo"><span id="meCoins">0.00</span> <span class="ac">AC</span></span>
          </div>
          <div style="display:flex; gap:8px; align-items:center;">
            <input type="search" class="searchbox" id="qPrize" placeholder="Cerca premio…">
          </div>
        </div>

        <div class="table-wrap">
          <table class="table" id="tblPrizes">
            <thead>
              <tr>
                <th>Codice</th>
                <th>Foto</th>
                <th class="sortable" data-sort="name">Nome <span class="arrow">↕</span></th>
                <th>Descrizione</th>
                <th>Stato</th>
                <th class="sortable" data-sort="coins">Arena Coins <span class="arrow">↕</span></th>
                <th style="text-align:right;">Azione</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      </div>

      <!-- Wizard richiesta premio -->
      <div class="modal" id="mdReq" aria-hidden="true">
        <div class="modal-backdrop" data-close></div>
        <div class="modal-card">
          <div class="modal-head">
            <h3>Richiedi premio</h3>
            <button class="modal-x" data-close>&times;</button>
          </div>
          <div class="modal-body">
            <form id="fReq" novalidate>
              <input type="hidden" id="r_prize_id"><input type="hidden" id="r_prize_name"><input type="hidden" id="r_prize_coins">

      <!-- STEP 1 — Residenza (solo indirizzo) -->
<section class="step active" data-step="1">
  <div class="badge">Dati di residenza (fatturazione)</div>
  <div class="grid2" style="margin-top:10px;">
    <div class="field" style="grid-column:span 2;"><label class="label">Via *</label><input class="input light" id="res_via" required></div>
    <div class="field"><label class="label">Civico *</label><input class="input light" id="res_civico" required></div>
    <div class="field"><label class="label">Città/Comune *</label><input class="input light" id="res_citta" required></div>
    <div class="field"><label class="label">Provincia *</label><input class="input light" id="res_prov" required maxlength="2" oninput="this.value=this.value.toUpperCase()"></div>
    <div class="field"><label class="label">CAP *</label><input class="input light" id="res_cap" required pattern="^\d{5}$"></div>
    <div class="field"><label class="label">Nazione *</label><input class="input light" id="res_nazione" required></div>
  </div>
</section>

<!-- STEP 2 — Dati fiscali / Documento -->
<section class="step" data-step="2">
  <div class="badge">Dati fiscali / Documento</div>
  <div class="grid2" style="margin-top:10px;">
    <div class="field"><label class="label">Codice fiscale *</label><input class="input light" id="res_cf" required pattern="[A-Z0-9]{16}" oninput="this.value=this.value.toUpperCase()"></div>
    <div class="field"><label class="label">Cittadinanza *</label><input class="input light" id="res_cittadinanza" required></div>

    <div class="field">
      <label class="label">Tipo documento *</label>
      <select class="select light" id="res_tipo_doc" required>
        <option value="">Seleziona…</option>
        <option value="PATENTE">Patente</option>
        <option value="CARTA_IDENTITA">Carta d'identità</option>
        <option value="PASSAPORTO">Passaporto</option>
      </select>
    </div>
    <div class="field"><label class="label">Numero documento *</label><input class="input light" id="res_num_doc" required></div>
    <div class="field"><label class="label">Data rilascio *</label><input class="input light" id="res_rilascio" type="date" required></div>
    <div class="field"><label class="label">Data scadenza *</label><input class="input light" id="res_scadenza" type="date" required></div>
    <div class="field" style="grid-column:span 2;"><label class="label">Rilasciato da *</label><input class="input light" id="res_rilasciato_da" required></div>
  </div>
</section>

<!-- STEP 3 — Spedizione -->
<section class="step" data-step="3">
  <div class="badge">Indirizzo di spedizione</div>
  <div style="margin:10px 0 8px;">
    <label class="check">
      <input type="checkbox" id="ship_same"> Spedizione uguale alla residenza
    </label>
  </div>
  <div class="grid2">
    <div class="field"><label class="label">Stato *</label><input class="input light" id="ship_stato" required></div>
    <div class="field"><label class="label">Città *</label><input class="input light" id="ship_citta" required></div>
    <div class="field"><label class="label">Comune *</label><input class="input light" id="ship_comune" required></div>
    <div class="field"><label class="label">Provincia *</label><input class="input light" id="ship_provincia" required></div>
    <div class="field" style="grid-column:span 2;"><label class="label">Via *</label><input class="input light" id="ship_via" required></div>
    <div class="field"><label class="label">Civico *</label><input class="input light" id="ship_civico" required></div>
    <div class="field"><label class="label">CAP *</label><input class="input light" id="ship_cap" required></div>
  </div>
</section>

<!-- STEP 4 — Riepilogo -->
<section class="step" data-step="4">
  <div class="card" style="padding:12px;">
    <div><strong>Premio:</strong> <span id="rv_name"></span></div>
    <div><strong>Costo:</strong> <span id="rv_coins"></span> <span class="muted">AC</span></div>
    <div class="hr"></div>
    <div><strong>Residenza:</strong></div>
    <div id="rv_res" class="muted-sm"></div>
    <div class="hr"></div>
    <div><strong>Spedizione:</strong> <span class="badge" id="rv_same"></span></div>
    <div id="rv_ship" class="muted-sm"></div>
  </div>
</section>

                        </form>
        </div> <!-- /.modal-body -->

        <!-- Footer wizard (bottoni) -->
        <div class="modal-foot">
          <div style="display:flex; gap:8px;">
            <button class="btn btn--outline" type="button" data-close>Annulla</button>
            <button class="btn btn--primary" type="button" id="r_next">Avanti</button>
            <button class="btn btn--primary hidden" type="button" id="r_send">Richiedi</button>
          </div>
        </div>
          
      <!-- Dialog OK -->
      <div class="modal" id="mdOk" aria-hidden="true">
        <div class="modal-backdrop" data-close></div>
        <div class="modal-card" style="max-width:560px;">
          <div class="modal-head">
            <h3>Premio richiesto!</h3>
            <button class="modal-x" data-close>&times;</button>
          </div>
          <div class="modal-body">
            <p>Riceverai aggiornamenti nella sezione <strong>Messaggi</strong> del tuo account.</p>
          </div>
          <div class="modal-foot">
            <button class="btn btn--primary" data-close>Chiudi</button>
          </div>
        </div>
      </div>

    </div>
  </section>
</main>
<?php
include __DIR__ . '/../partials/footer.php';
?>
<script>
document.addEventListener('DOMContentLoaded', ()=>{
  const $ = s => document.querySelector(s);
  const $$ = (s,p=document)=>[...p.querySelectorAll(s)];
  const CDN_BASE = <?= json_encode($CDN_BASE) ?>;
  const CSRF = '<?= $CSRF ?>';

  let meCoins = 0.00, sort='created', dir='desc', search='';

  /* ===== Modali: open/close con gestione focus ===== */
  const isInside = (el, root) => !!(el && root && (el===root || root.contains(el)));
  let lastOpener = null;

  function openM(sel){
    const m = $(sel); if(!m) return;
    document.body.classList.add('modal-open');
    m.setAttribute('aria-hidden','false');
    const focusable = m.querySelector('[data-close], button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
    (focusable || m).focus({preventScroll:true});
    m._opener = lastOpener || null;
  }
  function closeM(sel){
    const m = $(sel); if(!m) return;
    if (isInside(document.activeElement, m)) document.activeElement.blur();
    m.setAttribute('aria-hidden','true');
    document.body.classList.remove('modal-open');
    const target = m._opener && document.contains(m._opener) ? m._opener : document.body;
    if (target && target.focus) target.focus({preventScroll:true});
    m._opener = null; lastOpener = null;
  }
  document.addEventListener('click', (e)=>{
    const btn = e.target.closest('[data-close]');
    if (!btn) return;
    e.preventDefault();
    const modal = btn.closest('.modal');
    if (modal) closeM('#' + modal.id);
  });
  document.addEventListener('click', (e)=>{
    const bd = e.target.closest('.modal-backdrop');
    if (!bd) return;
    e.preventDefault();
    const modal = bd.closest('.modal');
    if (modal) closeM('#' + modal.id);
  });
  document.addEventListener('keydown', (e)=>{
    if (e.key !== 'Escape') return;
    const open = document.querySelector('.modal[aria-hidden="false"]');
    if (open) closeM('#' + open.id);
  });

  async function loadMe(){
    try{
      const r = await fetch('?action=me', { cache:'no-store' });
      let j;
      try { j = await r.json(); }
      catch(e){
        const raw = await r.text().catch(()=> '');
        console.error('[loadMe] risposta non JSON', {status:r.status, raw});
        return;
      }
      if (j.ok && j.me){
        meCoins = Number(j.me.coins || 0);
        document.getElementById('meCoins').textContent = meCoins.toFixed(2);
      }
    }catch(err){
      console.error('[loadMe] fetch error', err);
    }
  }

  async function loadPrizes(){
    const u = new URL('/premi.php', location.origin);
    u.searchParams.set('action','list_prizes');
    u.searchParams.set('sort',  sort);
    u.searchParams.set('dir',   dir);
    if (search) u.searchParams.set('search', search);
    u.searchParams.set('_', Date.now().toString());

    const tb = $('#tblPrizes tbody'); if (!tb) return;
    tb.innerHTML = '<tr><td colspan="7">Caricamento…</td></tr>';

    try{
      const r = await fetch(u.toString(), { cache:'no-store', credentials:'same-origin' });
      let j; try { j = await r.json(); }
      catch(parseErr){
        const txt = await r.text().catch(()=> '');
        console.error('[loadPrizes] parse error:', parseErr, txt);
        tb.innerHTML = '<tr><td colspan="7">Errore caricamento (risposta non valida)</td></tr>';
        return;
      }

      const rows = (j && j.ok && Array.isArray(j.rows)) ? j.rows : [];
      tb.innerHTML = '';
      if (rows.length === 0){
        tb.innerHTML = '<tr><td colspan="7">Nessun premio disponibile</td></tr>';
        return;
      }

      rows.forEach(row=>{
        const cost     = Number(row.amount_coins || 0);
        const enabled  = (row.is_enabled == 1);
        const can      = enabled && (Number(meCoins) >= cost);
        const reason   = !enabled ? 'Premio non richiedibile'
                                  : (Number(meCoins) < cost ? 'Arena Coins insufficienti' : '');

        let imgHTML = '<div class="img-thumb" style="background:#0d1326;"></div>';
        if (row.image_key && CDN_BASE) {
          const src = CDN_BASE + '/' + row.image_key;
          imgHTML = `<img class="img-thumb" src="${src}" alt="">`;
        }

        const btnClass = can ? 'btn btn--primary btn--sm' : 'btn btn--disabled';
        const btnAttrs = `data-req="${row.id}" data-name="${row.name || ''}" data-coins="${cost}" data-can="${can?1:0}" data-reason="${reason}" title="${reason}"`;

        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td><code>${row.prize_code || '-'}</code></td>
          <td>${imgHTML}</td>
          <td>${row.name || '-'}</td>
          <td>${row.description ? row.description : ''}</td>
          <td>${enabled ? '<span class="pill ok">Abilitato</span>' : '<span class="pill off">Disabilitato</span>'}</td>
          <td>${cost.toFixed(2)}</td>
          <td style="text-align:right;">
            <button type="button" class="${btnClass}" ${btnAttrs}>Richiedi</button>
          </td>
        `;
        tb.appendChild(tr);
      });

    } catch(err){
      console.error('[loadPrizes] fetch error:', err);
      $('#tblPrizes tbody').innerHTML = '<tr><td colspan="7">Errore caricamento</td></tr>';
    }
  }

  /* ===== Listener protetti ===== */

  // sort (thead)
  const thead = document.querySelector('#tblPrizes thead');
  if (thead) {
    thead.addEventListener('click', (e)=>{
      const th=e.target.closest('[data-sort]'); if(!th) return;
      const s=th.getAttribute('data-sort');
      if (sort===s) dir=(dir==='asc'?'desc':'asc'); else{ sort=s; dir='asc'; }
      loadPrizes();
    });
  }

  // search
  const qInput = document.getElementById('qPrize');
  if (qInput) {
    qInput.addEventListener('input', e=>{
      search=e.target.value.trim();
      loadPrizes();
    });
  }

  // apertura wizard — delega globale (funziona sempre anche dopo re-render)
  document.addEventListener('click', (e)=>{
    const b = e.target.closest('button[data-req]');
    if (!b) return;
    const table = document.getElementById('tblPrizes');
    if (!table || !table.contains(b)) return;

    // Ricontrollo LIVE
    const cost = Number(b.getAttribute('data-coins') || 0);
    const enabled = (b.getAttribute('data-reason') !== 'Premio non richiedibile');
    const canNow = enabled && (Number(meCoins) >= cost);
    if (!canNow){
      const why = !enabled ? 'Premio non richiedibile' : 'Arena Coins insufficienti';
      alert(why);
      return;
    }

    lastOpener = b;

    const id  = b.getAttribute('data-req');
    const nm  = b.getAttribute('data-name');
    const ac  = b.getAttribute('data-coins');
    $('#r_prize_id').value   = id;
    $('#r_prize_name').value = nm;
    $('#r_prize_coins').value= ac;

    // reset wizard
    const steps = $$('#fReq .step');
    steps.forEach((s,i)=>s.classList.toggle('active', i===0));
    const nextBtn = document.getElementById('r_next');
    const sendBtn = document.getElementById('r_send');
    if (nextBtn) nextBtn.classList.remove('hidden');
    if (sendBtn) sendBtn.classList.add('hidden');

    // pulizia campi
    $$('#fReq input').forEach(i=>{ if (!['hidden','checkbox','date'].includes(i.type)) i.value=''; });
    const shipSame = document.getElementById('ship_same');
    if (shipSame) shipSame.checked = false;
    toggleShipLock();

    openM('#mdReq');
  });

  // flag "uguale alla residenza"
  const shipSame = document.getElementById('ship_same');
  if (shipSame) {
    shipSame.addEventListener('change', ()=>{
      copyResToShip();
      toggleShipLock();
    });
  }

  function copyResToShip(){
    const same = document.getElementById('ship_same')?.checked;
    if (!same) return;
    $('#ship_stato').value     = $('#res_nazione').value;
    $('#ship_citta').value     = $('#res_citta').value;
    $('#ship_comune').value    = $('#res_citta').value;
    $('#ship_provincia').value = $('#res_prov').value;
    $('#ship_via').value       = $('#res_via').value;
    $('#ship_civico').value    = $('#res_civico').value;
    $('#ship_cap').value       = $('#res_cap').value;
  }
  function toggleShipLock(){
    const lock = document.getElementById('ship_same')?.checked;
    ['ship_stato','ship_citta','ship_comune','ship_provincia','ship_via','ship_civico','ship_cap']
      .forEach(id=>{ const el=$('#'+id); if (el) el.disabled=!!lock; });
  }

  // AVANTI
  const btnNext = document.getElementById('r_next');
  if (btnNext) {
    btnNext.addEventListener('click', ()=>{
      const steps = $$('#fReq .step');

      // Step 1 -> 2 : indirizzo di residenza
      if (steps[0]?.classList.contains('active')){
        const need1 = ['res_via','res_civico','res_citta','res_prov','res_cap','res_nazione'];
        for (const id of need1){ const el=$('#'+id); if (!el?.value.trim()){ el?.reportValidity?.(); return; } }
        steps[0].classList.remove('active'); steps[1]?.classList.add('active');
        return;
      }

      // Step 2 -> 3 : dati fiscali/documento
      if (steps[1]?.classList.contains('active')){
        const need2 = ['res_cf','res_cittadinanza','res_tipo_doc','res_num_doc','res_rilascio','res_scadenza','res_rilasciato_da'];
        for (const id of need2){ const el=$('#'+id); if (!el?.value.trim()){ el?.reportValidity?.(); return; } }
        steps[1].classList.remove('active'); steps[2]?.classList.add('active');
        return;
      }

      // Step 3 -> 4 : spedizione (o copia da residenza se flag)
      if (steps[2]?.classList.contains('active')){
        const same = document.getElementById('ship_same')?.checked;
        if (!same){
          const need3=['ship_stato','ship_citta','ship_comune','ship_provincia','ship_via','ship_civico','ship_cap'];
          for (const id of need3){ const el=$('#'+id); if (!el?.value.trim()){ el?.reportValidity?.(); return; } }
        } else {
          copyResToShip();
        }

        // Riepilogo
        $('#rv_name').textContent  = $('#r_prize_name').value;
        $('#rv_coins').textContent = Number($('#r_prize_coins').value||0).toFixed(2);

        const resHTML = `
          CF: ${$('#res_cf').value}<br>
          ${$('#res_via').value} ${$('#res_civico').value}<br>
          ${$('#res_cap').value} ${$('#res_citta').value} (${ $('#res_prov').value })<br>
          ${$('#res_nazione').value}<br>
          Doc: ${$('#res_tipo_doc').value} ${$('#res_num_doc').value} — rilasciato da ${$('#res_rilasciato_da').value}<br>
          Rilascio: ${$('#res_rilascio').value} • Scadenza: ${$('#res_scadenza').value}
        `;
        $('#rv_res').innerHTML = resHTML;

        $('#rv_same').textContent = same ? 'uguale alla residenza' : 'diverso';
        const shipHTML = `
          ${$('#ship_via').value} ${$('#ship_civico').value}<br>
          ${$('#ship_cap').value} ${$('#ship_citta').value} (${ $('#ship_provincia').value })<br>
          ${$('#ship_comune').value} — ${$('#ship_stato').value}
        `;
        $('#rv_ship').innerHTML = shipHTML;

        steps[2].classList.remove('active'); steps[3]?.classList.add('active');
        btnNext.classList.add('hidden');
        const btnSend = document.getElementById('r_send');
        if (btnSend) btnSend.classList.remove('hidden');
      }
    });
  }

  // INVIO
  const btnSend = document.getElementById('r_send');
  if (btnSend) {
    btnSend.addEventListener('click', async ()=>{
      const btn = btnSend; btn.disabled=true; btn.textContent='Invio…';
      try{
        const data = new URLSearchParams({
          prize_id: String(Number($('#r_prize_id').value||0)),

          // residenza -> USERS
          res_cf: $('#res_cf').value.trim(),
          res_cittadinanza: $('#res_cittadinanza').value.trim(),
          res_via: $('#res_via').value.trim(),
          res_civico: $('#res_civico').value.trim(),
          res_citta: $('#res_citta').value.trim(),
          res_prov: $('#res_prov').value.trim(),
          res_cap: $('#res_cap').value.trim(),
          res_nazione: $('#res_nazione').value.trim(),
          res_tipo_doc: $('#res_tipo_doc').value,
          res_num_doc: $('#res_num_doc').value.trim(),
          res_rilascio: $('#res_rilascio').value,
          res_scadenza: $('#res_scadenza').value,
          res_rilasciato_da: $('#res_rilasciato_da').value.trim(),

          // spedizione -> prize_requests
          ship_same_as_res: (document.getElementById('ship_same')?.checked ? '1' : '0'),
          ship_stato: $('#ship_stato').value.trim(),
          ship_citta: $('#ship_citta').value.trim(),
          ship_comune: $('#ship_comune').value.trim(),
          ship_provincia: $('#ship_provincia').value.trim(),
          ship_via: $('#ship_via').value.trim(),
          ship_civico: $('#ship_civico').value.trim(),
          ship_cap: $('#ship_cap').value.trim()
        });
        data.set('csrf_token', CSRF);

        const r = await fetch('/api/prize_request.php?action=request', {
          method:'POST',
          body:data,
          credentials:'same-origin',
          headers:{ 'Accept':'application/json', 'X-CSRF-Token': CSRF }
        });

        let j=null, raw='';
        try { j = await r.json(); } catch(_) { try{ raw = await r.text(); }catch(__){} }

        if (!j || j.ok!==true){
          let msg='Errore richiesta premio';
          if (j && j.detail) msg += ': '+j.detail;
          else if (raw) msg += ': '+raw.slice(0,300);
          alert(msg);
          return;
        }

        closeM('#mdReq');
        openM('#mdOk');
        await loadMe();
        await loadPrizes();

      }catch(e){
        alert('Errore invio: ' + (e && e.message ? e.message : ''));
      }finally{
        btn.disabled=false; btn.textContent='Richiedi';
      }
    });
  }

  // init
  (async ()=>{
    await loadMe();     // meCoins valorizzato
    await loadPrizes(); // la tabella usa meCoins
  })();

});
</script>
