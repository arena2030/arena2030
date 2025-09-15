<?php $page_css = '/pages-css/registrazione.css'; include __DIR__ . '/../partials/head.php'; ?>
<?php include __DIR__ . '/../partials/header_guest.php'; ?>
<main>
  <section class="hero">
    <div class="container">
      <h1 class="hero-title">Registrazione</h1>
      <p class="hero-sub">Template UI – colori blu, nessuna funzionalità.</p>
    </div>
  </section>
  <section class="section">
    <div class="container">
      
<div class="card" style="max-width:520px;margin-inline:auto;">
  <form class="grid" style="grid-template-columns:1fr 1fr;" onsubmit="return false;">
    <div class="field" style="grid-column: span 2;">
      <label class="label" for="username">Username</label>
      <input class="input" id="username" type="text" placeholder="Il tuo nickname" />
    </div>
    <div class="field">
      <label class="label" for="email">Email</label>
      <input class="input" id="email" type="email" placeholder="tu@esempio.com" />
    </div>
    <div class="field">
      <label class="label" for="password">Password</label>
      <input class="input" id="password" type="password" placeholder="••••••••" />
    </div>
    <div class="field" style="grid-column: span 2;">
      <label class="label" for="tos"><input type="checkbox" id="tos"> Accetto i termini</label>
    </div>
    <div style="grid-column: span 2;">
      <button class="btn btn--primary btn--md" type="button">Crea Account</button>
    </div>
    <p class="muted" style="grid-column: span 2;">UI soltanto. Nessuna logica.</p>
  </form>
</div>
    </div>
  </section>
</main>
<?php include __DIR__ . '/../partials/footer.php'; ?>
