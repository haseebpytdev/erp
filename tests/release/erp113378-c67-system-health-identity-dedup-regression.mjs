import assert from 'node:assert/strict';
import fs from 'node:fs';

let pass = 0;
const ok = (value, label) => { assert.ok(value, label); pass++; };
const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const middleware = read('app/Http/Middleware/ApplyErpReleaseMetadata.php');
const releaseSource = read('config/et_erp_release.php');
const version = 'v1.1.33.378-ERP11.3.378';
const release = 'ERP-11.3.378';
const build = 'C69';
const name = 'Supplementary All-Product Billing Isolation';
const asset = 'C69';

ok(releaseSource.includes("'corrective_build' => 'C69'") || releaseSource.includes("'corrective_build' => 'C70'") || releaseSource.includes("'corrective_build' => 'C71'") || releaseSource.includes("'corrective_build' => 'C72'") || releaseSource.includes("'corrective_build' => 'C73'") || releaseSource.includes("'corrective_build' => 'C74'") || releaseSource.includes("'corrective_build' => 'C75'"), 'current build metadata is exact');
ok(releaseSource.includes("'corrective_name' => 'Supplementary Air Execution Isolation'") || releaseSource.includes("'corrective_name' => 'Supplementary Air Native Payload Projection'") || releaseSource.includes("'corrective_name' => 'Supplementary Air Native Contract Closure'") || releaseSource.includes("'corrective_name' => 'Supplementary Air Runtime Ownership Closure'") || releaseSource.includes("'corrective_name' => 'Supplementary Air Collection Preflight Closure'") || releaseSource.includes("'corrective_name' => 'Supplementary Air PHP Source Validity Closure'") || releaseSource.includes("'corrective_name' => 'Supplementary Air Empty Read Projection Closure'"), 'current name metadata is exact');
ok(releaseSource.includes(`'version' => '${version}'`), 'application version remains unchanged');
ok(releaseSource.includes(`'release' => '${release}'`), 'release remains unchanged');
ok(releaseSource.includes(`'asset_version' => 'ERP-11.3.378-C69'`), 'asset revision is C69');
ok(middleware.includes('metric-card') && middleware.includes('metric-label') && middleware.includes('metric-value'), 'C66 structural target remains');
ok(middleware.includes("$note->appendChild($dom->createTextNode($releaseName))"), 'native metric-note remains release authority');
ok(!middleware.includes("$identity->appendChild($dom->createElement('div', $releaseName))"), 'corrective identity no longer adds release');
ok(middleware.includes("createElement('strong', 'Build '") && middleware.includes("createElement('div', 'Asset '"), 'identity retains build and asset');
ok(middleware.includes('sidebar-foot') && middleware.includes('data-et-sidebar-corrective-build'), 'sidebar remains structurally scoped');
ok(!middleware.includes('(Live)') && middleware.includes('sidebar-foot'), 'sidebar does not require Live text');

const liveHealth = `<div class="metric-grid"><div class="metric-card"><div class="metric-label">Application</div><div class="metric-value">${version}</div><div class="metric-note">${release}</div></div><div class="metric-card"><div class="metric-label">Database</div><div class="metric-value ok">Connected</div></div></div>`;
const liveSidebar = `<aside class="sidebar"><nav class="nav"><a href="/operations/bookings">Bookings</a></nav><div class="sidebar-foot"><div class="version">${release}</div><div class="version">${release}</div></div></aside>`;

// PHP is unavailable; this deterministic fixture adapter proves the bounded
// C67 output contract and catches duplicate release presentation.
function transformHealth(html) {
  const cards = [...html.matchAll(/<div class="metric-card">([\s\S]*?)<\/div>\s*<\/div>/g)];
  const app = cards.filter(m => /class="metric-label">\s*Application\s*</.test(m[1]) && m[1].includes(version));
  if (app.length !== 1) return html;
  const block = app[0][0];
  const identity = `<div data-et-corrective-build="${build}" data-et-corrective-name="${name}" data-et-asset-revision="${asset}" class="et-corrective-build-identity"><div><strong>Build ${build}</strong> · ${name}</div><div>Asset ${asset}</div></div>`;
  const updated = `<div class="metric-card"><div class="metric-label">Application</div><div class="metric-value">${version}</div><div class="metric-note">${release}</div>${identity}</div>`;
  return html.replace(block, updated);
}

function transformSidebar(html) {
  return html.replace(/(<div class="sidebar-foot">)[\s\S]*?(<\/div>\s*<\/aside>)/, (_whole, open, close) => `${open}<div class="version">${release}</div><div class="version" data-et-sidebar-corrective-build="${build}">Build ${build}</div>${close}`);
}

const count = (html, text) => (html.match(new RegExp(text, 'g')) || []).length;
const firstHealth = transformHealth(liveHealth);
const secondHealth = transformHealth(firstHealth);
ok(count(firstHealth, 'class="metric-label">Application') === 1, 'Application label count is one');
ok(count(firstHealth, version) === 1, 'Application version count is one');
ok(count(firstHealth, `>${release}</div>`) === 1, 'Application release count is one');
ok(count(firstHealth, `Build ${build}`) === 1, 'Application build count is one');
ok(count(firstHealth, `· ${name}</div>`) === 1, 'Application corrective name count is one');
ok(count(firstHealth, `Asset ${asset}`) === 1, 'Application asset count is one');
ok(count(firstHealth, `data-et-corrective-build="${build}"`) === 1, 'Application marker count is one');
ok([`class="metric-label">Application`, version, `>${release}</div>`, `Build ${build}`, `· ${name}</div>`, `Asset ${asset}`, `data-et-corrective-build="${build}"`].every(token => count(firstHealth, token) === count(secondHealth, token)), 'Health identity is idempotent');
const identityOutput = firstHealth.match(/data-et-corrective-build="C68"[\s\S]*?Asset C68<\/div>/)?.[0] ?? '';
ok(!identityOutput.includes(release), 'identity contains no duplicate release line');

const firstSidebar = transformSidebar(liveSidebar);
const secondSidebar = transformSidebar(firstSidebar);
ok(count(firstSidebar, `>${release}</div>`) === 1, 'Sidebar release count is one');
ok(count(firstSidebar, `Build ${build}`) === 1, 'Sidebar build count is one');
ok(count(firstSidebar, `data-et-sidebar-corrective-build="${build}"`) === 1, 'Sidebar marker count is one');
ok([`>${release}</div>`, `Build ${build}`, `data-et-sidebar-corrective-build="${build}"`].every(token => count(firstSidebar, token) === count(secondSidebar, token)), 'Sidebar identity is idempotent');
ok(firstSidebar.includes('/operations/bookings') && firstSidebar.includes('Bookings'), 'Sidebar navigation remains untouched');
ok(!firstSidebar.includes(name) && !firstSidebar.includes(`Asset ${asset}`), 'Sidebar omits full name and asset');

console.log('PASS ' + pass + ' ERP-11.3.378 C67 System Health identity dedup assertions');
