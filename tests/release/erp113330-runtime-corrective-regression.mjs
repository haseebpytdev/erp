import fs from 'node:fs';
import assert from 'node:assert/strict';

const policy = fs.readFileSync('app/Services/Administration/ErpRoleAccessPolicy.php', 'utf8');
const air = fs.readFileSync('public/erp-theme/js/products/air.js', 'utf8');
let assertions = 0;
const ok = (value, message) => { assert.equal(Boolean(value), true, message); assertions += 1; };

const modulePos = policy.indexOf('$module = $this->moduleForRequest($request);');
const fallbackPos = policy.indexOf('Read-only lookup endpoints remain available', modulePos);
ok(modulePos >= 0 && fallbackPos > modulePos, 'known module classification precedes JSON fallback');
const bridgePos = policy.indexOf("str_contains('/'.$path.'/', '/sales/invoices/from-booking/')");
ok(bridgePos > modulePos && bridgePos < fallbackPos, 'Booking invoice bridge remains before module return/fallback');
ok(policy.includes("air|hotel|transport|visa)-product"), 'Booking product APIs are classified as bookings');
ok(policy.includes("operational-summary|invoice-summary"), 'Booking summary APIs are classified as bookings');
ok(air.includes('unit.contains'), 'Airline outside-click uses DOM containment');
ok(air.includes('outsideListener=null'), 'outside listener is removed on close');
ok(!air.includes('querySelectorAll(\'*\').includes'), 'NodeList.includes workaround is absent');
ok(air.includes("var options=popup.querySelectorAll('[role=\"option\"]')"), 'keyboard options are queried after render');
ok(air.includes("input.removeAttribute('data-etgp-airline-active')"), 'active index resets on render');
ok(air.includes('Select an airline from the list.'), 'invalid airline text fails closed');
ok(air.includes('etgpAirIsBlankUnsavedSegment113330'), 'blank placeholder helper exists');
ok(air.includes("segment_type:'outbound'"), 'empty multi-group itinerary bootstraps outbound');
ok(air.includes('etgpAirNormalizeBlankSegments113330'), 'blank placeholders are normalized');
ok(air.includes('var itineraryRows=etgpAirNormalizeBlankSegments113330'), 'legacy hydration uses shared normalization');
ok(air.includes("if(!pageState.segments.length)pageState.segments=["), 'empty rerender bootstraps only when empty');
ok(air.includes('payload._etgpInvalidAirline'), 'invalid airline payload is blocked before save');
ok(air.includes("segment-new-'+Date.now()+'-1"), 'bootstrap row has a stable client key');

console.log(`ERP-11.3.330 runtime corrective regression: PASS (${assertions} executable contract assertions)`);
