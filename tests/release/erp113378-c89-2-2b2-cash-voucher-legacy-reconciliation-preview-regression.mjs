import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const service = read('app/Services/Accounting/CashVoucherLegacyBranchReconciliationService.php');
const controller = read('app/Http/Controllers/Accounting/CashVoucherLegacyBranchReconciliationController.php');
const routes = read('routes/erp103179.php');
const view = read('resources/views/accounting/cash-vouchers/legacy-branch-reconciliation.blade.php');
const migration = read('database/migrations/2026_10_15_220000_add_company_and_branch_to_cash_vouchers.php');
const release = read('config/et_erp_release.php');
const b1Service = read('app/Services/Accounting/CashVoucherService.php');
let assertions = 0;
const ok = (value, message) => { assertions += 1; assert.ok(value, message); };

ok(service.includes('final class CashVoucherLegacyBranchReconciliationService'), 'focused classifier exists');
ok(service.includes('PAGE_SIZE = 100'), 'classifier is batched');
ok(service.includes('chunkById'), 'summary avoids unlimited memory');
ok(service.includes('bookingEvidence'), 'booking adapter exists');
ok(service.includes("source_type','cash_voucher"), 'journal source type is exact');
ok(service.includes("source_id"), 'journal source id is exact');
ok(service.includes('SOURCE_MISMATCH'), 'mismatch classification exists');
ok(service.includes('MULTIPLE_SOURCES_CONSISTENT'), 'multiple-source classification exists');
ok(service.includes('BOOKING_PROVEN'), 'booking-only classification exists');
ok(service.includes('JOURNAL_PROVEN'), 'journal-only classification exists');
ok(service.includes('SOURCE_DOCUMENT_PROVEN'), 'source-only classification exists');
ok(service.includes('UNRESOLVED'), 'unresolved classification exists');
ok(service.includes('proposed_company_id'), 'proposed ownership is display-only');
ok(service.includes('current_ownership_match'), 'current ownership is separate evidence');
ok(!service.match(/->(update|insert|delete|upsert|save|create)\s*\(/i), 'classifier has no business-data write call');
ok(!service.match(/\b(UPDATE|INSERT|DELETE)\b/i), 'classifier has no mutation SQL');
ok(!service.includes('created_by') && !service.includes('posted_by'), 'user identity is not ownership evidence');
ok(!service.includes('singleton'), 'singleton fallback inference is absent');
ok(!service.includes('current user'), 'current-user fallback inference is absent');

ok(controller.includes('isSuperAdmin'), 'diagnostic is super-admin gated');
ok(controller.includes("abort_unless($request->user()"), 'authentication is required');
ok(controller.includes('streamDownload'), 'CSV export exists');
ok(controller.includes('authorizeDiagnostic'), 'page and export share authorization');
ok(!controller.match(/->(update|insert|delete|upsert|save|create)\s*\(/i), 'controller has no business-data write call');
ok(routes.includes('cash-voucher-legacy-reconciliation'), 'diagnostic route exists');
ok(routes.includes('cash-voucher-legacy-reconciliation/export'), 'export route exists');
ok(routes.includes('EnforceErpRoleScopedAccess::class'), 'protected system route middleware exists');
ok(view.includes('Export CSV'), 'page exposes read-only export');
ok(!view.match(/>\s*(Fix|Apply|Backfill|Resolve|Save)\s*</i), 'page has no mutation controls');
ok(view.includes('Booking Proven') && view.includes('Journal Proven') && view.includes('Unresolved'), 'summary cards exist');
ok(view.includes('Classification') && view.includes('Evidence'), 'diagnostic table exists');

let migrationUnchanged = true;
try { execFileSync('git', ['diff', '--quiet', 'HEAD', '--', 'database/migrations/2026_10_15_220000_add_company_and_branch_to_cash_vouchers.php'], { cwd: root, stdio: 'ignore' }); } catch { migrationUnchanged = false; }
ok(migrationUnchanged, 'B1 migration is unchanged');
ok(b1Service.includes('Legacy cash vouchers are read-only'), 'B1 legacy mutation protection remains');
ok(release.includes("'asset_version' => 'ERP-11.3.378-C89.2.2B2'"), 'B2 asset identity');
ok(release.includes("'corrective_build' => 'C89.2.2B2'"), 'B2 build identity');
ok(release.includes("'corrective_name' => 'Cash Voucher Legacy Reconciliation Preview'"), 'B2 corrective name');
ok(release.includes("'asset_version' => 'ERP-11.3.378-C89.2.2B1'"), 'B1 historical identity retained');

console.log(`C89.2.2B2 legacy cash voucher reconciliation preview: PASS (${assertions} assertions)`);
