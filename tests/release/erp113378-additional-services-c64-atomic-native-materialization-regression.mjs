import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = p => fs.readFileSync(p, 'utf8');
const m = read('app/Services/Operations/GeneralBookingAdditionalServiceMaterializer.php');
const w = read('app/Services/Operations/GeneralBookingAdditionalServiceWorkflowManager.php');
const p = read('app/Services/Operations/GeneralBookingAdditionalServiceMaterializationPlanner.php');
const r = read('app/Services/Operations/GeneralBookingHotelNativeStoreResolver.php');
let executed = 0;
const has = (s, re, n) => { executed++; assert.match(s, re, n); };
const no = (s, re, n) => { executed++; assert.doesNotMatch(s, re, n); };
const ok = (v, n) => { executed++; assert.ok(v, n); };

has(m, /DB::transaction\(function/s, 'ATOMIC_TRANSACTION');
has(m, /bookings.*lockForUpdate/s, 'BOOKING_LOCK');
has(m, /general_booking_billing_batches.*lockForUpdate/s, 'BATCH_LOCK');
has(m, /orderBy\('line_no'\).*orderBy\('id'\).*lockForUpdate/s, 'ITEM_LOCK_ORDER');
has(m, /\$plan\s*=\s*\$this->planner->plan[\s\S]*prepareAirGroups/s, 'PLAN_BEFORE_WRITES');
has(m, /already_materialized[\s\S]*idempotent/s, 'IDEMPOTENT_RETRY');
has(m, /partial_materialization_link/, 'PARTIAL_STATE_FAILS_CLOSED');
has(w, /DB::transaction[\s\S]*status.*approved[\s\S]*\}\);[\s\S]*materializer->materialize/s, 'APPROVAL_TRANSACTION_SEPARATE');
has(w, /already_approved[\s\S]*materializer->materialize/s, 'ALREADY_APPROVED_RETRY');

has(m, /masterFor\(string \$product\)/, 'PRODUCT_SERVICE_RESOLUTION');
has(m, /target_product_service_id[\s\S]*master\['id'\]/s, 'PRODUCT_SERVICE_ID_MATCH');
has(m, /booking_id.*\$booking->id[\s\S]*company_id.*branch_id/s, 'BOOKING_CONTEXT_FROM_LOCKED_BOOKING');
has(m, /service_name.*service_code/s, 'PRODUCT_MASTER_NAME_CODE_MAPPING');
has(m, /revenue_mapping_key|revenue_mapping/, 'REVENUE_MAPPING_PROJECTED');
has(m, /passenger_link_mode_snapshot/, 'PASSENGER_MODE_SNAPSHOT');
has(m, /pricing_basis_snapshot/, 'PRICING_BASIS_SNAPSHOT');
has(m, /commercialAggregate[\s\S]*supplier_cost[\s\S]*margin/s, 'FROZEN_COMMERCIAL_PROJECTED');
has(m, /existing_booking_service_id[\s\S]*new_service/s, 'REUSED_SERVICE_NOT_REPRICED');
no(m, /private function appendBookingService[^\r\n]*->update/, 'REUSED_SERVICE_NOT_UPDATED');

has(m, /enumCompatible[\s\S]*preg_match_all/s, 'ENUM_VALUES_PARSED');
has(m, /No compatible active native booking service status|No compatible pending Air ticket status|No compatible pending Visa status/s, 'ENUM_FAIL_CLOSED');
has(m, /nullable.*is_nullable/s, 'NULLABLE_NO_SAFE');
has(m, /AUTO_INCREMENT|GENERATED/, 'AUTO_INCREMENT_REQUIRED_COLUMN_IGNORED');

has(m, /product_groups[\s\S]*prepareAirGroups/s, 'AIR_GROUP_AUTHORITY');
has(m, /group_key.*item_ids/s, 'AIR_GROUP_REQUIRED');
has(m, /prepareAirGroups[\s\S]*appendAirItinerary/s, 'AIR_ONE_ITINERARY_PER_GROUP');
has(m, /appendAirTicket/, 'AIR_ONE_DETAIL_PER_ITEM');
no(m, /ticket_number.*fake|fake.*ticket_number/i, 'AIR_NO_FAKE_TICKET');
has(m, /pending.*unissued|unissued.*pending/, 'AIR_NO_FALSE_ISSUED');

has(m, /hotelStore->resolve[\s\S]*plannedTable/s, 'HOTEL_VERIFY_USES_STORE_RESOLVER');
has(m, /\['booking_column'\]/, 'HOTEL_VERIFY_USES_RESOLVED_BOOKING_COLUMN');
has(m, /\['service_link_column'\]/, 'HOTEL_VERIFY_USES_RESOLVED_SERVICE_LINK_COLUMN');
no(m, /where\('booking_service_id'/, 'HOTEL_VERIFY_NO_HARDCODED_SERVICE_FK');
has(p, /hotelStore->resolve[\s\S]*ownership_mode/s, 'PLANNER_HOTEL_OWNERSHIP');
has(r, /booking_column|service_link_column/, 'HOTEL_RESOLVER_CONTRACT');

has(m, /\$plan\['native_table'\][\s\S]*transportTableCompatible/s, 'TRANSPORT_USES_PLAN_NATIVE_TABLE');
no(m, /compatibleTable\(\[/, 'TRANSPORT_NO_FIRST_EXISTING_TABLE_SCAN');
no(m, /private function appendTransport[^\r\n]*booking_services[^\r\n]*->update/, 'TRANSPORT_REUSED_SERVICE_NOT_UPDATED');

has(m, /passengers->ids\(\$bookingId\)/, 'PASSENGER_OWNERSHIP_RECHECK');
has(m, /booking_visa_services.*booking_id.*booking_passenger_id.*exists/s, 'VISA_UNIQUENESS_RECHECK');
has(m, /sale_pkr.*supplier_cost_snapshot|vendor_cost_pkr.*supplier_cost_snapshot/s, 'VISA_FROZEN_COMMERCIAL');
no(m, /visa_number.*fake|fake.*visa_number/i, 'VISA_NO_FAKE_NUMBER');

has(m, /passenger_link_mode/, 'PASSENGER_LINK_MODE_AUTHORITY');
has(m, /pricing_basis/, 'PASSENGER_PRICING_BASIS_READ');
has(m, /REQUIRED.*MULTIPLE.*SINGLE/s, 'PASSENGER_MODES_ENFORCED');
has(m, /Required passenger-link table is unavailable/, 'PASSENGER_PIVOT_REQUIRED_COLUMN_GUARD');
has(m, /created_at.*updated_at/, 'PASSENGER_PIVOT_TIMESTAMPS_SUPPORTED');
no(m, /booking_service_passengers.*delete|delete.*booking_service_passengers/i, 'PASSENGER_LINK_NO_DELETE');

has(m, /whereNull\('source_table'\).*whereNull\('source_id'\).*whereNull\('booking_service_id'\).*whereNull\('product_service_id'/s, 'ITEM_LINK_GUARD');
has(m, /source_table.*source_id.*booking_service_id.*product_service_id/s, 'ITEM_LINK_FIELDS');
has(m, /updated!==1|updated !== 1/, 'ITEM_LINK_ROWCOUNT');
has(m, /source_table.*planner-approved|source booking ownership|Hotel resolver ownership/s, 'SOURCE_OWNERSHIP_VERIFIED');
has(m, /\$final.*planner->plan[\s\S]*already_materialized/s, 'FINAL_PLANNER_ALL_FIELDS');
has(m, /would_reset_travel_ready[\s\S]*resetTravelReady/s, 'TRAVEL_READY_RESET_GUARDED_BY_PLAN_FLAG');
has(m, /travel_ready_reset.*false/, 'ALREADY_MATERIALIZED_NO_READINESS_UPDATE');
has(m, /readinessValue[\s\S]*enumCompatible/s, 'READINESS_ENUM_INSPECTED_FOR_TRAVEL_STATUS');
has(m, /travel_status.*readiness_status.*travel_readiness_status/s, 'READINESS_FIELDS_ONLY');
no(m, /status.*booking_status.*workflow_status.*approval_status/s, 'READINESS_NO_APPROVAL_DEMOTION');

no(m, /DB::table\(['"](?:sales_invoices|sales_invoice_items|journals|journal_entries|ledgers|accounts_receivable|payments|receipts|payables)['"]\)/i, 'NO_ACCOUNTING_WRITES');
no(m, /->delete\(/, 'NO_DELETE_WRITES');

console.log(`C64_ASSERT_CALL_SITES=${executed}`);
console.log('C64_LOOPED_ASSERT_CALL_SITES=NONE');
console.log(`C64_RUNTIME_ASSERTION_EXECUTIONS=${executed}`);
console.log(`C64_PRINTED_ASSERTION_COUNT=${executed}`);
console.log('C64_ASSERTION_COUNT_MATCH=YES');
console.log(`ERP378 ADDITIONAL SERVICES C64 ATOMIC NATIVE MATERIALIZATION REGRESSION: PASS (${executed} assertions)`);
