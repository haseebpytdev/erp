import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = (p) => fs.readFileSync(path.join(root, p), 'utf8');
const matrix = read('app/Services/Administration/ErpPermissionMatrixService.php');
const access = read('app/Services/Administration/ErpStaffAccessService.php');
const override = read('app/Services/Administration/ErpUserPermissionOverrideService.php');
const view = read('resources/views/administration/staff-access/index.blade.php');
const migration = read('database/migrations/2026_10_16_120000_create_user_permission_overrides_table.php');
const release = read('config/et_erp_release.php');

const checks = [
  [matrix.includes("if (! Schema::hasTable('user_permission_overrides'))") && matrix.includes("}, $this->allPermissions())"), 'table-absent catalog fallback'],
  [matrix.includes("'inherited_allowed' => $allowed") && matrix.includes("'effective_allowed' => $allowed"), 'inherited effective parity'],
  [matrix.includes("'explicit_allow' => false") && matrix.includes("'explicit_deny' => false") && matrix.includes("'override_reason' => null"), 'table-absent exception state'],
  [matrix.includes('$this->inheritedPermissions($user)') && matrix.includes('$this->allPermissions()'), 'role and direct grant sources retained'],
  [override.includes("if (! Schema::hasTable('user_permission_overrides'))") && override.includes('current(int $targetUserId)'), 'override storage remains optional'],
  [access.includes("Permission exceptions are not available until the access schema is enabled."), 'mutation guard message'],
  [access.includes('if ($changes !== [] && ! Schema::hasTable'), 'override request rejected before save'],
  [access.includes('updateAccessAssignments'), 'roles branches scope remain independent'],
  [view.includes('override_table_available') && view.includes('disabled'), 'override controls disabled without table'],
  [view.includes('Permission exceptions are not available in the current schema'), 'setup-required notice'],
  [!/permission_id\s*=>\s*\d+/.test(matrix + access + view), 'no synthetic permission IDs'],
  [migration.includes("Schema::create('user_permission_overrides'") && !migration.includes('insert into') && !migration.includes('permission_masters'), 'migration remains catalog-neutral'],
  [release.includes("'asset_version' => 'ERP-11.3.378-C89.4.1'") && release.includes("'corrective_build' => 'C89.4.1'"), 'C89.4.1 metadata'],
];
for (const [ok, label] of checks) assert.ok(ok, label);
console.log(`C89.4.1 table-absent permission regression: ${checks.length} assertions passed`);
