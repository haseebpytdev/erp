import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

const root = process.cwd();
const transformer = fs.readFileSync(path.join(root, 'app/Services/Operations/AdministrationHubPresentationTransformer.php'), 'utf8');
const middleware = fs.readFileSync(path.join(root, 'app/Http/Middleware/ApplyErpReleaseMetadata.php'), 'utf8');
const release = fs.readFileSync(path.join(root, 'config/et_erp_release.php'), 'utf8');

const checks = [
  ['path scoped', transformer.includes("trim($request->path(), '/') !== 'administration'"), 'GET /administration path guard is missing'],
  ['GET only', transformer.includes("$request->method() !== 'GET'"), 'non-GET guard is missing'],
  ['marker fail closed', transformer.includes('Staff, Access & Approval Control') && transformer.includes('return $html;'), 'marker fail-closed behavior is missing'],
  ['decorator integration', middleware.includes('AdministrationHubPresentationTransformer') && middleware.includes('->transform($request, $html)'), 'final decorator integration is missing'],
  ['primary heading', transformer.includes("Administration") && transformer.includes('setPrimaryHeading'), 'Administration heading normalization is missing'],
  ['legacy header removal', transformer.includes('ERP-02') && transformer.includes('Control who works in the ERP'), 'legacy header removal is missing'],
  ['four native statistics preserved', !transformer.includes('Active Staff') && !transformer.includes('new database'), 'transformer does not recalculate native statistics'],
  ['ERP management route', transformer.includes("administration.erp-user-management.index") && transformer.includes('/administration/erp-user-management'), 'ERP User Management route is missing'],
  ['Staff Access route', transformer.includes("administration.staff-access.index") && transformer.includes('/administration/staff-access'), 'Staff Access route is missing'],
  ['idempotent duplicate guard', transformer.includes("str_contains(strtolower($card->textContent), 'staff access')"), 'Staff Access idempotence guard is missing'],
  ['security panel presentation removal', transformer.includes('Permanent Security Rules'), 'security panel presentation removal is missing'],
  ['no route addition', !fs.readFileSync(path.join(root, 'routes/erp103179.php'), 'utf8').includes("'/administration',"), 'new /administration route was added'],
  ['security services untouched', !execFileSync('git', ['diff', '--name-only', 'HEAD'], { cwd: root, encoding: 'utf8' }).split(/\r?\n/).some((file) => file.includes('app/Services/Administration/')), 'security boundary files were modified'],
  ['Staff Directory preserved', transformer.includes('cardElements') && transformer.includes('ERP Users'), 'native card discovery is missing'],
  ['Departments preserved', transformer.includes('cardElements'), 'native card preservation is missing'],
  ['Roles preserved', transformer.includes('cardElements'), 'native card preservation is missing'],
  ['Approval preserved', transformer.includes('cardElements'), 'native card preservation is missing'],
  ['Audit preserved', transformer.includes('cardElements'), 'native card preservation is missing'],
  ['active styling untouched', !transformer.includes('active tab'), 'active tab styling is not owned by this transformer'],
  ['sidebar untouched', !transformer.includes('sidebar'), 'transformer must not create sidebar entries'],
  ['no permission service usage', !transformer.includes('ErpPermission'), 'presentation transformer must not alter permission services'],
  ['no branch authority usage', !transformer.includes('ErpBranchAccess'), 'presentation transformer must not alter branch authority'],
  ['no database facade', !transformer.includes('DB::') && !transformer.includes('Schema::'), 'presentation transformer must not query the database'],
  ['safe exception boundary', transformer.includes('catch (\\Throwable)'), 'transformer must fail safe'],
  ['route fallback', transformer.includes("'/administration/erp-user-management'"), 'authoritative route fallback is missing'],
  ['staff route fallback', transformer.includes("'/administration/staff-access'"), 'authoritative Staff Access fallback is missing'],
  ['clone native visual card', transformer.includes('cloneNode(true)'), 'Staff Access must reuse the native card structure'],
  ['anchor href replacement', transformer.includes("setAttribute('href'"), 'card href replacement is missing'],
  ['exact marker string', transformer.includes('staff, access & approval control'), 'exact Administration marker is missing'],
  ['scoped DOM parsing', transformer.includes('DOMDocument') && transformer.includes('DOMXPath'), 'transformer must use scoped DOM operations'],
  ['text removal scoped', transformer.includes('removePresentationText'), 'presentation text removal helper is missing'],
  ['security panel scoped', transformer.includes('removeSecurityPanel'), 'security panel removal helper is missing'],
  ['primary heading scoped', transformer.includes('setPrimaryHeading'), 'primary heading helper is missing'],
  ['metadata version', release.includes("'version' => 'v1.1.33.378-ERP11.3.378'"), 'application version changed'],
  ['release identity', release.includes("'release' => 'ERP-11.3.378'"), 'release identity changed'],
];
for (const [name, ok, message] of checks) assert.ok(ok, `${name}: ${message}`);
assert.match(release, /'asset_version'\s*=>\s*'ERP-11\.3\.378-C89\.4\.6'/);
assert.match(release, /'corrective_build'\s*=>\s*'C89\.4\.6'/);
assert.match(release, /'corrective_name'\s*=>\s*'Administration Hub Integration'/);
console.log(`C89.4.6 administration hub presentation regression: ${checks.length + 3} assertions passed`);
