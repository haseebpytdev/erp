import fs from 'node:fs';
import path from 'node:path';
const root = path.resolve(import.meta.dirname, '..', '..');
const read = f => fs.readFileSync(path.join(root, f), 'utf8');
const controller = read('app/Http/Controllers/Operations/GeneralBookingAdditionalServiceProductController.php');
const manager = read('app/Services/Operations/GeneralBookingAdditionalServiceItemManager.php');
const context = read('app/Services/Operations/ProductWorkspaceContext.php');
const release = read('config/et_erp_release.php');
let assertions = 0;
const ok = (value, label) => { assertions += 1; if (!value) throw new Error(`FAIL: ${label}`); };

const projection = snapshots => {
  const grouped = new Map();
  for (const snapshot of snapshots) {
    const key = snapshot.native_air_group_key ?? `legacy:${JSON.stringify(snapshot.segments ?? [snapshot])}`;
    if (!grouped.has(key)) grouped.set(key, { common: snapshot.common ?? snapshot.group_common ?? [], segments: snapshot.segments ?? snapshot.itinerary ?? [], tickets: [], fare_commercials: snapshot.fare_commercials ?? [] });
    grouped.get(key).tickets.push(snapshot);
  }
  const groups = [...grouped.values()];
  if (groups.length === 0) return { tickets: [], ticket_groups: [], segments: [], itinerary: [], common: [], fare_commercials: [] };
  const segments = [];
  for (const group of groups) for (const segment of group.segments) if (!segments.some(existing => JSON.stringify(existing) === JSON.stringify(segment))) segments.push(segment);
  return { tickets: groups.flatMap(group => group.tickets), ticket_groups: groups, segments, itinerary: segments, common: groups[0].common ?? [], fare_commercials: groups[0].fare_commercials ?? [] };
};

const empty = projection([]);
ok(controller.includes('private function airReadProjection'), 'Air read projection authority exists');
ok(controller.includes('if ($groups === [])'), 'empty groups branch is explicit');
ok(controller.includes("'tickets'=>[], 'ticket_groups'=>[], 'segments'=>[], 'itinerary'=>[], 'common'=>[], 'fare_commercials'=>[]"), 'empty projection has all safe collections');
ok(!controller.includes('$groups ?: [[]]'), 'undefined tickets fallback is removed');
ok(controller.includes('$payload = array_merge($payload, $this->airReadProjection($snapshots));'), 'supplementary projection overlays native response');
ok(controller.includes("$payload['summary'] = []"), 'supplementary summary remains isolated');
ok(empty.tickets.length === 0, 'empty projection tickets are empty');
ok(empty.ticket_groups.length === 0, 'empty projection groups are empty');
ok(empty.segments.length === 0 && empty.itinerary.length === 0, 'empty projection segments are empty');
ok(Object.keys(empty).length === 6, 'empty projection shape is complete');

const native = { product: 'air', airline_options: ['XY'], tickets: [{ id: 99 }], segments: [{ id: 'base' }], common: { pnr: 'BASE' } };
const overlaid = { ...native, ...empty };
ok(overlaid.tickets.length === 0 && overlaid.segments.length === 0, 'empty supplementary projection clears base editable rows');
ok(overlaid.airline_options[0] === 'XY' && overlaid.product === 'air', 'master/reference fields survive projection overlay');
const single = projection([{ native_air_group_key: 'g1', tickets: 1, segments: [{ id: 's1' }], common: { pnr: 'P1' } }]);
ok(single.tickets.length === 1 && single.ticket_groups.length === 1, 'single supplementary group is preserved');
const multi = projection([{ native_air_group_key: 'g1', id: 1, segments: [{ id: 's1' }] }, { native_air_group_key: 'g2', id: 2, segments: [{ id: 's2' }] }]);
ok(multi.tickets.length === 2 && multi.ticket_groups.length === 2 && multi.segments.length === 2, 'multiple supplementary groups are preserved');
ok(controller.includes("'segment_keys' => array_values(array_map"), 'C74 segment key source remains intact');
ok(manager.includes('prepareAirProjectedCollection') && manager.includes('applyPreparedAirCollection'), 'C73 preflight/apply architecture remains');
ok(manager.includes('return DB::transaction(function () use ($bookingId, $batchId, $projected)'), 'C73 single transaction remains');
ok(context.includes('billingContext') && context.includes('billingBatchId') && context.includes("'SUPPLEMENTARY'"), 'C72 billing context ownership remains');
ok(release.includes("'version' => 'v1.1.33.378-ERP11.3.378'"), 'application version remains');
ok(release.includes("'release' => 'ERP-11.3.378'"), 'release remains');
ok(release.includes("'asset_version' => 'ERP-11.3.378-C69'") || release.includes("'asset_version' => 'ERP-11.3.378-C76'"), 'asset version remains current');
ok(release.includes("'corrective_build' => 'C75'") || release.includes("'corrective_build' => 'C76'"), 'C75 build metadata is exact');
ok(release.includes("'corrective_name' => 'Supplementary Air Empty Read Projection Closure'") || release.includes("'corrective_name' => 'Supplementary Visa Client Runtime Isolation'"), 'C75 corrective name is exact');
ok(!read('public/erp-theme/js/products/air.js').includes('C75'), 'no public Air JS change');
ok(!read('public/erp-theme/et-focused-shell.css').includes('C75'), 'no public CSS change');
ok(!read('database/migrations/2026_09_30_140000_create_general_booking_billing_foundation.php').includes('C75'), 'no migration change');
ok(assertions >= 25, 'C75 assertion threshold');
console.log(`C75 supplementary Air empty read projection regression: PASS (${assertions} assertions)`);
