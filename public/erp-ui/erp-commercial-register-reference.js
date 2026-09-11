(function () {
'use strict';
var path = String(window.location.pathname || '')
.replace(/^\/+|\/+$/g, '')
.toLowerCase();
var configs = {
'sales/invoices': {
key: 'sales-invoices',
marker: 'sales',
kicker: 'COMMERCIAL DOCUMENTS · ERP-09',
title: 'Sales Invoice Register',
subtitle: 'Confirmed bookings become customer receivables only after an approved Sales Invoice is posted.',
idHeader: 'invoice',
statusHeader: 'status',
amountHeader: 'amount',
groupHeader: 'customer',
bookingHeader: 'booking',
tableTitle: 'Sales Invoices',
exportPrefix: 'easy-ticket-sales-invoices',
totalLabel: 'Total Invoices',
pendingLabel: 'Pending Approval',
approvedLabel: 'Approved',
postedLabel: 'Posted',
field2Label: 'Customer',
field2All: 'All Customers',
field2Key: 'group',
field3Label: 'Booking',
field3All: 'All Bookings',
field3Key: 'booking',
breakdownTitle: 'Invoices by Status',
workflow: [
['Draft', 'Commercial review'],
['Pending', 'Maker / checker'],
['Approved', 'Ready to post'],
['Posted', 'Customer receivable']
],
actionText: 'Open Invoice'
},
'supplier-costing': {
key: 'supplier-costing',
marker: 'supplier',
kicker: 'PURCHASE & COSTING',
title: 'Supplier Costing',
subtitle: 'Supplier costs and payable posting.',
idHeader: 'costing',
statusHeader: 'status',
amountHeader: 'total',
groupHeader: 'supplier',
productHeader: 'products',
bookingHeader: 'booking',
tableTitle: 'Supplier Costings',
exportPrefix: 'easy-ticket-supplier-costings',
totalLabel: 'Total Costings',
pendingLabel: 'Pending Approval',
approvedLabel: 'Approved',
postedLabel: 'Posted',
field2Label: 'Supplier',
field2All: 'All Suppliers',
field2Key: 'group',
field3Label: 'Product',
field3All: 'All Products',
field3Key: 'product',
breakdownTitle: 'Costings by Product',
workflow: [
['Draft', 'Prepare supplier cost'],
['Pending', 'Await approval'],
['Approved', 'Ready to post'],
['Posted', 'Supplier payable']
],
actionText: 'Open Costing',
createAction: 'new supplier cost'
}
};
var cfg = configs[path];
if (!cfg) return;
var html = document.documentElement;
var body = document.body;
if (!html || !body) return;
if (html.dataset.etCommercialRegisterReference === 'ERP-11.3.238-' + cfg.marker) return;
html.dataset.etCommercialRegisterReference = 'ERP-11.3.238-' + cfg.marker;
html.classList.add('et-booking-register-reference', 'et-commercial-register-reference', 'et-commercial-reference-' + cfg.key);
var clean = function (value) {
return String(value || '').replace(/\s+/g, ' ').trim();
};
var lower = function (value) { return clean(value).toLowerCase(); };
var escapeHtml = function (value) {
return String(value == null ? '' : value)
.replace(/&/g, '&amp;')
.replace(/</g, '&lt;')
.replace(/>/g, '&gt;')
.replace(/"/g, '&quot;')
.replace(/'/g, '&#039;');
};
var uniqueSorted = function (values) {
return Array.from(new Set(values.map(clean).filter(Boolean))).sort(function (a, b) {
return a.localeCompare(b, undefined, { sensitivity: 'base' });
});
};
var root = document.querySelector('.et-reg-shell')
|| document.querySelector('main.content')
|| document.querySelector('section.content')
|| document.querySelector('.page-body > .container-xl')
|| document.querySelector('.page-body > .container')
|| document.querySelector('.main-content')
|| document.querySelector('.content-wrapper main')
|| document.querySelector('main')
|| document.querySelector('.sci')
|| body;
root.classList.add('et-reg-shell');
if (cfg.key === 'supplier-costing') {
Array.prototype.slice.call(document.querySelectorAll('h1,h2,h3,strong,span')).some(function (node) {
if (node.children.length || clean(node.textContent) !== 'Easy Ticket ERP') return false;
if (node.closest('aside,.sidebar,[class*=sidebar],.navbar-brand')) return false;
var rect = node.getBoundingClientRect();
if (rect.top < 0 || rect.top > 110) return false;
node.textContent = 'Supplier Costing';
return true;
});
}
var tables = Array.prototype.slice.call(root.querySelectorAll('table'));
var table = tables.find(function (candidate) {
var labels = Array.prototype.slice.call(candidate.querySelectorAll('thead th')).map(function (th) {
return lower(th.textContent);
});
return labels.some(function (label) { return label.indexOf(cfg.idHeader) !== -1; })
&& labels.some(function (label) { return label.indexOf(cfg.statusHeader) !== -1; })
&& labels.some(function (label) { return label.indexOf(cfg.amountHeader) !== -1; });
});
if (!table || !table.tBodies || !table.tBodies.length) return;
var rows = Array.prototype.slice.call(table.tBodies[0].rows);
if (!rows.length) return;
var headers = Array.prototype.slice.call(table.querySelectorAll('thead th'));
var index = {
id: -1, date: -1, booking: -1, group: -1, product: -1, status: -1, amount: -1, action: -1
};
headers.forEach(function (th, i) {
var label = lower(th.textContent);
if (index.id < 0 && label.indexOf(cfg.idHeader) !== -1) index.id = i;
if (index.date < 0 && label.indexOf('date') !== -1) index.date = i;
if (index.booking < 0 && label.indexOf('booking') !== -1) index.booking = i;
if (index.group < 0 && label.indexOf(cfg.groupHeader) !== -1) index.group = i;
if (cfg.productHeader && index.product < 0 && label.indexOf(cfg.productHeader) !== -1) index.product = i;
if (index.status < 0 && label.indexOf(cfg.statusHeader) !== -1) index.status = i;
if (index.amount < 0 && label.indexOf(cfg.amountHeader) !== -1) index.amount = i;
});
if (index.id < 0 || index.status < 0) return;
var parseDate = function (text) {
var value = clean(text);
if (!value) return null;
var slash = value.match(/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/);
if (slash) {
var dd = Number(slash[1]);
var mm = Number(slash[2]);
var yy = Number(slash[3]);
var d1 = new Date(yy, mm - 1, dd);
return Number.isNaN(d1.getTime()) ? null : d1;
}
var monthMap = {
jan: 0, feb: 1, mar: 2, apr: 3, may: 4, jun: 5,
jul: 6, aug: 7, sep: 8, oct: 9, nov: 10, dec: 11
};
var named = value.match(/^(\d{1,2})\s+([A-Za-z]{3,9})\s+(\d{4})/);
if (named) {
var month = monthMap[named[2].slice(0, 3).toLowerCase()];
if (month != null) {
var d2 = new Date(Number(named[3]), month, Number(named[1]));
return Number.isNaN(d2.getTime()) ? null : d2;
}
}
var parsed = new Date(value);
return Number.isNaN(parsed.getTime()) ? null : parsed;
};
var statusKey = function (value) {
var text = lower(value).replace(/[_\-]+/g, ' ');
if (!text) return '';
if (text.indexOf('pending') !== -1 || text.indexOf('awaiting') !== -1) return 'pending_approval';
if (text.indexOf('approved') !== -1) return 'approved';
if (text.indexOf('posted') !== -1 || text.indexOf('paid') !== -1) return 'posted';
if (text.indexOf('draft') !== -1) return 'draft';
if (text.indexOf('cancel') !== -1 || text.indexOf('void') !== -1 || text.indexOf('revers') !== -1) return 'cancelled';
return text.replace(/\s+/g, '_');
};
var numberValue = function (value) {
var normalized = String(value || '').replace(/[^0-9.\-]/g, '');
var num = Number(normalized);
return Number.isFinite(num) ? num : 0;
};
var items = rows.map(function (row, order) {
var cells = Array.prototype.slice.call(row.cells);
var idCell = cells[index.id];
var statusCell = cells[index.status];
var actionLink = Array.prototype.slice.call(row.querySelectorAll('a')).find(function (a) {
var t = lower(a.textContent);
return t === 'open' || t === 'view' || t === 'details' || t.indexOf('open') !== -1;
}) || (idCell ? idCell.querySelector('a[href]') : null);
return {
row: row,
order: order,
id: clean(idCell ? idCell.textContent : ''),
dateLabel: clean(index.date >= 0 && cells[index.date] ? cells[index.date].textContent : ''),
date: parseDate(index.date >= 0 && cells[index.date] ? cells[index.date].textContent : ''),
booking: clean(index.booking >= 0 && cells[index.booking] ? cells[index.booking].textContent : ''),
group: clean(index.group >= 0 && cells[index.group] ? cells[index.group].textContent : ''),
product: clean(index.product >= 0 && cells[index.product] ? cells[index.product].textContent : ''),
status: statusKey(statusCell ? statusCell.textContent : ''),
statusLabel: clean(statusCell ? statusCell.textContent : ''),
amount: numberValue(index.amount >= 0 && cells[index.amount] ? cells[index.amount].textContent : ''),
href: actionLink ? actionLink.href : '',
search: lower(row.textContent)
};
});
var findTextElement = function (selector, text) {
var nodes = Array.prototype.slice.call(root.querySelectorAll(selector));
return nodes.find(function (node) { return lower(node.textContent) === lower(text); }) || null;
};
var nativeTitle = findTextElement('h1,h2,h3', cfg.title);
if (!nativeTitle && cfg.key === 'sales-invoices') nativeTitle = findTextElement('h1,h2,h3', 'Sales Invoices');
var nativeCreate = cfg.createAction ? Array.prototype.slice.call(root.querySelectorAll('a,button')).find(function (node) {
return lower(node.textContent).indexOf(cfg.createAction) !== -1;
}) : null;
var copyNodes = Array.prototype.slice.call(root.querySelectorAll('small,p,div,span'));
var kickerNode = copyNodes.find(function (node) { return node.children.length <= 2 && lower(node.textContent) === lower(cfg.kicker); }) || null;
var subtitleNode = copyNodes.find(function (node) { return node.children.length <= 2 && lower(node.textContent) === lower(cfg.subtitle); }) || null;
var nativeHeaderShell = nativeTitle ? nativeTitle.closest('.sci-head,.page-header,.et-reg-header') : null;
if (nativeTitle && kickerNode && subtitleNode) {
var cursor = nativeTitle.parentElement;
var commonHeader = null;
while (cursor && cursor !== root) {
if (cursor.contains(kickerNode) && cursor.contains(subtitleNode) && !cursor.querySelector('table,form,.et-reg-metric')) commonHeader = cursor;
if (cursor.querySelector('.et-reg-metric,table,form')) break;
cursor = cursor.parentElement;
}
if (commonHeader) nativeHeaderShell = commonHeader;
}
if (nativeTitle) nativeTitle.classList.add('et-booking-native-superseded');
if (kickerNode) kickerNode.classList.add('et-booking-native-superseded');
if (subtitleNode) subtitleNode.classList.add('et-booking-native-superseded');
var header = document.createElement('section');
header.className = 'et-reg-header et-commercial-reference-header';
header.innerHTML = ''
+ '<div class="et-reg-title-block">'
+ '<small class="et-commercial-reference-kicker">' + escapeHtml(cfg.kicker) + '</small>'
+ '<h1 class="et-reg-title">' + escapeHtml(cfg.title) + '</h1>'
+ '<p class="text-muted">' + escapeHtml(cfg.subtitle) + '</p>'
+ '</div>'
+ '<div class="et-commercial-reference-header-action"></div>';
if (nativeCreate) {
nativeCreate.classList.add('et-reg-primary-action');
header.querySelector('.et-commercial-reference-header-action').appendChild(nativeCreate);
}
if (nativeHeaderShell && nativeHeaderShell !== root && !nativeHeaderShell.querySelector('table,form')) {
nativeHeaderShell.classList.add('et-booking-native-superseded');
}
var firstContent = root.firstElementChild;
if (firstContent) root.insertBefore(header, firstContent);
else root.appendChild(header);
var nativeMetrics = Array.prototype.slice.call(root.querySelectorAll('.et-reg-metric'));
if (nativeMetrics.length) {
var metricParent = nativeMetrics[0].parentElement;
if (metricParent && nativeMetrics.every(function (card) { return card.parentElement === metricParent; })) {
metricParent.classList.add('et-booking-native-superseded');
} else {
nativeMetrics.forEach(function (card) { card.classList.add('et-booking-native-superseded'); });
}
}
var total = items.length;
var counts = {
pending_approval: items.filter(function (item) { return item.status === 'pending_approval'; }).length,
approved: items.filter(function (item) { return item.status === 'approved'; }).length,
posted: items.filter(function (item) { return item.status === 'posted'; }).length
};
var share = function (count) {
return total ? Math.round((count / total) * 100) : 0;
};
var kpis = document.createElement('section');
kpis.className = 'et-booking-ref-kpis';
kpis.setAttribute('data-et-commercial-reference-kpis', 'ERP-11.3.238');
var metricSpec = [
['total', cfg.totalLabel, '▣', 'blue', total, 100, 'Current register'],
['pending', cfg.pendingLabel, '◷', 'amber', counts.pending_approval, share(counts.pending_approval), 'of total'],
['approved', cfg.approvedLabel, '✓', 'green', counts.approved, share(counts.approved), 'of total'],
['posted', cfg.postedLabel, '↗', 'green', counts.posted, share(counts.posted), 'of total']
];
kpis.innerHTML = metricSpec.map(function (metric) {
return '<article class="et-booking-ref-kpi et-booking-ref-kpi-' + metric[3] + '">'
+ '<div class="et-booking-ref-kpi-icon" aria-hidden="true">' + metric[2] + '</div>'
+ '<div class="et-booking-ref-kpi-main"><div class="et-booking-ref-kpi-label">' + escapeHtml(metric[1]) + '</div>'
+ '<div class="et-booking-ref-kpi-line"><strong>' + metric[4] + '</strong><span class="et-booking-ref-trend et-booking-ref-trend-flat">' + metric[5] + '%</span></div>'
+ '<div class="et-booking-ref-kpi-caption">' + escapeHtml(metric[6]) + '</div></div></article>';
}).join('');
header.insertAdjacentElement('afterend', kpis);
var nativeFilter = root.querySelector('form.et-reg-filter') || root.querySelector('.sci-tools');
if (!nativeFilter) {
nativeFilter = Array.prototype.slice.call(root.querySelectorAll('form')).find(function (form) {
var method = lower(form.getAttribute('method') || 'get');
return method === 'get' && !!form.querySelector('input:not([type="hidden"]),select');
}) || null;
}
if (nativeFilter) nativeFilter.classList.add('et-booking-native-superseded');
var filterCard = document.createElement('section');
filterCard.className = 'et-booking-ref-filter-card';
filterCard.setAttribute('data-et-commercial-reference-filter', 'ERP-11.3.238');
filterCard.innerHTML = ''
+ '<div class="et-booking-ref-filter-head">'
+ '<div class="et-booking-ref-filter-title"><span aria-hidden="true">⌕</span><strong>Search &amp; Filter</strong></div>'
+ '<div class="et-booking-ref-quick"><span>Quick Filters:</span>'
+ '<button type="button" data-quick="all" class="active">All</button>'
+ '<button type="button" data-quick="draft">Draft</button>'
+ '<button type="button" data-quick="pending_approval">Pending</button>'
+ '<button type="button" data-quick="approved">Approved</button>'
+ '<button type="button" data-quick="posted">Posted</button>'
+ '</div></div>'
+ '<div class="et-booking-ref-filter-grid">'
+ '<label>Search<input type="search" data-filter="search" placeholder="' + escapeHtml(cfg.key === 'sales-invoices' ? 'Invoice, booking, customer or reference' : 'Costing no, supplier, booking or invoice') + '"></label>'
+ '<label>' + escapeHtml(cfg.field2Label) + '<select data-filter="field2"><option value="">' + escapeHtml(cfg.field2All) + '</option></select></label>'
+ '<label>' + escapeHtml(cfg.field3Label) + '<select data-filter="field3"><option value="">' + escapeHtml(cfg.field3All) + '</option></select></label>'
+ '<label>Date From<input type="date" data-filter="from"></label>'
+ '<label>Date To<input type="date" data-filter="to"></label>'
+ '<label>Status<select data-filter="status"><option value="">All Statuses</option><option value="draft">Draft</option><option value="pending_approval">Pending Approval</option><option value="approved">Approved</option><option value="posted">Posted</option></select></label>'
+ '</div>'
+ '<div class="et-booking-ref-filter-actions"><button type="button" class="et-booking-ref-apply"><span aria-hidden="true">⌕</span> Apply Filter</button>'
+ '<button type="button" class="et-booking-ref-reset"><span aria-hidden="true">↻</span> Reset</button></div>';
kpis.insertAdjacentElement('afterend', filterCard);
var select2 = filterCard.querySelector('[data-filter="field2"]');
var select3 = filterCard.querySelector('[data-filter="field3"]');
uniqueSorted(items.map(function (item) { return item[cfg.field2Key]; })).forEach(function (label) {
var option = document.createElement('option'); option.value = label; option.textContent = label; select2.appendChild(option);
});
uniqueSorted(items.map(function (item) { return item[cfg.field3Key]; })).forEach(function (label) {
var option = document.createElement('option'); option.value = label; option.textContent = label; select3.appendChild(option);
});
var tableCard = table.closest('.et-reg-table-card') || table.closest('.sci-card') || table.parentElement;
if (!tableCard) return;
tableCard.classList.add('et-booking-ref-register-card');
if (tableCard.parentElement && filterCard.nextElementSibling !== tableCard) {
tableCard.parentElement.insertBefore(filterCard, tableCard);
}
Array.prototype.slice.call(root.querySelectorAll('h2,h3,h4')).forEach(function (heading) {
var t = lower(heading.textContent);
if (cfg.key === 'sales-invoices' && t === 'invoices') {
heading.classList.add('et-booking-native-superseded');
var next = heading.nextElementSibling;
if (next && !next.querySelector('table')) next.classList.add('et-booking-native-superseded');
}
});
var theadRow = table.querySelector('thead tr:last-child');
if (!theadRow) return;
var selectHead = document.createElement('th');
selectHead.className = 'et-booking-select-cell et-booking-select-head';
selectHead.innerHTML = '<input type="checkbox" class="et-booking-select-all" aria-label="Select visible rows">';
theadRow.insertBefore(selectHead, theadRow.firstChild);
var existingActionHeader = theadRow.lastElementChild;
var hasBlankAction = existingActionHeader && clean(existingActionHeader.textContent) === '' && headers.length === theadRow.children.length - 1;
if (hasBlankAction) {
existingActionHeader.textContent = 'Action';
existingActionHeader.classList.add('et-booking-action-head');
index.action = theadRow.children.length - 1;
} else {
var actionHead = document.createElement('th');
actionHead.className = 'et-booking-action-head';
actionHead.textContent = 'Action';
theadRow.appendChild(actionHead);
index.action = theadRow.children.length - 1;
}
var selected = new Set();
var closeMenus = function (except) {
items.forEach(function (item) {
if (!item.menu || item.menu === except) return;
item.menu.classList.remove('open');
var button = item.menu.querySelector('.et-booking-row-menu-button');
if (button) button.setAttribute('aria-expanded', 'false');
});
};
items.forEach(function (item) {
var selectCell = document.createElement('td');
selectCell.className = 'et-booking-select-cell';
var checkbox = document.createElement('input');
checkbox.type = 'checkbox';
checkbox.className = 'et-booking-row-select';
checkbox.setAttribute('aria-label', 'Select ' + item.id);
selectCell.appendChild(checkbox);
item.row.insertBefore(selectCell, item.row.firstChild);
item.checkbox = checkbox;
var actionCell;
var originalActionCell = item.row.lastElementChild;
if (hasBlankAction && originalActionCell) {
actionCell = originalActionCell;
while (actionCell.firstChild) actionCell.removeChild(actionCell.firstChild);
} else {
actionCell = document.createElement('td');
item.row.appendChild(actionCell);
}
actionCell.classList.add('et-booking-action-cell');
var menu = document.createElement('div');
menu.className = 'et-booking-row-menu';
var button = document.createElement('button');
button.type = 'button';
button.className = 'et-booking-row-menu-button';
button.setAttribute('aria-label', 'Actions for ' + item.id);
button.setAttribute('aria-expanded', 'false');
button.textContent = '⋮';
var panel = document.createElement('div');
panel.className = 'et-booking-row-menu-panel';
if (item.href) {
var link = document.createElement('a');
link.href = item.href;
link.textContent = cfg.actionText;
panel.appendChild(link);
} else {
var none = document.createElement('span');
none.textContent = 'No actions available';
panel.appendChild(none);
}
button.addEventListener('click', function (event) {
event.stopPropagation();
closeMenus(menu);
var open = menu.classList.toggle('open');
button.setAttribute('aria-expanded', open ? 'true' : 'false');
});
menu.appendChild(button); menu.appendChild(panel); actionCell.appendChild(menu); item.menu = menu;
checkbox.addEventListener('change', function () {
if (checkbox.checked) selected.add(item.id); else selected.delete(item.id);
refreshSelectAll();
});
});
document.addEventListener('click', function () { closeMenus(null); });
document.addEventListener('keydown', function (event) { if (event.key === 'Escape') closeMenus(null); });
var cardHeader = document.createElement('div');
cardHeader.className = 'et-booking-ref-table-head';
cardHeader.innerHTML = '<div class="et-booking-ref-table-title"><span aria-hidden="true">▧</span><strong>' + escapeHtml(cfg.tableTitle) + ' (<span data-commercial-count>' + items.length + '</span>)</strong></div>'
+ '<div class="et-booking-export"><button type="button" class="et-booking-export-button"><span aria-hidden="true">⇩</span> Export <span aria-hidden="true">⌄</span></button>'
+ '<div class="et-booking-export-menu"><button type="button" data-export="visible">Export visible CSV</button><button type="button" data-export="selected">Export selected CSV</button></div></div>';
tableCard.insertBefore(cardHeader, tableCard.firstChild);
var scrollWrap = document.createElement('div');
scrollWrap.className = 'et-booking-ref-table-scroll';
table.parentNode.insertBefore(scrollWrap, table);
scrollWrap.appendChild(table);
var tableFooter = document.createElement('div');
tableFooter.className = 'et-booking-ref-table-footer';
tableFooter.innerHTML = '<div class="et-booking-ref-range" data-commercial-range></div><nav class="et-booking-ref-pagination" aria-label="Register pages"></nav>';
tableCard.appendChild(tableFooter);
Array.prototype.slice.call(root.querySelectorAll('.pagination')).forEach(function (node) {
if (!node.closest('.et-booking-ref-pagination')) node.classList.add('et-booking-native-superseded');
});
var state = { filtered: items.slice(), page: 1, pageSize: 15 };
var filterSearch = filterCard.querySelector('[data-filter="search"]');
var filter2 = select2;
var filter3 = select3;
var filterFrom = filterCard.querySelector('[data-filter="from"]');
var filterTo = filterCard.querySelector('[data-filter="to"]');
var filterStatus = filterCard.querySelector('[data-filter="status"]');
var updateQuickButtons = function () {
Array.prototype.slice.call(filterCard.querySelectorAll('[data-quick]')).forEach(function (button) {
var key = button.getAttribute('data-quick');
var active = key === 'all' ? !filterStatus.value : filterStatus.value === key;
button.classList.toggle('active', active);
});
};
var refreshSelectAll = function () {
var selectAll = table.querySelector('.et-booking-select-all');
if (!selectAll) return;
var start = (state.page - 1) * state.pageSize;
var visible = state.filtered.slice(start, start + state.pageSize);
var selectedVisible = visible.filter(function (item) { return selected.has(item.id); }).length;
selectAll.checked = visible.length > 0 && selectedVisible === visible.length;
selectAll.indeterminate = selectedVisible > 0 && selectedVisible < visible.length;
};
var render = function () {
var totalFiltered = state.filtered.length;
var pages = Math.max(1, Math.ceil(totalFiltered / state.pageSize));
if (state.page > pages) state.page = pages;
var start = (state.page - 1) * state.pageSize;
var end = Math.min(start + state.pageSize, totalFiltered);
var visibleSet = new Set(state.filtered.slice(start, end));
items.forEach(function (item) { item.row.style.display = visibleSet.has(item) ? '' : 'none'; });
var countNode = cardHeader.querySelector('[data-commercial-count]');
if (countNode) countNode.textContent = String(totalFiltered);
var range = tableFooter.querySelector('[data-commercial-range]');
range.textContent = totalFiltered ? 'Showing ' + (start + 1) + '–' + end + ' of ' + totalFiltered + ' records' : 'Showing 0 records';
var nav = tableFooter.querySelector('.et-booking-ref-pagination');
nav.innerHTML = '';
var addPage = function (label, pageNumber, disabled, active) {
var b = document.createElement('button'); b.type = 'button'; b.textContent = label;
if (active) b.classList.add('active'); b.disabled = !!disabled;
b.addEventListener('click', function () { if (!disabled) { state.page = pageNumber; render(); } });
nav.appendChild(b);
};
addPage('‹', Math.max(1, state.page - 1), state.page === 1, false);
var windowStart = Math.max(1, Math.min(state.page - 2, pages - 4));
var windowEnd = Math.min(pages, windowStart + 4);
for (var p = windowStart; p <= windowEnd; p++) addPage(String(p), p, false, p === state.page);
addPage('›', Math.min(pages, state.page + 1), state.page === pages, false);
refreshSelectAll();
};
var applyFilters = function () {
var q = lower(filterSearch.value);
var v2 = clean(filter2.value);
var v3 = clean(filter3.value);
var status = filterStatus.value;
var from = filterFrom.value ? new Date(filterFrom.value + 'T00:00:00') : null;
var to = filterTo.value ? new Date(filterTo.value + 'T23:59:59') : null;
state.filtered = items.filter(function (item) {
if (q && item.search.indexOf(q) === -1) return false;
if (v2 && item[cfg.field2Key] !== v2) return false;
if (v3 && item[cfg.field3Key] !== v3) return false;
if (status && item.status !== status) return false;
if (from && (!item.date || item.date < from)) return false;
if (to && (!item.date || item.date > to)) return false;
return true;
});
state.page = 1;
updateQuickButtons();
render();
};
filterCard.querySelector('.et-booking-ref-apply').addEventListener('click', applyFilters);
filterCard.querySelector('.et-booking-ref-reset').addEventListener('click', function () {
filterSearch.value = ''; filter2.value = ''; filter3.value = ''; filterFrom.value = ''; filterTo.value = ''; filterStatus.value = '';
applyFilters();
});
filterSearch.addEventListener('keydown', function (event) { if (event.key === 'Enter') { event.preventDefault(); applyFilters(); } });
Array.prototype.slice.call(filterCard.querySelectorAll('[data-quick]')).forEach(function (button) {
button.addEventListener('click', function () {
var key = button.getAttribute('data-quick');
filterStatus.value = key === 'all' ? '' : key;
applyFilters();
});
});
var selectAll = table.querySelector('.et-booking-select-all');
selectAll.addEventListener('change', function () {
var start = (state.page - 1) * state.pageSize;
state.filtered.slice(start, start + state.pageSize).forEach(function (item) {
item.checkbox.checked = selectAll.checked;
if (selectAll.checked) selected.add(item.id); else selected.delete(item.id);
});
refreshSelectAll();
});
var csvCell = function (value) { return '"' + clean(value).replace(/"/g, '""') + '"'; };
var exportRows = function (exportItems, suffix) {
if (!exportItems.length) return;
var labels = headers.map(function (th) { return clean(th.textContent); }).filter(Boolean);
var lines = [labels.map(csvCell).join(',')];
exportItems.forEach(function (item) {
var cells = Array.prototype.slice.call(item.row.children).slice(1, -1);
lines.push(cells.map(function (cell) { return csvCell(cell.textContent); }).join(','));
});
var blob = new Blob([lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
var href = URL.createObjectURL(blob);
var a = document.createElement('a'); a.href = href; a.download = cfg.exportPrefix + '-' + suffix + '.csv';
document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(href);
};
var exportWrap = cardHeader.querySelector('.et-booking-export');
var exportButton = cardHeader.querySelector('.et-booking-export-button');
exportButton.addEventListener('click', function (event) { event.stopPropagation(); exportWrap.classList.toggle('open'); });
document.addEventListener('click', function () { exportWrap.classList.remove('open'); });
Array.prototype.slice.call(cardHeader.querySelectorAll('[data-export]')).forEach(function (button) {
button.addEventListener('click', function (event) {
event.stopPropagation();
var mode = button.getAttribute('data-export');
exportRows(mode === 'selected' ? items.filter(function (item) { return selected.has(item.id); }) : state.filtered, mode);
exportWrap.classList.remove('open');
});
});
var breakdownKey = cfg.key === 'sales-invoices' ? 'statusLabel' : 'product';
var breakdownCounts = {};
items.forEach(function (item) {
var key = clean(item[breakdownKey]) || 'Other';
breakdownCounts[key] = (breakdownCounts[key] || 0) + 1;
});
var entries = Object.keys(breakdownCounts).map(function (key) { return { label: key, count: breakdownCounts[key] }; })
.sort(function (a, b) { return b.count - a.count; }).slice(0, 6);
var palette = ['#1769d2', '#14a06f', '#3da7d8', '#f2a534', '#e66b57', '#7b63c8'];
var running = 0;
var gradient = entries.map(function (entry, i) {
var start = running;
var pct = total ? (entry.count / total) * 100 : 0;
running += pct;
return palette[i % palette.length] + ' ' + start.toFixed(2) + '% ' + running.toFixed(2) + '%';
}).join(',');
var recent = items.slice().sort(function (a, b) {
var at = a.date ? a.date.getTime() : 0; var bt = b.date ? b.date.getTime() : 0;
return bt !== at ? bt - at : a.order - b.order;
}).slice(0, 3);
var insights = document.createElement('section');
insights.className = 'et-booking-ref-insights';
insights.innerHTML = '<article class="et-booking-ref-insight-card et-booking-ref-workflow">'
+ '<div class="et-booking-ref-insight-head"><div><span aria-hidden="true">▢</span><strong>Quick Workflow</strong></div></div>'
+ '<div class="et-booking-ref-workflow-track">' + cfg.workflow.map(function (step, i) {
return '<div class="et-booking-ref-workflow-step' + (i === 0 ? ' active' : '') + '"><b>' + (i + 1) + '</b><strong>' + escapeHtml(step[0]) + '</strong><small>' + escapeHtml(step[1]) + '</small></div>';
}).join('') + '</div></article>'
+ '<article class="et-booking-ref-insight-card et-booking-ref-types">'
+ '<div class="et-booking-ref-insight-head"><div><span aria-hidden="true">▧</span><strong>' + escapeHtml(cfg.breakdownTitle) + '</strong></div></div>'
+ '<div class="et-booking-ref-type-body"><div class="et-booking-ref-donut" style="background:conic-gradient(' + (gradient || '#dfe7f0 0 100%') + ')"><span></span></div>'
+ '<div class="et-booking-ref-type-legend">' + entries.map(function (entry, i) {
var pct = total ? Math.round((entry.count / total) * 100) : 0;
return '<div><i style="background:' + palette[i % palette.length] + '"></i><span>' + escapeHtml(entry.label) + '</span><strong>' + pct + '%</strong></div>';
}).join('') + '</div></div></article>'
+ '<article class="et-booking-ref-insight-card et-booking-ref-activity">'
+ '<div class="et-booking-ref-insight-head"><div><span aria-hidden="true">▣</span><strong>Recent Activity</strong></div><button type="button" data-view-all>View all</button></div>'
+ '<div class="et-booking-ref-activity-list">' + recent.map(function (item) {
var tone = item.status === 'posted' || item.status === 'approved' ? 'green' : item.status === 'pending_approval' ? 'blue' : 'blue';
var label = cfg.key === 'sales-invoices' ? 'Sales invoice ' + (item.statusLabel || 'updated') : 'Supplier costing ' + (item.statusLabel || 'updated');
return '<div class="et-booking-ref-activity-row"><i class="' + tone + '"></i><div><strong>' + escapeHtml(label) + '</strong><small>' + escapeHtml(item.id) + '</small></div><time>' + escapeHtml(item.dateLabel) + '</time></div>';
}).join('') + '</div></article>';
tableCard.insertAdjacentElement('afterend', insights);
var viewAll = insights.querySelector('[data-view-all]');
if (viewAll) viewAll.addEventListener('click', function () {
filterSearch.value = ''; filter2.value = ''; filter3.value = ''; filterFrom.value = ''; filterTo.value = ''; filterStatus.value = '';
applyFilters(); tableCard.scrollIntoView({ block: 'start', behavior: 'smooth' });
});
applyFilters();
})();
