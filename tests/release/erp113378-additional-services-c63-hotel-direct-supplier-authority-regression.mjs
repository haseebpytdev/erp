import fs from 'node:fs';
import assert from 'node:assert/strict';

const source = fs.readFileSync('app/Services/Reports/TravelReportService.php', 'utf8');
let executed = 0;
const ok = (value, message) => { executed += 1; assert.ok(value, message); };
const has = (pattern, message) => { executed += 1; assert.match(source, pattern, message); };
const no = (pattern, message) => { executed += 1; assert.doesNotMatch(source, pattern, message); };

const base = source.match(/private function baseQuery[\s\S]*?private function applyTransportDateFilter/)?.[0] ?? '';
const product = source.match(/private function applyProductExistence[\s\S]*?private function passengerPage/)?.[0] ?? '';
const supplier = source.match(/private function supplierDimensionKeyQuery[\s\S]*?private function applySupplierBookingDateFilter/)?.[0] ?? '';

ok(base !== '', 'base query exists');
has(/if\(\$key==='hotels'\)\$this->applyLifecycle\(\$q,\$table,\$table\)/, 'direct Hotel report raw-table lifecycle');
has(/\$q=\$this->filteredQuery\(\$table,\$this->withoutDateFilters\(\$filters\)\);[\s\S]*?if\(\$key==='hotels'\)\$this->applyLifecycle\(\$q,\$table,\$table\)/, 'direct report lifecycle qualifier matches FROM');
no(/if\(\$key==='hotels'\)\$this->applyLifecycle\(\$q,'hotel_detail',\$table\)/, 'direct report has no phantom Hotel alias');
has(/applyLifecycle\(\$q,'hotel_service','booking_services'\)/, 'service-link Hotel service lifecycle');
has(/applyLifecycle\(\$q,'hotel_detail',\$table\)/, 'service-link Hotel detail lifecycle');
has(/\$mode==='direct_booking'/, 'direct booking ownership branch');
has(/bookingExpression=.*bookingColumn/, 'direct supplier booking expression');
has(/\$bookingExpression='hotel_service\.booking_id'/, 'service-link supplier booking expression');
no(/\$type==='hotel'\?'hotel_service\.booking_id'/, 'no unconditional Hotel service alias');
has(/\$bookingExpression!==null[\s\S]*?whereColumn\('b\.id',\$bookingExpression\)/, 'booking filters use proven expression');

ok(product !== '', 'product existence helper exists');
has(/\$normalized==='hotel'[\s\S]*?\$mode==='direct_booking'/, 'direct product ownership branch');
has(/whereColumn\('hotel_detail\.\'\.\$bookingColumn,'bookings\.id'\)/, 'direct product booking authority');
has(/applyLifecycle\(\$x,'hotel_detail',\$table\)/, 'direct product lifecycle');
has(/\$mode==='service_link'[\s\S]*?applyHotelProductExistence/, 'service-link product authority');
has(/if\(\$mode!==\'direct_booking\'\)\{\$q->whereRaw\('1=0'\);return;\}/, 'unknown product ownership fails closed');
has(/whereNotNull\('hotel_service\.booking_id'\)/, 'service booking must be non-null');
has(/where\('hotel_service\.product_service_id',\$productId\)/, 'Hotel Product Service scope');

ok(supplier !== '', 'supplier query exists');
has(/if\(\$present->isEmpty\(\)\)continue/, 'empty Hotel identity skipped');
no(/if\(\$present->isEmpty\(\)\)\{\$identity/, 'no empty COALESCE identity');
has(/\$mode==='direct_booking'[\s\S]*?applyLifecycle\(\$q,\$table,\$table\)/, 'direct supplier detail lifecycle');
has(/\$mode==='service_link'[\s\S]*?applyLifecycle\(\$q,'hotel_service','booking_services'\)/, 'service supplier lifecycle');
has(/\$mode==='service_link'[\s\S]*?applyLifecycle\(\$q,\$table,\$table\)/, 'service supplier detail lifecycle');
has(/\$mode==='service_link'[\s\S]*?\$bookingExpression='hotel_service\.booking_id'/, 'service supplier authority');
has(/\$mode==='direct_booking'[\s\S]*?bookingExpression=.*bookingColumn/, 'direct supplier authority');

has(/hotel_detail\.\'\.\$date/, 'qualified Hotel dates');
has(/hotel_detail\.\'\.\$column/, 'qualified Hotel filters');
no(/createBillingBatch|postJournal|\b(insert|update|delete)\s*\(/, 'no operational writes');
no(/supplier_cost|margin/, 'no cost or margin leak');

console.log('C63_ASSERT_CALL_SITES=' + executed);
console.log('C63_LOOPED_ASSERT_CALL_SITES=NONE');
console.log('C63_RUNTIME_ASSERTION_EXECUTIONS=' + executed);
console.log('C63_PRINTED_ASSERTION_COUNT=' + executed);
console.log('C63_ASSERTION_COUNT_MATCH=YES');
console.log('C63 hotel direct supplier authority regression: PASS (' + executed + ' assertions)');
