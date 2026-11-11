import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

const root = process.cwd();
const file = 'app/Services/Operations/AirServiceRemovalEligibilityResolver.php';
const source = fs.readFileSync(path.join(root, file), 'utf8');
const ok = (condition, message) => assert.ok(condition, message);

ok(source.includes('final class AirServiceRemovalEligibilityResolver'), 'dedicated resolver exists');
ok(source.includes('public function resolve(int $bookingId, int $serviceId'), 'stable resolver entry point exists');
ok(source.includes("public const ELIGIBLE = 'ELIGIBLE'"), 'eligible code is stable');
ok(source.includes("public const UNKNOWN = 'UNKNOWN_DEPENDENCY'"), 'unknown dependency code is stable');
ok(source.includes("source_booking_service_id"), 'invoice-line linkage is checked');
ok(source.includes("service_cost_allocations"), 'service cost allocations are checked');
ok(source.includes("supplier_costing_source_links"), 'supplier costing links are checked');
ok(source.includes('BookingSupplierObligationResolver') && source.includes('SUPPLIER_OBLIGATION_LINKED'), 'native supplier obligations are checked');
ok(source.includes("general_booking_billing_batch_items"), 'billing batch items are checked');
ok(source.includes('cash_vouchers') && source.includes('journal_entries'), 'voucher/accounting checks are present');
ok(source.includes('customer_advance_return_allocations') && source.includes('REFUND_LINKED'), 'native refund allocation linkage is checked');
ok(source.includes('companyIdForUser') && source.includes('mayAccessBranch'), 'company and branch context is validated');
ok(source.includes('where(\'source_booking_service_id\', $serviceId)'), 'invoice status is not used to bypass a linked line');
ok(source.includes('AirRemovalUnknownDependency'), 'unknown schema/linkage fails closed');
ok(source.includes('AirRemovalBlocked'), 'known dependencies block removal');
ok(!/->(?:delete|update|insert|upsert|save|create)\s*\(/.test(source), 'resolver contains no mutating query calls');
ok(!/DB::statement|DB::unprepared|DB::raw\s*\(/.test(source), 'resolver contains no raw mutation escape hatch');
ok(!source.includes('GeneralBookingAirProductController'), 'resolver is not wired to deletion controller');
const changed = execFileSync('git', ['status', '--short'], { cwd: root, encoding: 'utf8' }).split(/\r?\n/).filter(Boolean).map((line) => line.slice(3));
ok(changed.includes(file), 'resolver is the intended changed runtime file');
const authorizedC92Foundation = new Set([
  file,
  'tests/release/erp113378-c92-5a-air-financial-dependency-guard-regression.mjs',
  'app/Services/Operations/AirServiceRemovalAuthorization.php',
  'app/Services/Operations/AirServiceRemovalConcurrencyContract.php',
  'app/Services/Operations/AirCancellationAuditContract.php',
  'app/Services/Operations/AirServiceCancellationService.php',
  'tests/release/erp113378-c92-5b-air-cancellation-foundation-regression.mjs',
  'tests/release/erp113378-c92-5c-safe-air-cancellation-regression.mjs',
  'tests/release/erp113378-c92-5c-3-financial-writer-concurrency-regression.mjs',
  'app/Services/Accounting/PartyAdvanceReturnService.php',
  'app/Http/Controllers/Accounting/CashVoucherController.php',
  'app/Http/Controllers/Operations/GeneralBookingAirProductController.php',
  'app/Services/Reports/TravelReportService.php',
  'public/erp-theme/js/products/air.js',
  'tests/release/erp113378-c92-5d-air-group-ui-reader-preparation-regression.mjs',
  'tests/release/erp113378-c92-5g-air-save-deletion-bypass-regression.mjs',
  'app/Services/Operations/BookingProductSummaryResolver.php',
  'tests/release/erp113378-c92-5h-active-air-reader-consistency-regression.mjs',
  'app/Http/Controllers/Operations/AirServiceCancellationController.php',
  'tests/release/erp113378-c92-5i-cancellation-request-token-regression.mjs',
  'tests/release/erp113378-c92-5j-air-cancellation-ui-contract-regression.mjs',
]);
ok(changed.every((name) => authorizedC92Foundation.has(name)), 'cumulative C92.5A/C92.5B boundary contains only authorized foundation files');

console.log('ERP-11.3.378 C92.5A Air financial dependency guard regression: PASS (19 assertions)');
