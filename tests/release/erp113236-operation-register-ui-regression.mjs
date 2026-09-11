import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

const css = read('public/erp-ui/erp-operation-registers.css');
const js = read('public/erp-ui/erp-operation-registers.js');
const assetController = read('app/Http/Controllers/System/ErpProfessionalUiAssetController.php');
const version = read('VERSION.txt').trim();

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };

ok(css.includes('ERP-11.3.236 — shared professional register styling'), 'shared register visual layer is explicitly presentation-only scoped');
ok(js.includes("'operations/bookings': 'bookings'"), 'Bookings register is scoped by exact path');
ok(js.includes("'sales/invoices': 'sales-invoices'"), 'Sales Invoice register is scoped by exact path');
ok(js.includes("'supplier-costing': 'supplier-costing'"), 'Supplier Costing register is scoped by exact path');
ok(js.includes("html.classList.add('et-register-workspace', 'et-register-' + page)"), 'exact register marker is applied before generic professional finalization');
ok(css.includes('.et-reg-header'), 'shared professional header treatment is present');
ok(css.includes('.et-reg-metric'), 'shared KPI card treatment is present');
ok(css.includes('.et-reg-filter::before'), 'shared Search & Filter card treatment is present');
ok(css.includes('table.et-reg-table'), 'shared register table treatment is present');
ok(css.includes('.et-reg-status-success') && css.includes('.et-reg-status-danger'), 'semantic status pills are present');
ok(css.includes('.et-reg-primary-action'), 'primary New action treatment is present');
ok(css.includes('.et-reg-row-action'), 'compact row action treatment is present');
ok(css.includes('html.et-register-supplier-costing'), 'Supplier Costing stable classes receive the same visual system');
ok(!js.includes('fetch(') && !js.includes('XMLHttpRequest') && !js.includes('localStorage') && !js.includes('sessionStorage'), 'register enhancement performs no data/network persistence');
ok(!/\.submit\(|requestSubmit\(|FormData\(|DB::|->insert\(|->update\(|->delete\(/.test(js + css), 'register visual assets contain no business-data mutation mechanism');
ok(assetController.includes("base_path('public/erp-ui/erp-operation-registers.css')"), 'register stylesheet is served through authenticated professional UI');
ok(assetController.includes("base_path('public/erp-ui/erp-operation-registers.js')"), 'register marker script is served through authenticated professional UI');
ok(assetController.includes('file_get_contents($registerUi)\n            ."\\n".file_get_contents($base)'), 'register marker executes before base/finalizer JavaScript');
ok(assetController.includes('file_get_contents($voucherUi)\n            ."\\n".file_get_contents($registerUi)'), 'register CSS loads after the existing voucher visual layer');
ok(assetController.includes("'Cache-Control' => 'private, max-age=31536000, immutable'"), 'versioned professional asset caching remains intact');
ok(version === 'v1.1.33.236-ERP11.3.236', 'functional UI checkpoint does not bump production release metadata');

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
