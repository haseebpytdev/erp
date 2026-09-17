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
  dataset: { bookingReference: 'BK-2026-00001', bookingId: '31', etgpBookingLocked: '1', currency: 'PKR' },
  querySelector(selector) {
    if (selector === '.etgp-passenger-card') return this.passengerRoot;
    if (selector.includes('data-etgp-air-workspace')) return this.airHost;
    return null;
  },
  passengerRoot: { _etgpPassengerData113305: [{ id: 50, name: 'Saved Passenger' }] },
  airHost: { dataset: {} }
};
const context = {
  window: {},
  etgpBookingLockState113162: state,
  etgpBookingId11397: () => 31,
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
ok(adapter.getPassengerRoot() === root.passengerRoot && adapter.getPassengerData()[0].id === 50, 'passenger authority is reused without a duplicate store');
ok(adapter.getProductSelection('BK-2026-00001').join(',') === 'air,hotel', 'product selection reuses the existing selection authority');
adapter.saveProductSelection('BK-2026-00001', ['air']);
ok(selected.join(',') === 'air', 'selection writes remain on the existing selection authority');
ok(adapter.getProductHost('air') === root.airHost, 'product render host is abstracted from Step 1-specific traversal');
ok(runtime.includes('etBookingWorkspaceContext113305.getBookingId()'), 'Air/Hotel/Transport/Visa dependency points use the adapter');
ok(runtime.includes("renderAirProductWorkspace113106(shellBody)") && runtime.includes("renderHotelProductWorkspace113127(shellBody)") && runtime.includes("renderTransportProductWorkspace113139(shellBody)") && runtime.includes("renderVisaProductWorkspace113142(shellBody)"), 'all four product editors remain reachable');
ok(!runtime.includes('etgpBookingWorkspaceContext113305'), 'no second adapter authority was introduced');
ok(runtime.includes('loadSelected=function(reference)') && runtime.includes('saveSelected=function('), 'existing product-selection implementation remains authoritative');
console.log(`erp113305-product-workspace-adapter-regression: ${pass} assertions passed`);
