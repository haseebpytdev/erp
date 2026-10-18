import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = (path) => fs.readFileSync(path, 'utf8');
const accounts = read('resources/views/mobile/accounts/index.blade.php');
const responsive = read('resources/views/mobile/accounts/_responsive.blade.php');
const index = read('resources/views/mobile/movements/index.blade.php');
const report = read('resources/views/mobile/movements/report.blade.php');
const controller = read('app/Http/Controllers/Reports/MobileMovementController.php');
const service = read('app/Services/Reports/TravelReportService.php');
const routes = read('routes/erp103179.php');
const check = (value, message) => assert.ok(value, message);

check(index.includes("@include('mobile.accounts._responsive')") && report.includes("@include('mobile.accounts._responsive')"), 'movement pages reuse the Mobile Accounts responsive partial');
check(index.includes('mobile-accounts-page') && report.includes('mobile-accounts-page'), 'movement pages use the Mobile Accounts shell class');
check(index.includes('mobile-card') && report.includes('mobile-card'), 'movement cards use the Mobile Accounts card family');
check(index.includes('mobile-brand') && report.includes('mobile-brand'), 'movement headers use the Mobile Accounts header pattern');
check(index.includes('mobile-title') && report.includes('mobile-title') && index.includes('mobile-sub') && report.includes('mobile-sub'), 'movement title/subtitle styles reuse Accounts conventions');
check(responsive.includes('body:has(.mobile-accounts-page)') && responsive.includes('display: none !important'), 'shared responsive shell hides desktop chrome');
check(!index.includes('body:has(.mobile-movements-page)') && !report.includes('body:has(.mobile-movement-report)'), 'movement views do not duplicate shell hide rules');
check(index.includes('route(\'mobile.accounts.index\')') && report.includes('route(\'mobile.movements.index\')'), 'mobile back navigation remains present');
check(!index.includes('<table') && !report.includes('<table'), 'mobile movement pages use cards rather than desktop tables');
for (const label of ['Today', 'Tomorrow', 'Next 3 Days', 'Next Movements']) check(index.includes(label), `${label} dashboard section remains present`);
for (const filter of ['From Date', 'To Date']) check(report.includes(filter), `${filter} filter remains present`);
check(report.includes('Airport') && report.includes('Hotel Name'), 'C87.2 airport/hotel filter semantics remain present');
check(!report.match(/name="(status|branch|branch_id|customer|customer_id)"/), 'locked-out report filters remain absent');
check(controller.includes('mobileMovementSnapshot') && service.includes('public function mobileMovementSnapshot'), 'dashboard uses the request-scoped movement snapshot');
check((controller.match(/mobileMovementSnapshot/g) || []).length === 1, 'dashboard invokes one snapshot instead of repeated report evaluations');
check(service.includes('array_slice($upcoming, 0, 20)'), 'upcoming display remains capped at 20');
check(service.includes("$dateEvents->where('date', $today)->count()") && service.includes("$dateEvents->where('date', $tomorrow)->count()"), 'snapshot derives authoritative day counts from the uncapped event collections');
check(routes.includes('EnforceErpRoleScopedAccess::class') && routes.includes('EnforceErpBranchAccess::class'), 'movement route security remains unchanged');
check(accounts.includes('mobile-card'), 'Mobile Accounts card structure remains intact');

console.log('C90.7.3 mobile movements UI consistency regression: PASS (22 assertions)');
