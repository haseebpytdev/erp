(function () {
  'use strict';

  const body = document.body;
  if (!body || !body.classList.contains('et-ui-professional')) return;

  const path = location.pathname.replace(/^\/+|\/+$/g, '').toLowerCase();
  const exactLeaf = (root, text) => Array.from(root.querySelectorAll('h1,h2,h3,h4,h5,h6,div,span,p,small,strong,label'))
    .find(node => !node.children.length && node.textContent.trim().toLowerCase() === text.toLowerCase());
  const normalizePath = value => {
    try { return new URL(value, location.origin).pathname.replace(/\/+$/g, '') || '/'; }
    catch (_) { return ''; }
  };

  const sidebarNav = document.querySelector('.sidebar .nav,.navbar-vertical .nav,.side-nav .nav');
  if (sidebarNav) {
    const links = Array.from(sidebarNav.querySelectorAll('a[href]'));
    const normalizedText = value => value.replace(/\s+/g, ' ').trim().toLowerCase();
    const linkMatches = (link, aliases) => {
      const leafLabels = Array.from(link.querySelectorAll('span,strong,b,small'))
        .filter(node => !node.children.length)
        .map(node => normalizedText(node.textContent));
      const wholeLabel = normalizedText(link.textContent);
      return aliases.some(alias => leafLabels.includes(alias) || wholeLabel === alias || wholeLabel.endsWith(` ${alias}`));
    };
    const topLevelRow = link => {
      let row = link;
      while (row.parentElement && row.parentElement !== sidebarNav) row = row.parentElement;
      return row.parentElement === sidebarNav ? row : null;
    };
    const rowLink = row => row.matches('a[href]') ? row : row.querySelector(':scope > a[href],:scope > .nav-item > a[href]');
    const findRow = aliases => {
      const link = links.find(candidate => linkMatches(candidate, aliases));
      return link && topLevelRow(link);
    };
    const findDashboardRow = () => {
      const byLabel = links.find(candidate => linkMatches(candidate, ['dashboard', 'home']));
      if (byLabel) return topLevelRow(byLabel);
      const byPath = links.find(candidate => {
        const candidatePath = normalizePath(candidate.getAttribute('href'));
        return ['/', '/dashboard', '/home'].includes(candidatePath);
      });
      return byPath && topLevelRow(byPath);
    };
    const sections = [
      ['operations', 'OPERATIONS', [['bookings'], ['sales invoices'], ['supplier costing'], ['vouchers'], ['advances']]],
      ['accounting', 'ACCOUNTING', [['chart of accounts'], ['account mappings'], ['journals'], ['ledgers'], ['reports']]],
      ['master-data', 'MASTER DATA', [['party master'], ['travel masters'], ['products & services']]],
      ['administration', 'ADMINISTRATION', [['organization'], ['currency rates'], ['financial years'], ['health & updates', 'system health & updates', 'system settings'], ['administration'], ['foundation']]],
    ];

    Array.from(sidebarNav.querySelectorAll('.nav-section,.nav-heading,.menu-title,.et-ui-nav-section'))
      .filter(node => !node.querySelector('a[href]'))
      .forEach(node => node.remove());

    const groupedRows = new Set();
    const dashboardRow = findDashboardRow();
    if (dashboardRow) {
      groupedRows.add(dashboardRow);
      dashboardRow.dataset.etSidebarGroup = 'dashboard';
      sidebarNav.appendChild(dashboardRow);
    }

    sections.forEach(([key, title, itemAliases]) => {
      const rows = [];
      itemAliases.forEach(aliases => {
        const row = findRow(aliases);
        if (row && !groupedRows.has(row)) {
          groupedRows.add(row);
          row.dataset.etSidebarGroup = key;
          rows.push(row);
        }
      });
      if (!rows.length) return;
      const heading = document.createElement('div');
      heading.classList.add('nav-section', 'et-ui-nav-section');
      heading.dataset.etSidebarSection = key;
      heading.textContent = title;
      heading.style.setProperty('display', 'block', 'important');
      heading.style.setProperty('height', 'auto', 'important');
      heading.style.setProperty('min-height', '0', 'important');
      heading.style.setProperty('max-height', 'none', 'important');
      sidebarNav.appendChild(heading);
      rows.forEach(row => sidebarNav.appendChild(row));
    });

    const remainingRows = Array.from(sidebarNav.children)
      .filter(row => row.querySelector && row.querySelector('a[href]') && !groupedRows.has(row))
      .filter(row => !row.matches('a[href]') || row !== dashboardRow);
    remainingRows.forEach(row => {
      row.dataset.etSidebarGroup = 'administration-extra';
      sidebarNav.appendChild(row);
    });

    Array.from(groupedRows).concat(remainingRows).forEach(row => {
      const parentLink = rowLink(row);
      if (!parentLink) return;
      const icon = Array.from(parentLink.children).find(child => child.matches('span,i,svg'));
      if (icon) icon.classList.add('et-ui-nav-icon');
      const controlledId = parentLink.getAttribute('aria-controls');
      const controlledMenu = controlledId && document.getElementById(controlledId);
      const hasChildren = row.querySelectorAll('a[href]').length > 1
        || Boolean(controlledMenu && controlledMenu.querySelector('a[href]'));
      if (!hasChildren) return;
      row.dataset.etSidebarChildren = 'true';
      const existingChevron = parentLink.querySelector('.et-ui-nav-chevron,[class*="chevron"]')
        || Array.from(parentLink.children).find(child => /^[⌄⌃∨∧›»]$/.test(child.textContent.trim()));
      if (existingChevron) {
        existingChevron.classList.add('et-ui-nav-chevron');
      } else {
        const chevron = document.createElement('span');
        chevron.className = 'et-ui-nav-chevron';
        chevron.setAttribute('aria-hidden', 'true');
        chevron.textContent = '⌄';
        parentLink.appendChild(chevron);
      }
    });
    sidebarNav.dataset.etSidebarGrouped = 'reference';

    const sidebar = sidebarNav.closest('.sidebar,.navbar-vertical,.side-nav');
    const footer = sidebar && sidebar.querySelector('.sidebar-foot,.sidebar-footer,footer');
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

  document.querySelectorAll('.sidebar a[href],.navbar-vertical a[href],.side-nav a[href]').forEach(link => {
    const linkPath = normalizePath(link.getAttribute('href'));
    const currentPath = location.pathname.replace(/\/+$/g, '') || '/';
    if (linkPath && linkPath === currentPath) {
      link.classList.add('et-ui-current');
      link.setAttribute('aria-current', 'page');
      link.style.setProperty('background', 'rgba(16,85,176,.72)', 'important');
      link.style.setProperty('color', '#fff', 'important');
      link.style.setProperty('border-left-color', '#60a5fa', 'important');
      link.style.setProperty('border-radius', '6px', 'important');
      link.style.setProperty('font-weight', '650', 'important');
      link.style.setProperty('box-shadow', 'none', 'important');
    }
  });

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
