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

      const rows = Array.from(new Set(allLinks.map(topLevelRow).filter(Boolean)));
      const originalOrder = new Map(rows.map((row, index) => [row, index]));
      const identity = link => `${normalize(link.textContent)}|${normalizePath(link.getAttribute('href'))}?${normalizeSearch(new URL(link.href, location.origin).search)}`;
      const preferred = (a, b) => {
        const score = link => (link.classList.contains('active') || link.parentElement?.classList.contains('active') ? 3 : 0)
          + (link.closest('[data-et-live-accounting-nav]') ? 2 : 0);
        return score(a) > score(b) ? a : b;
      };
      const uniqueRows = [];
      const seen = new Map();
      rows.forEach(row => {
        const link = row.querySelector('a[href]');
        if (!link) return;
        const key = identity(link);
        if (seen.has(key)) {
          const priorRow = seen.get(key);
          const prior = priorRow.querySelector('a[href]');
          if (preferred(link, prior) === link) {
            const index = uniqueRows.indexOf(priorRow);
            if (index >= 0) {
              uniqueRows[index] = row;
              seen.set(key, row);
            }
          }
          return;
        }
        seen.set(key, row);
        uniqueRows.push(row);
      });

      const dashboardRow = uniqueRows.find(row => {
        const link = row.querySelector('a[href]');
        return link && (exactMatch(link, ['dashboard', 'home']) || ['/', '/dashboard', '/home'].includes(normalizePath(link.getAttribute('href'))));
      }) || null;
      const dashboardLink = dashboardRow && dashboardRow.querySelector('a[href]');
      const canonicalNav = dashboardLink ? rootNavFor(dashboardLink) : rootNavs[0];
      const linkForRow = row => row && row.querySelector('a[href]');

      const plan = [];

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
          const row = uniqueRows.find(candidate => {
            const link = linkForRow(candidate);
            return link && exactMatch(link, aliases);
          }) || null;
          if (row && !plan.some(item => item.rows?.includes(row)) && !sectionRows.includes(row)) sectionRows.push(row);
        });
        if (sectionRows.length) plan.push({ key, title, rows: sectionRows });
      });

      const claimed = new Set(plan.flatMap(item => item.rows));
      if (dashboardRow) claimed.add(dashboardRow);
      const remaining = uniqueRows.filter(row => !claimed.has(row)).sort((a,b) => originalOrder.get(a)-originalOrder.get(b));
      if (remaining.length) plan.push({ key: 'remaining', title: '', rows: remaining });
      const validPlan = plan.every(item => item.rows.every(row => row && row.querySelector('a[href]')));
      if (validPlan && canonicalNav) {
        const fragment = document.createDocumentFragment();
        const cloneRowForFinalNav = (row, key) => {
          const clone = row.cloneNode(true);
          clone.dataset.etSidebarGroup = key;
          clone.classList.add('et-ui-nav-row');
          return clone;
        };
        if (dashboardRow) {
          fragment.appendChild(cloneRowForFinalNav(dashboardRow, 'dashboard'));
        }
        plan.forEach(({ key, title, rows: sectionRows }) => {
          const heading = document.createElement('div');
          heading.className = 'nav-section et-ui-nav-section';
          heading.dataset.etSidebarSection = key;
          if (title) heading.textContent = title;
          if (title) fragment.appendChild(heading);
          const group = document.createElement('div');
          group.className = 'et-ui-nav-group';
          group.dataset.etSidebarSectionGroup = key;
          group.dataset.etSidebarGroup = key;
          sectionRows.forEach(row => {
            group.appendChild(cloneRowForFinalNav(row, key));
          });
          fragment.appendChild(group);
        });
        canonicalNav.replaceChildren(fragment);
        canonicalNav.dataset.etSidebarGrouped = 'final-v1';
        body.dataset.etSidebarNormalization = 'committed';
        rootNavs.forEach(nav => {
          if (nav !== canonicalNav) nav.classList.add('et-ui-nav-root-empty');
        });
      } else {
        body.dataset.etSidebarNormalization = 'failed';
      }

      // The base professional script historically marked every link sharing the
      // current pathname as selected. Cash voucher workspaces share one path and
      // differ only by ?type= / ?mode=, so Receipts, Payments, Expense and Contra
      // could all appear active together. Native server-side .active remains
      // authoritative; otherwise require an exact path + query-string match.
      const currentPath = normalizePath(location.pathname);
      const currentSearch = normalizeSearch(location.search);
      const committedLinks = canonicalNav ? Array.from(canonicalNav.querySelectorAll('a[href]')) : [];
      committedLinks.forEach(link => {
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
    if (sidebar.querySelector('[data-et-sidebar-grouped="final-v1"]')) body.dataset.etSidebarReady = 'true';
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
