import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = file => fs.readFileSync(new URL(`../../${file}`, import.meta.url), 'utf8');
const controller = read('app/Http/Controllers/System/ErpProfessionalUiAssetController.php');
const middleware = read('app/Http/Middleware/ApplyErpReleaseMetadata.php');
const js = read('public/erp-ui/erp-professional.js');
const css = read('public/erp-theme/modules/system.css');
const release = read('config/et_erp_release.php');
let assertions = 0;
const ok = (value, message) => { assert.ok(value, message); assertions++; };

ok(controller.includes("'system' => 'system.css'"), 'System Health uses a dedicated active module stylesheet');
ok(controller.includes("public/erp-theme/modules/'.$moduleFiles[$module]"), 'active asset controller serves module stylesheet');
ok(!controller.includes("file_get_contents(base_path('public/erp-ui/erp-professional.css'))"), 'retired global CSS is not reloaded');
ok(css.includes('.app-shell') && css.includes('grid-template-columns:208px'), 'Health has one two-column shell');
ok(css.includes('.app-shell>.sidebar') && css.includes('min-width:208px'), 'Health sidebar keeps the 208px frame');
ok(css.includes('.sidebar-menu') && css.includes('visibility:visible'), 'native Health sidebar rows are visible');
ok(css.includes('[data-et-health-section]') && css.includes('[data-et-dangerous-actions="true"]'), 'Health cards and Dangerous Actions are contained');
ok(js.includes('body.dataset.etUiModule === \'system\''), 'Health runtime uses the route module marker');
ok(js.includes('boundedHealthStructure') && js.includes('isSystemHealth'), 'Health detection is bounded beyond the legacy title');
ok(!js.includes('style.position = \'fixed\'') && !js.includes('style.position = "fixed"'), 'Health JS does not mutate sidebar geometry');
ok(middleware.includes('migrationStatus($release)'), 'migration authority remains server-side');
ok(middleware.includes("$status !== 'pending'"), 'current and unknown upgrade actions fail closed');
ok(css.includes('Application Cache') === false, 'cache behavior remains markup/API-owned');
ok(middleware.includes('data-et-dangerous-actions="true"'), 'Dangerous Actions routes remain server-rendered');
ok(release.includes("'asset_version' => 'ERP-11.3.378-C45'"), 'current immutable asset revision is active');
ok(release.includes("'version' => 'v1.1.33.378-ERP11.3.378'"), 'application version is unchanged');
console.log(`erp113378-c40-system-health-ui-regression: ${assertions} assertions passed`);
