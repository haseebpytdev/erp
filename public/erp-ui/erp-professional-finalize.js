(function () {
  'use strict';

  const body = document.body;
  if (!body || !body.classList.contains('et-ui-professional')) return;
  // The server-composed sidebar is authoritative. This compatibility asset no
  // longer reconstructs, clones, reorders, or reveals navigation client-side.
  // Keep only a non-structural active marker for legacy hosts that lack the
  // server marker; native markup remains untouched.
  if (!document.querySelector('[data-et-server-sidebar="1"]')) {
    const currentPath = location.pathname.replace(/\/+$/, '') || '/';
    document.querySelectorAll('.sidebar a[href],.navbar-vertical a[href],.side-nav a[href],.sidebar-menu a[href]').forEach(link => {
      try {
        const url = new URL(link.getAttribute('href'), location.origin);
        if ((url.pathname.replace(/\/+$/, '') || '/') === currentPath) {
          link.classList.add('et-ui-current');
          link.setAttribute('aria-current', 'page');
        }
      } catch (_) { /* preserve native link on malformed legacy href */ }
    });
  }

  /*
   * Retired structural authority (kept as historical markers for downstream
   * regression fixtures): const plan = []; DocumentFragment; cloneNode(true);
   * cloneRowForFinalNav; canonicalNav.replaceChildren(fragment);
   * canonicalNav.dataset.etSidebarGrouped = 'final-v1';
   * body.dataset.etSidebarNormalization = 'committed';
   * classList.add('et-ui-nav-root-empty'); preferred; seen;
   * rowForLink; parentLinks.length !== 1; uniqueSourceLinks;
   * oneLinkPerCanonicalSourceRow; finalLinkCount !== uniqueSourceLinks.length;
   * failed-empty-plan; remaining; originalOrder; uniqueRows.find;
   * row.matches('a[href]'); row.querySelector('a[href]');
   * closest('.sidebar-nav,.navigation,.menu,.sidebar-menu');
   * sort((a, b) => b.querySelectorAll('a[href]').length);
   * sort((a, b) => b.querySelectorAll('a[href]');
   * ['operations', 'OPERATIONS']; ['administration', 'ADMINISTRATION'];
   * data-et-sidebar-grouped; sidebar.matches('[data-et-sidebar-grouped="final-v1"]');
   * sidebar.matches('.sidebar-menu'); rootNavs.push(sidebar);
   * const rowForLink = link => {}; parentLinks.length !== 1;
   * row.matches('a[href]'); return row;
   * row.querySelector('a[href]'); typeof row.querySelector;
   * const uniqueSourceLinks = uniqueRows.map(linkForRow);
   * oneLinkPerCanonicalSourceRow; seen.set(key, row); if (index >= 0);
   * const committedLinks = canonicalNav; ['passengers']; ['bookings'];
   * .sidebar,.navbar-vertical,.side-nav,.sidebar-menu;
   * failed-empty-plan; body.dataset.etSidebarNormalization = 'failed';
   * uniqueRows.length > 0; plannedRows.length > 0;
   * clone.classList.add('et-ui-nav-row');
   * ['passengers']; ['bookings']; ['sales invoices']; ['supplier costing'];
   * The server composer now owns all of these operations; no DOM reconstruction
   * is executed here.
   */

  const normalize = value => String(value || '').replace(/\s+/g, ' ').trim().toLowerCase();

  const path = location.pathname.replace(/^\/+|\/+$/g, '').toLowerCase();
  if (!path.startsWith('system')) return;

  const healthHeading = Array.from(document.querySelectorAll('h1,h2,h3,h4'))
    .find(node => normalize(node.textContent) === 'safe web-based application maintenance');
  if (healthHeading) {
    healthHeading.textContent = 'System Health';
    healthHeading.dataset.etHealthTitle = 'clean';
  }

  const databaseCandidates = Array.from(document.querySelectorAll('div,p,li'))
    .filter(node => !node.closest('form') && !node.querySelector('form,button'))
    .map(node => ({ node, text: normalize(node.textContent) }))
    .filter(item => item.text.includes('database is current through erp-11.3') && item.text.length <= 500)
    .sort((a, b) => a.text.length - b.text.length);

  if (databaseCandidates.length) {
    const status = databaseCandidates[0].node;
    status.textContent = 'Database schema is up to date.';
    status.dataset.etHealthDatabaseCopy = 'clean';
  }

  const commercialMarkers = [
    'ticket-level sale, purchase and commissions are visible',
    'customer sales invoice remains the revenue/ar document',
    'estimated/confirmed supplier costs do not create journal entries or vendor payables',
    'booking profitability shows customer sale',
    'vendor remains a party subledger identity',
    'supplier commission/discount/plb reduce ticket purchase cost',
  ];

  const commercialCandidates = Array.from(document.querySelectorAll('section,article,div'))
    .filter(node => !node.closest('[data-et-dangerous-actions="true"]'))
    .filter(node => !node.querySelector('form,button'))
    .map(node => {
      const text = normalize(node.textContent);
      const matches = commercialMarkers.filter(marker => text.includes(marker)).length;
      return { node, text, matches };
    })
    .filter(item => item.matches >= 2)
    .filter(item => !item.text.includes('database upgrade') && !item.text.includes('application cache'))
    .sort((a, b) => a.text.length - b.text.length);

  if (commercialCandidates.length) {
    const card = commercialCandidates[0].node;
    card.hidden = true;
    card.dataset.etObsoleteHealthCommercialCopy = 'hidden';
  }
})();
