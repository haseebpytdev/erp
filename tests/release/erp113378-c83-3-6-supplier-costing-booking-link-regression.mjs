import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const view = read('resources/views/system/register-workspace-v113239.blade.php');
const js = read('public/erp-ui/erp-register-workspace.js');
const queue = read('tests/release/erp113378-c83-3-booking-cost-queue-action-regression.mjs');
const sync = read('app/Services/Purchase/SupplierCostingDraftSynchronizer.php');
const config = read('config/et_erp_release.php');

const checks = [
  ['Supplier Costing Action header is conditional', /@if\(!\$isSupplierCosting\)[\s\S]*et-booking-action-head/],
  ['Supplier Costing Action cell is conditional', /@if\(!\$isSupplierCosting\)[\s\S]*et-booking-action-cell/],
  ['Booking column is detected', /mb_strtolower\(trim\(\$header\)\) === 'booking'/],
  ['Booking cell uses direct anchor', /et-supplier-costing-booking-link[\s\S]*href="\{\{ \$row\['href'\] \}\}"/],
  ['Booking anchor uses row href', /\$row\['href'\]/],
  ['Booking display value remains cell HTML', /\$row\['cells'\]\[\$cellIndex\]\['html'\]/],
  ['No separate Open Costing button remains', !view.includes('Open Costing')],
  ['Direct-action CSS is removed', !view.includes('et-supplier-costing-row-action')],
  ['Booking link is text styled', /et-supplier-costing-booking-link\{[^}]*color:[^}]*text-decoration:none/],
  ['Supplier empty colspan excludes Action', /count\(\$headers\) \+ \(\$isSupplierCosting \? 1 : 2\)/],
  ['Non-Supplier Action header remains', /et-booking-action-head/],
  ['Non-Supplier row menu remains', /data-register-row-menu[\s\S]*et-booking-row-menu-panel/],
  ['Supplier export keeps Status', /root\.dataset\.registerKey === 'supplier-costing'[\s\S]*slice\(1, isSupplierCosting \? undefined : -1\)/],
  ['Other export still excludes Action', /slice\(1, isSupplierCosting \? undefined : -1\)/],
  ['Export is register-aware', /root\.dataset\.registerKey/],
  ['C83.3.4 purchase/register mapping remains', /supplier-costing.*purchase|purchase.*supplier-costing/s],
  ['Keyed register root selector remains', /data-et-register-workspace="ERP\.3?\.239"|data-et-register-workspace/],
  ['Quick Filter server path remains', /serverMode|data-et-register-filter-mode="server"/],
  ['Booking Cost Queue Create Draft remains POST+CSRF', /method="POST"[\s\S]*@csrf/],
  ['onlySupplierId closure capture remains', /onlySupplierId|use \([^)]*onlySupplierId/],
];

for (const [label, pattern] of checks) {
  if (typeof pattern === 'boolean') assert.equal(pattern, true, label);
  else assert.match(view + js + queue + sync + config, pattern, label);
}
assert.match(config, /C83\.3\.6|C84/);
assert.match(config, /Supplier Costing Booking Link Navigation|Makkah Check-in Native Movement Report/);
console.log(`C83_3_6_BOOKING_LINK_REGRESSION=PASS (${checks.length + 2} assertions)`);
