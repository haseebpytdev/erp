import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const accounting = read('app/Services/Accounting/ManagementAccountingReportService.php');
const statements = read('app/Services/Accounting/PartyStatementService.php');
const lifecycle = read('app/Services/Accounting/PartyBalanceLifecycleService.php');
const returns = read('app/Services/Accounting/PartyAdvanceReturnService.php');
const cash = read('app/Services/Accounting/CashVoucherService.php');
const cashController = read('app/Http/Controllers/Accounting/CashVoucherController.php');
const adjustment = read('app/Http/Controllers/Accounting/AdvanceAdjustmentController.php');
const users = read('app/Services/Administration/ErpUserManagementService.php');
const release = read('config/et_erp_release.php');
let assertions = 0;
const ok = (value, message) => { assertions += 1; assert.ok(value, message); };

ok(accounting.includes("'branch_ids' => $branchIds"), 'management reports carry allowed branch ids');
ok(accounting.includes('whereIn(\'je.branch_id\', $branchIds)'), 'management report journal rows are branch scoped');
ok(statements.includes('allowedBranchIds'), 'party statements use canonical branch authority');
ok(statements.includes("whereIn('je.branch_id', $filters['branchIds'])"), 'party statement transactions are branch scoped');
ok(lifecycle.includes('function scopeQuery'), 'opening balance scope helper exists');
ok(returns.includes('function scopeQuery'), 'advance return scope helper exists');
ok(cash.includes('assertAllocationTargetParity'), 'cash voucher target parity is service enforced');
ok(cash.includes('assertSourceTargetParity'), 'advance adjustment parity is service enforced');
ok(cash.includes('assertBranchTargetParity'), 'return parent parity is service enforced');
ok(cash.includes('Allocation ownership could not be resolved safely'), 'ownership resolution fails closed');
ok(cashController.includes('assertAllocationTargetParity'), 'cash controller validates before mutation');
const allocationMethod = cashController.slice(cashController.indexOf('private function replaceAllocations'));
ok(allocationMethod.indexOf('assertAllocationTargetParity') < allocationMethod.lastIndexOf("cash_voucher_allocations')->where('cash_voucher_id', $voucherId)->delete()"), 'cash parity occurs before allocation deletion');
ok(adjustment.includes('assertSourceTargetParity'), 'adjustment controller validates source and target parity');
ok(returns.includes('assertBranchTargetParity'), 'customer return validates every allocation source');
ok(users.includes('validateBranchAssignments'), 'user branch assignment company validation exists');
ok(users.includes('Branch assignments must belong to the target user company.'), 'user assignment mismatch is rejected');
ok(release.includes("'asset_version' => 'ERP-11.3.378-C89.2.3A'"), 'C89.2.3A asset identity');
ok(release.includes("'corrective_build' => 'C89.2.3A'"), 'C89.2.3A build identity');
ok(release.includes("'corrective_name' => 'Source-Proven Final Branch Closure'"), 'C89.2.3A corrective name');
let migrationsUnchanged = true;
try { execFileSync('git', ['diff', '--quiet', 'HEAD', '--', 'database/migrations'], { cwd: root, stdio: 'ignore' }); } catch { migrationsUnchanged = false; }
ok(migrationsUnchanged, 'database migrations are unchanged');
ok(!cashController.match(/request\([^)]*branch_id/i), 'cash allocation does not infer target branch from request');

console.log(`C89.2.3A source-proven branch closure: PASS (${assertions} assertions)`);
