import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const authority = read('app/Services/Administration/ErpBranchAccessAuthority.php');
const routes = read('routes/erp103179.php');
const release = read('config/et_erp_release.php');

const checks = [
  [authority.includes('resolvedCompanyId'), 'central company resolver exists'],
  [authority.includes("attribute($user, 'staff_id')") && authority.includes("DB::table('staff')"), 'linked staff company source'],
  [authority.includes("where('company_id', $companyId)"), 'booking branch remains company bounded'],
  [authority.includes('mayAccessBranch') && authority.includes('allowedBranchIds'), 'branch authority remains centralized'],
  [routes.includes('EnforceErpBranchAccess::class'), 'booking routes use branch authority'],
  [routes.includes("'operations.bookings.show'") || routes.includes('operations.bookings.show'), 'native booking show route remains authoritative'],
  [!authority.includes('$request->input') && !authority.includes('$request->query'), 'request company cannot override authority'],
  [release.includes("'asset_version' => 'ERP-11.3.378-C89.4.4'") && release.includes("'corrective_build' => 'C89.4.4'") && release.includes("'corrective_name' => 'Booking Direct Object Access Parity'"), 'C89.4.4 metadata'],
  [!authority.includes('created_by') && !authority.includes('salesperson_staff_id'), 'no creator ownership bypass'],
  [authority.includes('return []') && authority.includes('return null'), 'unresolved authority remains fail closed'],
];

for (const [ok, label] of checks) assert.ok(ok, label);
console.log(`C89.4.4 booking direct access parity regression: ${checks.length} assertions passed`);
