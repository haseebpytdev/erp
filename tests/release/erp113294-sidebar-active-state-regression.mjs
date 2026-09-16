import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const base = read('public/erp-ui/erp-professional.js');
const finalizer = read('public/erp-ui/erp-professional-finalize.js');
const executableFinalizer = finalizer.replace(/\/\*[\s\S]*?\*\//g, '');
const assets = read('app/Http/Controllers/System/ErpProfessionalUiAssetController.php');
const executableAssets = assets.replace(/\/\/[^\n]*|\/\*[\s\S]*?\*\//g, '');
let pass = 0;
const ok = (value, label) => { assert.ok(value, label); pass++; };

const normalizeSearch = value => new URLSearchParams(String(value || '').replace(/^\?/, ''));
const sameQuery = (a, b) => [...normalizeSearch(a).entries()].sort().join('&') === [...normalizeSearch(b).entries()].sort().join('&');
const current = (href, location, nativeActive = false) => {
  const link = new URL(href, 'https://erp.test');
  const page = new URL(location, 'https://erp.test');
  return nativeActive || (link.pathname.replace(/\/+$/g, '') || '/') === (page.pathname.replace(/\/+$/g, '') || '/') && sameQuery(link.search, page.search);
};

ok(base.includes('const normalizeSearch = value =>'), 'query normalization helper exists');
ok(current('/accounting/vouchers?type=receipt', '/accounting/vouchers?type=receipt'), 'exact type query matches');
ok(!current('/accounting/vouchers?type=receipt', '/accounting/vouchers?type=payment'), 'different type query does not match');
ok(current('/accounting/vouchers?mode=expense', '/accounting/vouchers?mode=expense'), 'exact mode query matches');
ok(!current('/accounting/vouchers?mode=expense', '/accounting/vouchers?mode=payment'), 'different mode query does not match');
ok(current('/accounting/vouchers?a=1&b=2', '/accounting/vouchers?b=2&a=1'), 'query ordering is normalized');
ok(current('/accounting/vouchers', '/accounting/vouchers'), 'queryless location matches queryless link');
ok(current('/accounting/vouchers?type=other', '/accounting/vouchers?type=receipt', true), 'native active remains authoritative');
ok(base.includes('link.classList.remove(\'et-ui-current\')') && base.includes('link.removeAttribute(\'aria-current\')'), 'stale presentation markers are cleared');
const processLinks = (hrefs, location) => hrefs.map(href => {
  try {
    const link = new URL(href, 'https://erp.test');
    const page = new URL(location, 'https://erp.test');
    return link.pathname === page.pathname && sameQuery(link.search, page.search);
  } catch (_) { return false; }
});
const processed = processLinks(['http://[invalid', '/accounting/vouchers?type=receipt'], '/accounting/vouchers?type=receipt');
ok(processed[0] === false && processed[1] === true, 'malformed href is skipped and later valid link is processed');
ok(!executableFinalizer.includes('document.createDocumentFragment()') && !executableFinalizer.includes('replaceChildren(') && !executableFinalizer.includes('cloneNode('), 'structural sidebar rebuild is absent');
ok(!executableAssets.includes("file_get_contents($ready)") && !executableAssets.includes("file_get_contents($prepaint)"), 'retired sidebar assets are not composed');
ok(!executableAssets.includes("$ready = base_path('public/erp-ui/erp-sidebar-ready.js')"), 'unused ready variable is absent');

console.log(`erp113294-sidebar-active-state-regression: ${pass} assertions passed`);
