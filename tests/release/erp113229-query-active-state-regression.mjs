import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const finalizer = read('public/erp-ui/erp-professional-finalize.js');
const cashLinks = read('app/Http/Middleware/PresentCashVoucherLinks.php');
const version = read('VERSION.txt').trim();

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };

ok(finalizer.includes('const normalizeSearch = value =>'), 'query strings are normalized before active-state comparison');
ok(finalizer.includes("normalizeSearch(linkUrl.search) === currentSearch"), 'active-state fallback requires exact query-string match');
ok(finalizer.includes("link.classList.remove('et-ui-current')"), 'incorrect path-only current state is removed');
ok(finalizer.includes("link.removeAttribute('aria-current')"), 'incorrect aria-current state is removed');
ok(finalizer.includes("const nativeActive = link.classList.contains('active')"), 'native server-side active state remains authoritative');
ok(cashLinks.includes("$receiptActive") && cashLinks.includes("$paymentActive") && cashLinks.includes("$expenseActive") && cashLinks.includes("$contraActive"), 'cash voucher middleware keeps one query-aware native active authority');
ok(version === 'v1.1.33.237-ERP11.3.237', 'functional checkpoint does not bump deployed release metadata');

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
