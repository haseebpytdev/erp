import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = file => fs.readFileSync(new URL(`../../${file}`, import.meta.url), 'utf8');
const middleware = read('app/Http/Middleware/ApplyErpReleaseMetadata.php');
const c43 = read('tests/release/erp113378-c43-health-migration-boundary-regression.mjs');
const c41 = read('app/Services/Operations/BookingProductSummaryResolver.php');
let assertions = 0;
const ok = (value, message) => { assert.ok(value, message); assertions++; };

ok(middleware.includes('normalizeSystemHealthShell'), 'C42 Health normalization remains present');
ok(middleware.includes('NativeErpLayoutResolver'), 'native layout authority remains present');
ok(middleware.includes('renderNativeSidebar'), 'native sidebar renderer remains present');
ok(middleware.includes('$appShell->replaceChild($nativeSidebar, $sidebar)'), 'empty sidebar is replaced by the native sidebar frame');
ok(middleware.includes('isSystemHealthNode'), 'positive Health-node identification remains present');
ok(middleware.includes('$main->appendChild($child)'), 'identified Health nodes still move into main');
ok(!middleware.includes('$main->removeChild($node)'), 'destructive direct-main legacy removal is absent');
ok(!middleware.includes('Remove the obsolete bounded commercial-boundary panel only on Health.'), 'obsolete cleanup block comment is absent');
ok(c43.includes('suppressMigrationActionBounded'), 'C43 bounded migration suppression remains present');
ok(!c43.includes('/run\\s+safe\\s+database\\s+upgrade/i'), 'C43 cross-panel migration regex remains absent');
ok(!middleware.includes('while ($form instanceof'), 'C43 ancestor-form walk remains absent');
ok(c41.includes("in_array('deleted_at', $columns, true)"), 'C41 Air schema guard remains present');

const hostileFixture = `<main class="main"><div class="panel health-wrapper">
  <div class="panel"><h3>Application Cache</h3><form><button>Clear Application Cache</button></form></div>
  <div class="panel"><h3>Database Maintenance</h3><form><button>Run Safe Database Upgrade</button></form></div>
  <div class="panel"><h3>ERP-10.1 Ticket Commercial Boundary</h3><p>Ticket-level sale, purchase and commissions are visible</p></div>
</div></main>`;
ok(hostileFixture.includes('Application Cache') && hostileFixture.includes('Database Maintenance'), 'hostile fixture documents protected native Health panels');
ok(hostileFixture.includes('Clear Application Cache') && hostileFixture.includes('Run Safe Database Upgrade'), 'hostile fixture documents protected actions');
ok(hostileFixture.includes('ERP-10.1 Ticket Commercial Boundary'), 'hostile fixture documents the legacy text');
ok(hostileFixture.includes('health-wrapper'), 'hostile fixture documents aggregate wrapper risk');
ok((hostileFixture.match(/class="panel(?:\s|")/g) || []).length === 4, 'hostile fixture contains wrapper plus three panels');
ok(!middleware.includes('legacyMarkers') && !middleware.includes('protectedMarkers'), 'C44 does not replace deletion with another cleanup algorithm');
console.log('RUNTIME_FIXTURE_EXECUTION=BLOCKED_PHP_UNAVAILABLE');
console.log('erp113378-c44-health-destructive-cleanup-removal-regression: ' + assertions + ' assertions passed');
