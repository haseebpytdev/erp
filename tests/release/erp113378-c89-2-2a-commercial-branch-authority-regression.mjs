import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = path.resolve(import.meta.dirname, '../..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const authority = read('app/Services/Administration/ErpCommercialBranchAuthority.php');
const costing = read('app/Http/Controllers/Purchase/SupplierCostingController.php');
const coverage = read('app/Services/Purchase/SupplierCostingCoverageResolver.php');
const invoice = read('app/Http/Controllers/Sales/StableBookingSalesInvoiceController.php');
const update = read('app/Http/Controllers/Sales/SalesInvoiceDraftUpdateBridgeController.php');
const reports = read('app/Services/Accounting/ManagementAccountingReportService.php');
const routes = read('routes/erp103179.php');
const cash = read('app/Http/Controllers/Accounting/CashVoucherController.php');
const bridge = read('app/Services/Accounting/CashVoucherNativeJournalBridge.php');

assert.match(authority, /ErpBranchAccessAuthority/);
assert.match(authority, /allowedBranchIds/);
assert.match(authority, /assertMayAccessBranch/);
assert.match(authority, /supplier_costings/);
assert.match(authority, /sales_invoices/);
assert.match(costing, /scopeSupplierCostings/);
assert.match(costing, /assertBooking\(/);
assert.match(costing, /assertSupplierCosting\(/);
assert.match(coverage, /allowedBranchIds/);
assert.match(invoice, /assertBooking\(/);
assert.match(update, /assertInvoice\(/);
assert.match(authority, /invoice->branch_id/);
assert.match(authority, /invoice->company_id/);
assert.match(reports, /branchFilter\(/);
assert.match(routes, /EnforceCommercialBranchAuthority/);
assert.doesNotMatch(cash, /ErpCommercialBranchAuthority/);
assert.doesNotMatch(bridge, /ErpCommercialBranchAuthority/);
assert.doesNotMatch(routes, /cash-vouchers.*EnforceCommercialBranchAuthority/);
assert.match(read('config/et_erp_release.php'), /C89\.2\.2A/);

console.log('C89.2.2A commercial branch authority regression: PASS (18 assertions)');
