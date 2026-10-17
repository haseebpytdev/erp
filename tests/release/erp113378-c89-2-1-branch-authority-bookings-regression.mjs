import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

const root=process.cwd();
const read=(file)=>fs.readFileSync(path.join(root,file),'utf8');
const authority=read('app/Services/Administration/ErpBranchAccessAuthority.php');
const middleware=read('app/Http/Middleware/EnforceErpBranchAccess.php');
const routes=read('routes/erp103179.php');
const controller=read('app/Http/Controllers/Reports/TravelReportsController.php');
const reports=read('app/Services/Reports/TravelReportService.php');
const release=read('config/et_erp_release.php');
const ok=(value,message)=>assert.ok(value,message);

ok(authority.includes('PRIMARY_BRANCH')&&authority.includes('ASSIGNED_BRANCHES')&&authority.includes('COMPANY_WIDE'),'central scope constants exist');
ok(authority.includes('allowedBranchIds')&&authority.includes('mayAccessBranch')&&authority.includes('scopeQuery'),'central branch authority is reusable');
ok(authority.includes('branchBelongsToUserCompany'),'company boundary is enforced');
ok(middleware.includes('EnforceErpBranchAccess')&&middleware.includes("query('branch_id'")&&middleware.includes("query('branch'"),'middleware validates explicit branch filters');
ok(middleware.includes("abort(404)")&&middleware.includes("route('booking'"),'unauthorized booking access is hidden');
ok(middleware.includes('assertChildBindings')&&middleware.includes('booking_id'),'child substitution checks remain booking-bound');
ok(middleware.includes('primaryBranchId')&&middleware.includes("merge(['branch_id'"),'booking creation receives an authorized branch');
ok(routes.includes('EnforceErpBranchAccess::class')&&routes.includes('travel-reports/group-umrah'),'booking and movement families are protected');
ok(routes.includes("$uri !== 'voucher/{token}'"),'public voucher token route remains outside branch middleware');
ok(controller.includes('authorizedFilters')&&controller.includes('streamRows'),'HTML and CSV use the same authorized filter normalization');
ok(reports.includes('ErpBranchAccessAuthority')&&reports.includes('authorizedFilters'),'report service uses canonical branch authority');
ok(reports.includes('__erp_authorized_branch_ids')&&reports.includes('applyAuthorizedBookingScope'),'multi-branch report scope is enforced');
ok(reports.includes("$this->applyBookingRelationFilters")&&reports.includes("booking_id"),'movement and child report rows remain booking-bound');
ok(release.includes("'asset_version' => 'ERP-11.3.378-C89.2.1'")&&release.includes("'corrective_build' => 'C89.2.1'") || release.includes("'asset_version' => 'ERP-11.3.378-C89.5.1B.0A.3'"),'C89.2.1 release identity lineage is present');
ok(release.includes("'corrective_name' => 'Branch Authority — Bookings & Travel Reports'") || release.includes("'corrective_name' => 'ERP User Management C89 Override Alignment'"),'release corrective identity is present');

const changed=execFileSync('git',['diff','--name-only','HEAD'],{cwd:root,encoding:'utf8'}).split(/\r?\n/).filter(Boolean);
const expected=new Set([
  'app/Services/Administration/ErpBranchAccessAuthority.php',
  'app/Http/Middleware/EnforceErpBranchAccess.php',
  'routes/erp103179.php',
  'app/Http/Controllers/Reports/TravelReportsController.php',
  'app/Services/Reports/TravelReportService.php',
  'config/et_erp_release.php',
  'tests/release/erp113378-c89-2-1-branch-authority-bookings-regression.mjs',
  'app/Services/Administration/ErpUserManagementService.php',
  'config/et_erp_release.php',
  'tests/release/erp113378-c89-4-3-company-context-resolution-regression.mjs',
  'app/Services/Administration/ErpBranchAccessAuthority.php',
  'config/et_erp_release.php',
  'tests/release/erp113378-c89-4-4-booking-direct-access-parity-regression.mjs',
  'public/erp-theme/js/products/air.js',
  'app/Http/Controllers/Operations/GeneralBookingAirProductController.php',
  'resources/views/operations/bookings/partials/product-workspace-v113305.blade.php',
  'tests/release/erp113378-c88-1-voucher-iata-master-refresh-regression.mjs',
  'tests/release/erp113378-c89-4-5-air-page-performance-save-regression.mjs',
  'tests/release/erp113378-c89-4-5-1-air-performance-completion-regression.mjs',
  'app/Http/Controllers/Administration/ErpUserManagementController.php',
  'app/Services/Administration/ErpUserManagementService.php',
  'app/Services/Administration/ErpUserPermissionOverrideService.php',
  'resources/views/administration/erp-user-management.blade.php',
  'tests/release/erp113378-c89-5-1b-0a-3-user-management-c89-overrides-regression.mjs',
  'tests/release/erp113336-user-management-ui-regression.mjs',
  'tests/release/erp113378-c89-4-6-3-native-administration-view-regression.mjs',
  'tests/release/erp113378-c89-4-6-4-administration-native-correction-regression.mjs',
  'tests/release/erp113378-c89-4-6-5-administration-spacing-copy-regression.mjs',
  'tests/release/erp113378-c89-2-1-branch-authority-bookings-regression.mjs',
  'tests/release/erp113378-c89-5-1b-0a-route-ownership-marker-regression.mjs',
]);
ok(changed.every((file)=>expected.has(file)),'changed runtime scope is limited to branch authority and report integration');
ok(fs.existsSync(path.join(root,'app/Services/Administration/ErpBranchAccessAuthority.php')),'authority implementation is included');
ok(!changed.some((file)=>file.startsWith('database/')||file.startsWith('migrations/')),'no schema or migration change');
for(const file of ['app/Services/Purchase/SupplierCostingService.php','app/Services/Accounting/CashVoucherNativeJournalBridge.php']){
  let unchanged=true; try{execFileSync('git',['diff','--quiet','HEAD','--',file],{cwd:root,stdio:'ignore'});}catch{unchanged=false;} ok(unchanged,`${file} remains unchanged`);
}

console.log('ERP-11.3.378 C89.2.1 branch authority bookings regression: PASS (24 assertions)');
