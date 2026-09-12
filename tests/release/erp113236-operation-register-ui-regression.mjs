import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const legacyCss = read('public/erp-ui/erp-operation-registers.css');
const legacyJs = read('public/erp-ui/erp-operation-registers.js');
const controller = read('app/Http/Controllers/System/ErpProfessionalUiAssetController.php');
const presenter = read('app/Http/Middleware/PresentUnifiedRegisterWorkspace.php');
const version = read('VERSION.txt').trim();

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };

ok(legacyCss.includes('ERP-11.3.236 — shared professional register styling'), 'historical .236 CSS remains traceable');
ok(legacyJs.includes("'operations/bookings': 'bookings'") && legacyJs.includes("'sales/invoices': 'sales-invoices'") && legacyJs.includes("'supplier-costing': 'supplier-costing'"), 'historical .236 exact route scope remains traceable');
ok(!controller.includes("base_path('public/erp-ui/erp-operation-registers.css')"), 'obsolete .236 register CSS is no longer served');
ok(!controller.includes("base_path('public/erp-ui/erp-operation-registers.js')"), 'obsolete .236 DOM marker/reconstruction JS is no longer served');
ok(controller.includes("base_path('public/erp-ui/erp-booking-register-reference.css')"), 'one approved shared register stylesheet remains served');
ok(controller.includes("base_path('public/erp-ui/erp-register-workspace.js')"), 'minimal register interaction JS is served');
ok(presenter.includes("'operations/bookings' => [") && presenter.includes("'sales/invoices' => [") && presenter.includes("'supplier-costing' => ["), 'all three registers are now server-presented');
ok(controller.includes("'Cache-Control' => 'private, max-age=31536000, immutable'"), 'versioned professional asset caching remains intact');
ok(version === 'v1.1.33.247-ERP11.3.247', 'functional consolidation checkpoint does not bump release metadata');

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
