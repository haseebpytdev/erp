import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const service = read('app/Services/Administration/ErpUserManagementService.php');
const staff = read('app/Services/Administration/ErpStaffAccessService.php');
const authority = read('app/Services/Administration/ErpBranchAccessAuthority.php');
const release = read('config/et_erp_release.php');

const checks = [
  [service.includes('resolveTargetCompanyId'), 'shared target company resolver exists'],
  [service.includes('linkedStaffId($userId, $user)') && service.includes("$staffCompanyColumn"), 'linked staff company authority'],
  [service.includes("collect(['company_id', 'company', 'organization_id'])"), 'company column compatibility resolution'],
  [service.includes('Request data and submitted branches are never used as company evidence.'), 'request company is not trusted'],
  [service.includes('Target user company context is required for branch assignments.'), 'unresolved company fails closed'],
  [service.includes("where('company_id', $companyId)") || service.includes("where('company_id', $companyId)"), 'same-company branch validation preserved'],
  [service.includes('users.staff_id') && service.includes('staff.company_id'), 'staff-linked ownership documented'],
  [service.includes('if ($companyColumn)'), 'direct company source remains compatible'],
  [staff.includes('updateAccessAssignments'), 'Staff Access uses shared persistence'],
  [authority.includes('companyBranches'), 'branch authority remains company bounded'],
  [release.includes("'asset_version' => 'ERP-11.3.378-C89.4.3'") && release.includes("'corrective_build' => 'C89.4.3'") && release.includes("'corrective_name' => 'Target User Company Context Resolution'"), 'C89.4.3 metadata'],
  [!service.includes("$input['company_id']") && !service.includes("$input[\"company_id\"]"), 'request company cannot override target'],
];

for (const [ok, label] of checks) assert.ok(ok, label);
console.log(`C89.4.3 company context resolution regression: ${checks.length} assertions passed`);
