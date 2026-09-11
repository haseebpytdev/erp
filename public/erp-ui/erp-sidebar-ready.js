(function () {
  'use strict';

  const body = document.body;
  if (!body || !body.classList.contains('et-ui-professional')) return;

  body.dataset.etSidebarReady = 'true';
})();
