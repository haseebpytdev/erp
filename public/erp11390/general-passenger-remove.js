(function () {
  'use strict';

  var html = document.documentElement;
  if (!html.classList.contains('et-general-progressive-step1-11390')) return;
  if (html.dataset.etPassengerRemoveInit === '1') return;
  html.dataset.etPassengerRemoveInit = '1';

  var normalize = function (value) {
    return String(value || '').replace(/\s+/g, ' ').trim().toLowerCase();
  };
  var compact = function (value) {
    return normalize(value).replace(/[^a-z0-9]/g, '');
  };
  var bookingId = function () {
    var match = location.pathname.match(/\/operations\/bookings\/(\d+)/i);
    return match ? parseInt(match[1], 10) : 0;
  };
  var csrf = function () {
    var meta = document.querySelector('meta[name="csrf-token"]');
    if (meta && meta.content) return meta.content;
    var input = document.querySelector('input[name="_token"]');
    return input ? String(input.value || '') : '';
  };
  var notice = function (message, ok) {
    if (typeof window.etBookingLiveNotice103169 === 'function') {
      window.etBookingLiveNotice103169(message, ok !== false);
      return;
    }
    if (!ok) window.alert(message);
  };

  var state = {
    loading: false,
    editable: false,
    passengers: []
  };

  function visiblePassengerTable() {
    return document.querySelector('.etgp-current-passenger-table');
  }

  function headerIndex(table, aliases) {
    if (!table) return -1;
    var headers = Array.prototype.slice.call(table.querySelectorAll('thead th'));
    for (var i = 0; i < headers.length; i++) {
      var text = normalize(headers[i].textContent);
      if (aliases.indexOf(text) !== -1) return i;
    }
    return -1;
  }

  function cellText(row, index) {
    if (!row || index < 0) return '';
    var cells = row.querySelectorAll('td');
    return cells[index] ? String(cells[index].textContent || '').trim() : '';
  }

  function matchPassenger(row, table) {
    var direct = row.dataset.bookingPassengerId || row.dataset.booking_passenger_id || row.dataset.passengerId || row.dataset.passenger_id;
    if (direct && /^\d+$/.test(String(direct))) {
      var directId = parseInt(direct, 10);
      return state.passengers.find(function (passenger) { return Number(passenger.id) === directId; }) || null;
    }

    var hidden = row.querySelector('input[name*="booking_passenger_id"],input[name="passenger_id"],input[data-booking-passenger-id]');
    if (hidden && /^\d+$/.test(String(hidden.value || hidden.dataset.bookingPassengerId || ''))) {
      var hiddenId = parseInt(hidden.value || hidden.dataset.bookingPassengerId, 10);
      return state.passengers.find(function (passenger) { return Number(passenger.id) === hiddenId; }) || null;
    }

    var nameIndex = headerIndex(table, ['passenger', 'name', 'passenger name']);
    var passportIndex = headerIndex(table, ['passport', 'passport no', 'passport no.', 'passport number']);
    var rowName = normalize(cellText(row, nameIndex >= 0 ? nameIndex : 0));
    var rowPassport = compact(cellText(row, passportIndex >= 0 ? passportIndex : 2));

    var candidates = state.passengers.filter(function (passenger) {
      if (rowPassport && compact(passenger.passport_number) === rowPassport) return true;
      return rowName && normalize(passenger.name) === rowName;
    });

    if (candidates.length === 1) return candidates[0];

    if (candidates.length > 1) {
      var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));
      var sameBefore = rows.slice(0, rows.indexOf(row) + 1).filter(function (candidateRow) {
        var candidateName = normalize(cellText(candidateRow, nameIndex >= 0 ? nameIndex : 0));
        var candidatePassport = compact(cellText(candidateRow, passportIndex >= 0 ? passportIndex : 2));
        return (rowPassport && candidatePassport === rowPassport) || (!rowPassport && rowName && candidateName === rowName);
      }).length;
      return candidates[Math.max(0, Math.min(candidates.length - 1, sameBefore - 1))] || null;
    }

    return null;
  }

  function ensureActionCell(row, table) {
    var actionIndex = headerIndex(table, ['action', 'actions']);
    var cells = row.querySelectorAll('td');
    if (actionIndex >= 0 && cells[actionIndex]) return cells[actionIndex];

    var theadRow = table.querySelector('thead tr');
    if (theadRow) {
      var th = document.createElement('th');
      th.textContent = 'Action';
      th.dataset.etPassengerRemoveHeader = '1';
      theadRow.appendChild(th);
    }

    var cell = document.createElement('td');
    cell.dataset.etPassengerRemoveCell = '1';
    row.appendChild(cell);
    return cell;
  }

  function removeButtonFor(row, passenger, table) {
    if (!state.editable || !passenger || !Number(passenger.id)) return;
    if (row.querySelector('[data-et-passenger-remove]')) return;

    row.dataset.bookingPassengerId = String(passenger.id);
    var cell = ensureActionCell(row, table);
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn btn-sm btn-outline-danger';
    button.textContent = 'Remove';
    button.setAttribute('data-et-passenger-remove', String(passenger.id));
    button.setAttribute('aria-label', 'Remove ' + (passenger.name || 'passenger') + ' from this booking');

    button.addEventListener('click', function () {
      var id = Number(button.getAttribute('data-et-passenger-remove'));
      if (!id || button.disabled) return;

      var label = passenger.name ? ' "' + passenger.name + '"' : ' this passenger';
      if (!window.confirm('Remove' + label + ' from this booking? Passenger Master will not be deleted.')) return;

      button.disabled = true;
      button.textContent = 'Removing…';

      fetch('/system/erp-bookings/' + bookingId() + '/passengers/' + id, {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-TOKEN': csrf()
        }
      }).then(function (response) {
        return response.json().catch(function () { return {}; }).then(function (payload) {
          if (!response.ok || !payload.ok) {
            var message = payload.message || (payload.errors && payload.errors.passenger && payload.errors.passenger[0]) || 'Passenger could not be removed.';
            throw new Error(message);
          }
          return payload;
        });
      }).then(function (payload) {
        row.remove();
        state.passengers = state.passengers.filter(function (item) { return Number(item.id) !== id; });
        notice(payload.message || 'Passenger removed from booking.', true);
        reconcileBooking();
      }).catch(function (error) {
        button.disabled = false;
        button.textContent = 'Remove';
        notice(error && error.message ? error.message : 'Passenger could not be removed.', false);
      });
    });

    cell.textContent = '';
    cell.appendChild(button);
  }

  function bindRows() {
    var table = visiblePassengerTable();
    if (!table) return;

    Array.prototype.slice.call(table.querySelectorAll('tbody tr')).forEach(function (row) {
      var passenger = matchPassenger(row, table);
      if (passenger) removeButtonFor(row, passenger, table);
    });
  }

  function loadState() {
    var id = bookingId();
    if (!id || state.loading) return Promise.resolve();
    state.loading = true;

    return fetch('/system/erp-bookings/' + id + '/passengers/current', {
      method: 'GET',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      }
    }).then(function (response) {
      if (!response.ok) throw new Error('Passenger list could not be loaded.');
      return response.json();
    }).then(function (payload) {
      state.editable = Boolean(payload && payload.editable);
      state.passengers = Array.isArray(payload && payload.passengers) ? payload.passengers : [];
      if (!state.editable) {
        document.querySelectorAll('[data-et-passenger-remove]').forEach(function (button) { button.remove(); });
      }
      bindRows();
    }).catch(function () {
      state.editable = false;
    }).finally(function () {
      state.loading = false;
    });
  }

  function reconcileBooking() {
    fetch(location.href, {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (response) {
      if (!response.ok) throw new Error('refresh');
      return response.text();
    }).then(function (text) {
      var doc = new DOMParser().parseFromString(text, 'text/html');
      if (typeof window.etGeneralProgressiveStep1Sync11390 === 'function') {
        window.etGeneralProgressiveStep1Sync11390(doc, ['metrics', 'passengers']);
        return loadState();
      }
      location.reload();
    }).catch(function () {
      location.reload();
    });
  }

  var boot = function () {
    loadState();
    var observer = new MutationObserver(function (mutations) {
      if (!state.editable) return;
      var relevant = mutations.some(function (mutation) {
        return Array.prototype.some.call(mutation.addedNodes || [], function (node) {
          return node && node.nodeType === 1 && (node.matches && (node.matches('.etgp-current-passenger-table, .etgp-current-passenger-table *') || node.querySelector && node.querySelector('.etgp-current-passenger-table')));
        });
      });
      if (relevant) window.setTimeout(bindRows, 0);
    });
    observer.observe(document.body, { childList: true, subtree: true });
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
  else boot();
})();
