import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const middleware = read('app/Http/Middleware/ApplyErpReleaseMetadata.php');
const js = read('public/erp-ui/erp-register-workspace.js');
const assetController = read('app/Http/Controllers/System/ErpProfessionalUiAssetController.php');
const view = read('resources/views/system/register-workspace-v113239.blade.php');
const presenter = read('app/Http/Middleware/PresentUnifiedRegisterWorkspace.php');
const synchronizer = read('app/Services/Purchase/SupplierCostingDraftSynchronizer.php');
const config = read('config/et_erp_release.php');

const checks = [
  ['exact Supplier Costing index maps to purchase', /\$path === 'supplier-costing'\s*=>\s*'purchase'/],
  ['Supplier Costing index remains register role', /purchase\/supplier-costing.*?register/s],
  ['register asset source is the shared workspace script', /erp-register-workspace\.js/],
  ['register script selects the actual keyed root', /data-et-register-workspace="ERP-11\.3\.239"\]\[data-register-key\]/],
  ['Supplier Costing root owns server mode marker', /data-register-key="\{\{ \$config\['key'\] \}\}"[^>]*.*data-et-register-filter-mode="server"/s],
  ['server mode is read from the selected root', /root\.getAttribute\('data-et-register-filter-mode'\)/],
  ['All quick filter is bound', /root\.querySelectorAll\('\[data-quick\]'\)/],
  ['Draft quick filter is server-backed by status', /setOrDelete\('status', status && status\.value\)/],
  ['pending approval value is present', /pending_approval/],
  ['approved value is present', /data-quick="approved"|status.*approved/],
  ['posted value is present', /data-quick="posted"|status.*posted/],
  ['server apply updates URL query', /new URL\(document\.URL\)[\s\S]*location\.assign\(url\.toString\(\)\)/],
  ['server filter resets register page', /params\.delete\('page'\)/],
  ['row action menu listener is bound', /root\.querySelectorAll\('\[data-register-row-menu\]'\)/],
  ['row action opens existing popover path', /openRowPopover\(trigger\)/],
  ['Apply Filter is bound', /querySelector\('\[data-register-apply\]'\)[\s\S]*addEventListener/],
  ['Reset is bound', /querySelector\('\[data-register-reset\]'\)[\s\S]*addEventListener/],
  ['Export toggle is bound', /querySelector\('\[data-register-export-toggle\]'\)[\s\S]*addEventListener/],
  ['row selection is bound', /querySelectorAll\('\[data-register-row-select\]'\)[\s\S]*addEventListener/],
  ['Booking Cost Queue Create Draft remains protected POST', /<form method="POST" action="\{\{ \$item\['action_url'\] \}\}"[\s\S]*@csrf[\s\S]*Create Draft/],
  ['C83.3.1 supplier scope closure remains', /\?int \$onlySupplierId = null|use \([^\)]*\$onlySupplierId/],
  ['C83.3.3 table fit remains', /table-layout:fixed|et-reg-table/],
];

for (const [label, pattern] of checks) {
  assert.match(middleware + js + view + presenter + assetController + synchronizer, pattern, label);
}
assert.match(config, /C83\.3\.4|C83\.3\.5/);
assert.match(config, /Supplier Costing Register Interaction Authority|Supplier Costing Direct Row Action/);
console.log(`C83_3_4_REGISTER_INTERACTION_REGRESSION=PASS (${checks.length + 2} assertions)`);
