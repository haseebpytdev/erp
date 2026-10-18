import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = (path) => fs.readFileSync(path, 'utf8');
const index = read('resources/views/mobile/movements/index.blade.php');
const report = read('resources/views/mobile/movements/report.blade.php');
const controller = read('app/Http/Controllers/Reports/MobileMovementController.php');
const routes = read('routes/erp103179.php');
const service = read('app/Services/Reports/TravelReportService.php');
const check = (condition, message) => assert.ok(condition, message);

function bladeBalance(source, name) {
    const tokens = [...source.matchAll(/@(if|foreach|forelse|isset|unless|endif|endforeach|endforelse|endisset|endunless|elseif|else|empty)\b/g)].map((match) => match[1]);
    const stack = [];
    for (const token of tokens) {
        if (['if', 'foreach', 'forelse', 'isset', 'unless'].includes(token)) {
            stack.push(token);
            continue;
        }
        if (['elseif', 'else', 'empty'].includes(token)) {
            check(stack.length > 0, `${name} has an orphan @${token}`);
            continue;
        }
        const expected = {
            endif: 'if',
            endforeach: 'foreach',
            endforelse: 'forelse',
            endisset: 'isset',
            endunless: 'unless',
        }[token];
        check(stack.at(-1) === expected, `${name} has mismatched @${token}`);
        stack.pop();
    }
    check(stack.length === 0, `${name} has unclosed Blade directives: ${stack.join(', ')}`);
}

bladeBalance(index, 'movement index view');
bladeBalance(report, 'movement report view');
check(!index.match(/â€|�/) && !report.match(/â€|�/), 'movement views contain no mojibake');
check(index.includes('Today') && index.includes('Tomorrow') && index.includes('Next 3 Days'), 'summary labels remain present');
check(index.includes('No upcoming movements in the next 3 days.'), 'upcoming empty state remains present');
check(index.includes("route('mobile.movements.'.$key"), 'today cards use the existing movement route family');
for (const movement of ['arrival', 'makkah-checkin', 'makkah-checkout', 'madinah-checkin', 'madinah-checkout', 'departure']) {
    check(controller.includes(`'${movement}'`) && routes.includes(`->defaults('movement', $movement)`), `${movement} remains an allowlisted movement route`);
}
check(index.includes("route('mobile.accounts.index')"), 'Mobile Accounts navigation remains present');
check(report.includes('From Date') && report.includes('To Date'), 'report date filters remain present');
check(report.includes('name="hotel"') && report.includes('destination') && report.includes('origin'), 'report filter semantics remain present');
check(controller.includes('$tomorrowRows->total()') && controller.includes('$nextThreeDaysRows->total()'), 'authoritative summary totals remain in controller');
check(controller.includes('array_slice($upcoming, 0, 20)'), 'upcoming display list remains capped');
check(controller.includes('catch (\\Throwable)'), 'invalid dates remain fail-safe');
check(routes.includes('EnforceErpRoleScopedAccess::class') && routes.includes('EnforceErpBranchAccess::class'), 'movement security middleware remains');
check(service.includes('public function rows'), 'TravelReportService remains the report authority');

console.log('C90.7.2 mobile movements Blade regression: PASS (18 assertions)');
