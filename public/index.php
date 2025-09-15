<?php $page_css = '/pages-css/index.css'; include __DIR__ . '/../partials/head.php'; ?>
<?php include __DIR__ . '/../partials/header_guest.php'; ?>
<main>
  <section class="hero">
    <div class="container">
      <h1 class="hero-title">Index</h1>
      <p class="hero-sub">Template UI – colori blu, nessuna funzionalità.</p>
    </div>
  </section>
  <section class="section">
    <div class="container">
      
<div class="banner">
  <p>Sezione descrittiva statica. Colori e layout aggiornati.</p>
  <div class="stack-horizontal">
    <a class="btn btn--primary btn--lg" href="/public/registrazione.php">Inizia Ora</a>
    <a class="btn btn--secondary btn--lg" href="/public/login.php">Accedi</a>
  </div>
</div>
<div class="section-tight"></div>
<div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));">
  <div class="card"><h3>Layout coerente</h3><p class="muted">Header, footer, container uniformi.</p></div>
  <div class="card"><h3>Colori aggiornati</h3><p class="muted">Palette blu + testi bianchi.</p></div>
  <div class="card"><h3>Mobile first</h3><p class="muted">Menu mobile e griglie responsive.</p></div>
</div>
    </div>
  </section>
</main>
<?php include __DIR__ . '/../partials/footer.php'; ?>
