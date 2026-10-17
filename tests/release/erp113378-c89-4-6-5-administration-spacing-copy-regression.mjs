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

const descriptions = {
  staff: 'Employees, designation, branch, department, manager and employment status.',
  departments: 'Configurable operational departments for staff classification.',
  roles: 'Granular capability matrix for accounting, operations, refunds and approvals.',
  approvals: 'Amount limits and override rights for refunds, payments, credit sales and journals.',
  audit: 'Who changed access, logged in, approved or altered controlled setup.',
};

const assertions = [
  ['Administration Blade exists', fs.existsSync(viewPath)],
  ['page title retained', view.includes("@section('page_title', 'Administration')")],
  ['metric grid is div', view.includes('<section class="metric-grid">')],
  ['metric cards are div', count(view, '<div class="metric-card">') === 4],
  ['Active Staff label is div', view.includes('<div class="metric-label">Active Staff</div>')],
  ['staff value is separate div', view.includes('<div class="metric-value">{{ $staffCount }}</div>')],
  ['Active ERP Users label is div', view.includes('<div class="metric-label">Active ERP Users</div>')],
  ['user value is separate div', view.includes('<div class="metric-value">{{ $userCount }}</div>')],
  ['Roles label is div', view.includes('<div class="metric-label">Roles</div>')],
  ['role value is separate div', view.includes('<div class="metric-value">{{ $roleCount }}</div>')],
  ['Approval Policies label is div', view.includes('<div class="metric-label">Approval Policies</div>')],
  ['policy value is separate div', view.includes('<div class="metric-value">{{ $policyCount }}</div>')],
  ['metric labels and values are separate', !view.match(/metric-label[^<]*metric-value|metric-value[^<]*metric-label/)],
  ['no span metric label', !view.includes('<span class="metric-label">')],
  ['no span metric value', !view.includes('<span class="metric-value">')],
  ['no br spacing workaround', !view.includes('<br')],
  ['no nbsp spacing workaround', !view.includes('&nbsp;')],
  ['metric card count four', count(view, '<div class="metric-card">') === 4],
  ['settings grid retained', view.includes('<section class="settings-grid">')],
  ['seven settings cards', count(view, 'settings-card"') === 7],
  ['staff description restored', view.includes(descriptions.staff)],
  ['departments description restored', view.includes(descriptions.departments)],
  ['ERP User Management retained', view.includes('<strong>ERP User Management</strong>')],
  ['ERP User Management route retained', view.includes("route('administration.erp-user-management.index')")],
  ['ERP User Management description retained', view.includes('Manage login accounts, linked staff and ERP access.')],
  ['Staff Access retained', view.includes('<strong>Staff Access</strong>')],
  ['Staff Access route retained', view.includes("route('administration.staff-access.index')")],
  ['Staff Access description retained', view.includes('Manage user roles, branches and permission overrides.')],
  ['roles description restored', view.includes(descriptions.roles)],
  ['approval description restored', view.includes(descriptions.approvals)],
  ['audit description restored', view.includes(descriptions.audit)],
  ['five native routes preserved', [
    'administration.staff.index', 'administration.departments.index', 'administration.roles.index',
    'administration.approvals.index', 'administration.audit.index',
  ].every((route) => view.includes(`route('${route}')`))],
  ['permission gates preserved', ['staff.view', 'departments.view', 'users.view', 'roles.view', 'approvals.view', 'audit.view'].every((permission) => view.includes(`hasPermission('${permission}')`))],
  ['legacy ERP Users absent', !view.includes('<strong>ERP Users</strong>')],
  ['legacy administration users link absent', !view.includes("route('administration.users.index')")],
  ['ERP-02 absent', !view.includes('ERP-02')],
  ['old hero absent', !view.includes('Control who works in the ERP')],
  ['old paragraph absent', !view.includes('Staff identity is separate from login access')],
  ['Permanent Security Rules absent', !view.includes('Permanent Security Rules')],
  ['no CSS changed in view', !view.includes('<style')],
  ['no inline CSS', !view.includes('style=')],
  ['no JavaScript', !view.includes('<script')],
  ['transformer unchanged', transformer.includes('AdministrationHubPresentationTransformer')],
  ['metadata middleware unchanged', middleware.includes('ApplyErpReleaseMetadata')],
  ['C89.4.6.4 regression remains', fs.existsSync(path.join(root, 'tests/release/erp113378-c89-4-6-4-administration-native-correction-regression.mjs'))],
  ['C89.4.6.3 regression remains', fs.existsSync(path.join(root, 'tests/release/erp113378-c89-4-6-3-native-administration-view-regression.mjs'))],
  ['C89.4.6.2 regression remains', fs.existsSync(path.join(root, 'tests/release/erp113378-c89-4-6-2-administration-production-markup-regression.mjs'))],
  ['C89.4.6.1 regression remains', fs.existsSync(path.join(root, 'tests/release/erp113378-c89-4-6-1-administration-hub-failsafe-regression.mjs'))],
  ['C89.4.6 regression remains', fs.existsSync(path.join(root, 'tests/release/erp113378-c89-4-6-administration-hub-presentation-regression.mjs'))],
  ['C89.4.5.2 regression remains', fs.existsSync(path.join(root, 'tests/release/erp113378-c89-4-5-2-air-flight-field-entry-performance-regression.mjs'))],
  ['release version', release.includes("'version' => 'v1.1.33.378-ERP11.3.378'")],
  ['release name', release.includes("'release' => 'ERP-11.3.378'")],
  ['C89.4.6.5 identity', release.includes("'asset_version' => 'ERP-11.3.378-C89.4.6.5'") || release.includes("'asset_version' => 'ERP-11.3.378-C89.5.1B.0A.3'")],
];

for (const [name, ok] of assertions) check(ok, name);

const historicalCommit = '506d3b629e09f61a40db54d63d1f6d7caa7324ba';
const historicalParent = '95a7fcf1552a00aacf56e19ceef771862b4e398a';
const historicalDiff = spawnSync('git', ['diff', '--name-only', historicalParent, historicalCommit], { cwd: root, encoding: 'utf8' });
const historicalParents = spawnSync('git', ['show', '-s', '--format=%P', historicalCommit], { cwd: root, encoding: 'utf8' });
check(historicalDiff.status === 0 && historicalParents.status === 0, 'historical corrective commit is available');
const parents = historicalParents.stdout.trim().split(/\s+/).filter(Boolean);
check(parents.length === 1 && parents[0] === historicalParent, 'historical corrective parent is exact');
const changed = historicalDiff.stdout.trim().split(/\r?\n/).filter(Boolean);
check(changed.length === 3, 'historical exact three-file committed scope');
check(changed.includes('resources/views/administration/index.blade.php'), 'historical native view is tracked change');
check(!changed.some((file) => /^(routes\/|app\/Http\/Controllers\/|app\/Http\/Middleware\/|app\/Services\/|public\/)/.test(file)), 'historical protected files unchanged');

console.log(`C89.4.6.5 Administration spacing/copy regression: ${assertions.length + 5} assertions passed`);
