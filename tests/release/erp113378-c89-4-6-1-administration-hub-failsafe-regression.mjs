import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const transformer = fs.readFileSync(path.join(root, 'app/Services/Operations/AdministrationHubPresentationTransformer.php'), 'utf8');
const middleware = fs.readFileSync(path.join(root, 'app/Http/Middleware/ApplyErpReleaseMetadata.php'), 'utf8');
const release = fs.readFileSync(path.join(root, 'config/et_erp_release.php'), 'utf8');
const check = (condition, message) => assert.equal(Boolean(condition), true, message);

const assertions = [
  ['original snapshot', transformer.includes('$original = $html;')],
  ['GET guard', transformer.includes("$request->method() !== 'GET'")],
  ['exact path guard', transformer.includes("trim($request->path(), '/') !== 'administration'")],
  ['main marker guard', transformer.includes('Staff, Access & Approval Control')],
  ['hero preflight', transformer.includes('Control who works in the ERP and what each person may do.')],
  ['description preflight', transformer.includes('Staff identity is separate from login access.')],
  ['security preflight', transformer.includes('Permanent Security Rules')],
  ['all cards preflight', transformer.includes('Staff Directory') && transformer.includes('Audit & Login History')],
  ['stats preflight', transformer.includes("'Active'.' Staff'") && transformer.includes('Approval Policies')],
  ['preflight helper', transformer.includes('private function preflight')],
  ['preflight before mutation', transformer.indexOf('preflight($xpath, $html)') < transformer.indexOf('setPrimaryHeading($xpath)')],
  ['failure original', transformer.includes('if (! $this->preflight($xpath, $html)) return $original;')],
  ['postcheck helper', transformer.includes('private function postcheck')],
  ['old header postcheck', transformer.includes("'staff, access & approval control'")],
  ['old hero postcheck', transformer.includes("'control who works in the erp and what each person may do.'")],
  ['ERP02 postcheck', transformer.includes("'erp-02'")],
  ['security postcheck', transformer.includes("'permanent security rules'")],
  ['postcheck failure original', transformer.includes('return $this->postcheck($transformed) ? $transformed : $original;')],
  ['exception failure original', transformer.includes('catch (\\Throwable)') && transformer.includes('return $original;')],
  ['ERP management route', transformer.includes('/administration/erp-user-management')],
  ['Staff Access route', transformer.includes('/administration/staff-access')],
  ['ERP card rename', transformer.includes("renameCard($cards, 'ERP Users', 'ERP User Management'")],
  ['Staff card clone', transformer.includes('cloneNode(true)')],
  ['Staff duplicate guard', transformer.includes("'staff access'")],
  ['native card preservation', transformer.includes('cardNamed')],
  ['native stats not calculated', !transformer.includes('DB::') && !transformer.includes('Schema::')],
  ['no middleware edit', middleware.includes('AdministrationHubPresentationTransformer')],
  ['no route file required', fs.existsSync(path.join(root, 'routes/erp103179.php'))],
  ['no controller required', fs.existsSync(path.join(root, 'app/Http/Controllers/Administration/ErpStaffAccessController.php'))],
  ['metadata version', release.includes("'version' => 'v1.1.33.378-ERP11.3.378'")],
  ['metadata release', release.includes("'release' => 'ERP-11.3.378'")],
  ['metadata asset', release.includes("'asset_version' => 'ERP-11.3.378-C89.4.6.1'")],
  ['metadata build', release.includes("'corrective_build' => 'C89.4.6.1'")],
  ['metadata name', release.includes("'corrective_name' => 'Administration Hub Fail-Safe Completion'")],
  ['no security service reference', !transformer.includes('ErpBranchAccessAuthority')],
  ['no air runtime reference', !transformer.includes('air.js')],
  ['no database query', !transformer.includes('DB::')],
  ['idempotent marker removal', transformer.includes('Staff, Access & Approval Control')],
  ['malformed fallback path', transformer.includes('if (! $loaded) return $original;')],
  ['authoritative source remains native', middleware.includes('ApplyErpReleaseMetadata')],
];

for (const [name, ok] of assertions) check(ok, name);
console.log(`C89.4.6.1 fail-safe Administration Hub regression: ${assertions.length} assertions passed`);
