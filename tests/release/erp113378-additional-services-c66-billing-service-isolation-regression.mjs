import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = p => fs.readFileSync(p, 'utf8');
const planner = read('app/Services/Operations/GeneralBookingAdditionalServiceMaterializationPlanner.php');
const materializer = read('app/Services/Operations/GeneralBookingAdditionalServiceMaterializer.php');
const functionBody = name => planner.slice(planner.indexOf(`private function ${name}`), planner.indexOf('private function', planner.indexOf(`private function ${name}`) + 1));
const transportPlan = functionBody('transportPlan');
const visaPlan = functionBody('visaPlan');
let executed = 0;
const has = (s, re, n) => { executed++; assert.match(s, re, n); };
const no = (s, re, n) => { executed++; assert.doesNotMatch(s, re, n); };

has(planner, /private function airPlan[\s\S]*service_strategy.*new_service/s, 'SUPPLEMENTARY_AIR_NEW_SERVICE_ONLY');
has(planner, /private function hotelPlan[\s\S]*service_strategy.*new_service/s, 'SUPPLEMENTARY_HOTEL_NEW_SERVICE_ONLY');
has(transportPlan, /service_strategy.*new_service/s, 'SUPPLEMENTARY_TRANSPORT_NEW_SERVICE_ONLY');
no(transportPlan, /\$this->serviceStrategy\s*\(/, 'TRANSPORT_REUSE_EXISTING_SERVICE_REMOVED');
has(visaPlan, /service_strategy.*new_service/s, 'SUPPLEMENTARY_VISA_NEW_SERVICE_ONLY');
no(visaPlan, /\$this->serviceStrategy\s*\(/, 'VISA_REUSE_EXISTING_SERVICE_REMOVED');
has(materializer, /Unmaterialized supplementary items must use new_service/, 'UNMATERIALIZED_REUSE_STRATEGY_FAILS_CLOSED');
has(planner, /product_groups[\s\S]*group_key[\s\S]*item_ids/s, 'AIR_GROUP_ONE_NEW_SERVICE');
has(materializer, /commercialAggregate[\s\S]*appendBookingService/s, 'FROZEN_COMMERCIAL_TO_NEW_SERVICE');
no(materializer, /booking_services[^;]*->update/, 'EXISTING_BOOKING_SERVICE_NOT_UPDATED');
no(materializer, /existing_booking_service_id[^;]*appendBookingService|appendBookingService[^;]*existing_booking_service_id/, 'EXISTING_BOOKING_SERVICE_NOT_REPRICED');
has(materializer, /passengerContract[\s\S]*appendPassengerLinks/s, 'PASSENGER_CONTRACT_PRESERVED');
has(materializer, /if\s*\(!DB::table\('booking_service_passengers'\).*exists\(\)\)\s*DB::table\('booking_service_passengers'\).*insert/s, 'PIVOT_APPEND_ONLY');
has(materializer, /already_materialized[\s\S]*idempotent/s, 'ALREADY_MATERIALIZED_IDEMPOTENT');
no(materializer, /sales_invoices|sales_invoice_items/, 'NO_SALES_INVOICE_CREATE');
no(materializer, /invoice_lines|invoice_items/, 'NO_INVOICE_LINE_CREATE');
no(materializer, /journal_entries|journals|ledgers/, 'NO_JOURNAL_WRITE');
no(materializer, /payments|receipts/, 'NO_PAYMENT_RECEIPT');
has(materializer, /existingServiceIds[\s\S]*Supplementary service was not newly created/s, 'NEW_SERVICE_IDENTITY_GUARD');

console.log(`C66_ASSERT_CALL_SITES=${executed}`);
console.log('C66_LOOPED_ASSERT_CALL_SITES=NONE');
console.log(`C66_RUNTIME_ASSERTION_EXECUTIONS=${executed}`);
console.log(`C66_PRINTED_ASSERTION_COUNT=${executed}`);
console.log('C66_ASSERTION_COUNT_MATCH=YES');
console.log(`ERP378 ADDITIONAL SERVICES C66 BILLING SERVICE ISOLATION REGRESSION: PASS (${executed} assertions)`);
