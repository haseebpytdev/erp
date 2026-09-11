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

  // ERP-11.3.241 — one hidden prepaint pass only. Native row order is preserved.
  // This code annotates the existing navigation and inserts section labels in
  // place; it never appends/reorders menu rows after first paint.
  const sidebar = document.querySelector('.sidebar,.navbar-vertical,.side-nav');
  if (sidebar) {
    const navCandidates = Array.from(sidebar.querySelectorAll('.nav'));
    const rootNavs = navCandidates.filter(nav => !(nav.parentElement && nav.parentElement.closest('.nav')));
    const nestedNavs = navCandidates.filter(nav => !rootNavs.includes(nav));

    const labelParts = link => Array.from(link.querySelectorAll('span,strong,b,small'))
      .filter(node => !node.children.length)
      .map(node => normalize(node.textContent))
      .filter(Boolean);

    const exactMatch = (link, aliases) => {
      if (!link) return false;
      const parts = labelParts(link);
      const whole = normalize(link.textContent);
      return aliases.some(alias => parts.includes(alias) || whole === alias);
    };

    const primaryLink = row => {
      if (!row) return null;
      if (row.matches && row.matches('a[href]')) return row;
      return row.querySelector(':scope > a[href],:scope > .nav-item > a[href],:scope > div > a[href]');
    };

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

    rootNavs.forEach(nav => {
      Array.from(nav.querySelectorAll(':scope > .et-ui-nav-section'))
        .forEach(node => node.remove());

      const rows = Array.from(nav.children)
        .filter(row => row.matches?.('a[href]') || Boolean(row.querySelector?.('a[href]')));

      rows.forEach(row => {
        row.dataset.etSidebarLevel = 'root';
        const link = primaryLink(row);
        if (!link) return;

        const icon = Array.from(link.children).find(child => child.matches?.('span,i,svg'));
        if (icon) icon.classList.add('et-ui-nav-icon');

        const hasChildren = row.querySelectorAll('a[href]').length > 1
          || Boolean(row.querySelector('.nav,.submenu,[class*="submenu"],[class*="collapse"] a[href]'));
        if (hasChildren) {
          row.dataset.etSidebarChildren = 'true';
          const existingChevron = link.querySelector('.et-ui-nav-chevron,[class*="chevron"]')
            || Array.from(link.children).find(child => /^[⌄⌃∨∧›»]$/.test(normalize(child.textContent)));
          if (existingChevron) existingChevron.classList.add('et-ui-nav-chevron');
        }
      });

      const dashboardRow = rows.find(row => {
        const link = primaryLink(row);
        return exactMatch(link, ['dashboard', 'home'])
          || ['/', '/dashboard', '/home'].includes(normalizePath(link?.getAttribute('href')));
      });
      if (dashboardRow) dashboardRow.dataset.etSidebarGroup = 'dashboard';

      sections.forEach(([key, title, itemAliases]) => {
        const sectionRows = rows.filter(row => {
          const link = primaryLink(row);
          return itemAliases.some(aliases => exactMatch(link, aliases));
        });
        if (!sectionRows.length) return;

        sectionRows.forEach(row => {
          row.dataset.etSidebarGroup = key;
        });

        const heading = document.createElement('div');
        heading.className = 'nav-section et-ui-nav-section';
        heading.dataset.etSidebarSection = key;
        heading.textContent = title;
        nav.insertBefore(heading, sectionRows[0]);
      });

      nav.dataset.etSidebarStable = 'ERP-11.3.241';
    });

    nestedNavs.forEach(nav => {
      nav.dataset.etSidebarLevel = 'nested';
      Array.from(nav.querySelectorAll(':scope > *')).forEach(row => {
        if (row.matches?.('a[href]') || row.querySelector?.('a[href]')) {
          row.dataset.etSidebarLevel = 'nested-row';
        }
      });
    });

    const allLinks = Array.from(sidebar.querySelectorAll('a[href]'));
    let nativeActiveFound = false;

    allLinks.forEach(link => {
      const row = link.parentElement;
      const linkActive = link.classList.contains('active') || link.getAttribute('aria-current') === 'page';
      const rowActive = Boolean(row && row.classList.contains('active'));
      const activeDescendant = Boolean(row && row.querySelector('.nav a.active,.nav [aria-current="page"],.nav .active > a'));
      if (linkActive || (rowActive && !activeDescendant)) {
        link.dataset.etNativeActive = 'true';
        nativeActiveFound = true;
      }
    });

    // Exact path+query fallback only when the native server did not identify a
    // current link. Unlike the old path-only pass, this cannot light up every
    // Cash Voucher mode at once.
    if (!nativeActiveFound) {
      const currentPath = normalizePath(location.pathname);
      const currentSearch = normalizeSearch(location.search);
      const exactLocation = allLinks.find(link => {
        let linkUrl;
        try { linkUrl = new URL(link.getAttribute('href'), location.origin); }
        catch (_) { return false; }
        return normalizePath(linkUrl.pathname) === currentPath
          && normalizeSearch(linkUrl.search) === currentSearch;
      });
      if (exactLocation) exactLocation.dataset.etNativeActive = 'true';
    }

    sidebar.dataset.etSidebarStable = 'ERP-11.3.241';
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
