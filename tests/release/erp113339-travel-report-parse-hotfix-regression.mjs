import assert from 'node:assert/strict';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const servicePath = new URL('../../app/Services/Reports/TravelReportService.php', import.meta.url);
const service = fs.readFileSync(servicePath, 'utf8');
const parent = execFileSync('git', ['show', 'd5d4b05699a95868e16a0c900cd32c5f71c0d7ec:app/Services/Reports/TravelReportService.php'], { encoding: 'utf8' });
let assertions = 0;
const ok = (value, message) => { assertions += 1; assert.ok(value, message); };

ok(fs.existsSync(servicePath), 'TravelReportService exists');
ok(service.includes('applyNativeChildFilters'), 'child-filter method exists');
ok(service.includes("from('booking_group_package_hotels as h')") && service.includes("$dateMap[$basis]"), 'Group Umrah child-date query path exists');
ok(service.includes("whereDate('h.'.$date,'<=' ,$v)") || service.includes("whereDate('h.'.$date,'<=',$v)))"), 'final child-date whereExists closes both nested calls');
ok(!parent.includes("whereDate('h.'.$date,'<=',$v)))"), 'parent source lacks the corrected closing structure');
ok(service.includes("whereDate('h.'.$date,'<=',$v)));}}}"), 'candidate contains the corrected closing structure');
ok(service.includes("$q->whereExists(fn($x)=>$x->select(DB::raw(1))"), 'Group Umrah child-date predicate is executable');

console.log(`ERP-11.3.339 Travel Report parse hotfix regression: PASS (${assertions} assertions)`);
