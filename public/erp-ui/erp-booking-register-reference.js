(function () {
  'use strict';

  var path = String(window.location.pathname || '')
    .replace(/^\/+|\/+$/g, '')
    .toLowerCase();

  if (path !== 'operations/bookings') return;

  var html = document.documentElement;
  var body = document.body;
  if (!html || !body) return;

  if (html.dataset.etBookingRegisterReference === 'ERP-11.3.237') return;
  html.dataset.etBookingRegisterReference = 'ERP-11.3.237';
  html.classList.add('et-booking-register-reference');

  var clean = function (value) {
    return String(value || '').replace(/\s+/g, ' ').trim();
  };

  var lower = function (value) {
    return clean(value).toLowerCase();
  };

  var root = document.querySelector('.et-reg-shell')
    || document.querySelector('main.content')
    || document.querySelector('section.content')
    || document.querySelector('.page-body > .container-xl')
    || document.querySelector('.page-body > .container')
    || document.querySelector('.main-content')
    || document.querySelector('.content-wrapper main')
    || document.querySelector('main')
    || body;

  var table = Array.prototype.slice.call(root.querySelectorAll('table')).find(function (candidate) {
    var labels = Array.prototype.slice.call(candidate.querySelectorAll('thead th')).map(function (th) {
      return lower(th.textContent);
    });
    return labels.some(function (label) { return label.indexOf('booking') !== -1; })
      && labels.some(function (label) { return label === 'status' || label.indexOf('status') !== -1; });
  });

  if (!table || !table.tBodies || !table.tBodies.length) return;

  var originalRows = Array.prototype.slice.call(table.tBodies[0].rows);
  if (!originalRows.length) return;

  var headers = Array.prototype.slice.call(table.querySelectorAll('thead th'));
  var headerIndex = {
    booking: -1,
    date: -1,
    customer: -1,
    type: -1,
    passengers: -1,
    services: -1,
    branch: -1,
    status: -1
  };

  headers.forEach(function (th, index) {
    var label = lower(th.textContent);
    if (headerIndex.booking < 0 && label.indexOf('booking') !== -1) headerIndex.booking = index;
    if (headerIndex.date < 0 && label.indexOf('date') !== -1) headerIndex.date = index;
    if (headerIndex.customer < 0 && label.indexOf('customer') !== -1) headerIndex.customer = index;
    if (headerIndex.type < 0 && label === 'type') headerIndex.type = index;
    if (headerIndex.passengers < 0 && label.indexOf('passenger') !== -1) headerIndex.passengers = index;
    if (headerIndex.services < 0 && label.indexOf('service') !== -1) headerIndex.services = index;
    if (headerIndex.branch < 0 && label.indexOf('branch') !== -1) headerIndex.branch = index;
    if (headerIndex.status < 0 && label.indexOf('status') !== -1) headerIndex.status = index;
  });

  if (headerIndex.booking < 0 || headerIndex.status < 0) return;

  var monthMap = {
    jan: 0, feb: 1, mar: 2, apr: 3, may: 4, jun: 5,
    jul: 6, aug: 7, sep: 8, oct: 9, nov: 10, dec: 11
  };

  var parseRegisterDate = function (text) {
    var match = clean(text).match(/\b(\d{1,2})\s+([A-Za-z]{3})\s+(\d{4})\b/);
    if (!match) return null;
    var month = monthMap[String(match[2]).toLowerCase()];
    if (typeof month !== 'number') return null;
    var value = new Date(Number(match[3]), month, Number(match[1]));
    return isNaN(value.getTime()) ? null : value;
  };

  var firstReadableLine = function (cell) {
    if (!cell) return '';
    var preferred = cell.querySelector('strong,b,[data-name]');
    if (preferred && clean(preferred.textContent)) return clean(preferred.textContent);
    var lines = String(cell.innerText || cell.textContent || '').split(/\n+/).map(clean).filter(Boolean);
    return lines[0] || clean(cell.textContent);
  };

  var statusKey = function (text) {
    var value = lower(text);
    if (value.indexOf('cancel') !== -1 || value.indexOf('void') !== -1 || value.indexOf('revers') !== -1) return 'cancelled';
    if (value.indexOf('confirm') !== -1 || value.indexOf('approved') !== -1 || value.indexOf('posted') !== -1) return 'confirmed';
    if (value.indexOf('pending') !== -1 || value.indexOf('await') !== -1) return 'pending';
    if (value.indexOf('closed') !== -1 || value.indexOf('complete') !== -1 || value.indexOf('travel ready') !== -1) return 'closed';
    return 'draft';
  };

  var itemFromRow = function (row, order) {
    var cells = Array.prototype.slice.call(row.children);
    var bookingCell = cells[headerIndex.booking] || null;
    var dateCell = headerIndex.date >= 0 ? cells[headerIndex.date] : null;
    var customerCell = headerIndex.customer >= 0 ? cells[headerIndex.customer] : null;
    var typeCell = headerIndex.type >= 0 ? cells[headerIndex.type] : null;
    var statusCell = cells[headerIndex.status] || null;
    var bookingMatch = clean(row.textContent).match(/BK-\d{4}-\d{6}/i);
    var bookingLink = bookingCell
      ? Array.prototype.slice.call(bookingCell.querySelectorAll('a[href]')).find(function (link) {
          return /\/operations\/bookings\/\d+/i.test(String(link.getAttribute('href') || ''));
        })
      : null;
    var invoiceLink = bookingCell
      ? Array.prototype.slice.call(bookingCell.querySelectorAll('a[href]')).find(function (link) {
          return /\/sales\/invoices\//i.test(String(link.getAttribute('href') || ''));
        })
      : null;

    return {
      row: row,
      order: order,
      booking: bookingMatch ? bookingMatch[0].toUpperCase() : firstReadableLine(bookingCell),
      bookingHref: bookingLink ? bookingLink.href : '',
      invoiceHref: invoiceLink ? invoiceLink.href : '',
      date: parseRegisterDate(dateCell ? dateCell.textContent : ''),
      dateLabel: dateCell ? firstReadableLine(dateCell) : '',
      customer: firstReadableLine(customerCell),
      type: firstReadableLine(typeCell) || 'Other',
      status: statusKey(statusCell ? statusCell.textContent : ''),
      searchText: lower(row.textContent)
    };
  };

  var items = originalRows.map(itemFromRow);

  var uniqueSorted = function (values) {
    var seen = {};
    values.forEach(function (value) {
      var label = clean(value);
      if (label) seen[label.toLowerCase()] = label;
    });
    return Object.keys(seen).sort().map(function (key) { return seen[key]; });
  };

  var escapeHtml = function (value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  };

  var nativeFilter = root.querySelector('form.et-reg-filter')
    || Array.prototype.slice.call(root.querySelectorAll('form')).find(function (form) {
      return String(form.getAttribute('method') || 'get').toLowerCase() === 'get'
        && !!form.querySelector('input:not([type="hidden"]),select');
    })
    || null;

  var nativeMetricCards = Array.prototype.slice.call(root.querySelectorAll('.et-reg-metric'));
  if (nativeMetricCards.length) {
    var metricParent = nativeMetricCards[0].parentElement;
    var oneParent = metricParent && nativeMetricCards.every(function (card) {
      return card.parentElement === metricParent;
    });
    if (oneParent && nativeMetricCards.length >= 3) {
      metricParent.classList.add('et-booking-native-superseded');
    } else {
      nativeMetricCards.forEach(function (card) {
        card.classList.add('et-booking-native-superseded');
      });
    }
  }
  if (nativeFilter) nativeFilter.classList.add('et-booking-native-superseded');

  var today = new Date();
  today.setHours(23, 59, 59, 999);
  var dayMs = 24 * 60 * 60 * 1000;

  var inDays = function (item, fromDays, toDays) {
    if (!item.date) return false;
    var days = Math.floor((today.getTime() - item.date.getTime()) / dayMs);
    return days >= fromDays && days <= toDays;
  };

  var countPeriod = function (status, fromDays, toDays) {
    return items.filter(function (item) {
      return (!status || item.status === status) && inDays(item, fromDays, toDays);
    }).length;
  };

  var trend = function (status) {
    var current = countPeriod(status, 0, 29);
    var previous = countPeriod(status, 30, 59);
    if (previous === 0) {
      return current === 0
        ? { label: '0%', direction: 'flat' }
        : { label: 'New', direction: 'up' };
    }
    var pct = Math.round(((current - previous) / previous) * 100);
    return {
      label: (pct >= 0 ? '↑ ' : '↓ ') + Math.abs(pct) + '%',
      direction: pct > 0 ? 'up' : (pct < 0 ? 'down' : 'flat')
    };
  };

  var counts = {
    total: items.length,
    pending: items.filter(function (item) { return item.status === 'pending'; }).length,
    confirmed: items.filter(function (item) { return item.status === 'confirmed'; }).length,
    cancelled: items.filter(function (item) { return item.status === 'cancelled'; }).length
  };

  var metricSpec = [
    { key: 'total', label: 'Total Bookings', icon: '▣', tone: 'blue', trend: trend('') },
    { key: 'pending', label: 'Pending Confirmation', icon: '◷', tone: 'amber', trend: trend('pending') },
    { key: 'confirmed', label: 'Confirmed', icon: '✓', tone: 'green', trend: trend('confirmed') },
    { key: 'cancelled', label: 'Cancelled', icon: '×', tone: 'red', trend: trend('cancelled') }
  ];

  var kpis = document.createElement('section');
  kpis.className = 'et-booking-ref-kpis';
  kpis.setAttribute('data-et-booking-reference-kpis', 'ERP-11.3.237');
  kpis.innerHTML = metricSpec.map(function (metric) {
    return '<article class="et-booking-ref-kpi et-booking-ref-kpi-' + metric.tone + '">'
      + '<div class="et-booking-ref-kpi-icon" aria-hidden="true">' + metric.icon + '</div>'
      + '<div class="et-booking-ref-kpi-main">'
      + '<div class="et-booking-ref-kpi-label">' + metric.label + '</div>'
      + '<div class="et-booking-ref-kpi-line"><strong>' + counts[metric.key] + '</strong>'
      + '<span class="et-booking-ref-trend et-booking-ref-trend-' + metric.trend.direction + '">' + metric.trend.label + '</span></div>'
      + '<div class="et-booking-ref-kpi-caption">vs last 30 days</div>'
      + '</div></article>';
  }).join('');

  var tableCard = table.closest('.et-reg-table-card') || table.parentElement;
  if (!tableCard) return;
  tableCard.classList.add('et-booking-ref-register-card');

  var anchor = nativeFilter || tableCard;
  if (anchor.parentNode) anchor.parentNode.insertBefore(kpis, anchor);

  var filterCard = document.createElement('section');
  filterCard.className = 'et-booking-ref-filter-card';
  filterCard.setAttribute('data-et-booking-reference-filter', 'ERP-11.3.237');
  filterCard.innerHTML = ''
    + '<div class="et-booking-ref-filter-head">'
    + '<div class="et-booking-ref-filter-title"><span aria-hidden="true">⌕</span><strong>Search &amp; Filter</strong></div>'
    + '<div class="et-booking-ref-quick"><span>Quick Filters:</span>'
    + '<button type="button" data-quick="all" class="active">All</button>'
    + '<button type="button" data-quick="pending">Pending</button>'
    + '<button type="button" data-quick="confirmed">Confirmed</button>'
    + '<button type="button" data-quick="cancelled">Cancelled</button>'
    + '</div></div>'
    + '<div class="et-booking-ref-filter-grid">'
    + '<label>Search<input type="search" data-filter="search" placeholder="Booking no, customer or reference"></label>'
    + '<label>Customer<select data-filter="customer"><option value="">All Customers</option></select></label>'
    + '<label>Travel Type<select data-filter="type"><option value="">All Types</option></select></label>'
    + '<label>Travel Date From<input type="date" data-filter="from"></label>'
    + '<label>Travel Date To<input type="date" data-filter="to"></label>'
    + '<label>Status<select data-filter="status"><option value="">All Statuses</option><option value="draft">Draft</option><option value="pending">Pending</option><option value="confirmed">Confirmed</option><option value="cancelled">Cancelled</option><option value="closed">Closed</option></select></label>'
    + '</div>'
    + '<div class="et-booking-ref-filter-actions">'
    + '<button type="button" class="et-booking-ref-apply"><span aria-hidden="true">⌕</span> Apply Filter</button>'
    + '<button type="button" class="et-booking-ref-reset"><span aria-hidden="true">↻</span> Reset</button>'
    + '</div>';

  tableCard.parentNode.insertBefore(filterCard, tableCard);

  var customerSelect = filterCard.querySelector('[data-filter="customer"]');
  var typeSelect = filterCard.querySelector('[data-filter="type"]');
  uniqueSorted(items.map(function (item) { return item.customer; })).forEach(function (label) {
    var option = document.createElement('option');
    option.value = label;
    option.textContent = label;
    customerSelect.appendChild(option);
  });
  uniqueSorted(items.map(function (item) { return item.type; })).forEach(function (label) {
    var option = document.createElement('option');
    option.value = label;
    option.textContent = label;
    typeSelect.appendChild(option);
  });

  var filterSearch = filterCard.querySelector('[data-filter="search"]');
  var filterCustomer = customerSelect;
  var filterType = typeSelect;
  var filterFrom = filterCard.querySelector('[data-filter="from"]');
  var filterTo = filterCard.querySelector('[data-filter="to"]');
  var filterStatus = filterCard.querySelector('[data-filter="status"]');

  if (nativeFilter) {
    var nativeSearch = nativeFilter.querySelector('input[name="q"],input[type="search"],input[type="text"]');
    var nativeStatus = nativeFilter.querySelector('select[name="status"]');
    if (nativeSearch && clean(nativeSearch.value)) filterSearch.value = nativeSearch.value;
    if (nativeStatus && clean(nativeStatus.value)) {
      var normalizedNativeStatus = statusKey(nativeStatus.value);
      if (normalizedNativeStatus) filterStatus.value = normalizedNativeStatus;
    }
  }

  var theadRow = table.querySelector('thead tr:last-child');
  if (!theadRow) return;

  var selectHead = document.createElement('th');
  selectHead.className = 'et-booking-select-cell et-booking-select-head';
  selectHead.innerHTML = '<input type="checkbox" class="et-booking-select-all" aria-label="Select visible bookings">';
  theadRow.insertBefore(selectHead, theadRow.firstChild);

  var actionHead = document.createElement('th');
  actionHead.className = 'et-booking-action-head';
  actionHead.textContent = 'Action';
  theadRow.appendChild(actionHead);

  var selected = new Set();

  items.forEach(function (item) {
    var selectCell = document.createElement('td');
    selectCell.className = 'et-booking-select-cell';
    var checkbox = document.createElement('input');
    checkbox.type = 'checkbox';
    checkbox.className = 'et-booking-row-select';
    checkbox.setAttribute('aria-label', 'Select ' + item.booking);
    checkbox.addEventListener('change', function () {
      if (checkbox.checked) selected.add(item.booking);
      else selected.delete(item.booking);
      refreshSelectAll();
    });
    selectCell.appendChild(checkbox);
    item.row.insertBefore(selectCell, item.row.firstChild);
    item.checkbox = checkbox;

    var actionCell = document.createElement('td');
    actionCell.className = 'et-booking-action-cell';
    var menu = document.createElement('div');
    menu.className = 'et-booking-row-menu';
    var menuButton = document.createElement('button');
    menuButton.type = 'button';
    menuButton.className = 'et-booking-row-menu-button';
    menuButton.setAttribute('aria-label', 'Actions for ' + item.booking);
    menuButton.setAttribute('aria-expanded', 'false');
    menuButton.textContent = '⋮';
    var menuPanel = document.createElement('div');
    menuPanel.className = 'et-booking-row-menu-panel';

    if (item.bookingHref) {
      var openBooking = document.createElement('a');
      openBooking.href = item.bookingHref;
      openBooking.textContent = 'Open Booking';
      menuPanel.appendChild(openBooking);
    }
    if (item.invoiceHref) {
      var openInvoice = document.createElement('a');
      openInvoice.href = item.invoiceHref;
      openInvoice.textContent = 'Open Invoice';
      menuPanel.appendChild(openInvoice);
    }
    if (!menuPanel.children.length) {
      var unavailable = document.createElement('span');
      unavailable.textContent = 'No actions available';
      menuPanel.appendChild(unavailable);
    }

    menuButton.addEventListener('click', function (event) {
      event.stopPropagation();
      closeMenus(menu);
      var open = menu.classList.toggle('open');
      menuButton.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    menu.appendChild(menuButton);
    menu.appendChild(menuPanel);
    actionCell.appendChild(menu);
    item.row.appendChild(actionCell);
    item.menu = menu;
  });

  function closeMenus(except) {
    items.forEach(function (item) {
      if (!item.menu || item.menu === except) return;
      item.menu.classList.remove('open');
      var button = item.menu.querySelector('.et-booking-row-menu-button');
      if (button) button.setAttribute('aria-expanded', 'false');
    });
  }

  document.addEventListener('click', function () { closeMenus(null); });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeMenus(null);
  });

  var cardHeader = document.createElement('div');
  cardHeader.className = 'et-booking-ref-table-head';
  cardHeader.innerHTML = ''
    + '<div class="et-booking-ref-table-title"><span aria-hidden="true">▧</span><strong>Bookings (<span data-booking-count>' + items.length + '</span>)</strong></div>'
    + '<div class="et-booking-export"><button type="button" class="et-booking-export-button"><span aria-hidden="true">⇩</span> Export <span aria-hidden="true">⌄</span></button>'
    + '<div class="et-booking-export-menu"><button type="button" data-export="visible">Export visible CSV</button><button type="button" data-export="selected">Export selected CSV</button></div></div>';

  tableCard.insertBefore(cardHeader, tableCard.firstChild);

  var scrollWrap = document.createElement('div');
  scrollWrap.className = 'et-booking-ref-table-scroll';
  table.parentNode.insertBefore(scrollWrap, table);
  scrollWrap.appendChild(table);

  var tableFooter = document.createElement('div');
  tableFooter.className = 'et-booking-ref-table-footer';
  tableFooter.innerHTML = '<div class="et-booking-ref-range" data-booking-range></div><nav class="et-booking-ref-pagination" aria-label="Booking register pages"></nav>';
  tableCard.appendChild(tableFooter);

  var exportWrap = cardHeader.querySelector('.et-booking-export');
  var exportButton = cardHeader.querySelector('.et-booking-export-button');
  exportButton.addEventListener('click', function (event) {
    event.stopPropagation();
    exportWrap.classList.toggle('open');
  });
  document.addEventListener('click', function () { exportWrap.classList.remove('open'); });

  var state = {
    page: 1,
    pageSize: 15,
    filtered: items.slice()
  };

  var dateInputValue = function (value) {
    if (!value) return null;
    var parts = value.split('-').map(Number);
    if (parts.length !== 3 || !parts[0] || !parts[1] || !parts[2]) return null;
    return new Date(parts[0], parts[1] - 1, parts[2]);
  };

  var currentFilter = function () {
    return {
      search: lower(filterSearch.value),
      customer: clean(filterCustomer.value),
      type: clean(filterType.value),
      from: dateInputValue(filterFrom.value),
      to: dateInputValue(filterTo.value),
      status: clean(filterStatus.value)
    };
  };

  var matches = function (item, filter) {
    if (filter.search && item.searchText.indexOf(filter.search) === -1 && lower(item.booking).indexOf(filter.search) === -1) return false;
    if (filter.customer && item.customer !== filter.customer) return false;
    if (filter.type && item.type !== filter.type) return false;
    if (filter.status && item.status !== filter.status) return false;
    if (filter.from && (!item.date || item.date < filter.from)) return false;
    if (filter.to) {
      var toEnd = new Date(filter.to.getTime());
      toEnd.setHours(23, 59, 59, 999);
      if (!item.date || item.date > toEnd) return false;
    }
    return true;
  };

  var updateQuickButtons = function () {
    Array.prototype.slice.call(filterCard.querySelectorAll('[data-quick]')).forEach(function (button) {
      var key = button.getAttribute('data-quick');
      var active = (!filterStatus.value && key === 'all') || filterStatus.value === key;
      button.classList.toggle('active', active);
    });
  };

  var refreshSelectAll = function () {
    var selectAll = table.querySelector('.et-booking-select-all');
    if (!selectAll) return;
    var visible = state.filtered.slice((state.page - 1) * state.pageSize, state.page * state.pageSize);
    if (!visible.length) {
      selectAll.checked = false;
      selectAll.indeterminate = false;
      return;
    }
    var selectedVisible = visible.filter(function (item) { return selected.has(item.booking); }).length;
    selectAll.checked = selectedVisible === visible.length;
    selectAll.indeterminate = selectedVisible > 0 && selectedVisible < visible.length;
  };

  var pageButtons = function (totalPages, current) {
    var result = [];
    if (totalPages <= 7) {
      for (var i = 1; i <= totalPages; i++) result.push(i);
      return result;
    }
    result.push(1);
    var start = Math.max(2, current - 1);
    var end = Math.min(totalPages - 1, current + 1);
    if (start > 2) result.push('…');
    for (var pageNo = start; pageNo <= end; pageNo++) result.push(pageNo);
    if (end < totalPages - 1) result.push('…');
    result.push(totalPages);
    return result;
  };

  var renderPagination = function () {
    var count = state.filtered.length;
    var totalPages = Math.max(1, Math.ceil(count / state.pageSize));
    if (state.page > totalPages) state.page = totalPages;
    var start = count ? ((state.page - 1) * state.pageSize) + 1 : 0;
    var end = Math.min(count, state.page * state.pageSize);

    items.forEach(function (item) { item.row.hidden = true; });
    state.filtered.slice(start ? start - 1 : 0, end).forEach(function (item) {
      item.row.hidden = false;
      item.checkbox.checked = selected.has(item.booking);
    });

    var range = tableFooter.querySelector('[data-booking-range]');
    range.textContent = count ? ('Showing ' + start + '–' + end + ' of ' + count + ' bookings') : 'No bookings match the selected filters';
    var countNode = cardHeader.querySelector('[data-booking-count]');
    if (countNode) countNode.textContent = count;

    var nav = tableFooter.querySelector('.et-booking-ref-pagination');
    nav.innerHTML = '';

    var makeButton = function (label, pageNo, disabled, currentPage, ariaLabel) {
      var button = document.createElement('button');
      button.type = 'button';
      button.textContent = label;
      if (ariaLabel) button.setAttribute('aria-label', ariaLabel);
      if (disabled) button.disabled = true;
      if (currentPage) button.classList.add('active');
      if (!disabled && typeof pageNo === 'number') {
        button.addEventListener('click', function () {
          state.page = pageNo;
          renderPagination();
          tableCard.scrollIntoView({ block: 'start', behavior: 'smooth' });
        });
      }
      return button;
    };

    nav.appendChild(makeButton('‹', state.page - 1, state.page <= 1, false, 'Previous page'));
    pageButtons(totalPages, state.page).forEach(function (value) {
      if (value === '…') {
        var ellipsis = document.createElement('span');
        ellipsis.textContent = '…';
        nav.appendChild(ellipsis);
      } else {
        nav.appendChild(makeButton(String(value), value, false, value === state.page, 'Page ' + value));
      }
    });
    nav.appendChild(makeButton('›', state.page + 1, state.page >= totalPages, false, 'Next page'));
    refreshSelectAll();
  };

  var applyFilters = function () {
    var filter = currentFilter();
    state.filtered = items.filter(function (item) { return matches(item, filter); });
    state.page = 1;
    updateQuickButtons();
    renderPagination();
  };

  filterCard.querySelector('.et-booking-ref-apply').addEventListener('click', applyFilters);
  filterCard.querySelector('.et-booking-ref-reset').addEventListener('click', function () {
    filterSearch.value = '';
    filterCustomer.value = '';
    filterType.value = '';
    filterFrom.value = '';
    filterTo.value = '';
    filterStatus.value = '';
    applyFilters();
  });

  filterSearch.addEventListener('keydown', function (event) {
    if (event.key === 'Enter') {
      event.preventDefault();
      applyFilters();
    }
  });

  Array.prototype.slice.call(filterCard.querySelectorAll('[data-quick]')).forEach(function (button) {
    button.addEventListener('click', function () {
      var key = button.getAttribute('data-quick');
      filterStatus.value = key === 'all' ? '' : key;
      applyFilters();
    });
  });

  var selectAll = table.querySelector('.et-booking-select-all');
  selectAll.addEventListener('change', function () {
    var visible = state.filtered.slice((state.page - 1) * state.pageSize, state.page * state.pageSize);
    visible.forEach(function (item) {
      item.checkbox.checked = selectAll.checked;
      if (selectAll.checked) selected.add(item.booking);
      else selected.delete(item.booking);
    });
    refreshSelectAll();
  });

  var csvCell = function (value) {
    var text = clean(value);
    return '"' + text.replace(/"/g, '""') + '"';
  };

  var exportRows = function (rows, suffix) {
    if (!rows.length) return;
    var originalHeaderLabels = headers.map(function (th) { return clean(th.textContent); });
    var lines = [originalHeaderLabels.map(csvCell).join(',')];
    rows.forEach(function (item) {
      var cells = Array.prototype.slice.call(item.row.children).slice(1, -1);
      lines.push(cells.map(function (cell) { return csvCell(cell.textContent); }).join(','));
    });
    var blob = new Blob([lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
    var href = URL.createObjectURL(blob);
    var download = document.createElement('a');
    download.href = href;
    download.download = 'easy-ticket-bookings-' + suffix + '.csv';
    document.body.appendChild(download);
    download.click();
    download.remove();
    URL.revokeObjectURL(href);
    exportWrap.classList.remove('open');
  };

  Array.prototype.slice.call(cardHeader.querySelectorAll('[data-export]')).forEach(function (button) {
    button.addEventListener('click', function () {
      var mode = button.getAttribute('data-export');
      if (mode === 'selected') {
        exportRows(items.filter(function (item) { return selected.has(item.booking); }), 'selected');
      } else {
        exportRows(state.filtered, 'visible');
      }
    });
  });

  var typeCounts = {};
  items.forEach(function (item) {
    var key = clean(item.type) || 'Other';
    typeCounts[key] = (typeCounts[key] || 0) + 1;
  });
  var typeEntries = Object.keys(typeCounts).map(function (key) {
    return { label: key, count: typeCounts[key] };
  }).sort(function (a, b) { return b.count - a.count; }).slice(0, 6);
  var palette = ['#1769d2', '#14a06f', '#3da7d8', '#f2a534', '#e66b57', '#7b63c8'];
  var running = 0;
  var gradient = typeEntries.map(function (entry, index) {
    var startPct = running;
    var pct = items.length ? (entry.count / items.length) * 100 : 0;
    running += pct;
    return palette[index % palette.length] + ' ' + startPct.toFixed(2) + '% ' + running.toFixed(2) + '%';
  }).join(',');

  var recent = items.slice().sort(function (a, b) {
    var aTime = a.date ? a.date.getTime() : 0;
    var bTime = b.date ? b.date.getTime() : 0;
    if (bTime !== aTime) return bTime - aTime;
    return a.order - b.order;
  }).slice(0, 3);

  var eventLabel = function (item) {
    if (item.status === 'confirmed') return 'Booking confirmed';
    if (item.status === 'cancelled') return 'Booking cancelled';
    if (item.status === 'pending') return 'Booking sent for confirmation';
    if (item.status === 'closed') return 'Booking completed';
    return 'New booking created';
  };

  var insights = document.createElement('section');
  insights.className = 'et-booking-ref-insights';
  insights.setAttribute('data-et-booking-reference-insights', 'ERP-11.3.237');
  insights.innerHTML = ''
    + '<article class="et-booking-ref-insight-card et-booking-ref-workflow">'
    + '<div class="et-booking-ref-insight-head"><div><span aria-hidden="true">▢</span><strong>Quick Workflow</strong></div></div>'
    + '<div class="et-booking-ref-workflow-track">'
    + '<div class="et-booking-ref-workflow-step active"><b>1</b><strong>Draft</strong><small>Create booking</small></div>'
    + '<div class="et-booking-ref-workflow-step"><b>2</b><strong>Pending</strong><small>Await confirmation</small></div>'
    + '<div class="et-booking-ref-workflow-step"><b>3</b><strong>Confirmed</strong><small>Ready for invoicing</small></div>'
    + '<div class="et-booking-ref-workflow-step"><b>4</b><strong>Closed</strong><small>Travel completed</small></div>'
    + '</div></article>'
    + '<article class="et-booking-ref-insight-card et-booking-ref-types">'
    + '<div class="et-booking-ref-insight-head"><div><span aria-hidden="true">▧</span><strong>Bookings by Type</strong></div></div>'
    + '<div class="et-booking-ref-type-body"><div class="et-booking-ref-donut" style="background:conic-gradient(' + (gradient || '#dfe7f0 0 100%') + ')"><span></span></div>'
    + '<div class="et-booking-ref-type-legend">'
    + typeEntries.map(function (entry, index) {
      var pct = items.length ? Math.round((entry.count / items.length) * 100) : 0;
      return '<div><i style="background:' + palette[index % palette.length] + '"></i><span>' + escapeHtml(entry.label) + '</span><strong>' + pct + '%</strong></div>';
    }).join('')
    + '</div></div></article>'
    + '<article class="et-booking-ref-insight-card et-booking-ref-activity">'
    + '<div class="et-booking-ref-insight-head"><div><span aria-hidden="true">▣</span><strong>Recent Activity</strong></div><button type="button" data-view-all>View all</button></div>'
    + '<div class="et-booking-ref-activity-list">'
    + recent.map(function (item) {
      var tone = item.status === 'confirmed' ? 'green' : (item.status === 'cancelled' ? 'red' : 'blue');
      return '<div class="et-booking-ref-activity-row"><i class="' + tone + '"></i><div><strong>' + escapeHtml(eventLabel(item)) + '</strong><small>' + escapeHtml(item.booking) + '</small></div><time>' + escapeHtml(item.dateLabel || '') + '</time></div>';
    }).join('')
    + '</div></article>';

  tableCard.parentNode.insertBefore(insights, tableCard.nextSibling);

  var viewAll = insights.querySelector('[data-view-all]');
  if (viewAll) {
    viewAll.addEventListener('click', function () {
      filterSearch.value = '';
      filterCustomer.value = '';
      filterType.value = '';
      filterFrom.value = '';
      filterTo.value = '';
      filterStatus.value = '';
      applyFilters();
      tableCard.scrollIntoView({ block: 'start', behavior: 'smooth' });
    });
  }

  updateQuickButtons();
  applyFilters();
})();
