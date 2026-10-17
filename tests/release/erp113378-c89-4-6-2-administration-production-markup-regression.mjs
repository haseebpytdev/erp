import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const transformerPath = path.join(root, 'app/Services/Operations/AdministrationHubPresentationTransformer.php');
const transformer = fs.readFileSync(transformerPath, 'utf8');
const release = fs.readFileSync(path.join(root, 'config/et_erp_release.php'), 'utf8');
const middleware = fs.readFileSync(path.join(root, 'app/Http/Middleware/ApplyErpReleaseMetadata.php'), 'utf8');
const routes = fs.readFileSync(path.join(root, 'routes/erp103179.php'), 'utf8');
const fixture = `
<main class="settings-grid">
  <div class="settings-card"><h2>Staff, Access &amp; Approval Control</h2>
    <p>Control who works in the ERP and what each person may do.</p>
    <p>Staff identity is separate from login access. Roles grant granular permissions, branch scope limits operational visibility, and approval authority controls high-risk financial actions.</p>
    <div class="stat">Active Staff</div><div>Active ERP Users</div><div>Roles</div><div>Approval Policies</div>
  </div>
  <div class="settings-card"><a href="https://erp.easyticket.pk/administration/staff">Staff Directory</a></div>
  <div class="settings-card"><a href="/departments">Departments</a></div>
  <div class="settings-card"><a href="/users">ERP Users</a></div>
  <div class="settings-card"><a href="/roles">Roles &amp; Permissions</a></div>
  <div class="settings-card"><a href="/approvals">Approval Authority</a></div>
  <div class="settings-card"><a href="/audit">Audit &amp; Login History</a></div>
  <section><h3>Permanent Security Rules</h3></section>
</main>`;
const check = (condition, message) => assert.equal(Boolean(condition), true, message);

const assertions = [
  ['production entity fixture', fixture.includes('Staff, Access &amp; Approval Control')],
  ['encoded roles fixture', fixture.includes('Roles &amp; Permissions')],
  ['encoded audit fixture', fixture.includes('Audit &amp; Login History')],
  ['absolute production href fixture', fixture.includes('https://erp.easyticket.pk/administration/staff')],
  ['relative production href fixture', fixture.includes('href="/users"')],
  ['original response snapshot', transformer.includes('$original = $html;')],
  ['entity validation decode', transformer.includes('html_entity_decode($html, ENT_QUOTES | ENT_HTML5, \'UTF-8\')')],
  ['raw document remains parser input', transformer.includes('$dom->loadHTML(') && transformer.includes("'.$html")],
  ['decoded string is not returned', !transformer.includes('return $validationHtml;')],
  ['preflight remains before mutation', transformer.indexOf('preflight($xpath, $validationHtml)') < transformer.indexOf('setPrimaryHeading($xpath)')],
  ['strict preflight helper', transformer.includes('private function preflight')],
  ['encoded marker accepted', transformer.includes('str_contains(strtolower($validationHtml),')],
  ['entity-aware postcheck', transformer.includes('html_entity_decode($html, ENT_QUOTES | ENT_HTML5, \'UTF-8\')')],
  ['original fallback on preflight', transformer.includes('preflight($xpath, $html)) return $original;')],
  ['original fallback on parse failure', transformer.includes('if (! $loaded) return $original;')],
  ['original fallback on postcheck', transformer.includes('return $this->postcheck($transformed) ? $transformed : $original;')],
  ['exception fail safe', transformer.includes('catch (\\Throwable)') && transformer.includes('return $original;')],
  ['main title replacement', transformer.includes("createTextNode('Administration')")],
  ['non-heading marker fallback', transformer.includes("findElementContaining($xpath, 'Staff, Access & Approval Control')")],
  ['settings card compatibility', transformer.includes('" settings-card "')],
  ['native card compatibility', transformer.includes('" card "') && transformer.includes('" admin-card "')],
  ['staff directory preflight', transformer.includes("'Staff Directory'")],
  ['departments preflight', transformer.includes("'Departments'")],
  ['roles card preflight', transformer.includes("'Roles & Permissions'")],
  ['audit card preflight', transformer.includes("'Audit & Login History'")],
  ['erp card rename', transformer.includes("renameCard($cards, 'ERP Users', 'ERP User Management'")],
  ['staff card clone', transformer.includes('cloneNode(true)')],
  ['staff route', transformer.includes('/administration/staff-access')],
  ['erp management route', transformer.includes('/administration/erp-user-management')],
  ['route generation retained', transformer.includes('routeUrl(')],
  ['raw href postcheck', transformer.includes("str_contains($html, '/administration/erp-user-management')")],
  ['no global entity rewrite', !transformer.includes("str_replace('&amp;'")],
  ['no global decode return', !transformer.includes('$html = html_entity_decode')],
  ['GET guard retained', transformer.includes("$request->method() !== 'GET'")],
  ['administration path guard retained', transformer.includes("trim($request->path(), '/') !== 'administration'")],
  ['HTML guard retained', transformer.includes("str_contains(strtolower($html), 'text/html')")],
  ['security panel removal retained', transformer.includes('removeSecurityPanel($xpath)')],
  ['stats postcheck retained', transformer.includes("'Active'.' Staff'") && transformer.includes('Approval Policies')],
  ['duplicate staff guard retained', transformer.includes("'staff access'")],
  ['no database access', !transformer.includes('DB::') && !transformer.includes('Schema::')],
  ['no route mutation', !transformer.includes('Route::')],
  ['middleware integration unchanged', middleware.includes('AdministrationHubPresentationTransformer')],
  ['routes remain present', routes.includes('administration')],
  ['release version', release.includes("'version' => 'v1.1.33.378-ERP11.3.378'")],
  ['release name', release.includes("'release' => 'ERP-11.3.378'")],
  ['C89.4.6.2 asset', release.includes("'asset_version' => 'ERP-11.3.378-C89.4.6.2'")],
  ['C89.4.6.2 build', release.includes("'corrective_build' => 'C89.4.6.2'")],
  ['C89.4.6.2 name', release.includes("'corrective_name' => 'Administration Hub Production Markup Compatibility'")],
  ['no historical test rewrite', fs.existsSync(path.join(root, 'tests/release/erp113378-c89-4-6-1-administration-hub-failsafe-regression.mjs'))],
];

for (const [name, ok] of assertions) check(ok, name);
console.log(`C89.4.6.2 production Administration markup regression: ${assertions.length} assertions passed`);
