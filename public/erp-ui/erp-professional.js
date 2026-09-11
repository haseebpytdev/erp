(function () {
  'use strict';

  const body = document.body;
  if (!body || !body.classList.contains('et-ui-professional')) return;

  const path = location.pathname.replace(/^\/+|\/+$/g, '').toLowerCase();
  const normalizePath = value => {
    try { return new URL(value, location.origin).pathname.replace(/\/+$/g, '') || '/'; }
    catch (_) { return ''; }
  };

  document.querySelectorAll('.sidebar a[href],.navbar-vertical a[href],.side-nav a[href]').forEach(link => {
    const linkPath = normalizePath(link.getAttribute('href'));
    const currentPath = location.pathname.replace(/\/+$/g, '') || '/';
    if (linkPath && linkPath === currentPath) {
      link.classList.add('et-ui-current');
      link.setAttribute('aria-current', 'page');
    }
  });

  if (path !== '' && path !== 'dashboard') {
    const shell = document.querySelector('.topbar,.top-bar,.app-header,.main-header,.navbar-horizontal');
    if (shell) {
      Array.from(shell.querySelectorAll('h1,h2,h3,strong,span,div')).some(node => {
        if (node.children.length || node.textContent.trim().toLowerCase() !== 'dashboard') return false;
        node.textContent = 'Easy Ticket ERP';
        return true;
      });
    }
  }

  const statuses = new Set([
    'draft','pending approval','approved','posted','travel ready','reopened',
    'rejected','reversed','cancelled','issued','unissued'
  ]);
  document.querySelectorAll('.badge,[class*="badge"],[class*="status"],.tag,.pill').forEach(node => {
    if (node.children.length > 2) return;
    const value = node.textContent.replace(/[_\s]+/g, ' ').trim().toLowerCase();
    if (statuses.has(value)) node.dataset.etStatus = value;
  });
})();
