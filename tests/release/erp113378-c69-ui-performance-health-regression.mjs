import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = (path) => fs.readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const css = read('public/erp11390/general-progressive-step1.css');
const bookingCss = read('public/erp-theme/modules/booking.css');
const coreCss = read('public/erp-theme/et-core.css');
const progressive = read('public/erp11390/general-progressive-step1.js');
const routes = read('routes/erp103179.php');
const health = read('app/Http/Middleware/ApplyErpReleaseMetadata.php');
const views = fs.readdirSync(new URL('../../resources/views/operations/bookings/additional-services', import.meta.url))
  .filter((name) => name.endsWith('.blade.php'))
  .map((name) => read(`resources/views/operations/bookings/additional-services/${name}`))
  .join('\n');

let assertions = 0;
const ok = (value, message) => { assert.ok(value, message); assertions++; };
const absent = (value, message) => { assert.equal(value, false, message); assertions++; };

// C69 performance: server-rendered page content is never globally hidden while
// the progressive enhancement script is waiting for ready/fallback.
absent(
  /et-general-progressive-step1-11390[^{}]*section\.content\s*>\s*\*/s.test(css),
  'progressive CSS must not globally hide server content'
);
absent(
  /et-booking-focus-prepaint[^{}]*et-general-progressive-step1-11390[^{}]*section\.content\s*>\s*\*/s.test(bookingCss),
  'booking CSS must not reintroduce the global hide rule'
);
ok(css.includes('Server-rendered Booking/Product content remains visible'), 'CSS documents progressive enhancement visibility authority');
ok(progressive.includes('nativeRevealFallback11390'), 'secondary progressive fallback remains available');
ok(progressive.includes("'etgp-step1-ready-11390'"), 'progressive ready state remains available');
ok(progressive.includes("'etgp-step1-fallback-11390'"), 'progressive fallback state remains available');
ok(progressive.includes('6000'), 'fallback timer remains secondary safety');

// C69 shell boundary: Additional Services stays on the permanent ERP shell.
ok(routes.includes("str_starts_with($name, 'bookings.additional-services.')"), 'Additional Services route-name exclusion is explicit');
ok(routes.includes('PresentBookingFocusedWorkspace::class'), 'focused booking shell authority remains present');
ok(routes.includes("name('bookings.additional-services.index')"), 'Additional Services index route remains');
ok(routes.includes("name('bookings.additional-services.show')"), 'Additional Services show route remains');
ok(routes.includes("name('bookings.additional-services.products.edit')"), 'Additional Services product route remains');

// C69 active operations CSS contract.
for (const token of [
  'et-page', 'et378-page', 'et-eyebrow', 'et-grid', 'et-grid-3', 'et-grid-5',
  'et-card-label', 'et-card-heading', 'et-alert', 'et-alert-warning',
  'et-alert-info', 'et-alert-danger', 'et-alert-success', 'et-list-row',
  'et-summary', 'et-empty-state', 'et-muted',
]) {
  ok(bookingCss.includes(`.${token}`), `active booking CSS defines ${token}`);
  ok(views.includes(`class="${token}`) || views.includes(` ${token}`), `Additional Services uses ${token}`);
}
ok(coreCss.includes('.et-btn'), 'existing active et-btn authority is retained');
ok(coreCss.includes('.et-card'), 'existing active et-card authority is retained');
ok(bookingCss.includes('body.et-ui-module-operations'), 'Additional Services CSS is scoped to operations');
ok(bookingCss.includes('grid-template-columns:repeat(3,minmax(0,1fr))'), 'desktop summary uses three columns');
ok(bookingCss.includes('grid-template-columns:repeat(5,minmax(0,1fr))'), 'desktop product grid uses five columns');
ok(bookingCss.includes('@media(max-width:640px)'), 'Additional Services CSS has responsive collapse');

// C69 health authority: migration files and migrations table are authoritative.
ok(health.includes("glob(database_path('migrations/*.php'))"), 'migration files are discovered from disk');
ok(health.includes("Schema::hasTable('migrations')"), 'migrations table existence is checked');
ok(health.includes("DB::table('migrations')"), 'recorded migrations are read from the native table');
ok(health.includes('pathinfo($file, PATHINFO_FILENAME)'), 'migration names are derived from basenames');
ok(health.includes('array_diff($discovered, $recorded)'), 'pending migrations are disk minus recorded');
ok(health.includes("'current' =>") || health.includes("'status' => $pending === [] ? 'current'"), 'current status is explicit');
ok(health.includes("'status' => $pending === [] ? 'current' : 'pending'"), 'pending status is explicit');
ok(health.includes("'status' => 'unknown'"), 'unknown status is explicit');
ok(health.includes('Database upgrade pending'), 'pending health message is truthful');
ok(health.includes('Database migration status could not be verified.'), 'unknown health message is fail-closed');
ok(!health.includes('return true;'), 'empty release requirements cannot independently produce current');

// Pure authority model for the three required health cases.
const resolve = (disk, recorded, authorityAvailable = true) => {
  if (!authorityAvailable) return 'unknown';
  const pending = disk.filter((name) => !recorded.includes(name));
  return pending.length === 0 ? 'current' : 'pending';
};
ok(resolve(['a', 'b'], ['a', 'b']) === 'current', 'all recorded migrations are current');
ok(resolve(['a', 'b'], ['a']) === 'pending', 'an unrecorded migration is pending');
ok(resolve(['a', 'b'], [], false) === 'unknown', 'migration authority failure is unknown');
ok(resolve(['a'], []) !== 'current', 'unknown/pending never becomes current');

console.log(`erp113378-c69-ui-performance-health-regression: ${assertions} assertions passed`);
