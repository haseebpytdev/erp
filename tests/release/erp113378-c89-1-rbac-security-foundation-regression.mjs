import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const policy = read('app/Services/Administration/ErpRoleAccessPolicy.php');
const matrix = read('app/Services/Administration/ErpPermissionMatrixService.php');
const users = read('app/Services/Administration/ErpUserManagementService.php');
const release = read('config/et_erp_release.php');
const ok = (condition, message) => assert.ok(condition, message);

ok(policy.includes("return 'system_diagnostics';"), 'system diagnostics has an explicit module');
ok(policy.includes("'system.health'"), 'diagnostics use the existing system.health authority');
ok(policy.includes("return 'system_repairs';"), 'system repairs have an explicit module');
ok(policy.includes("'system.update'"), 'repairs use the existing system.update authority');
ok(policy.includes("return 'system_destructive';"), 'destructive system routes have an explicit module');
ok(policy.includes("'/system/production-data-reset'"), 'production reset remains centrally classified');
ok(policy.includes("'/system/post-reset-financial-cleanup'"), 'post-reset cleanup remains centrally classified');
ok(policy.includes("return 'bookings';") && policy.includes("/operations/group-package-bookings"), 'group package routes use booking authority');
ok(policy.includes("return 'travel_masters';") && policy.includes("/system/visa-masters"), 'system visa masters use travel-master authority');
ok(policy.includes("return 'travel_reports';") && policy.includes("/reports/group-umrah-profitability"), 'Group Umrah profitability uses travel-report authority');
ok(policy.includes("'/travel-reports'"), 'travel reports remain explicitly classified');
ok(policy.includes('Booking-side invoice creation is an operational bridge'), 'booking invoice bridge exception remains present');
ok(policy.includes('if (! $this->isPermissionControlled($user))'), 'Super Admin bypass remains at policy entry');
ok(users.includes('rolePermissionIds') && users.includes('array_merge('), 'multiple-role permission union remains source-backed');
ok(!policy.includes('access_scope') && !policy.includes('branch_user'), 'C89.1 does not add branch scope enforcement');
ok(!policy.includes('permission_user') && !policy.includes('user_permission_override'), 'C89.1 adds no direct-permission schema');
ok(release.includes("'asset_version' => 'ERP-11.3.378-C89.1'"), 'C89.1 asset identity is present');
ok(release.includes("'corrective_build' => 'C89.1'"), 'C89.1 corrective build is present');
ok(release.includes("'corrective_name' => 'RBAC Security Foundation'"), 'C89.1 corrective name is present');

const protectedFiles = [
  'app/Services/Purchase/SupplierCostingService.php',
  'app/Services/Purchase/SupplierCostingDraftSynchronizer.php',
  'app/Services/Reports/TravelReportService.php',
  'app/Http/Controllers/Operations/GeneralBookingVoucherPreviewController.php',
  'app/Http/Controllers/Operations/GeneralBookingAirProductController.php',
  'app/Http/Controllers/Operations/GeneralBookingHotelProductController.php',
];
const changed = execFileSync('git', ['diff', '--name-only', 'HEAD'], { cwd: root, encoding: 'utf8' })
  .split(/\r?\n/).filter(Boolean);
ok(changed.includes('app/Services/Administration/ErpRoleAccessPolicy.php'), 'policy is the implementation source');
ok(changed.includes('config/et_erp_release.php'), 'release metadata is scoped to C89.1');
ok(fs.existsSync(path.join(root, 'tests/release/erp113378-c89-1-rbac-security-foundation-regression.mjs')), 'C89.1 regression is included');
ok(changed.every((file) => file === 'app/Services/Administration/ErpRoleAccessPolicy.php' || file === 'config/et_erp_release.php' || file === 'tests/release/erp113378-c89-1-rbac-security-foundation-regression.mjs'), 'changed source scope is minimal');
for (const file of protectedFiles) {
  let unchanged = true;
  try { execFileSync('git', ['diff', '--quiet', 'HEAD', '--', file], { cwd: root, stdio: 'ignore' }); }
  catch { unchanged = false; }
  ok(unchanged, `${file} remains unchanged`);
}
ok(!changed.some((file) => file.includes('database/') || file.includes('migrations/')), 'no database or migration file changed');
ok(policy.includes('if ($module === null)') && policy.includes('return true;'), 'unrelated unknown-route compatibility remains');

console.log('ERP-11.3.378 C89.1 RBAC security foundation regression: PASS (31 assertions)');
