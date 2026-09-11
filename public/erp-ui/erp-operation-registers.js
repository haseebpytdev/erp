(function () {
  'use strict';

  var path = String(window.location.pathname || '')
    .replace(/^\/+|\/+$/g, '')
    .toLowerCase();

  var pages = {
    'operations/bookings': 'bookings',
    'sales/invoices': 'sales-invoices',
    'supplier-costing': 'supplier-costing'
  };

  var page = pages[path];
  if (!page) return;

  var html = document.documentElement;
  var body = document.body;
  if (!html || !body) return;

  html.classList.add('et-register-workspace', 'et-register-' + page);
  body.setAttribute('data-et-operation-register', page);

  var first = function (selectors, scope) {
    var root = scope || document;
    for (var i = 0; i < selectors.length; i++) {
      var found = root.querySelector(selectors[i]);
      if (found) return found;
    }
    return null;
  };

  var root = first([
    'main.content',
    'section.content',
    '.page-body > .container-xl',
    '.page-body > .container',
    '.main-content',
    '.content-wrapper main',
    'main',
    '.sci'
  ]) || body;

  root.classList.add('et-reg-shell');

  var wantedTitles = {
    'bookings': ['booking register', 'bookings'],
    'sales-invoices': ['sales invoice register', 'sales invoices'],
    'supplier-costing': ['supplier costing']
  }[page] || [];

  var headings = Array.prototype.slice.call(
    root.querySelectorAll('h1,h2')
  );

  var title = headings.find(function (node) {
    var text = String(node.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
    return wantedTitles.some(function (candidate) {
      return text === candidate || text.indexOf(candidate) !== -1;
    });
  }) || headings[0] || null;

  if (title) {
    title.classList.add('et-reg-title');

    var titleBlock = title.parentElement;
    if (titleBlock) {
      titleBlock.classList.add('et-reg-title-block');
    }

    var header = title.closest(
      '.sci-head,.br-top,.page-header,.d-flex.justify-content-between,.d-flex.align-items-center'
    );

    if (!header && titleBlock && titleBlock.parentElement) {
      var parent = titleBlock.parentElement;
      var hasAction = parent.querySelector(
        'a[href*="/create"],button,a.btn,.sci-primary'
      );
      if (hasAction) header = parent;
    }

    if (!header) header = titleBlock;
    if (header) header.classList.add('et-reg-header');
  }

  var actionMatchers = {
    'bookings': ['new booking'],
    'sales-invoices': ['new sales invoice', 'new invoice', 'create invoice'],
    'supplier-costing': ['new supplier cost', 'new supplier costing']
  }[page] || [];

  Array.prototype.slice.call(
    root.querySelectorAll('a,button')
  ).forEach(function (node) {
    var text = String(node.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
    if (actionMatchers.some(function (candidate) { return text.indexOf(candidate) !== -1; })) {
      node.classList.add('et-reg-primary-action');
    }
  });

  Array.prototype.slice.call(root.querySelectorAll('form')).forEach(function (form) {
    var method = String(form.getAttribute('method') || 'get').toLowerCase();
    var hasControls = !!form.querySelector('input:not([type="hidden"]),select');
    if (method === 'get' && hasControls) {
      form.classList.add('et-reg-filter');
    }
  });

  var tables = Array.prototype.slice.call(root.querySelectorAll('table'));
  tables.forEach(function (table) {
    if (table.classList.contains('ui-datepicker-calendar')) return;

    table.classList.add('et-reg-table');

    var card = table.closest(
      '.card,.sci-card,.table-responsive,.card-body,.table-container,.table-wrapper'
    );

    if (!card) card = table.parentElement;
    if (card) card.classList.add('et-reg-table-card');
  });

  var metricCandidates = Array.prototype.slice.call(
    root.querySelectorAll('.card,.stat-card,.summary-card,.metric-card,.kpi-card')
  );

  metricCandidates.forEach(function (card) {
    if (card.querySelector('table,form')) return;

    var text = String(card.textContent || '').replace(/\s+/g, ' ').trim();
    if (!text || text.length > 160) return;
    if (!/\d/.test(text)) return;

    card.classList.add('et-reg-metric');
  });

  var statusNodes = Array.prototype.slice.call(
    root.querySelectorAll('.badge,.status,.status-badge,.sci-badge,td span')
  );

  statusNodes.forEach(function (node) {
    var text = String(node.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
    if (!text || text.length > 40) return;

    var className = '';

    if (text === 'draft') {
      className = 'et-reg-status-draft';
    } else if (
      text.indexOf('pending') !== -1
      || text.indexOf('awaiting') !== -1
    ) {
      className = 'et-reg-status-pending';
    } else if (
      text.indexOf('confirmed') !== -1
      || text.indexOf('posted') !== -1
      || text.indexOf('approved') !== -1
      || text.indexOf('paid') !== -1
    ) {
      className = 'et-reg-status-success';
    } else if (
      text.indexOf('cancelled') !== -1
      || text.indexOf('canceled') !== -1
      || text.indexOf('reversed') !== -1
      || text.indexOf('void') !== -1
    ) {
      className = 'et-reg-status-danger';
    } else if (
      text.indexOf('partial') !== -1
      || text.indexOf('open') !== -1
    ) {
      className = 'et-reg-status-info';
    }

    if (className) {
      node.classList.add('et-reg-status', className);
    }
  });

  tables.forEach(function (table) {
    Array.prototype.slice.call(table.querySelectorAll('tbody tr')).forEach(function (row) {
      var links = Array.prototype.slice.call(row.querySelectorAll('a'));
      links.forEach(function (link) {
        var text = String(link.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
        if (
          text === 'open'
          || text === 'view'
          || text === 'edit'
          || text === 'details'
          || text === '⋮'
          || text === '...'
        ) {
          link.classList.add('et-reg-row-action');
        }
      });
    });
  });
})();
