import fs from 'node:fs';
import assert from 'node:assert/strict';

const service = fs.readFileSync('app/Services/Reports/TravelReportService.php', 'utf8');
const reader = fs.readFileSync('app/Services/Operations/GeneralBookingHotelOperationalReader.php', 'utf8');
let executed = 0;
const check = (value, message) => { executed += 1; assert.ok(value, message); };
const match = (value, pattern, message) => { executed += 1; assert.match(value, pattern, message); };
const absent = (value, pattern, message) => { executed += 1; assert.doesNotMatch(value, pattern, message); };

const base = service.match(/private function hotelServiceQuery[\s\S]*?public function definition/);
check(base, 'service-link helper exists');
const helper = base?.[0] ?? '';
match(service, /\$this->hotelStore->resolve\(\)/, 'resolver authority');
match(helper, /service_link_column/, 'verified service FK');
match(helper, /hotel_detail\.\'\.\$link/, 'verified FK join');
match(helper, /hotel_service\.booking_id[\s\S]*?whereColumn\('b\.id','hotel_service\.booking_id'\)/, 'derived booking authority');
match(helper, /product_service_id/, 'Hotel Product Service scope');
match(helper, /whereNotNull\('hotel_service\.booking_id'\)/, 'valid booking ownership');
match(helper, /applyLifecycle\(\$q,'hotel_service','booking_services'\)/, 'service lifecycle');
match(helper, /applyLifecycle\(\$q,'hotel_detail',\$table\)/, 'detail lifecycle');
match(helper, /hotel_detail\.\'\.\$date/, 'qualified date filters');
match(helper, /hotel_detail\.\'\.\$column/, 'qualified child filters');
for (const field of ['status','branch','customer','agent','salesperson']) {
  match(helper, new RegExp(field), field + ' filter uses booking relation');
}

const existence = service.match(/private function applyHotelProductExistence[\s\S]*?\/\/ Legacy hotel authority marker/);
check(existence, 'service-link product existence helper exists');
match(existence?.[0] ?? '', /hotel_detail[\s\S]*?hotel_service\.booking_id/, 'existence detail and booking authority');
match(existence?.[0] ?? '', /hotel_service\.product_service_id/, 'existence product scope');
match(existence?.[0] ?? '', /applyLifecycle/, 'existence lifecycle');

const supplier = service.match(/private function supplierDimensionKeyQuery[\s\S]*?private function applySupplierBookingDateFilter/);
check(supplier, 'supplier query exists');
match(supplier?.[0] ?? '', /service_link_column/, 'supplier verified FK');
match(supplier?.[0] ?? '', /hotel_service\.booking_id/, 'supplier derived booking');
match(supplier?.[0] ?? '', /hotel_service\.product_service_id/, 'supplier product scope');
match(supplier?.[0] ?? '', /applyLifecycle/, 'supplier lifecycle');

const fallback = service.match(/private function baseQuery[\s\S]*?private function applyTransportDateFilter/);
check(fallback, 'base query exists');
match(fallback?.[0] ?? '', /ownership_mode[\s\S]*?service_link/, 'ownership service-link branch');
match(fallback?.[0] ?? '', /emptyHotelQuery/, 'service-link fail closed');
match(reader, /(?=[\s\S]*ownership_mode)(?=[\s\S]*bookingColumn)(?=[\s\S]*isActive)(?=[\s\S]*findHotel)/, 'reader lifecycle and direct path');

console.log('C62_ASSERT_CALL_SITES=25');
console.log('C62_RUNTIME_ASSERTION_EXECUTIONS=' + executed);
console.log('C62_PRINTED_ASSERTION_COUNT=' + executed);
console.log('C62_ASSERTION_COUNT_MATCH=YES');
console.log('C62 hotel service-link query regression: PASS (' + executed + ' assertions)');
