import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const css = read('public/erp-ui/erp-accounting-vouchers.css');
const assetController = read('app/Http/Controllers/System/ErpProfessionalUiAssetController.php');
const version = read('VERSION.txt').trim();

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };

ok(css.includes('ERP-11.3.235 — Accounting voucher workspace visual system'), 'voucher UI layer remains presentation-only scoped');
ok(css.includes('.et-fin-modes'), 'cash voucher register retains compact segmented navigation styling');
ok(css.includes('.et-fin-actions'), 'cash voucher create actions retain separate compact toolbar');
ok(css.includes('content:"⌕  Search & Filter"'), 'filter card retains approved Search & Filter treatment');
ok(css.includes('grid-template-columns:repeat(3,minmax(0,1fr))'), 'summary metrics retain approved three-column rhythm');
ok(css.includes('.cvf27-card'), 'cash voucher create/edit cards remain in the Accounting visual system');
ok(css.includes('.cvs27-card'), 'cash voucher detail cards remain in the Accounting visual system');
ok(css.includes('.aa-wrap') && css.includes('.aa-item'), 'advance adjustment pages remain in the Accounting visual system');
ok(css.includes('@media(max-width:620px)'), 'Accounting UI remains responsive');
ok(!css.includes('position:fixed'), 'Accounting UI does not create fixed overlays');
ok(!css.includes('display:none'), 'Accounting UI does not hide business controls');
ok(assetController.includes("base_path('public/erp-ui/erp-accounting-vouchers.css')"), 'one authoritative Accounting stylesheet is served');
ok(assetController.includes('file_get_contents($base)\n            ."\\n".file_get_contents($accountingUi)'), 'Accounting stylesheet loads after shared professional base CSS');
ok(assetController.includes("'Cache-Control' => 'private, max-age=31536000, immutable'"), 'versioned professional assets retain immutable caching');
ok(version === 'v1.1.33.244-ERP11.3.244', 'functional consolidation checkpoint does not bump release metadata');

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
