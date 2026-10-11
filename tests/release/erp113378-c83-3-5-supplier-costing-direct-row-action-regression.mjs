import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const view = read('resources/views/system/register-workspace-v113239.blade.php');
const js = read('public/erp-ui/erp-register-workspace.js');
const middleware = read('app/Http/Middleware/ApplyErpReleaseMetadata.php');
const asset = read('app/Http/Controllers/System/ErpProfessionalUiAssetController.php');
const queue = read('tests/release/erp113378-c83-3-booking-cost-queue-action-regression.mjs');
const sync = read('app/Services/Purchase/SupplierCostingDraftSynchronizer.php');
const config = read('config/et_erp_release.php');

const checks = [
  ['Supplier Costing conditional exists', /\$isSupplierCosting/],
  ['Supplier Costing uses authoritative anchor navigation', /(?:et-supplier-costing-row-action|et-supplier-costing-booking-link)[\s\S]*href="\{\{ \$row\['href'\] \}\}"/],
  ['direct action uses existing row href', /\$row\['href'\]/],
  ['Supplier Costing navigation remains authoritative', /(?:Open Costing|et-supplier-costing-booking-link)/],
  ['Supplier Costing branch excludes row menu marker', /@if\(\$isSupplierCosting\)[\s\S]*?@else[\s\S]*?data-register-row-menu/s],
  ['Supplier Costing branch excludes popover panel', /@if\(\$isSupplierCosting\)[\s\S]*?(?:et-supplier-costing-row-action|et-supplier-costing-booking-link)[\s\S]*?@else[\s\S]*?et-booking-row-menu-panel/s],
  ['shared non-Supplier row menu remains', /data-register-row-menu/],
  ['shared popover JavaScript remains', /openRowPopover\(trigger\)[\s\S]*activeRowPopover/],
  ['C83.3.4 module mapping remains', /\$path === 'supplier-costing'\s*=>\s*'purchase'/],
  ['keyed register root selector remains', /data-et-register-workspace="ERP-11\.3\.239"\]\[data-register-key\]/],
  ['server quick-filter path remains', /data-et-register-filter-mode="server"|serverMode/],
  ['Booking Cost Queue Create Draft POST remains', /method="POST"[\s\S]*@csrf[\s\S]*Create Draft/],
  ['onlySupplierId closure remains', /\?int \$onlySupplierId = null|use \([^)]*\$onlySupplierId/],
  ['C83.3.3 table fit remains', /et-reg-table/],
];

for (const [label, pattern] of checks) {
  assert.match(view + js + middleware + asset + queue + sync + config, pattern, label);
}
assert.match(config, /C83\.3\.5|C83\.3\.6|C84\.3/);
assert.match(config, /Supplier Costing Direct Row Action|Supplier Costing Booking Link Navigation|Arrival Style Single Authority/);
console.log(`C83_3_5_DIRECT_ACTION_REGRESSION=PASS (${checks.length + 2} assertions)`);
