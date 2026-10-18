import fs from 'node:fs';
import assert from 'node:assert/strict';

const source = fs.readFileSync('app/Http/Controllers/Reports/MobileMovementController.php', 'utf8');
const policy = fs.readFileSync('app/Services/Administration/ErpRoleAccessPolicy.php', 'utf8');
const routes = fs.readFileSync('routes/erp103179.php', 'utf8');
const views = fs.readFileSync('resources/views/mobile/movements/index.blade.php', 'utf8') + fs.readFileSync('resources/views/mobile/movements/report.blade.php', 'utf8');
const check = (value, message) => assert.ok(value, message);

check(source.includes('$tomorrowRows->total()'), 'tomorrow uses paginator total');
check(source.includes('$nextThreeDaysRows->total()'), 'next three days uses paginator total');
check(source.includes('array_slice($upcoming, 0, 20)'), 'display list remains capped');
check(source.includes('$tomorrow, $tomorrow'), 'tomorrow excludes today');
check(source.includes('$tomorrow, $threeDays'), 'next three days excludes today');
check(source.includes("format('Y-m-d') === $value"), 'valid dates retain strict date semantics');
check(source.includes('catch (\\Throwable)'), 'invalid calendar dates fail closed');
check(!source.match(/â€|�/) && !views.match(/â€|�/), 'mojibake is absent');
check(policy.includes("#^/mobile/movements(?:/|$)#") && policy.includes("return 'travel_reports';"), 'travel_reports permission remains mapped');
check(routes.includes('EnforceErpRoleScopedAccess::class') && routes.includes('EnforceErpBranchAccess::class'), 'role and branch middleware remain');

console.log('C90.7.1 mobile movement hardening regression: PASS (10 assertions)');
