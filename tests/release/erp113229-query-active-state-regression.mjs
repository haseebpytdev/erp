import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const baseJs = read('public/erp-ui/erp-professional.js');
const finalizer = read('public/erp-ui/erp-professional-finalize.js');
const shellCss = read('public/erp-ui/erp-shell-spacing.css');
const cashLinks = read('app/Http/Middleware/PresentCashVoucherLinks.php');
const version = read('VERSION.txt').trim();

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };

ok(!baseJs.includes("link.classList.add('et-ui-current')"), 'base UI no longer paints path-only active sidebar links');
ok(finalizer.includes('const normalizeSearch = value =>'), 'query strings are normalized only for exact fallback matching');
ok(finalizer.includes('if (!nativeActiveFound)'), 'exact URL fallback runs only when native active authority is absent');
ok(finalizer.includes("link.dataset.etNativeActive = 'true'"), 'finalizer annotates rather than replaces native active state');
ok(!finalizer.includes("link.classList.remove('et-ui-current')") && !finalizer.includes("link.removeAttribute('aria-current')"), 'finalizer does not rewrite native active classes/aria state');
ok(shellCss.includes('a[data-et-native-active="true"]'), 'CSS renders the annotated native current destination');
ok(shellCss.includes(':has(.nav [data-et-native-active="true"])'), 'parent row is prevented from looking like a second active destination');
ok(cashLinks.includes("$receiptActive") && cashLinks.includes("$paymentActive") && cashLinks.includes("$expenseActive") && cashLinks.includes("$contraActive"), 'cash voucher middleware remains query-aware native authority');
ok(version === 'v1.1.33.243-ERP11.3.243', 'functional .241 checkpoint does not bump deployed release metadata');

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
