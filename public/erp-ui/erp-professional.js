(function () {
  'use strict';

  const body = document.body;
  if (!body || !body.classList.contains('et-ui-professional')) return;

  const path = location.pathname.replace(/^\/+|\/+$/g, '').toLowerCase();
  const exactLeaf = (root, text) => Array.from(root.querySelectorAll('h1,h2,h3,h4,h5,h6,div,span,p,small,strong,label'))
    .find(node => !node.children.length && node.textContent.trim().toLowerCase() === text.toLowerCase());

  // ERP-11.3.241: sidebar row ordering, section insertion and active-state
  // normalization are intentionally NOT performed here. The sidebar stays
  // hidden behind the prepaint guard until the single deterministic finalizer
  // pass completes. This prevents the double regroup/reflow seen in ERP-11.3.240.
  const sidebar = document.querySelector('.sidebar,.navbar-vertical,.side-nav');
  if (sidebar) {
    const footer = sidebar.querySelector('.sidebar-foot,.sidebar-footer,footer');
    if (footer && !footer.querySelector('.et-ui-live-badge')) {
      const releaseLine = Array.from(footer.querySelectorAll('div,p,span,small'))
        .find(node => !node.children.length && /ERP-\d+(?:\.\d+)+/i.test(node.textContent));
      if (releaseLine) {
        releaseLine.classList.add('et-ui-sidebar-release');
        const liveBadge = document.createElement('span');
        liveBadge.className = 'et-ui-live-badge';
        liveBadge.textContent = 'Live';
        releaseLine.appendChild(liveBadge);
      }
    }
  }

  const shell = document.querySelector('.topbar,.top-bar,.app-header,.main-header,.navbar-horizontal');
  if (shell) {
    shell.classList.add('et-ui-utility-topbar');
    const shellDashboard = exactLeaf(shell, 'Dashboard');
    if (shellDashboard) shellDashboard.textContent = 'Easy Ticket ERP';
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

  const isDashboard = path === '' || path === 'dashboard';
  if (isDashboard) {
    body.dataset.etDashboardPhase1 = 'approved-reference';

    const overview = exactLeaf(document, 'Management Overview');
    if (overview && !overview.closest('.sidebar,.navbar-vertical,.side-nav,.et-ui-utility-topbar')) {
      let header = overview.parentElement;
      for (let depth = 0; header && depth < 4; depth++, header = header.parentElement) {
        const companyTitle = exactLeaf(header, 'Easy Group Of Travels');
        if (!companyTitle) continue;
        let headerCanvas = header.closest('.card,[class*="card"],section,article') || header;
        if (header.parentElement && header.parentElement !== body && header.parentElement.textContent.length < 900 && header.parentElement.querySelector('a,button')) {
          headerCanvas = header.parentElement;
        }
        headerCanvas.dataset.etDashboardHeader = 'true';
        overview.textContent = 'Overview';
        companyTitle.textContent = 'Dashboard';
        break;
      }
    }

    const kpiLabels = ['Today Sales','Month Sales','Receivables','Payables','Cash & Bank','Gross Profit'];
    const kpiCards = [];
    kpiLabels.forEach(label => {
      const labelNode = exactLeaf(document, label);
      if (!labelNode || labelNode.closest('.sidebar,.navbar-vertical,.side-nav,.et-ui-utility-topbar')) return;
      let card = labelNode.parentElement;
      for (let depth = 0; card && depth < 5; depth++, card = card.parentElement) {
        const content = card.textContent.replace(/\s+/g, ' ').trim();
        const labelsInside = kpiLabels.filter(item => content.toLowerCase().includes(item.toLowerCase())).length;
        if (labelsInside === 1 && /PKR\s/i.test(content)) break;
      }
      if (!card || card === body) return;
      card.classList.add('et-dashboard-kpi');
      card.dataset.etDashboardKpi = label.toLowerCase().replace(/[^a-z]+/g, '-').replace(/^-|-$/g, '');
      card.querySelectorAll('div,span,strong,p').forEach(node => {
        if (!node.children.length && /^PKR\s/i.test(node.textContent.trim())) node.classList.add('et-dashboard-money');
      });
      kpiCards.push(card);
    });

    const parentCounts = new Map();
    kpiCards.forEach(card => {
      const parent = card.parentElement;
      if (parent) parentCounts.set(parent, (parentCounts.get(parent) || 0) + 1);
    });
    const kpiGrid = Array.from(parentCounts.entries()).sort((a, b) => b[1] - a[1])[0];
    if (kpiGrid && kpiGrid[1] >= 4) kpiGrid[0].classList.add('et-dashboard-kpi-grid');

    [
      ['Financial Trend','trend'],
      ['Product Mix','mix'],
      ['Next 7 Days','travel'],
      ['Attention','attention'],
      ['Outstanding','outstanding'],
      ['Latest','latest'],
      ['Activity','activity'],
      ['Shortcuts','shortcuts'],
    ].forEach(([title, key]) => {
      const titleNode = exactLeaf(document, title);
      const card = titleNode && titleNode.closest('.card,[class*="card"],section,article');
      if (card && !card.closest('.sidebar,.navbar-vertical,.side-nav')) card.dataset.etDashboardSection = key;
    });
  }

  const healthTitle = exactLeaf(document, 'System Health & Updates');
  if (healthTitle && path.startsWith('system')) {
    body.dataset.etSystemHealthPhase1 = 'true';
    const obsoleteHealthCopy = [
      'no-ssh maintenance',
      'this page exists because the hosting has no ssh or terminal',
      'erp-10.1 ticket commercial boundary',
      'no gl yet',
      'future vendor bill/ap workflow',
      '2110 later',
    ];
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    const textNodes = [];
    while (walker.nextNode()) textNodes.push(walker.currentNode);
    textNodes.forEach(textNode => {
      const value = textNode.nodeValue.replace(/\s+/g, ' ').trim().toLowerCase();
      const namedObsoleteCopy = obsoleteHealthCopy.some(copy => value.includes(copy));
      const legacyReleaseCopy = /\berp-10(?:\.\d+){0,2}\b/.test(value) && value.length < 240;
      const futurePayableCopy = value.includes('vendor bill') && (value.includes('future') || value.includes('later'));
      const deferredAccountCopy = value.includes('2110') && value.includes('later');
      if (!value || (!namedObsoleteCopy && !legacyReleaseCopy && !futurePayableCopy && !deferredAccountCopy)) return;
      let target = textNode.parentElement;
      if (!target || target.closest('a,button,form')) return;
      const bounded = target.closest('p,li,small,h1,h2,h3,h4,h5,h6') || (target.children.length === 0 ? target : null);
      if (!bounded || bounded.closest('form')) return;
      bounded.hidden = true;
      bounded.dataset.etObsoleteHealthCopy = 'hidden';
    });

    const maintenanceCopy = Array.from(document.querySelectorAll('p,div,small'))
      .find(node => !node.children.length && node.textContent.toLowerCase().includes('safe web-based application maintenance'));
    if (maintenanceCopy) maintenanceCopy.textContent = 'Application and database status.';

    const healthCards = [];
    ['Application','Database','Report Header','Runtime'].forEach(label => {
      const labelNode = exactLeaf(document, label);
      if (!labelNode || labelNode.closest('.sidebar,.navbar-vertical,.side-nav,.et-ui-utility-topbar')) return;
      const card = labelNode.closest('.card,[class*="card"],article,section') || labelNode.parentElement;
      if (!card || card === body || card.querySelector('form')) return;
      card.classList.add('et-health-status');
      healthCards.push(card);
    });
    const healthParents = new Map();
    healthCards.forEach(card => healthParents.set(card.parentElement, (healthParents.get(card.parentElement) || 0) + 1));
    const healthGrid = Array.from(healthParents.entries()).sort((a, b) => b[1] - a[1])[0];
    if (healthGrid && healthGrid[0] && healthGrid[1] >= 3) healthGrid[0].classList.add('et-health-status-grid');

    ['Database Maintenance','Application Cache'].forEach(label => {
      const labelNode = exactLeaf(document, label);
      const section = labelNode && (labelNode.closest('.card,[class*="card"],article,section') || labelNode.parentElement);
      if (section && section !== body) section.dataset.etHealthSection = label.toLowerCase().replace(/\s+/g, '-');
    });
  }
})();
