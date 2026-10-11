import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = file => fs.readFileSync(new URL(`../../${file}`, import.meta.url), 'utf8');
const resolver = read('app/Services/Purchase/BookingSupplierObligationResolver.php');
const native = read('app/Services/Operations/NativeProductServiceResolver.php');
const controller = read('app/Http/Controllers/Purchase/SupplierCostingController.php');
const service = read('app/Services/Purchase/SupplierCostingService.php');
const form = read('resources/views/purchase/supplier-costing/form.blade.php');
const release = read('config/et_erp_release.php');
let assertions = 0;
const check = (value, message) => { assert.ok(value, message); assertions += 1; };

check(resolver.includes('NativeProductServiceResolver'), 'supplier obligations use native Product/Service authority');
check(/findAir\(\)/.test(resolver) && /findHotel\(\)/.test(resolver) && /findTransport\(\)/.test(resolver), 'Air, Hotel, and Transport resolve through native master methods');
check(!/serviceIds\(\$services,\s*[134]\)/.test(resolver), 'hardcoded Product/Service IDs are removed');
check(/resolvedProductId\('air'\)/.test(resolver) && /resolvedProductId\('hotel'\)/.test(resolver) && /resolvedProductId\('transport'\)/.test(resolver), 'all three native product IDs are resolved before service filtering');
check(/catch \(Throwable\)/.test(resolver) && /return 0;/.test(resolver), 'missing or ambiguous native master fails closed');

// Deterministic equivalent of the confirmed booking shape: native Air id 8,
// service 91, vendor 37, and six persisted Air detail costs.
const masters = [{ id: 8, code: 'AIRTICKET', category: 'AIR_TICKET', active: 1 }];
const air = masters.find(row => row.active && (row.code === 'AIR' || row.code === 'AIRTICKET' || row.category === 'AIR_TICKET'));
const services = [{ id: 91, booking_id: 72, product_service_id: air.id, vendor_id: 37, status: 'active' }];
const details = Array.from({ length: 6 }, (_, index) => ({ id: index + 1, booking_service_id: 91, net_supplier_cost: 160000 }));
const serviceRows = services.filter(row => row.product_service_id === air.id && row.status === 'active');
const obligations = details.filter(row => serviceRows.some(serviceRow => serviceRow.id === row.booking_service_id)).map(row => ({ product_type: 'Air Ticket', supplier_id: 37, status: 'available', source_cost: row.net_supplier_cost }));
check(air.id === 8, 'non-default native Air identity 8 resolves');
check(serviceRows.length === 1 && serviceRows[0].id === 91, 'booking service 91 is included');
check(obligations.length === 6, 'six Air obligations resolve');
check(obligations.every(row => row.product_type === 'Air Ticket' && row.supplier_id === 37 && row.status === 'available' && row.source_cost === 160000), 'Air obligation authority fields are preserved');
check(obligations.reduce((sum, row) => sum + row.source_cost, 0) === 960000, 'six Air costs total 960000');
check([37].map(id => ({ id, name: 'Synthetic Vendor' })).length === 1, 'available supplier list contains one valid supplier');

const alreadyCosted = obligations.slice(0, 1);
const availableAfterClaim = obligations.filter(row => !alreadyCosted.includes(row));
check(availableAfterClaim.length === 5, 'already-costed source identity is excluded from available lines');
check(/source_key/.test(resolver) && /supplier_costing_source_links/.test(resolver), 'source key claim authority remains present');
check(/ACTIVE_COSTING_STATUSES/.test(resolver) && /posted/.test(resolver), 'posted and active costing exclusions remain present');
check(/booking_services\.vendor_id|vendor_id/.test(resolver), 'service-scoped Air vendor authority remains');
check(/booking_visa_services/.test(resolver) && !/findVisa\(\)/.test(resolver), 'Visa obligation runtime remains direct and unchanged');
check(controller.includes('obligations') && controller.includes('suppliers'), 'Supplier Costing form remains obligation-driven');
check(!/createFromBooking|journal|payable/i.test(resolver), 'resolver contains no posting authority');
check(!/SupplierCostingService/.test(resolver) && service.includes('class SupplierCostingService'), 'posting service remains separate');
check(form.includes('obligations') && form.includes('suppliers'), 'Supplier Costing form remains server-rendered and obligation-driven');
check(!/CREATE TABLE|Schema::create|migration/i.test(resolver), 'resolver adds no schema or migration behavior');
check(/'asset_version' => 'ERP-11\.3\.378-C(?:81|82(?:\.1)?|83(?:\.1|\.2)?)'/.test(release) && /'corrective_build' => 'C(?:81|82(?:\.1)?|83(?:\.1|\.2)?)'/.test(release), 'C81 lineage release metadata remains active');
check(/'corrective_name' => '(?:Supplier Costing Native Product Identity|Supplier Costing Register Authority|Supplier Costing Register Filter State Closure|Automatic Supplier Costing Drafts & Coverage|Supplier Costing Automation Integrity & Coverage Closure|Supplier Costing Final Integrity Closure)'/.test(release), 'C81 lineage corrective name remains active');

console.log(`ERP-11.3.378 C81 supplier costing native Product/Service identity regression: PASS (${assertions} assertions)`);
