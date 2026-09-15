(function () {
  'use strict';

  const body = document.body;
  if (!body || !body.classList.contains('et-ui-professional')) return;

  const bookingMatch = location.pathname.match(/\/operations\/bookings\/(\d+)/i)
    || location.pathname.match(/\/system\/erp-bookings\/(\d+)/i);
  if (!bookingMatch) return;

  const bookingId = Number(bookingMatch[1] || 0);
  if (!bookingId) return;

  const normalize = value => String(value || '').replace(/\s+/g, ' ').trim().toLowerCase();
  const pageText = normalize(document.body.textContent);
  const locked = /\bpending approval\b/.test(pageText)
    || /\btravel ready\b/.test(pageText)
    || /\bapproved\b/.test(pageText);

  const csrf = () => {
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta && meta.content) return meta.content;
    const token = document.querySelector('input[name="_token"]');
    return token ? token.value : '';
  };

  const visibleRows = () => Array.from(document.querySelectorAll('.etgp-current-passenger-table tbody tr'))
    .filter(row => normalize(row.textContent) && !normalize(row.textContent).includes('no passenger'));

  const passengerIdForRow = row => {
    const direct = ['bookingPassengerId', 'booking_passenger_id', 'passengerId', 'passenger_id'];
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
    const airRows = Array.from(document.querySelectorAll('[data-etgp-air-ticket-row-113106]'));
    const exact = airRows.filter(airRow =>
      normalize(airRow.querySelector('.etgp-air-passenger-name-113106')?.textContent) === wantedName
    );
    if (exact.length === 1) return Number(exact[0].getAttribute('data-etgp-air-ticket-row-113106')) || 0;

    const rows = visibleRows();
    const index = rows.indexOf(row);
    if (index >= 0 && airRows[index]) {
      return Number(airRows[index].getAttribute('data-etgp-air-ticket-row-113106')) || 0;
    }

    return 0;
  };

  const feedback = message => {
    window.alert(message);
  };

  const wire = () => {
    const table = document.querySelector('.etgp-current-passenger-table');
    if (!table) return;

    visibleRows().forEach(row => {
      if (row.dataset.etPassengerRemoveReady === '1') return;
      row.dataset.etPassengerRemoveReady = '1';

      const passengerId = passengerIdForRow(row);
      if (!passengerId) return;

      const cell = row.lastElementChild || row.appendChild(document.createElement('td'));
      if (cell.querySelector('[data-et-passenger-remove]')) return;

      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'btn btn-sm btn-outline-danger et-passenger-remove';
      button.dataset.etPassengerRemove = String(passengerId);
      button.textContent = 'Remove';
      button.title = locked
        ? 'Reopen the booking before removing passengers.'
        : 'Remove this passenger from the booking';
      button.disabled = locked;

      button.addEventListener('click', function () {
        if (button.disabled) return;
        if (!window.confirm('Remove this passenger from the booking? Passenger Master will not be deleted.')) return;

        button.disabled = true;
        const original = button.textContent;
        button.textContent = 'Removing…';

        fetch('/system/erp-bookings/' + bookingId + '/passengers/' + passengerId, {
          method: 'DELETE',
          credentials: 'same-origin',
          headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrf()
          }
        }).then(async response => {
          let data = {};
          try { data = await response.json(); } catch (_) {}
          if (!response.ok || data.ok !== true) {
            throw new Error(data.message || data.passenger || 'Passenger could not be removed.');
          }
          row.remove();
          location.reload();
        }).catch(error => {
          button.disabled = false;
          button.textContent = original;
          feedback(error && error.message ? error.message : 'Passenger could not be removed.');
        });
      });

      cell.appendChild(button);
    });
  };

  wire();
  const observer = new MutationObserver(() => wire());
  observer.observe(document.body, { childList: true, subtree: true });
})();
