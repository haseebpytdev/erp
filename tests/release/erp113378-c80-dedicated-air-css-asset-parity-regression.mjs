import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = file => fs.readFileSync(new URL(`../../${file}`, import.meta.url), 'utf8');
const presenter = read('app/Services/Operations/BookingWorkspaceShellPresenter.php');
const assetController = read('app/Http/Controllers/System/ErpProfessionalUiAssetController.php');
const navigation = read('public/erp-theme/js/dedicated-product-navigation.js');
const airCss = read('public/erp-theme/css/products/air.css');
const airJs = read('public/erp-theme/js/products/air.js');
const release = read('config/et_erp_release.php');
let assertions = 0;
const check = (value, message) => { assert.ok(value, message); assertions += 1; };

const airRoute = String.raw`#^operations/bookings/\d+/(?:products/air|additional-services/\d+/products/air)$#`;
check(presenter.includes(airRoute), 'presenter targets both direct Air route families');
check(/route\('system\.erp-assets\.erp-professional-css'\)/.test(presenter), 'Air CSS uses the professional asset route');
check(/\?module=operations&role=focused&dedicated=1&product=air&v=/.test(presenter), 'Air CSS query has the focused dedicated Air authority');
check(/data-et-dedicated-air-css=/.test(presenter), 'Air CSS has a stable server marker');
check(/! str_contains\(\$html, 'data-et-dedicated-air-css=/.test(presenter), 'Air CSS injection is marker guarded');
check((presenter.match(/data-et-dedicated-air-css=/g) || []).length === 2, 'Air marker is emitted only by the dedicated Air branch');
check((presenter.match(/product=air&v=/g) || []).length === 1, 'presenter has one dedicated Air stylesheet query');

check(/module=operations&role=focused&dedicated=1&product=air/.test(navigation), 'fast navigation uses the same Air CSS query');
check(!/dedicated-product-navigation\.js[\s\S]*module=operations&role=standard/.test(navigation), 'fast navigation does not downgrade Air CSS role');
check(/if \(\$role === 'focused'\)/.test(assetController) && /et-focused-shell\.css/.test(assetController), 'focused shell remains in the asset authority');
check(/request\(\)->boolean\('dedicated'\)/.test(assetController), 'dedicated bundle remains controller-authoritative');
check(/product === 'air'/.test(assetController) && /css\/products\/air\.css/.test(assetController), 'Air product CSS is included by the existing asset controller');
check(/modules\/dedicated-product\.css/.test(assetController), 'dedicated product CSS is included by the existing asset controller');

for (const selector of [
  'etgp-air-workspace-113106', 'etgp-air-block-113106', 'etgp-air-segment-row-113106',
  'etgp-air-common-grid-113106', 'etgp-air-ticket-table-113106',
  'etgp-air-ticket-group-editor-113324', 'etgp-air-multi-group-page-113324',
  'etgp-air-group-segments-113324', 'etgp-air-multi-group-totals-113324',
]) check(airCss.includes(selector), `${selector} remains available in the Air stylesheet`);

check(!presenter.includes('product=hotel&v=') && !presenter.includes('product=transport&v='), 'Hotel and Transport presentation are unchanged');
check(!/products\/air\.js/.test(presenter), 'Air JavaScript is not injected by the C80 presenter change');
check(/data-et-dedicated-product-air/.test(presenter) && /getProductEndpoint/.test(airJs), 'C79 Air runtime authority remains present');
check(/asset_version' => 'ERP-11\.3\.378-C(?:80|81|82(?:\.1)?|83(?:\.1|\.2|\.3(?:\.1|\.2|\.3|\.4|\.5)?)?)'/.test(release) && /corrective_build' => 'C(?:80|81|82(?:\.1)?|83(?:\.1|\.2|\.3(?:\.1|\.2|\.3|\.4|\.5)?)?)'/.test(release), 'C80 release metadata lineage remains active');
check(/corrective_name' => 'Dedicated Air CSS Asset Parity'/.test(release) || /corrective_name' => 'Supplier Costing Native Product Identity'/.test(release) || /corrective_name' => 'Supplier Costing Register Authority'/.test(release) || /corrective_name' => 'Supplier Costing Register Filter State Closure'/.test(release) || /corrective_name' => 'Automatic Supplier Costing Drafts & Coverage'/.test(release) || /corrective_name' => 'Supplier Costing Automation Integrity & Coverage Closure'/.test(release) || /corrective_name' => 'Supplier Costing Final Integrity Closure'/.test(release) || /corrective_name' => 'Supplier Costing Compact Operations & Draft Actions'/.test(release) || /corrective_name' => 'Supplier Costing Target Scope Runtime Closure'/.test(release) || /corrective_name' => 'Supplier Costing Detail Screen Fit'/.test(release) || /corrective_name' => 'Supplier Costing Table Fit'/.test(release) || /corrective_name' => 'Supplier Costing Register Interaction Authority'/.test(release) || /corrective_name' => 'Supplier Costing Direct Row Action'/.test(release), 'C80 corrective name remains in lineage');

console.log(`ERP-11.3.378 C80 dedicated Air CSS asset parity regression: PASS (${assertions} assertions)`);
