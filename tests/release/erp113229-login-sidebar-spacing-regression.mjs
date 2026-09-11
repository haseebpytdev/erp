import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const finalizer = read('public/erp-ui/erp-professional-finalize.js');
const middleware = read('app/Http/Middleware/ApplyErpReleaseMetadata.php');
const routes = read('routes/erp103179.php');
const version = read('VERSION.txt').trim();

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };

ok(finalizer.includes("heading.style.setProperty('margin', '16px 9px 9px', 'important')"), 'section heading has explicit top, horizontal and bottom separation');
ok(finalizer.includes("heading.style.setProperty('padding', '0', 'important')"), 'section heading keeps zero internal padding');
ok(finalizer.includes("heading.style.setProperty('line-height', '1.15', 'important')"), 'section heading line-height remains visually separated from first row');
ok(finalizer.includes("sectionRows.forEach((row, index) =>"), 'section rows track first-row position explicitly');
ok(finalizer.includes("if (index === 0) row.style.setProperty('margin-top', '9px', 'important')"), 'first permitted row retains explicit physical separation from heading');
ok(middleware.includes('if (! $request->user())'), 'guest pages skip professional UI asset injection');
ok(middleware.includes('Never inject those authenticated asset URLs into guest/login pages'), 'guest/login redirect rationale is documented');
ok(routes.includes("Route::middleware(['auth'])->group"), 'professional asset route remains authenticated for ERP pages');
ok(routes.includes("'/system/erp-assets/erp-professional.js'"), 'professional JS route remains present');
ok(version === 'v1.1.33.238-ERP11.3.238', 'functional checkpoint does not bump deployed release metadata');

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
