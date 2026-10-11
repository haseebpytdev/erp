import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = (file) => fs.readFileSync(file, 'utf8');
const style = read('resources/views/reports/travel/partials/movement-arrival-style.blade.php');
const tabs = read('resources/views/reports/travel/partials/movement-tabs.blade.php');
const arrival = read('resources/views/reports/travel/movements/arrival.blade.php');
const departure = read('resources/views/reports/travel/movements/departure.blade.php');
const makkah = read('resources/views/reports/travel/movements/makkah-checkin.blade.php');
const config = read('config/et_erp_release.php');

assert.match(style, /\.et-arrival-card\{background:#fff;border:1px solid #dfe7f0;border-radius:8px;padding:14px;margin-bottom:12px\}/);
assert.match(style, /\.et-arrival-tabs\{display:flex;gap:7px;align-items:center;overflow-x:auto;flex-wrap:nowrap/);
assert.match(style, /\.et-arrival-tabs a\{white-space:nowrap;padding:7px 10px;border:1px solid #d7e1ec;border-radius:6px;color:#315273;text-decoration:none;font-size:10px;font-weight:700\}/);
assert.match(style, /\.et-arrival-tabs a\.active\{background:#1769d2;border-color:#1769d2;color:#fff\}/);
assert.match(style, /\.et-arrival-tabs[^}]*overflow-x:auto/);
assert.match(style, /\.et-movement-pagination svg[^}]*width:16px!important;height:16px!important/);
assert.match(style, /@media print\{[^}]*\.et-arrival-tabs\{display:none!important\}/s);

for (const view of [arrival, departure, makkah]) assert.match(view, /@include\('reports\.travel\.partials\.movement-arrival-style'\)/);
assert.match(tabs, /<nav class="et-arrival-card et-arrival-tabs"[^>]*aria-label="Movement Reports"/);
assert.doesNotMatch(tabs, /\.et-arrival-tabs\s*(?:\{|a\s*\{|\.active\s*\{)/);
for (const view of [arrival, departure, makkah]) assert.doesNotMatch(view, /\.et-arrival-tabs\s*(?:\{|a\s*\{|\.active\s*\{)/);
assert.equal([style, tabs, arrival, departure, makkah].filter((source) => /\.et-arrival-tabs\s*\{/.test(source)).length, 1);
assert.match(arrival, /class="et-movement-pagination"/);
assert.match(makkah, /class="et-movement-pagination"/);
assert.match(makkah, /Check-in Date.*Booking No\..*Customer \/ Group.*Coming From.*Total Pax.*Adult.*Child.*Infant.*Makkah Hotel.*Check-out Date.*Nights/s);
const service = read('app/Services/Reports/TravelReportService.php');
assert.match(service, /GeneralBookingHotelOperationalReader/);
assert.match(service, /Madinah/);
assert.match(service, /air_ticket_details|Air/);
assert.doesNotMatch(service, /general_booking_billing_batch_items/);
assert.match(config, /'asset_version'\s*=>\s*'ERP-11\.3\.378-C84\.3'/);
assert.match(config, /'corrective_build'\s*=>\s*'C84\.3'/);
assert.match(config, /'corrective_name'\s*=>\s*'Arrival Style Single Authority'/);

console.log('ERP-11.3.378 C84.3 arrival style single authority regression: PASS (22 assertions)');
