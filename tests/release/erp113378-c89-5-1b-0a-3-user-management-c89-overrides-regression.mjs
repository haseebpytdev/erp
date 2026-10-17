import fs from 'node:fs';

const ok = (label, value) => {
  if (!value) throw new Error(`FAIL: ${label}`);
  console.log(`PASS: ${label}`);
};

const service = fs.readFileSync('app/Services/Administration/ErpUserManagementService.php', 'utf8');
const overrides = fs.readFileSync('app/Services/Administration/ErpUserPermissionOverrideService.php', 'utf8');
const matrix = fs.readFileSync('app/Services/Administration/ErpPermissionMatrixService.php', 'utf8');
const controller = fs.readFileSync('app/Http/Controllers/Administration/ErpUserManagementController.php', 'utf8');
const blade = fs.readFileSync('resources/views/administration/erp-user-management.blade.php', 'utf8');
const release = fs.readFileSync('config/et_erp_release.php', 'utf8');

ok('override table is editor availability authority', service.includes("Schema::hasTable('user_permission_overrides')") && service.includes("'user_id', 'permission_id', 'effect'"));
ok('tri-state request field', controller.includes("permission_overrides") && blade.includes('permission_overrides['));
ok('inherited allow deny UI', blade.includes('>Inherited</option>') && blade.includes('>Allow</option>') && blade.includes('>Deny</option>'));
ok('legacy direct state is not editor state', !blade.includes('direct_permission_ids') && !blade.includes('permission_ids[]'));
ok('ERP user management does not call legacy sync', !service.includes('syncPermissions($id'));
ok('override writes are centralized', service.includes('syncOverrides') && overrides.includes('updateOrInsert') && overrides.includes('query->delete()'));
ok('invalid state and permission fail closed', overrides.includes('Unknown permission override state') && overrides.includes('Unknown permission override target'));
ok('effective access remains central matrix', controller.includes('effectivePermissions') && matrix.includes('applyPermissionOverrides'));
ok('legacy direct reads remain matrix compatibility', matrix.includes('user_permission_link') && matrix.includes('allowedIds'));
ok('override audit and guards preserved', overrides.includes('writeAudit') && overrides.includes('sameCompany') && overrides.includes('Users cannot edit their own permission overrides'));
ok('release identity', release.includes("'corrective_build' => 'C89.5.1B.0A.3'") && release.includes("'asset_version' => 'ERP-11.3.378-C89.5.1B.0A.3'"));
console.log('ASSERTIONS=11');
