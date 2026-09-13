(function () {
  'use strict';

  const body = document.body;
  if (!body || !body.classList.contains('et-ui-professional')) return;

  const sidebar = document.querySelector('.sidebar,.navbar-vertical,.side-nav,.sidebar-menu');
  const canonical = sidebar && (sidebar.matches('[data-et-sidebar-grouped="final-v1"]')
    || sidebar.querySelector('[data-et-sidebar-grouped="final-v1"]'));
  if (!sidebar || canonical) body.dataset.etSidebarReady = 'true';
})();
