import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const users = read('app/Services/Administration/ErpUserManagementService.php');
const staff = read('app/Services/Administration/ErpStaffAccessService.php');
const authority = read('app/Services/Administration/ErpBranchAccessAuthority.php');
const controller = read('app/Http/Controllers/Administration/ErpUserManagementController.php');
const view = read('resources/views/administration/erp-user-management.blade.php');
const staffView = read('resources/views/administration/staff-access/index.blade.php');
const routes = read('routes/erp103179.php');
const release = read('config/et_erp_release.php');
const migrationDiff = execFileSync('git', ['diff', '--name-only', '--', 'database/migrations'], { cwd: root, encoding: 'utf8' });

const checks = [
  [users.includes('public function persistAccessAuthority'), 'one shared access persistence authority'],
  [users.includes("$scope = strtoupper(trim((string) ($input['access_scope'] ?? '')))") && users.includes("$scope = 'PRIMARY_BRANCH'"), 'canonical scope normalization'],
  [users.includes("$scope === 'PRIMARY_BRANCH'") && users.includes('$branches = [$primary]'), 'primary scope requires one branch'],
  [users.includes("$scope === 'COMPANY_WIDE'") && users.includes('$branches = []'), 'company-wide has no fake branch rows'],
  [users.includes('Branch assignments must belong to the target user company.'), 'same-company branch validation'],
  [users.includes('is_primary') && users.includes('$primaryColumn'), 'branch primary flag persistence'],
  [users.includes('if (($input[\'sync_roles\'] ?? true) === true)'), 'protected role mutation option'],
  [staff.includes('updateAccessAssignments'), 'Staff Access uses shared authority'],
  [controller.includes('access_scope'), 'ERP User Management submits access scope'],
  [view.includes('name="access_scope"') && view.includes('COMPANY_WIDE'), 'ERP User Management scope editor'],
  [staffView.includes('name="access_scope"'), 'Staff Access scope editor preserved'],
  [authority.includes('is_primary') && authority.includes('assignedBranchIds'), 'branch authority resolver preserved'],
  [routes.includes("'/administration/erp-user-management'") && routes.includes("'/administration/staff-access'"), 'both access destinations preserved'],
  [release.includes("'asset_version' => 'ERP-11.3.378-C89.4.2'") && release.includes("'corrective_build' => 'C89.4.2'") && release.includes("'corrective_name' => 'User Access Persistence Authority'"), 'C89.4.2 metadata'],
  [migrationDiff.trim() === '', 'override schema migration untouched'],
  [!users.includes('DB::statement') && !staff.includes('DB::statement'), 'no raw SQL'],
];

for (const [ok, label] of checks) assert.ok(ok, label);
console.log(`C89.4.2 user access persistence regression: ${checks.length} assertions passed`);
