(function () {
  'use strict';

  const body = document.body;
  if (!body || !body.classList.contains('et-ui-professional')) return;

  const normalize = value => String(value || '').replace(/\s+/g, ' ').trim().toLowerCase();
  const normalizePath = value => {
    try { return new URL(value, location.origin).pathname.replace(/\/+$/g, '') || '/'; }
    catch (_) { return ''; }
  };
  const normalizeSearch = value => {
    const params = new URLSearchParams(String(value || '').replace(/^\?/, ''));
    return Array.from(params.entries())
      .sort(([ak, av], [bk, bv]) => ak.localeCompare(bk) || av.localeCompare(bv))
      .map(([key, val]) => `${encodeURIComponent(key)}=${encodeURIComponent(val)}`)
      .join('&');
  };

  const sidebar = document.querySelector('.sidebar,.navbar-vertical,.side-nav');
  if (sidebar) {
    const navCandidates = Array.from(sidebar.querySelectorAll('.nav'));
    const rootNavs = navCandidates.filter(nav => !(nav.parentElement && nav.parentElement.closest('.nav')));

    if (rootNavs.length) {
      const allLinks = Array.from(sidebar.querySelectorAll('a[href]'))
        .filter(link => rootNavs.some(nav => nav.contains(link)));

      const labelParts = link => Array.from(link.querySelectorAll('span,strong,b,small'))
        .filter(node => !node.children.length)
        .map(node => normalize(node.textContent))
        .filter(Boolean);

      const exactMatch = (link, aliases) => {
        const parts = labelParts(link);
        const whole = normalize(link.textContent);
        return aliases.some(alias => parts.includes(alias) || whole === alias);
      };

      const rootNavFor = link => rootNavs.find(nav => nav.contains(link)) || null;
      const topLevelRow = link => {
        const rootNav = rootNavFor(link);
        if (!rootNav) return null;
        let row = link;
        while (row.parentElement && row.parentElement !== rootNav) row = row.parentElement;
        return row.parentElement === rootNav ? row : null;
      };

      const rowForAliases = aliases => {
        const link = allLinks.find(candidate => exactMatch(candidate, aliases));
        return link ? topLevelRow(link) : null;
      };

      const dashboardLink = allLinks.find(link => exactMatch(link, ['dashboard', 'home']))
        || allLinks.find(link => ['/', '/dashboard', '/home'].includes(normalizePath(link.getAttribute('href'))));
      const dashboardRow = dashboardLink ? topLevelRow(dashboardLink) : null;
      const canonicalNav = dashboardLink ? rootNavFor(dashboardLink) : rootNavs[0];

      const rows = Array.from(new Set(allLinks.map(topLevelRow).filter(Boolean)));

      rootNavs.forEach(nav => {
        Array.from(nav.querySelectorAll(':scope > .nav-section,:scope > .nav-heading,:scope > .menu-title,:scope > .et-ui-nav-section'))
          .filter(node => !node.querySelector('a[href]'))
          .forEach(node => node.remove());
      });

      const grouped = new Set();
      const appendRow = row => {
        if (!row || grouped.has(row)) return false;
        grouped.add(row);
        canonicalNav.appendChild(row);
        return true;
      };

      if (dashboardRow) {
        dashboardRow.dataset.etSidebarGroup = 'dashboard';
        appendRow(dashboardRow);
      }

      const sections = [
        ['operations', 'OPERATIONS', [
          ['bookings'],
          ['sales invoices'],
          ['supplier costing'],
        ]],
        ['accounting', 'ACCOUNTING', [
          ['vouchers'],
          ['receipts'],
          ['payments'],
          ['expense vouchers'],
          ['contra vouchers'],
          ['advances'],
          ['customer advances'],
          ['supplier advances'],
          ['advance adjustments', 'advance adjustment'],
          ['chart of accounts'],
          ['account mappings'],
          ['journals'],
          ['ledgers'],
          ['reports'],
        ]],
        ['master-data', 'MASTER DATA', [
          ['party master'],
          ['travel masters'],
          ['products & services'],
        ]],
        ['administration', 'ADMINISTRATION', [
          ['organization'],
          ['currency rates'],
          ['financial years'],
          ['health & updates', 'system health & updates', 'system settings'],
          ['administration'],
          ['foundation'],
        ]],
      ];

      sections.forEach(([key, title, itemAliases]) => {
        const sectionRows = [];
        itemAliases.forEach(aliases => {
          const row = rowForAliases(aliases);
          if (row && !grouped.has(row)) sectionRows.push(row);
        });
        if (!sectionRows.length) return;

        const heading = document.createElement('div');
        heading.className = 'nav-section et-ui-nav-section';
        heading.dataset.etSidebarSection = key;
        heading.textContent = title;
        heading.style.setProperty('display', 'block', 'important');
        heading.style.setProperty('height', 'auto', 'important');
        heading.style.setProperty('min-height', '0', 'important');
        heading.style.setProperty('max-height', 'none', 'important');
        canonicalNav.appendChild(heading);

        sectionRows.forEach(row => {
          row.dataset.etSidebarGroup = key;
          appendRow(row);
        });
      });

      rows.forEach(row => {
        if (grouped.has(row)) return;
        row.dataset.etSidebarGroup = 'remaining';
        appendRow(row);
      });

      rootNavs.forEach(nav => {
        if (nav !== canonicalNav && !nav.querySelector('a[href]')) nav.style.display = 'none';
      });

      canonicalNav.dataset.etSidebarGrouped = 'reference-v2';

      // The base professional script historically marked every link sharing the
      // current pathname as selected. Cash voucher workspaces share one path and
      // differ only by ?type= / ?mode=, so Receipts, Payments, Expense and Contra
      // could all appear active together. Native server-side .active remains
      // authoritative; otherwise require an exact path + query-string match.
      const currentPath = normalizePath(location.pathname);
      const currentSearch = normalizeSearch(location.search);
      allLinks.forEach(link => {
        let linkUrl;
        try { linkUrl = new URL(link.getAttribute('href'), location.origin); }
        catch (_) { return; }

        const nativeActive = link.classList.contains('active')
          || Boolean(link.parentElement && link.parentElement.classList.contains('active'));
        const exactLocation = normalizePath(linkUrl.pathname) === currentPath
          && normalizeSearch(linkUrl.search) === currentSearch;
        const shouldBeCurrent = nativeActive || exactLocation;
        const wasUiCurrent = link.classList.contains('et-ui-current');

        if (shouldBeCurrent) {
          link.classList.add('et-ui-current');
          link.setAttribute('aria-current', 'page');
          return;
        }

        link.classList.remove('et-ui-current');
        if (link.getAttribute('aria-current') === 'page') link.removeAttribute('aria-current');
        if (wasUiCurrent && !nativeActive) {
          ['background', 'color', 'border-left-color', 'border-radius', 'font-weight', 'box-shadow']
            .forEach(property => link.style.removeProperty(property));
        }
      });
    }
  }

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
