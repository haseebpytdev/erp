(function () {
  'use strict';

  const normalize = value => String(value || '').replace(/\s+/g, ' ').trim().toLowerCase();

  // The native passenger list can contain a Passenger Master ID as well as a
  // booking-passenger snapshot ID.  This feature is allowed to operate only
  // on the latter.  Keep the resolver deliberately fail-closed: matching by
  // visual row ordinal can delete the wrong snapshot after sorting/filtering.
  const stableBookingPassengerId = (row, airRows) => {
    const direct = ['bookingPassengerId', 'booking_passenger_id'];
    for (const key of direct) {
      const raw = row.dataset && row.dataset[key];
      if (Number(raw) > 0) return Number(raw);
    }

    const hidden = Array.from(row.querySelectorAll('input[type="hidden"]')).find(input =>
      /booking[_-]?passenger[_-]?id/i.test(String(input.name || input.id || '')) && Number(input.value) > 0
    );
    if (hidden) return Number(hidden.value) || 0;

    const visible = Array.from(row.children).filter(cell => !cell.classList.contains('etgp-passenger-column-hidden'));
    const wantedName = normalize(visible[1] && visible[1].textContent);
    if (!wantedName) return 0;

    // The controlled Air API exposes booking-passenger IDs, never Passenger
    // Master IDs.  A unique exact name match is usable; duplicate names remain
    // unresolved rather than falling back to an unsafe row index.
    const exact = airRows.filter(airRow =>
      normalize(airRow.querySelector('.etgp-air-passenger-name-113106')?.textContent) === wantedName
    );
    return exact.length === 1
      ? Number(exact[0].getAttribute('data-etgp-air-ticket-row-113106')) || 0
      : 0;
  };

  const responseMessage = data => {
    const errors = data && data.errors;
    if (errors && typeof errors === 'object') {
      const first = Object.values(errors).flat().find(value => typeof value === 'string' && value.trim());
      if (first) return first;
    }
    return (data && (data.passenger || data.message)) || 'Passenger could not be removed.';
  };

  // This button is injected into a legacy booking form.  It must never enter
  // any native delegated booking-save handler: its only authority is the
  // scoped DELETE endpoint below.  The helper is exposed for regression
  // execution, not as a second UI authority.
  const removeBookingPassenger = async options => {
    const event = options.event;
    if (event) {
      event.preventDefault();
      if (typeof event.stopImmediatePropagation === 'function') event.stopImmediatePropagation();
      if (typeof event.stopPropagation === 'function') event.stopPropagation();
    }

    const button = options.button;
    if (!button || button.disabled) return { ok: false, skipped: true };
    if (!options.confirmRemoval()) return { ok: false, cancelled: true };

    const original = button.textContent;
    button.disabled = true;
    button.textContent = 'Removing…';

    try {
      const response = await options.request();
      let data = {};
      try { data = await response.json(); } catch (_) {}
      if (!response.ok || data.ok !== true) throw new Error(responseMessage(data));

      // Do not mutate the legacy booking DOM.  It has independent observers
      // and save handlers; the server remains authoritative after DELETE.
      options.reload();
      return { ok: true };
    } catch (error) {
      button.disabled = Boolean(options.isLocked());
      button.textContent = original;
      options.feedback(error && error.message ? error.message : 'Passenger could not be removed.');
      return { ok: false, error };
    }
  };

  window.ETBookingPassengerRemoval = Object.freeze({
    stableBookingPassengerId,
    removeBookingPassenger
  });

  const body = document.body;
  if (!body || !body.classList.contains('et-ui-professional')) return;

  const bookingMatch = location.pathname.match(/\/operations\/bookings\/(\d+)/i)
    || location.pathname.match(/\/system\/erp-bookings\/(\d+)/i);
  if (!bookingMatch) return;

  const bookingId = Number(bookingMatch[1] || 0);
  if (!bookingId) return;

  const bookingLocked = () => {
    const explicit = document.querySelector(
      '.etgp-status,[data-booking-status],[data-et-booking-status],[data-et-status]'
    );
    if (!explicit) return false;
    const status = normalize(
      explicit.getAttribute('data-booking-status')
      || explicit.getAttribute('data-et-booking-status')
      || explicit.getAttribute('data-et-status')
      || explicit.textContent
    );
    return status === 'pending approval'
      || status === 'approved'
      || status === 'travel ready';
  };

  const csrf = () => {
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta && meta.content) return meta.content;
    const token = document.querySelector('input[name="_token"]');
    return token ? token.value : '';
  };

  const visibleRows = () => Array.from(document.querySelectorAll('.etgp-current-passenger-table tbody tr'))
    .filter(row => normalize(row.textContent) && !normalize(row.textContent).includes('no passenger'));

  const passengerIdForRow = row => {
    const airRows = Array.from(document.querySelectorAll('[data-etgp-air-ticket-row-113106]'));
    return stableBookingPassengerId(row, airRows);
  };

  const feedback = message => {
    window.alert(message);
  };

  const wire = () => {
    const table = document.querySelector('.etgp-current-passenger-table');
    if (!table) return;

    const locked = bookingLocked();

    visibleRows().forEach(row => {
      const passengerId = passengerIdForRow(row);
      if (!passengerId) return;

      let button = row.querySelector('[data-et-passenger-remove]');
      if (!button) {
        const cell = row.lastElementChild || row.appendChild(document.createElement('td'));
        button = document.createElement('button');
        button.className = 'btn btn-sm btn-outline-danger et-passenger-remove';
        button.textContent = 'Remove';
        cell.appendChild(button);
      }

      // Existing native/legacy controls are normalized before wiring so a
      // stale submit button can never enter booking-save form authority.
      button.type = 'button';
      button.dataset.etPassengerRemove = String(passengerId);
      if (button.dataset.etPassengerRemoveBound !== '1') {
        button.dataset.etPassengerRemoveBound = '1';
        button.addEventListener('click', function (event) {
          void removeBookingPassenger({
            event,
            button,
            confirmRemoval: () => window.confirm('Remove this passenger from the booking? Passenger Master will not be deleted.'),
            isLocked: bookingLocked,
            feedback,
            reload: () => location.reload(),
            request: () => fetch('/system/erp-bookings/' + bookingId + '/passengers/' + passengerId, {
              method: 'DELETE',
              credentials: 'same-origin',
              headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf()
              }
            })
          });
        }, true);
      }

      button.title = locked
        ? 'Reopen the booking before removing passengers.'
        : 'Remove this passenger from the booking';
      button.disabled = locked;
    });
  };

  wire();

  // Observe only the authoritative passenger table.  A document-wide observer
  // can repeatedly rewire unrelated workspace updates and create duplicate
  // controls; this scoped, animation-frame-batched observer is idempotent.
  const table = document.querySelector('.etgp-current-passenger-table');
  if (table) {
    let queued = false;
    const observer = new MutationObserver(() => {
      if (queued) return;
      queued = true;
      requestAnimationFrame(() => {
        queued = false;
        wire();
      });
    });
    observer.observe(table, { childList: true, subtree: true });
  }
})();
