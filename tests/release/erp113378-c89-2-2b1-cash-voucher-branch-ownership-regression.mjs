import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
let assertions = 0;
const ok = (condition, message) => { assertions += 1; assert.ok(condition, message); };

const migration = read('database/migrations/2026_10_15_220000_add_company_and_branch_to_cash_vouchers.php');
const controller = read('app/Http/Controllers/Accounting/CashVoucherController.php');
const service = read('app/Services/Accounting/CashVoucherService.php');
const bridge = read('app/Services/Accounting/CashVoucherNativeJournalBridge.php');
const authority = read('app/Services/Administration/ErpBranchAccessAuthority.php');
const release = read('config/et_erp_release.php');

ok(migration.includes("Schema::table('cash_vouchers'"), 'cash voucher migration exists');
ok(migration.includes("$table->unsignedBigInteger('company_id')->nullable()"), 'company_id is nullable');
ok(migration.includes("$table->unsignedBigInteger('branch_id')->nullable()"), 'branch_id is nullable');
ok(migration.includes('cash_vouchers_company_branch_idx'), 'company/branch index exists');
ok(migration.includes('cash_vouchers_status_branch_idx'), 'status/branch index exists');
ok(migration.includes('cash_vouchers_type_branch_idx'), 'type/branch index exists');
ok(migration.includes('Schema::hasColumn'), 'migration is rerunnable');

ok(service.includes('ErpBranchAccessAuthority'), 'service uses canonical authority');
ok(service.includes('resolveVoucherOwnership'), 'creation ownership is resolved server-side');
ok(service.includes('bookingOwnership'), 'booking ownership is authoritative');
ok(service.includes('assertMayAccessBranch'), 'branch scope is enforced');
ok(service.includes('Legacy cash vouchers without branch ownership'), 'normal-user legacy denial exists');
ok(service.includes('Legacy cash vouchers are read-only'), 'legacy mutation denial exists');
ok(service.includes('scopeVoucherQuery'), 'register query scope exists');
ok(service.includes('branch_id'), 'service persists branch identity');
ok(service.includes('company_id'), 'service persists company identity');

ok(controller.includes("'company_id' => $ownership['company_id']"), 'store persists company ownership');
ok(controller.includes("'branch_id' => $ownership['branch_id']"), 'store persists branch ownership');
ok(controller.includes('resolveVoucherOwnership'), 'controller cannot trust client ownership');
ok(controller.includes('scopeVoucherQuery'), 'register applies canonical scope');
ok(controller.includes('assertVoucherAccess'), 'direct object access is guarded');
ok(controller.includes("'branch_id' => ['nullable', 'integer', 'min:1']"), 'branch input is validated');
ok(controller.includes("'company_id' => ['nullable', 'integer', 'min:1']"), 'company input is validated');

ok(bridge.includes('persisted voucher branch/company ownership is inconsistent'), 'journal bridge rejects mismatch');
ok(bridge.includes('persisted voucher ownership is incomplete'), 'journal bridge rejects incomplete ownership');
ok(bridge.includes('$voucher->branch_id'), 'journal bridge prefers persisted branch');
ok(bridge.includes('$voucher->company_id'), 'journal bridge prefers persisted company');

ok(authority.includes('class ErpBranchAccessAuthority'), 'canonical authority class remains intact');
ok(authority.includes('function mayAccessBranch'), 'canonical branch predicate remains intact');
ok(!service.includes('DB::table(\'cash_vouchers\')->insert'), 'service does not create duplicate voucher logic');
ok(!migration.includes('->constrained('), 'migration follows existing no-FK cash voucher convention');
ok(release.includes("'asset_version' => 'ERP-11.3.378-C89.2.2B1'"), 'release asset identity');
ok(release.includes("'corrective_build' => 'C89.2.2B1'"), 'release corrective identity');
ok(release.includes("'corrective_name' => 'Cash Voucher Branch Ownership'"), 'release corrective name');
ok(release.includes("'version' => 'v1.1.33.378-ERP11.3.378'"), 'application version preserved');
ok(release.includes("'release' => 'ERP-11.3.378'"), 'release preserved');

console.log(`C89.2.2B1 cash voucher ownership regression: PASS (${assertions} assertions)`);
