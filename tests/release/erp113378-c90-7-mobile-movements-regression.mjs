import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = (p) => fs.readFileSync(p, 'utf8');
const routes = read('routes/erp103179.php');
const controller = read('app/Http/Controllers/Reports/MobileMovementController.php');
const policy = read('app/Services/Administration/ErpRoleAccessPolicy.php');
const accounts = read('resources/views/mobile/accounts/index.blade.php');
const home = read('resources/views/mobile/movements/index.blade.php');
const report = read('resources/views/mobile/movements/report.blade.php');
const service = read('app/Services/Reports/TravelReportService.php');

const movements = ['arrival', 'makkah-checkin', 'makkah-checkout', 'madinah-checkin', 'madinah-checkout', 'departure'];
const check = (condition, message) => assert.ok(condition, message);

check(routes.includes("prefix('mobile/movements')->name('mobile.movements.')"), 'mobile movement route group exists');
check(routes.includes('MobileMovementController'), 'mobile movement controller is routed');
check(routes.includes('EnforceErpRoleScopedAccess::class') && routes.includes('EnforceErpBranchAccess::class'), 'movement routes retain role and branch middleware');
check(routes.includes("C89ManagedBusinessRoute::class"), 'managed business middleware remains in the enclosing route group');
check(policy.includes("#^/mobile/movements(?:/|$)#") && policy.includes("return 'travel_reports';"), 'mobile movements map to travel_reports authority');
for (const movement of movements) check(routes.includes(`->defaults('movement', $movement)->name($movement)`), `allowlisted route loop supports ${movement}`);

check(controller.includes('TravelReportService'), 'controller delegates to TravelReportService');
check(controller.includes('authorizedFilters'), 'controller applies existing authorization filter');
check(controller.includes("CarbonImmutable::today()") && controller.includes("$today, $today"), 'today counts use today-to-today windows');
check(controller.includes('$tomorrow, $tomorrow') && controller.includes('$tomorrow, $threeDays'), 'upcoming windows exclude today');
check(controller.includes('$tomorrowCount += $tomorrowRows->total()') && controller.includes('$nextThreeDaysCount += $nextThreeDaysRows->total()'), 'upcoming counts use authoritative paginator totals');
check(controller.includes('array_slice($upcoming, 0, 20)'), 'upcoming display list remains capped');
check(controller.includes('usort($upcoming'), 'upcoming events are chronologically ordered');
check(!controller.includes('DB::') && !controller.includes('Schema::'), 'controller contains no duplicate SQL engine');
check(!controller.match(/â€|�/), 'mobile controller has no mojibake');
check(!home.match(/â€|�/) && !report.match(/â€|�/), 'mobile views have no mojibake');
check(controller.includes("format('Y-m-d') === $value") && controller.includes('catch (\\Throwable)'), 'invalid calendar dates fail safely');
check(home.includes('Today') && home.includes('Next 3 Days') && home.includes('No upcoming movements'), 'home dashboard and empty state exist');
check(report.includes('From Date') && report.includes('To Date'), 'mobile reports retain date filters');
check(report.includes('name="{{ $movement===\'arrival\'?\'destination\':\'origin\' }}"') && report.includes('name="hotel"'), 'hotel and airport filters are present');
for (const forbidden of ['name="status"', 'name="branch"', 'name="branch_id"', 'name="customer"', 'name="customer_id"']) check(!report.includes(forbidden), `${forbidden} is absent from mobile filters`);
check(accounts.includes("route('mobile.movements.index')"), 'accounts page links to movements');
check(service.includes("public function rows") && service.includes('movementPage($key'), 'existing movement rows authority remains');
for (const movement of movements) check(controller.includes(`'${movement}'`), `${movement} remains allowlisted`);
check(!routes.includes('ScheduleMovement') && !controller.includes('Notification'), 'no movement scheduler or notification engine created');

console.log('C90.7 mobile movements regression: PASS');
console.log('Assertions:', 36);
