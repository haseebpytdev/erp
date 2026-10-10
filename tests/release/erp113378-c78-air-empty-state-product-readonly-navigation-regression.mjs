import assert from 'node:assert/strict';
import fs from 'node:fs';

const root = new URL('../../', import.meta.url).pathname.replace(/^\//, '').replace(/\//g, '\\');
const read = file => fs.readFileSync(new URL(`../../${file}`, import.meta.url), 'utf8');
const air = read('public/erp-theme/js/products/air.js');
const review = read('resources/views/operations/bookings/general-booking-review-v113160.blade.php');
const presenter = read('app/Services/Operations/BookingWorkspaceShellPresenter.php');
const airController = read('app/Http/Controllers/Operations/GeneralBookingAirProductController.php');
const release = read('config/et_erp_release.php');
let assertions = 0;
const check = (value, message) => { assert.ok(value, message); assertions += 1; };

check(/Enter flight no\./.test(air), 'neutral flight guidance is present');
check(/'From'/.test(air) && /'To'/.test(air), 'neutral route guidance is present');
check(/Enter ticket no\./.test(air) && /'Class'/.test(air) && /'Baggage'/.test(air), 'neutral ticket guidance is present');
check(!/placeholder='(?:SV739|LHE|JED|065-1234567890|Y|23 KG)'/.test(air), 'realistic placeholders are not assigned');
check(/Booking passengers are shown for ticket entry\. Blank rows are not saved as Air tickets\./.test(air), 'passenger reference clarification is visible');

// Execute the production ticket projection expression against two passenger
// rows: one meaningful new row, one blank row, and then a persisted blank row.
const start = air.indexOf('var tickets=Array.prototype');
const end = air.indexOf('var farePayload=', start);
assert.ok(start >= 0 && end > start, 'ticket projection expression found');
const projection = new Function('tbody', 'plain', `${air.slice(start, end)}; return tickets;`);
const row = (id, values, persisted = false) => ({ _etgpAir: { passengerId: id, ticket: { value: values.ticket_number || '' }, bookingClass: { value: values.booking_class || '' }, baggage: { value: values.baggage || '' }, persisted } });
const tbody = rows => ({ querySelectorAll: () => rows });
const plain = value => String(value ?? '').trim();
const mixed = projection(tbody([
  row(101, { ticket_number: 'T-101', booking_class: 'Y', baggage: '20 KG' }),
  row(102, {}),
]), plain);
check(mixed.length === 1 && mixed[0].booking_passenger_id === 101, 'one meaningful and one blank row projects one ticket');
const persisted = projection(tbody([row(102, {}, true)]), plain);
check(persisted.length === 1 && persisted[0].booking_passenger_id === 102, 'persisted blank ticket remains serialized');
check(/\.filter\(function\(ticket\)\{return ticket!==null;\}\)/.test(air), 'blank ticket projection filters null rows');
check(/persisted:Number\(saved\.id\|\|0\)>0/.test(air), 'persisted ticket authority is carried into the row');

check(/if \(\s*! \$existing && \$ticketNumber === '' && ! \$hasCommercial\) \{\s*continue;/.test(airController), 'server safely ignores a blank new ticket');
check(!airController.includes('C78'), 'Air server write path is unchanged');

for (const [product, label] of [['air', 'View Flights'], ['hotel', 'View Hotels'], ['transport', 'View Transport'], ['visa', 'View Visa']]) {
  check(review.includes(`route('bookings.products.workspace',['booking'=>$bookingId,'product'=>'${product}'])`), `${label} uses canonical product route`);
}
check(!/url\('\/operations\/bookings\/'.*#(?:air|hotel|transport|visa)/.test(review), 'review product hash links are absent');
check(/\$supplementOnly\s*\?\s*url\('\/operations\/bookings\/'\.\$bookingId\.'\/review'\)/.test(presenter), 'supplement-only cards retain safe review ownership');
check(/url\('\/operations\/bookings\/'\.\$bookingId\.'\/products\/'\.\$key\)/.test(presenter), 'base product cards use dedicated workspaces');
check(/corrective_build' => 'C(?:78|79)'/.test(release) && /asset_version' => 'ERP-11\.3\.378-C(?:78|79)'/.test(release), 'C78 release metadata lineage remains active');
check(/structuralRerender=rerender/.test(air) && /refresh:function\(bookingId\)/.test(air), 'C77 state integrity hooks remain');

console.log(`ERP-11.3.378 C78 Air empty-state/product navigation regression: PASS (${assertions} assertions)`);
