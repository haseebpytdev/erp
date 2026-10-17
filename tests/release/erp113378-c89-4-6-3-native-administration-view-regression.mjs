import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

const root = process.cwd();
const viewPath = path.join(root, 'resources/views/administration/index.blade.php');
const view = fs.readFileSync(viewPath, 'utf8');
const release = fs.readFileSync(path.join(root, 'config/et_erp_release.php'), 'utf8');
const transformer = fs.readFileSync(path.join(root, 'app/Services/Operations/AdministrationHubPresentationTransformer.php'), 'utf8');
const metadataMiddleware = fs.readFileSync(path.join(root, 'app/Http/Middleware/ApplyErpReleaseMetadata.php'), 'utf8');
const check = (condition, message) => assert.equal(Boolean(condition), true, message);
const count = (value, needle) => value.split(needle).length - 1;

const assertions = [
  ['native Administration Blade exists', fs.existsSync(viewPath)],
  ['extends layouts.app', view.includes("@extends('layouts.app')")],
  ['title is Administration', view.includes("@section('title', 'Administration')")],
  ['page title is Administration', view.includes("@section('page_title', 'Administration')")],
  ['ERP-02 absent', !view.includes('ERP-02')],
  ['old hero absent', !view.includes('Control who works in the ERP')],
  ['old description absent', !view.includes('Staff identity is separate from login access')],
  ['permanent rules absent', !view.includes('Permanent Security Rules')],
  ['staff count variable preserved', view.includes('$staffCount')],
  ['user count variable preserved', view.includes('$userCount')],
  ['role count variable preserved', view.includes('$roleCount')],
  ['policy count variable preserved', view.includes('$policyCount')],
  ['four stat labels', ['Active Staff', 'Active ERP Users', 'Roles', 'Approval Policies'].every((label) => view.includes(label))],
  ['stat values are not hard-coded', !view.match(/>(?:3|4|9|14)<\/strong>/)],
  ['staff permission gate', view.includes("hasPermission('staff.view')")],
  ['staff directory route', view.includes("route('administration.staff.index')")],
  ['departments route', view.includes("route('administration.departments.index')")],
  ['departments permission gate', view.includes("hasPermission('departments.view')")],
  ['users visibility gate', view.includes("hasPermission('users.view')")],
  ['legacy users link absent', !view.includes("route('administration.users.index')")],
  ['ERP User Management title once', count(view, 'ERP User Management') === 1],
  ['ERP User Management route once', count(view, "route('administration.erp-user-management.index')") === 1],
  ['Staff Access title once', count(view, 'Staff Access') === 1],
  ['Staff Access route once', count(view, "route('administration.staff-access.index')") === 1],
  ['roles permission gate', view.includes("hasPermission('roles.view')")],
  ['roles route', view.includes("route('administration.roles.index')")],
  ['approvals permission gate', view.includes("hasPermission('approvals.view')")],
  ['approvals route', view.includes("route('administration.approvals.index')")],
  ['audit permission gate', view.includes("hasPermission('audit.view')")],
  ['audit route', view.includes("route('administration.audit.index')")],
  ['seven card templates', count(view, '<a class="administration-card"') === 7],
  ['no duplicate ERP User Management card', count(view, '<strong>ERP User Management</strong>') === 1],
  ['no duplicate Staff Access card', count(view, '<strong>Staff Access</strong>') === 1],
  ['transformer source remains fail-safe', transformer.includes('return $original;') && transformer.includes('preflight')],
  ['release metadata middleware unchanged', metadataMiddleware.includes('ApplyErpReleaseMetadata')],
  ['no route import in view', !view.includes('Route::')],
  ['no controller import in view', !view.includes('Controller')],
  ['C89.4.6.2 regression exists', fs.existsSync(path.join(root, 'tests/release/erp113378-c89-4-6-2-administration-production-markup-regression.mjs'))],
  ['C89.4.6.1 regression exists', fs.existsSync(path.join(root, 'tests/release/erp113378-c89-4-6-1-administration-hub-failsafe-regression.mjs'))],
  ['C89.4.6 regression exists', fs.existsSync(path.join(root, 'tests/release/erp113378-c89-4-6-administration-hub-presentation-regression.mjs'))],
  ['C89.4.5.2 regression exists', fs.existsSync(path.join(root, 'tests/release/erp113378-c89-4-5-2-air-flight-field-entry-performance-regression.mjs'))],
  ['security services are not referenced', !view.includes('ErpBranchAccessAuthority') && !view.includes('ErpPermissionOverrideService')],
  ['release version', release.includes("'version' => 'v1.1.33.378-ERP11.3.378'")],
  ['release name', release.includes("'release' => 'ERP-11.3.378'")],
  ['C89.4.6.3 identity', release.includes("'asset_version' => 'ERP-11.3.378-C89.4.6.3'") || release.includes("'asset_version' => 'ERP-11.3.378-C89.5.1B.0A.3'")],
];

for (const [name, ok] of assertions) check(ok, name);

const historicalCommit = '803fcd0956e1980d981d3d412f884b484155de5f';
const historicalParent = '812b61902126bbd326a4d60a63c55ecc5b2f9f4e';
const historicalDiff = spawnSync('git', ['diff', '--name-only', historicalParent, historicalCommit], { cwd: root, encoding: 'utf8' });
const historicalParents = spawnSync('git', ['show', '-s', '--format=%P', historicalCommit], { cwd: root, encoding: 'utf8' });
check(historicalDiff.status === 0 && historicalParents.status === 0, 'historical corrective commit is available');
const parents = historicalParents.stdout.trim().split(/\s+/).filter(Boolean);
check(parents.length === 1 && parents[0] === historicalParent, 'historical corrective parent is exact');
const changed = historicalDiff.stdout.trim().split(/\r?\n/).filter(Boolean);
check(changed.length === 3, 'historical committed change boundary is three files');
check(changed.includes('resources/views/administration/index.blade.php'), 'historical native view is the only runtime view change');
check(!changed.some((file) => /^(routes\/|app\/Http\/Controllers\/|app\/Http\/Middleware\/|app\/Services\/Administration\/)/.test(file)), 'historical routes/controllers/middleware/security unchanged');

console.log(`C89.4.6.3 native Administration view regression: ${assertions.length + 5} assertions passed`);
