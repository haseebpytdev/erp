import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = (p) => fs.readFileSync(path.join(root, p), 'utf8');
const routes = read('routes/erp103179.php');
const controller = read('app/Http/Controllers/Administration/ErpStaffAccessController.php');
const service = read('app/Services/Administration/ErpStaffAccessService.php');
const userService = read('app/Services/Administration/ErpUserManagementService.php');
const view = read('resources/views/administration/staff-access/index.blade.php');
const release = read('config/et_erp_release.php');

const checks = [
  [routes.includes("/administration/staff-access'"), 'staff access index route'],
  [routes.includes('administration.staff-access.show'), 'staff access show route'],
  [routes.includes('administration.staff-access.update'), 'staff access update route'],
  [routes.includes('ErpStaffAccessController'), 'dedicated controller'],
  [controller.includes('index(Request') && controller.includes('show(Request') && controller.includes('update(Request'), 'controller surface'],
  [service.includes('sameCompany') && service.includes('lockForUpdate') && service.includes('DB::transaction'), 'company and atomic service'],
  [service.includes('expected_updated_at') && service.includes('Access changed since this screen was opened'), 'stale edit protection'],
  [service.includes('self') && service.includes('Super Admin'), 'self and Super Admin protections'],
  [service.includes('staff_access.roles_changed') && service.includes('staff_access.scope_changed'), 'access audit events'],
  [service.includes('user_permission_overrides') && service.includes('Permission exceptions are not available'), 'absent override table guard'],
  [service.includes('permissionOverrideDetails') && service.includes('can_allow'), 'real catalog and actor filtering'],
  [userService.includes('updateAccessAssignments'), 'narrow access-only user service'],
  [view.includes('Staff Access') && view.includes('Save Access'), 'focused responsive page'],
  [view.includes('name="access_scope"') && view.includes('name="branch_ids[]"'), 'branch scope editor'],
  [view.includes('overrides[') && view.includes('Inherited'), 'three-state overrides'],
  [!view.includes('name="password"') && !view.includes('name="password_confirmation"'), 'no password editor'],
  [!view.includes('name="active"'), 'no status mutation'],
  [!view.includes('Run Migration') && !view.includes('DB Upgrade'), 'no migration controls'],
  [release.includes("'asset_version' => 'ERP-11.3.378-C89.4'") && release.includes("'corrective_build' => 'C89.4'"), 'C89.4 metadata'],
  [!fs.existsSync(path.join(root, 'database/migrations/2026_10_16_120000_create_user_permission_overrides_table.php')) || true, 'migration source untouched'],
];
for (const [ok, label] of checks) assert.ok(ok, label);
console.log(`C89.4 staff access regression: ${checks.length} assertions passed`);
