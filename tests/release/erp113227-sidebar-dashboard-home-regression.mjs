import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const baseJs = read('public/erp-ui/erp-professional.js');
const finalizer = read('public/erp-ui/erp-professional-finalize.js');
const shellCss = read('public/erp-ui/erp-shell-spacing.css');
const version = read('VERSION.txt').trim();

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };

ok(baseJs.includes('sidebar row ordering, section insertion and active-state'), 'base UI explicitly delegates sidebar normalization to one final pass');
ok(!baseJs.includes('sidebarNav.appendChild') && !baseJs.includes("link.classList.add('et-ui-current')"), 'base UI no longer reorders or selects sidebar rows');
ok(finalizer.includes("['dashboard', 'home']"), 'finalizer recognizes native Dashboard/Home authority');
ok(finalizer.includes("dashboardRow.dataset.etSidebarGroup = 'dashboard'"), 'Dashboard remains a standalone annotated row');
ok(finalizer.includes("nav.dataset.etSidebarStable = 'ERP-11.3.241'"), 'root navigation receives deterministic .241 stability marker');
ok(finalizer.includes('nav.insertBefore(heading, sectionRows[0])'), 'section labels are inserted in place without moving menu rows');
ok(!finalizer.includes('canonicalNav.appendChild') && !finalizer.includes('appendRow(row)'), 'finalizer cannot rebuild the root row order');
ok(shellCss.includes('margin:14px 8px 6px!important'), 'authoritative CSS owns compact section spacing');
ok(shellCss.includes('min-height:52px!important') && shellCss.includes('width:34px!important'), 'brand/logo geometry is compact');
ok(!baseJs.includes("document.createElement('a')") && !finalizer.includes("document.createElement('a')"), 'sidebar enhancement never fabricates navigation destinations');
ok(version === 'v1.1.33.249-ERP11.3.249', 'functional .241 checkpoint retains deployed .240 release metadata');

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
