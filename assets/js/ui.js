document.addEventListener('DOMContentLoaded', () => {
  const btn = document.querySelector('[data-toggle="mobile-menu"]');
  const menu = document.querySelector('[data-role="mobile-menu"]');
  if (btn && menu) {
    btn.addEventListener('click', () => menu.classList.toggle('open'));
  }
});