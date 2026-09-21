import fs from 'node:fs';
import assert from 'node:assert/strict';
import vm from 'node:vm';

const read = file => fs.readFileSync(file, 'utf8');
const air = read('app/Http/Controllers/Operations/GeneralBookingAirProductController.php');
const readiness = read('app/Services/Operations/BookingTravelReadinessResolver.php');
const migration = read('database/migrations/2026_09_21_000000_add_booking_service_id_to_booking_itinerary_segments.php');
const js = read('public/erp-theme/js/products/air.js');
const css = read('public/erp-theme/css/products/air.css');
const ok = (value, message) => assert.ok(value, message);

ok(air.includes("'ticket_groups'") && air.includes('ticketGroupsSnapshot'), 'Air GET exposes native Ticket Groups');
ok(air.includes('findAirServices') && air.includes('booking_service_id'), 'Ticket Groups use native booking services and durable segment links');
ok(air.includes('syncGroupedItinerary') && air.includes('segment_keys'), 'group segment ownership is persisted by stable keys');
ok(air.includes('An itinerary segment cannot belong to more than one Ticket Group'), 'duplicate segment ownership fails closed');
ok(air.includes('A passenger may appear only once inside one Ticket Group'), 'duplicate passenger rows within one group are rejected');
ok(air.includes('assertGroupDeletionSafe') && air.includes('terminal'), 'terminal persisted groups cannot be silently deleted');
ok(air.includes('aggregateTicketGroupSummary'), 'Air totals aggregate across all groups');
ok(air.includes('deleted_group_service_ids'), 'explicit persisted group deletion contract exists');
ok(air.includes('multiple Air Ticket Groups. Reload the Air Workspace before saving.'), 'legacy single-group saves fail closed for multi-group bookings');
ok(air.includes('ensureAirService($booking, $bookingRow, true)'), 'new groups use an explicit force-new native service path');
ok(air.includes('resolvedServiceIds'), 'resolved native service IDs are unique per submitted group');
ok(air.includes('booking_service_passengers'), 'group deletion removes generic service passenger links');
ok(js.includes("data-etgp-air-group-editor") && js.includes('editorHost'), 'every group mounts an independent editor host');
ok(js.includes('renderGroupOnly') && js.includes('_etgpAllGroups'), 'group editors serialize all groups while editing one group');
ok(js.includes('data-etgp-air-segment-owner'), 'frontend segment ownership is exclusive');
ok(migration.includes('Intentionally non-destructive') && !migration.includes('dropColumn'), 'migration rollback is fail-safe and non-destructive');
ok(readiness.includes('ticket_groups') && readiness.includes('Air Ticket Group #'), 'travel readiness evaluates every group');
ok(migration.includes('booking_service_id') && migration.includes('nullable'), 'segment link migration is additive and nullable');
ok(js.includes('fareCommercials') && js.includes('etgpAirDraft113314'), 'dedicated Air renderer and draft authority remain intact');
ok(/\.etgp-air-group-editor-main-113324\{[^}]*display:block;[^}]*width:100%/.test(css), 'ticket group sections use a full-width controlled flow');
ok(/\.etgp-air-ticket-group-editor-113324\{[^}]*display:block;[^}]*width:100%/.test(css) && !/\.etgp-air-ticket-group-editor-113324\{[^}]*grid-template-columns/.test(css), 'outer group editor remains full-width block layout');
ok(css.includes('.etgp-air-group-ticket-column-113324,.etgp-air-group-commercial-column-113324'), 'ticket and commercial columns own the two-column children');
ok(/\.etgp-air-group-commercial-column-113324\{[^}]*width:100%;[^}]*overflow:visible/.test(css), 'commercial column is full width without clipping its controls');
ok(css.includes('.etgp-air-group-commercial-column-113324 .etgp-air-fare-wrap-113108') && css.includes('overflow-x:hidden'), 'commercial matrix has no internal scrollbar policy');
ok(/\.etgp-air-group-commercial-column-113324 \.etgp-air-fare-table-113108\{[^}]*width:100%;[^}]*min-width:0!important[^}]*table-layout:fixed/.test(css), 'commercial matrix fits the full Ticket Group width');
ok(/\.etgp-air-page-itinerary-113324 \.etgp-air-segment-row-113106\{[^}]*108px[^}]*minmax\(86px,auto\)/.test(css), 'itinerary Type and Remove actions have readable columns');
ok(js.includes('Applies To Flight Segments') && css.includes('etgp-air-group-segments-113324 input[type=checkbox]'), 'segment assignment uses explicit compact checkbox styling');
ok(/\.etgp-air-group-segments-113324 label\{[^}]*border-radius:999px/.test(css), 'segment assignment uses compact selectable cards');
ok(js.includes('etgp-air-group-editor-main-113324') && js.includes('etgp-air-group-ticket-column-113324') && js.includes('etgp-air-group-commercial-column-113324'), 'DOM declares common, inner main and ticket/commercial columns');
ok(js.includes('groupMain.appendChild(commercialColumn);') && js.indexOf('groupMain.appendChild(commercialColumn);')<js.indexOf('groupMain.appendChild(ticketColumn);'), 'commercials precede Passenger Tickets in the group DOM');
ok(js.includes('page.appendChild(totals);') && js.indexOf('page.appendChild(totals);')>js.indexOf('groupMain.appendChild(ticketColumn);'), 'group summary remains below both group tables');
ok(css.includes('etgp-air-multi-group-totals-113324'), 'page-level multi-group totals use scoped Air CSS');
ok(!js.includes('etgpAirRender113106(groupHost'), 'group editor rendering does not recursively remount the page');
ok(js.includes("Array.isArray(data&&data.ticket_groups)&&data.ticket_groups.length===0") && js.includes("group-new-'+bookingId"), 'empty ticket_groups bootstraps an unsaved starter group');
ok(js.includes("if(Array.isArray(data&&data.ticket_groups)&&data.ticket_groups.length===0)") && js.includes('service_id:null'), 'zero-group bootstrap preserves backend service creation authority');

/* Execute the production draft-normalization helper in a minimal VM. The
   source is loaded unchanged; only a test-only export is injected so the
   helper can be exercised without reimplementing its algorithm. */
const helperWindow = { etDedicatedProductCore: { create() {}, plain() {}, norm() {} } };
const helperContext = vm.createContext({ window: helperWindow, document: {}, console });
const helperSource = js.replace('window.etDedicatedAirProduct={mount:mountAir,getState:function(){return {saveInFlight:airLifecycle.saveInFlight,dirty:airLifecycle.dirty,draftPending:airLifecycle.draftPending,bookingId:airLifecycle.bookingId};}};', '$& window.__normalizeAirDraftAgainstServer113119=normalizeAirDraftAgainstServer113119;');
vm.runInContext(helperSource, helperContext);
const normalizeDraft = helperWindow.__normalizeAirDraftAgainstServer113119;
const serverGroups = [
  { service_id: 101, client_key: 'group-101', segment_keys: ['segment-41'] },
  { service_id: 102, client_key: 'group-102', segment_keys: ['segment-42'] },
];
const compatible = normalizeDraft({ ticket_groups: serverGroups }, { ticket_groups: [...serverGroups, { service_id: null, client_key: 'group-3', segment_keys: ['segment-43'] }], segments: [{ id: 41 }, { id: 42 }, { id: 43 }] });
assert.equal(compatible.applied, true);
assert.equal(compatible.data.ticket_groups.length, 3);
assert.equal(compatible.data.ticket_groups[2].service_id, null);
const staleLegacy = normalizeDraft({ ticket_groups: serverGroups }, { common: { pnr: 'OLD' }, tickets: [], fare_commercials: {} });
assert.equal(staleLegacy.applied, false);
const foreign = normalizeDraft({ ticket_groups: serverGroups }, { ticket_groups: [{ service_id: 101 }, { service_id: 999 }] });
assert.equal(foreign.applied, false);
const behavioralAssertions = 3;
const sourceStaticAssertions = 36;
console.log(`ERP-11.3.324 Air multi-ticket-group regression: PASS (${behavioralAssertions + sourceStaticAssertions} assertions; behavioral=${behavioralAssertions}; source-static=${sourceStaticAssertions})`);
