import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const js = read('public/erp-ui/erp-professional.js');
const version = read('VERSION.txt').trim();

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };

ok(js.includes("const findDashboardRow = () =>"), 'sidebar has deterministic dashboard/home discovery');
ok(js.includes("linkMatches(candidate, ['dashboard', 'home'])"), 'native Dashboard and Home labels are accepted');
ok(js.includes("['/', '/dashboard', '/home'].includes(candidatePath)"), 'canonical dashboard/home paths are accepted as fallback');
ok(js.includes('const dashboardRow = findDashboardRow();'), 'dashboard row uses robust discovery before section regrouping');
ok(js.includes("dashboardRow.dataset.etSidebarGroup = 'dashboard'"), 'dashboard row remains outside grouped sections at the top');
ok(js.includes("heading.style.setProperty('margin', '10px 9px 0', 'important')"), 'section headings keep a compact gap above and no extra gap below');
ok(js.includes("heading.style.setProperty('line-height', '1', 'important')"), 'section headings use tight reference line height');
ok(js.includes("heading.style.setProperty('min-height', '0', 'important')"), 'section headings cannot inherit clickable-row height');
ok(!js.includes("document.createElement('a')"), 'dashboard recovery does not create a fake navigation link');
ok(version === 'v1.1.33.230-ERP11.3.230', 'functional checkpoint does not bump release version');

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
