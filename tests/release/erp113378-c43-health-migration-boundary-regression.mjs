import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = file => fs.readFileSync(new URL('../../' + file, import.meta.url), 'utf8');
const middleware = read('app/Http/Middleware/ApplyErpReleaseMetadata.php');
const c42 = read('tests/release/erp113378-c42-system-health-server-shell-regression.mjs');
let assertions = 0;
const ok = (value, message) => { assert.ok(value, message); assertions++; };

const fixture = '<main class="main">'
  + '<div class="panel"><h3>Application Cache</h3><form><button>Clear Application Cache</button></form></div>'
  + '<div class="panel"><h3>Database Maintenance</h3><form><button>Run Safe Database Upgrade</button></form></div>'
  + '<section data-et-dangerous-actions="true"><a>Production Transaction Reset</a><a>Post-Reset Financial Cleanup</a></section>'
  + '</main>';
const hostileFixture = '<main class="main"><form id="whole-health-form">'
  + '<div class="panel"><h3>Application Cache</h3><button>Clear Application Cache</button></div>'
  + '<div class="panel"><h3>Database Maintenance</h3><div><button>Run Safe Database Upgrade</button></div></div>'
  + '</form></main>';

ok(middleware.includes('suppressMigrationActionBounded'), 'current/unknown status uses bounded migration action suppression');
ok(middleware.includes('DOMDocument') && middleware.includes('DOMXPath'), 'migration suppression parses HTML with DOM APIs');
ok(middleware.includes('run safe database upgrade') && middleware.includes('html_entity_decode'), 'exact normalized action label is targeted');
ok(middleware.includes("setAttribute('hidden', 'hidden')"), 'suppressed action is hidden without deleting its panel');
ok(middleware.includes("setAttribute('aria-hidden', 'true')") && middleware.includes("setAttribute('aria-disabled', 'true')"), 'suppressed action is inaccessible and disabled');
ok(middleware.includes("setAttribute('tabindex', '-1')"), 'suppressed action is removed from keyboard navigation');
ok(middleware.includes("setAttribute('disabled', 'disabled')"), 'button/input actions are disabled');
ok(!middleware.includes('while ($form instanceof'), 'ancestor form walking is absent');
ok(!middleware.includes('.*?Run\\\\s+Safe\\\\s+Database\\\\s+Upgrade.*?'), 'cross-panel migration regex is absent');
ok(!middleware.includes('preg_replace') || middleware.includes('suppressMigrationActionBounded'), 'migration action removal is not delegated to broad regex');
ok(middleware.includes("if ($status !== 'pending')") && middleware.includes('$target instanceof') && middleware.includes('return $html;'), 'current and unknown states fail closed when action is found or absent');
ok(middleware.includes("$status !== 'pending'") && middleware.includes('suppressMigrationActionBounded($html)'), 'pending status preserves the action by skipping suppression');
ok(middleware.includes('data-et-migration-presentation=') && middleware.includes('data-et-migration-message='), 'migration presentation markers remain preserved');
ok(fixture.includes('Application Cache') && fixture.includes('Clear Application Cache'), 'fixture contains the cache panel and action');
ok(fixture.includes('Database Maintenance') && fixture.includes('Run Safe Database Upgrade'), 'fixture contains the database panel and action');
ok(fixture.includes('data-et-dangerous-actions="true"'), 'fixture contains bounded Dangerous Actions');
ok((fixture.match(/<div class="panel">/g) || []).length === 2, 'fixture has two independent panels');
ok(!fixture.includes('sidebar') && fixture.includes('<main class="main">'), 'fixture models normalized C42 main without shell changes');
ok(middleware.includes('$form = $target->parentNode') && middleware.includes("strtolower($form->tagName) === 'form'"), 'only the direct parent form may be additionally disabled');
ok(hostileFixture.includes('whole-health-form') && hostileFixture.includes('<div><button>Run Safe Database Upgrade</button></div>'), 'hostile wrapper fixture has a non-form direct parent');
ok(hostileFixture.includes('Clear Application Cache') && hostileFixture.includes('Application Cache'), 'hostile fixture keeps cache action and panel');
ok(fixture.includes('<form><button>Run Safe Database Upgrade</button></form>'), 'dedicated upgrade form fixture is present');
ok(middleware.includes('$normalized = $dom->saveHTML()') && middleware.includes('return $normalized'), 'bounded DOM result is serialized server-side');
ok(c42.includes('NativeErpLayoutResolver') && c42.includes('replaceChild($nativeSidebar, $sidebar)'), 'C42 native shell replacement contract remains present');
ok(!middleware.includes('$sidebar->appendChild($nativeSidebar)'), 'C42 nested-sidebar defect remains prevented');
ok(!middleware.includes("createElement('a')") && !middleware.includes("setAttribute('href'"), 'no navigation URLs are fabricated');
console.log('RUNTIME_FIXTURE_EXECUTION=BLOCKED_PHP_UNAVAILABLE');
console.log('erp113378-c43-health-migration-boundary-regression: ' + assertions + ' assertions passed');
