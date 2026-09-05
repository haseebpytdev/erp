import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const air = read('app/Http/Controllers/Operations/GeneralBookingAirProductController.php');
const ui = read('public/erp11390/general-progressive-step1.js');
const css = read('public/erp11390/general-progressive-step1.css');
const review = read('resources/views/operations/bookings/general-booking-review-v113160.blade.php');
const focus = read('public/erp11335/booking-focus.js');
const migration = read('database/migrations/2026_09_05_220000_add_air_vendor_to_booking_services.php');
let checks = 0;
const has = (body, value, label) => { assert.ok(body.includes(value), label); checks++; };

has(ui, 'supplier_id:supplierData.supplier_id', 'Air payload sends stable supplier ID');
has(air, "private const AIR_VENDOR_COLUMN = 'vendor_id'", 'Air has one explicit vendor authority');
has(migration, "Schema::hasColumn('booking_services', 'vendor_id')", 'migration creates vendor authority only when absent');
has(migration, "$table->unsignedBigInteger('vendor_id')->nullable()->index()", 'service-scoped vendor ID is persisted');
has(air, "$this->physicalColumnListing('booking_services')", 'Air service persistence uses physical schema');
has(air, '$update[self::AIR_VENDOR_COLUMN] = $supplierId > 0 ? $supplierId : null', 'Air writes nested request ID to exact authority');
has(air, '$supplierId = (int) ($serviceRow[self::AIR_VENDOR_COLUMN] ?? 0)', 'Air reload reads the same exact authority');
has(air, "'common' => $this->commonSnapshot(", 'Air save response returns persisted common snapshot');
has(ui, 'Select Vendor / Supplier before saving Air commercial data.', 'client validation is explicit');
has(air, "airVendorErrors($common, $fareCommercials)", 'server validation uses shared commercial resolver');

for (const title of ['Passenger Tickets','PNR Fare Commercials','Hotel Stays','Transport Services','Visa Services']) {
  has(ui, title, `${title} exists`);
}
assert.ok((ui.match(/etgp-product-subsection-161/g)||[]).length >= 5, 'all product subsection headings use shared class'); checks++;
has(css, 'font-size:14px!important;font-weight:700!important', 'shared subsection typography is readable');
has(css, 'font-size:12.5px!important;line-height:1.2!important', 'product controls use readable font');
has(css, '.etgp-visa-main-row-113142 .form-control', 'Visa controls use readable contract');
has(css, 'font-size:11.5px!important;font-weight:700!important', 'headers and answers are readable');

has(css, '100px 48px!important', 'Hotel reserves a fixed 48px action column');
has(css, 'width:38px!important;min-width:38px!important;height:35px!important', 'Hotel remove button is fully visible');
has(css, '.etgp-hotel-grid-scroll-113127{overflow-x:hidden!important}', 'Hotel desktop grid avoids horizontal scroll');
has(ui, "remove.title='Remove Hotel Stay'", 'Hotel remove tooltip is correct');
has(ui, "'Answer','Action'", 'Hotel action column has a visible heading');
has(css, 'th:nth-child(1){width:7%!important;white-space:nowrap!important}', 'PNR Fare Type stays on one line');
has(ui, "'Exchange Rate','Answer PKR','Action'", 'Transport headers retain Action and Exchange Rate');
has(ui, "etgp-hotel-remove-113127 etgp-transport-remove-113139','×'", 'Transport reuses compact Hotel X action');
has(ui, "remove.title='Remove Transport Service'", 'Transport remove tooltip is correct');
assert.ok(!ui.includes("etgp-transport-remove-113139','Remove'"), 'wide Transport Remove text is absent'); checks++;
has(css, '.etgp-transport-grid-head-113139>div{white-space:nowrap!important}', 'Transport headers stay on one line');
has(css, '92px 82px 48px!important', 'Transport reserves Exchange Rate and Action widths');
has(css, 'grid-template-columns:28px minmax(130px,1.35fr)', 'Visa has dedicated checkbox and passenger columns');
has(css, '.etgp-visa-grid-head-113142>div{white-space:nowrap!important', 'Visa primary headers stay on one line');
has(css, '.etgp-visa-table-113142{overflow-x:hidden!important}', 'Visa avoids desktop horizontal scrolling');

has(review, 'max-width:1280px', 'Review uses normal booking canvas width');
has(review, 'data-et-booking-review-root="1"', 'Review exposes dynamic booking identity');
has(focus, "nativePageTitle.textContent=bookingReference", 'native Dashboard title is replaced with booking identity');
has(focus, "et-booking-review-workspace-161", 'Review uses compact booking shell marker');
has(focus, "reviewHeaderActions.appendChild(toolbar)", 'Review reuses Menu and Booking Register toolbar');
has(review, 'href="{{ url(\'/operations/bookings/\'.$bookingId) }}"', 'Back to Booking route remains native');

console.log(`ERP-11.3.161 master correction regression: ${checks} assertions passed.`);
