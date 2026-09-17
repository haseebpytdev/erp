import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const runtime = read('public/erp11390/general-progressive-step1.js');
let pass = 0;
const ok = (value, message) => { assert.ok(value, message); pass++; };

const marker = 'var etBookingWorkspaceContext113305=';
const start = runtime.indexOf(marker);
const end = runtime.indexOf('\n})();', start) + '\n})();'.length;
ok(start >= 0 && end > start, 'one product workspace compatibility adapter is present');

const state = { locked: true, status: 'APPROVED', reason: '' };
const selected = ['air', 'hotel'];
const root = {
  dataset: { bookingReference: 'BK-2026-00001', bookingId: '31', etgpBookingLocked: '1', currency: 'PKR', etgpSelectedProducts: 'air,hotel' },
  querySelector(selector) {
    if (selector === '.etgp-passenger-card') return null;
    if (selector.includes('data-etgp-air-workspace')) return this.airHost;
    return null;
  },
  airHost: { dataset: {} }
};
const passengerFixture = [{ id: 50, name: 'Saved Passenger', fare_type: 'ADULT', passport_number: 'P123', dob: '1990-01-01', passport_expiry: '2030-01-01', nationality: 'PK', status: 'active' }];
const context = {
  window: {},
  etgpBookingLockState113162: state,
  etgpBookingId11397: () => 31,
  fetch: async url => ({ json: async () => ({ passengers: passengerFixture }) }),
  loadSelected: reference => { assert.equal(reference, 'BK-2026-00001'); return selected.slice(); },
  saveSelected: (reference, value) => { assert.equal(reference, 'BK-2026-00001'); selected.splice(0, selected.length, ...value); }
};
vm.runInNewContext(`${runtime.slice(start, end)};this.adapter=etBookingWorkspaceContext113305;`, context);
const adapter = context.adapter;
adapter.setRoot(root, 'BK-2026-00001');
ok(context.window.etBookingWorkspaceContext === adapter, 'adapter is the single reusable context authority');
ok(adapter.getBookingId() === 31, 'booking ID resolves through existing booking ID authority');
ok(adapter.getBookingReference() === 'BK-2026-00001', 'booking reference resolves through existing authority');
ok(adapter.isLocked() === true && adapter.getLockState() === state, 'lock state reuses server-seeded lock authority');
ok(adapter.getPassengerRoot() === null, 'passenger visual root is optional on a Products-style document');
const loadedPassengers = await adapter.loadPassengerData();
ok(loadedPassengers[0].id === 50 && loadedPassengers[0].passport_expiry === '2030-01-01', 'passenger data loads from the existing Air product authority');
ok(adapter.getPassengerData()[0].id === 50, 'structured passenger fields remain available without table scraping');
ok(adapter.getProductSelection('BK-2026-00001').join(',') === 'air,hotel', 'product selection reuses the existing selection authority');
adapter.saveProductSelection('BK-2026-00001', ['air']);
ok(selected.join(',') === 'air' && adapter.getProductSelection('BK-2026-00001').join(',') === 'air', 'removing a product survives immediate rerender without server seed remerge');
adapter.saveProductSelection('BK-2026-00001', ['air', 'hotel']);
ok(adapter.getProductSelection('BK-2026-00001').join(',') === 'air,hotel', 'adding a product survives immediate rerender');
ok(runtime.includes('selectionHydrated') && runtime.includes('hydrateProductSelection'), 'server selection seed is guarded by one-time hydration');
ok(adapter.getProductHost('air') === root.airHost, 'product render host is abstracted from Step 1-specific traversal');
ok(runtime.includes('etBookingWorkspaceContext113305.getBookingId()'), 'Air/Hotel/Transport/Visa dependency points use the adapter');
ok(runtime.includes("renderAirProductWorkspace113106(shellBody)") && runtime.includes("renderHotelProductWorkspace113127(shellBody)") && runtime.includes("renderTransportProductWorkspace113139(shellBody)") && runtime.includes("renderVisaProductWorkspace113142(shellBody)"), 'all four product editors remain reachable');
ok(!runtime.slice(start, end).includes('tbody tr') && !runtime.slice(start, end).includes('textContent'), 'passenger data authority does not scrape rendered table cells');
ok(runtime.includes('loadSelected=function(reference)') && runtime.includes('saveSelected=function('), 'existing product-selection implementation remains authoritative');
console.log(`erp113305-product-workspace-adapter-regression: ${pass} assertions passed`);
