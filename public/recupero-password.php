<?php $page_css = '/pages-css/recupero-password.css'; include __DIR__ . '/../partials/head.php'; ?>
<?php include __DIR__ . '/../partials/header_guest.php'; ?>
<main>
  <section class="hero">
    <div class="container">
      <h1 class="hero-title">Recupero Password</h1>
      <p class="hero-sub">Template UI – colori blu, nessuna funzionalità.</p>
    </div>
  </section>
  <section class="section">
    <div class="container">
      
<div class="card" style="max-width:440px;margin-inline:auto;">
  <form class="stack-vertical" onsubmit="return false;">
    <div class="field">
      <label class="label" for="email">Email</label>
      <input class="input" id="email" type="email" placeholder="tu@esempio.com" />
      <span class="help">Riceverai un link per il reset. (UI solo)</span>
    </div>
    <button class="btn btn--primary btn--md" type="button">Invia Link</button>
  </form>
</div>
    </div>
  </section>
</main>
<?php include __DIR__ . '/../partials/footer.php'; ?>
