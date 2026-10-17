import assert from 'node:assert/strict';
import fs from 'node:fs';

const root = process.cwd();
const read = file => fs.readFileSync(`${root}/${file}`, 'utf8');
const service = read('app/Services/Administration/ErpUserManagementService.php');
const controller = read('app/Http/Controllers/Administration/ErpUserManagementController.php');
const matrix = read('app/Services/Administration/ErpPermissionMatrixService.php');
const overrides = read('app/Services/Administration/ErpUserPermissionOverrideService.php');
const staff = read('app/Services/Administration/ErpStaffAccessService.php');
const view = read('resources/views/administration/erp-user-management.blade.php');
const release = read('config/et_erp_release.php');
const markerBusiness = read('app/Http/Middleware/C89ManagedBusinessRoute.php');
const markerSupport = read('app/Http/Middleware/C89AuthenticatedSupportAssetRoute.php');

const check = (condition, message) => assert.equal(condition, true, message);

check(view.includes('!$permissionStorageAvailable') && view.includes('Individual Custom Permissions are not yet available'), 'warning remains tied to supplied availability variable');
check(service.includes('public function userPermissionOverrideStorageAvailable(): bool'), 'C89.3 override availability method exists');
check(service.includes("Schema::hasTable('user_permission_overrides')"), 'availability checks authoritative override table');
for (const column of ['user_id', 'permission_id', 'effect', 'reason', 'created_by', 'updated_by', 'created_at', 'updated_at']) check(service.includes(`'${column}'`), `availability checks ${column}`);
const overrideAvailabilityBody = service.slice(service.indexOf('public function userPermissionOverrideStorageAvailable'), service.indexOf('/** @param array<int,mixed> $permissionIds', service.indexOf('public function userPermissionOverrideStorageAvailable')));
check(!overrideAvailabilityBody.includes('directPermissionStorageAvailable'), 'legacy direct pivot is not required for override availability');
check(controller.includes("'permissionStorageAvailable' => $this->users->userPermissionOverrideStorageAvailable()"), 'ERP User Management uses C89.3 availability');
check(service.includes("['permissions', 'permission_masters', 'permission_master']"), 'permission catalog detection remains adaptive');
check(service.includes("['role_has_permissions', 'permission_role', 'role_permissions', 'role_permission']"), 'role inheritance detection remains adaptive');
check(matrix.includes("DB::table('user_permission_overrides')") && matrix.includes("if ($effect === 'DENY')"), 'explicit override reads use C89.3 storage and deny precedence');
check(overrides.includes("DB::table('user_permission_overrides')") && overrides.includes('updateOrInsert') && overrides.includes("->where('user_id'"), 'explicit override writes use C89.3 storage');
check(overrides.includes("['ALLOW', 'DENY']") && matrix.includes("'explicit_allow' => false") && matrix.includes("'explicit_deny' => false"), 'Inherited, Allow and Deny states remain supported');
check(staff.includes("Schema::hasTable('user_permission_overrides')") && staff.includes('Permission exceptions are not available until the access schema is enabled.'), 'Staff Access availability and fail-closed mutation remain intact');
check(!overrides.includes("DB::table('model_has_permissions')->insert") && !overrides.includes("DB::table('permission_user')->insert") && !overrides.includes("DB::table('user_permissions')->insert") && !overrides.includes("DB::table('user_permission')->insert"), 'no legacy direct write introduced');
check(!service.includes('C89ManagedBusinessRoute') && !service.includes('C89AuthenticatedSupportAssetRoute'), 'route marker middleware is untouched by service');
check(markerBusiness.length > 0 && markerSupport.length > 0, 'C89 route marker middleware remains present');
check(release.includes("'asset_version' => 'ERP-11.3.378-C89.5.1B.0A.2'") && release.includes("'corrective_build' => 'C89.5.1B.0A.2'"), 'corrective release identity is C89.5.1B.0A.2');
console.log('ERP-11.3.378 C89.5.1B.0A.2 user override storage regression: PASS (23 assertions)');
