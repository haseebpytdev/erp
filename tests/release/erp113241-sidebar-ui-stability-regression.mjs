import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const baseJs = read('public/erp-ui/erp-professional.js');
const finalizer = read('public/erp-ui/erp-professional-finalize.js');
const shellCss = read('public/erp-ui/erp-shell-spacing.css');

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };

// ERP-11.3.241 was rejected during visual UAT and must not remain the active
// production UI implementation. Preserve Git history, but protect the forward
// restoration to the last accepted ERP-11.3.240 UI behavior.
ok(!baseJs.includes('ERP-11.3.241'), 'rejected .241 base sidebar implementation is not active');
ok(baseJs.includes('sidebarNav.appendChild'), 'ERP-11.3.240 sidebar grouping behavior is restored');
ok(finalizer.includes('canonicalNav.appendChild') && finalizer.includes('appendRow(row)'), 'ERP-11.3.240 finalizer behavior is restored');
ok(shellCss.includes('ERP-11.3.240'), 'ERP-11.3.240 shell stylesheet is restored');
ok(!shellCss.includes('--et-page-title-size:26px'), 'rejected .241 global page-title override is absent');
ok(!shellCss.includes(':has(.nav [data-et-native-active="true"])'), 'rejected .241 parent/child selector is absent');
ok(shellCss.includes('--et-shell-gutter-x:24px') && shellCss.includes('--et-shell-gutter-y:20px'), 'accepted .240 outer frame remains protected');

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
