import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..', '..');
const read = relative => fs.readFileSync(path.join(root, relative), 'utf8');
let checks = 0;
const has = (text, value, message) => { assert.ok(text.includes(value), message); checks++; };
const lacks = (text, value, message) => { assert.ok(!text.includes(value), message); checks++; };

assert.match(read('VERSION.txt').trim(), /^v1\.1\.33\.(?:15[3-9]|1[6-9]\d|[2-9]\d\d)-ERP11\.3\.(?:15[3-9]|1[6-9]\d|[2-9]\d\d)$/); checks++;
const routes = read('routes/erp103179.php');
const summary = read('app/Http/Controllers/Operations/GeneralBookingOperationalSummaryController.php');
const readiness = read('app/Services/Operations/BookingTravelReadinessResolver.php');
const workspace = read('public/erp11390/general-progressive-step1.js');
const profile = read('app/Services/Organization/CompanyProfileSnapshotService.php');
const voucherController = read('app/Http/Controllers/Operations/GeneralBookingVoucherPreviewController.php');
const voucher = read('resources/views/operations/bookings/general-client-voucher-v113142.blade.php');

has(routes, '/operational-summary', 'central operational summary route exists');
for (const key of ["'air' =>", "'hotel' =>", "'transport' =>", "'visa' =>"]) has(summary, key, `persisted total includes ${key}`);
has(summary, "'booking_value' => array_sum($totals)", 'Booking Value sums persisted product controller summaries');
has(summary, '$readiness->resolve(', 'one central readiness resolver is used');
has(readiness, "'PendingTravel'", 'resolver emits PendingTravel');
has(readiness, "'Ready'", 'resolver emits Ready');
has(workspace, 'etgpRefreshPersistedBookingState113153(bookingId)', 'save success paths refresh persisted summary');
has(workspace, "etgpAirSetKpi113124('Travel Status'", 'Travel Status KPI refreshes from server state');
has(workspace, "document.addEventListener('et:booking-product-saved'", 'future products have a refresh event contract');
const visaSummary = workspace.match(/var refreshSummary=function\(\)\{[^\n]+/u)?.[0] ?? '';
lacks(visaSummary, 'etgpSetProductCustomerTotal113127', 'unsaved Visa edits cannot change top Booking Value');

lacks(profile, "'name' => 'Easy Ticket'", 'Company Profile service has no hard-coded company name');
lacks(profile, "'subtitle' => 'Easy Group Of Travels'", 'Company Profile service has no hard-coded trading text');
has(profile, "Storage::disk('public')->url", 'uploaded public-disk logo resolves to a print-safe URL');
has(voucherController, "'visaRelationships' => array_values($visaRelationships)", 'voucher receives deduplicated saved Visa relationships');
has(voucher, '<strong>Saudi Company:</strong>', 'voucher header shows Saudi Company');
has(voucher, '<strong>Pakistani IATA:</strong>', 'voucher header shows Pakistani IATA');
lacks(voucher, '<div>{{ $branchName }}</div>', 'voucher top-right no longer shows branch/Head Office');
lacks(voucher, '<div>{{ $bookingReference }}</div>', 'voucher top-right no longer shows booking number');
lacks(voucher, '<div class="section-title blue">Visa</div>', 'superseding voucher keeps Visa only in the passenger table');
has(voucher, '<th>Visa No.</th>', 'superseding voucher exposes Visa No. in the passenger table');
has(voucher, '$visaByPassenger', 'Visa number uses the stable passenger map');
for (const secret of ['vendor_cost_pkr', 'cost_currency', 'exchange_rate', 'margin_pkr', 'vendor_id']) lacks(voucher, secret, `voucher excludes ${secret}`);
has(voucher, 'thead{display:table-header-group}', 'print repeats table headers');
has(voucher, 'tr{break-inside:avoid}', 'print avoids split table rows');

console.log(`ERP-11.3.153 static regression checks passed: ${checks}`);
