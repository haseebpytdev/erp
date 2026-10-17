import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

const root = process.cwd();
const viewPath = path.join(root, 'resources/views/administration/index.blade.php');
const view = fs.readFileSync(viewPath, 'utf8');
const release = fs.readFileSync(path.join(root, 'config/et_erp_release.php'), 'utf8');
const transformer = fs.readFileSync(path.join(root, 'app/Services/Operations/AdministrationHubPresentationTransformer.php'), 'utf8');
const middleware = fs.readFileSync(path.join(root, 'app/Http/Middleware/ApplyErpReleaseMetadata.php'), 'utf8');
const check = (condition, message) => assert.equal(Boolean(condition), true, message);
const count = (value, needle) => value.split(needle).length - 1;

const assertions = [
  ['native view exists', fs.existsSync(viewPath)],
  ['native metric grid preserved', view.includes('<section class="metric-grid">')],
  ['native metric card class preserved', count(view, 'class="metric-card"') === 4],
  ['metric label class preserved', count(view, 'class="metric-label"') === 4],
  ['metric value class preserved', count(view, 'class="metric-value"') === 4],
  ['native settings grid preserved', view.includes('<section class="settings-grid">')],
  ['native settings card class preserved', count(view, 'settings-card"') === 7],
  ['native card child structure preserved', count(view, '<b>Open →</b>') === 7],
  ['title is Administration', view.includes("@section('title', 'Administration')")],
  ['page title is Administration', view.includes("@section('page_title', 'Administration')")],
  ['ERP-02 absent', !view.includes('ERP-02')],
  ['old hero absent', !view.includes('Control who works in the ERP')],
  ['old description absent', !view.includes('Staff identity is separate from login access')],
  ['no replacement intro', !view.includes('administration-subtitle') && !view.includes('page-intro')],
  ['permanent rules absent', !view.includes('Permanent Security Rules')],
  ['staff count preserved', view.includes('{{ $staffCount }}')],
  ['user count preserved', view.includes('{{ $userCount }}')],
  ['role count preserved', view.includes('{{ $roleCount }}')],
  ['policy count preserved', view.includes('{{ $policyCount }}')],
  ['four native metric labels', ['Active Staff', 'Active ERP Users', 'Roles', 'Approval Policies'].every((label) => view.includes(label))],
  ['stat values not hard-coded', !view.match(/(?:metric-value|metric-card)[^>]*>\s*(?:3|4|9|14)\s*</)],
  ['staff permission preserved', view.includes("hasPermission('staff.view')")],
  ['staff route preserved', view.includes("route('administration.staff.index')")],
  ['departments permission preserved', view.includes("hasPermission('departments.view')")],
  ['departments route preserved', view.includes("route('administration.departments.index')")],
  ['users visibility gate preserved', view.includes("hasPermission('users.view')")],
  ['legacy users route absent', !view.includes("route('administration.users.index')")],
  ['ERP User Management title once', count(view, '<strong>ERP User Management</strong>') === 1],
  ['ERP User Management route once', count(view, "route('administration.erp-user-management.index')") === 1],
  ['Staff Access title once', count(view, '<strong>Staff Access</strong>') === 1],
  ['Staff Access route once', count(view, "route('administration.staff-access.index')") === 1],
  ['roles gate preserved', view.includes("hasPermission('roles.view')")],
  ['roles route preserved', view.includes("route('administration.roles.index')")],
  ['approvals gate preserved', view.includes("hasPermission('approvals.view')")],
  ['approvals route preserved', view.includes("route('administration.approvals.index')")],
  ['audit gate preserved', view.includes("hasPermission('audit.view')")],
  ['audit route preserved', view.includes("route('administration.audit.index')")],
  ['fully authorized card count', count(view, '<a class="administration-card settings-card"') === 7],
  ['no duplicate ERP User Management', count(view, '<strong>ERP User Management</strong>') === 1],
  ['no duplicate Staff Access', count(view, '<strong>Staff Access</strong>') === 1],
  ['no CSS added', !view.includes('<style')],
  ['no inline CSS added', !view.includes('style=')],
  ['no JavaScript added', !view.includes('<script')],
  ['transformer unchanged', transformer.includes('AdministrationHubPresentationTransformer') && transformer.includes('return $original;')],
  ['metadata middleware unchanged', middleware.includes('ApplyErpReleaseMetadata')],
  ['no security service reference', !view.includes('ErpBranchAccessAuthority') && !view.includes('ErpPermissionOverrideService')],
  ['C89.4.6.3 regression remains', fs.existsSync(path.join(root, 'tests/release/erp113378-c89-4-6-3-native-administration-view-regression.mjs'))],
  ['C89.4.6.2 regression remains', fs.existsSync(path.join(root, 'tests/release/erp113378-c89-4-6-2-administration-production-markup-regression.mjs'))],
  ['C89.4.6.1 regression remains', fs.existsSync(path.join(root, 'tests/release/erp113378-c89-4-6-1-administration-hub-failsafe-regression.mjs'))],
  ['C89.4.6 regression remains', fs.existsSync(path.join(root, 'tests/release/erp113378-c89-4-6-administration-hub-presentation-regression.mjs'))],
  ['C89.4.5.2 regression remains', fs.existsSync(path.join(root, 'tests/release/erp113378-c89-4-5-2-air-flight-field-entry-performance-regression.mjs'))],
  ['release version preserved', release.includes("'version' => 'v1.1.33.378-ERP11.3.378'")],
  ['release preserved', release.includes("'release' => 'ERP-11.3.378'")],
  ['C89.4.6.4 asset', release.includes("'asset_version' => 'ERP-11.3.378-C89.4.6.4'")],
  ['C89.4.6.4 build', release.includes("'corrective_build' => 'C89.4.6.4'")],
  ['C89.4.6.4 name', release.includes("'corrective_name' => 'Administration Native Page Correction'")],
];

for (const [name, ok] of assertions) check(ok, name);

const historicalCommit = '95a7fcf1552a00aacf56e19ceef771862b4e398a';
const historicalParent = '803fcd0956e1980d981d3d412f884b484155de5f';
const historicalDiff = spawnSync('git', ['diff', '--name-only', historicalParent, historicalCommit], { cwd: root, encoding: 'utf8' });
const historicalParents = spawnSync('git', ['show', '-s', '--format=%P', historicalCommit], { cwd: root, encoding: 'utf8' });
check(historicalDiff.status === 0 && historicalParents.status === 0, 'historical corrective commit is available');
const parents = historicalParents.stdout.trim().split(/\s+/).filter(Boolean);
check(parents.length === 1 && parents[0] === historicalParent, 'historical corrective parent is exact');
const changed = historicalDiff.stdout.trim().split(/\r?\n/).filter(Boolean);
check(changed.length === 3, 'historical exact three-file committed scope');
check(changed.includes('resources/views/administration/index.blade.php'), 'historical native view is tracked change');
check(!changed.some((file) => /^(routes\/|app\/Http\/Controllers\/|app\/Http\/Middleware\/|app\/Services\/)/.test(file)), 'historical protected runtime files unchanged');

console.log(`C89.4.6.4 native Administration correction regression: ${assertions.length + 5} assertions passed`);
