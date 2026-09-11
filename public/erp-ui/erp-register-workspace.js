(function () {
  'use strict';

  var root = document.querySelector('[data-et-register-workspace="ERP-11.3.239"]');
  if (!root) return;

  var clean = function (value) {
    return String(value == null ? '' : value).replace(/\s+/g, ' ').trim();
  };
  var lower = function (value) { return clean(value).toLowerCase(); };
  var rows = Array.prototype.slice.call(root.querySelectorAll('[data-et-register-row]'));
  var table = root.querySelector('.et-register-server-table');
  if (!table) return;

  var pageSize = 15;
  var currentPage = 1;
  var filtered = rows.slice();
  var activeQuick = 'all';

  var search = root.querySelector('[data-filter="search"]');
  var group = root.querySelector('[data-filter="group"]');
  var secondary = root.querySelector('[data-filter="secondary"]');
  var from = root.querySelector('[data-filter="from"]');
  var to = root.querySelector('[data-filter="to"]');
  var status = root.querySelector('[data-filter="status"]');
  var count = root.querySelector('[data-register-count]');
  var range = root.querySelector('[data-register-range]');
  var pagination = root.querySelector('[data-register-pagination]');
  var selectAll = root.querySelector('[data-register-select-all]');

  var matches = function (row) {
    var q = lower(search && search.value);
    var groupValue = lower(group && group.value);
    var secondaryValue = lower(secondary && secondary.value);
    var fromValue = clean(from && from.value);
    var toValue = clean(to && to.value);
    var statusValue = clean(status && status.value);
    var rowStatus = clean(row.dataset.status);

    if (activeQuick !== 'all' && rowStatus !== activeQuick) return false;
    if (statusValue && rowStatus !== statusValue) return false;
    if (q && lower(row.dataset.search).indexOf(q) === -1) return false;
    if (groupValue && lower(row.dataset.group) !== groupValue) return false;
    if (secondaryValue && lower(row.dataset.secondary) !== secondaryValue) return false;

    var rowDate = clean(row.dataset.date);
    if (fromValue && (!rowDate || rowDate < fromValue)) return false;
    if (toValue && (!rowDate || rowDate > toValue)) return false;

    return true;
  };

  var visibleRows = function () {
    return filtered.filter(function (row) { return row.style.display !== 'none'; });
  };

  var updateSelectAll = function () {
    if (!selectAll) return;
    var visible = visibleRows();
    var selected = visible.filter(function (row) {
      var checkbox = row.querySelector('[data-register-row-select]');
      return checkbox && checkbox.checked;
    }).length;
    selectAll.checked = visible.length > 0 && selected === visible.length;
    selectAll.indeterminate = selected > 0 && selected < visible.length;
  };

  var renderPagination = function () {
    if (!pagination) return;
    pagination.textContent = '';

    var pageCount = Math.max(1, Math.ceil(filtered.length / pageSize));
    if (currentPage > pageCount) currentPage = pageCount;

    var button = function (label, page, disabled, active) {
      var node = document.createElement('button');
      node.type = 'button';
      node.textContent = label;
      node.disabled = !!disabled;
      if (active) node.classList.add('active');
      node.addEventListener('click', function () {
        currentPage = page;
        render();
      });
      return node;
    };

    pagination.appendChild(button('‹', Math.max(1, currentPage - 1), currentPage === 1, false));

    var first = Math.max(1, currentPage - 2);
    var last = Math.min(pageCount, first + 4);
    first = Math.max(1, last - 4);

    for (var page = first; page <= last; page++) {
      pagination.appendChild(button(String(page), page, false, page === currentPage));
    }

    pagination.appendChild(button('›', Math.min(pageCount, currentPage + 1), currentPage === pageCount, false));
  };

  var render = function () {
    filtered = rows.filter(matches);
    var start = (currentPage - 1) * pageSize;
    var end = Math.min(start + pageSize, filtered.length);
    var visibleSet = new Set(filtered.slice(start, end));

    rows.forEach(function (row) {
      row.style.display = visibleSet.has(row) ? '' : 'none';
    });

    if (count) count.textContent = String(filtered.length);
    if (range) {
      range.textContent = filtered.length
        ? 'Showing ' + (start + 1) + '–' + end + ' of ' + filtered.length
        : 'Showing 0–0 of 0';
    }

    renderPagination();
    updateSelectAll();
  };

  var applyFilters = function () {
    currentPage = 1;
    render();
  };

  root.querySelectorAll('[data-quick]').forEach(function (quick) {
    quick.addEventListener('click', function () {
      activeQuick = clean(quick.dataset.quick) || 'all';
      root.querySelectorAll('[data-quick]').forEach(function (node) {
        node.classList.toggle('active', node === quick);
      });
      if (status) status.value = activeQuick === 'all' ? '' : activeQuick;
      applyFilters();
    });
  });

  var apply = root.querySelector('[data-register-apply]');
  if (apply) apply.addEventListener('click', applyFilters);

  var reset = root.querySelector('[data-register-reset]');
  if (reset) {
    reset.addEventListener('click', function () {
      [search, group, secondary, from, to, status].forEach(function (field) {
        if (field) field.value = '';
      });
      activeQuick = 'all';
      root.querySelectorAll('[data-quick]').forEach(function (node) {
        node.classList.toggle('active', clean(node.dataset.quick) === 'all');
      });
      applyFilters();
    });
  }

  [search, group, secondary, from, to, status].forEach(function (field) {
    if (!field) return;
    field.addEventListener(field === search ? 'keydown' : 'change', function (event) {
      if (field === search && event.key !== 'Enter') return;
      if (field === search) event.preventDefault();
      applyFilters();
    });
  });

  if (selectAll) {
    selectAll.addEventListener('change', function () {
      visibleRows().forEach(function (row) {
        var checkbox = row.querySelector('[data-register-row-select]');
        if (checkbox) checkbox.checked = selectAll.checked;
      });
      updateSelectAll();
    });
  }

  root.querySelectorAll('[data-register-row-select]').forEach(function (checkbox) {
    checkbox.addEventListener('change', updateSelectAll);
  });

  var closeMenus = function (except) {
    root.querySelectorAll('.et-booking-row-menu.open,.et-booking-export.open').forEach(function (menu) {
      if (menu !== except) menu.classList.remove('open');
    });
  };

  root.querySelectorAll('[data-register-row-menu]').forEach(function (trigger) {
    trigger.addEventListener('click', function (event) {
      event.stopPropagation();
      var menu = trigger.closest('.et-booking-row-menu');
      if (!menu) return;
      var opening = !menu.classList.contains('open');
      closeMenus(menu);
      menu.classList.toggle('open', opening);
    });
  });

  var exportBox = root.querySelector('[data-register-export]');
  var exportToggle = root.querySelector('[data-register-export-toggle]');
  if (exportBox && exportToggle) {
    exportToggle.addEventListener('click', function (event) {
      event.stopPropagation();
      var opening = !exportBox.classList.contains('open');
      closeMenus(exportBox);
      exportBox.classList.toggle('open', opening);
    });
  }

  document.addEventListener('click', function () { closeMenus(null); });

  var csvCell = function (value) {
    return '"' + clean(value).replace(/"/g, '""') + '"';
  };

  var exportRows = function (targetRows) {
    if (!targetRows.length) return;
    var headers = Array.prototype.slice.call(table.querySelectorAll('thead th'))
      .slice(1, -1)
      .map(function (th) { return csvCell(th.textContent); });
    var lines = [headers.join(',')];

    targetRows.forEach(function (row) {
      var cells = Array.prototype.slice.call(row.cells)
        .slice(1, -1)
        .map(function (cell) { return csvCell(cell.textContent); });
      lines.push(cells.join(','));
    });

    var blob = new Blob(['\uFEFF' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
    var url = URL.createObjectURL(blob);
    var link = document.createElement('a');
    link.href = url;
    link.download = 'easy-ticket-' + clean(root.dataset.registerKey || 'register') + '.csv';
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 0);
  };

  var exportVisible = root.querySelector('[data-register-export-visible]');
  if (exportVisible) exportVisible.addEventListener('click', function () { exportRows(visibleRows()); });

  var exportSelected = root.querySelector('[data-register-export-selected]');
  if (exportSelected) {
    exportSelected.addEventListener('click', function () {
      exportRows(rows.filter(function (row) {
        var checkbox = row.querySelector('[data-register-row-select]');
        return checkbox && checkbox.checked;
      }));
    });
  }

  render();
})();
