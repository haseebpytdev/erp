import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const finalizer = read('public/erp-ui/erp-professional-finalize.js');
const shellCss = read('public/erp-ui/erp-shell-spacing.css');
const middleware = read('app/Http/Middleware/ApplyErpReleaseMetadata.php');
const routes = read('routes/erp103179.php');
const version = read('VERSION.txt').trim();

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };

ok(finalizer.includes("nav.insertBefore(heading, sectionRows[0])"), 'section headings are inserted in place before their first native row');
ok(!finalizer.includes("heading.style.setProperty('margin'") && !finalizer.includes("row.style.setProperty('margin-top'"), 'runtime code does not own sidebar spacing');
ok(shellCss.includes('margin:14px 8px 6px!important'), 'section heading has explicit CSS top/horizontal/bottom separation');
ok(shellCss.includes('min-height:34px!important') && shellCss.includes('padding:7px 8px!important'), 'root rows use deterministic compact geometry');
ok(shellCss.includes('min-height:30px!important') && shellCss.includes('margin:2px 0 6px 22px!important'), 'nested rows have separate compact geometry and indentation');
ok(middleware.includes('if (! $request->user())'), 'guest pages skip professional UI asset injection');
ok(middleware.includes('Never inject those authenticated asset URLs into guest/login pages'), 'guest/login redirect rationale is documented');
ok(routes.includes("Route::middleware(['auth'])->group"), 'professional asset route remains authenticated for ERP pages');
ok(routes.includes("'/system/erp-assets/erp-professional.js'"), 'professional JS route remains present');
ok(version === 'v1.1.33.249-ERP11.3.249', 'functional .241 checkpoint does not bump deployed release metadata');

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
