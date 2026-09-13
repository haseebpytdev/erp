(function () {
  'use strict';

  const body = document.body;
  if (!body || !body.classList.contains('et-ui-professional')) return;

  const sidebar = document.querySelector('.sidebar,.navbar-vertical,.side-nav');
  const canonical = sidebar && sidebar.querySelector('[data-et-sidebar-grouped="final-v1"]');
  if (!sidebar || canonical) body.dataset.etSidebarReady = 'true';
})();
