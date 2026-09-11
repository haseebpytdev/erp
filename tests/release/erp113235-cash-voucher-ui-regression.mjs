import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const css = read('public/erp-ui/erp-accounting-vouchers.css');
const assetController = read('app/Http/Controllers/System/ErpProfessionalUiAssetController.php');
const version = read('VERSION.txt').trim();

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };

ok(css.includes('ERP-11.3.235 — Accounting voucher workspace visual system'), 'voucher UI layer is explicitly presentation-only scoped');
ok(css.includes('.et-fin-modes'), 'cash voucher register has compact segmented mode navigation styling');
ok(css.includes('.et-fin-actions'), 'cash voucher create actions use a separate compact toolbar');
ok(css.includes('content:"⌕  Search & Filter"'), 'filter card receives the approved Search & Filter header treatment');
ok(css.includes('grid-template-columns:repeat(3,minmax(0,1fr))'), 'summary metrics use the approved three-column dashboard rhythm');
ok(css.includes('.cvf27-card'), 'cash voucher create/edit cards share the new visual system');
ok(css.includes('.cvs27-card'), 'cash voucher detail cards share the new visual system');
ok(css.includes('.aa-wrap'), 'advance adjustment pages share the new visual system');
ok(css.includes('.aa-item'), 'advance adjustment detail fields use compact dashboard tiles');
ok(css.includes('@media(max-width:620px)'), 'voucher UI remains responsive on small screens');
ok(!css.includes('position:fixed'), 'voucher UI layer does not create fixed overlays');
ok(!css.includes('display:none'), 'voucher UI layer does not hide business controls');
ok(assetController.includes("base_path('public/erp-ui/erp-accounting-vouchers.css')"), 'professional CSS response includes the voucher visual layer');
ok(assetController.includes('file_get_contents($base)."\\n".file_get_contents($voucherUi)'), 'voucher visual layer loads after the base professional stylesheet');
ok(assetController.includes("'Cache-Control' => 'private, max-age=31536000, immutable'"), 'versioned professional assets retain immutable browser caching');
ok(version === 'v1.1.33.235-ERP11.3.235', 'functional UI checkpoint does not bump production release metadata');

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
