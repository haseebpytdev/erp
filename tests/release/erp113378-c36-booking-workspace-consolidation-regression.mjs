import fs from 'node:fs';

const read = (path) => fs.readFileSync(path, 'utf8');
const ok = (condition, label) => { if (!condition) throw new Error(label); console.log(`PASS ${label}`); };

const presenter = read('app/Services/Operations/BookingWorkspaceShellPresenter.php');
const progressive = read('public/erp11390/general-progressive-step1.js');
const focus = read('public/erp11335/booking-focus.js');
const bookingCss = read('public/erp-theme/modules/booking.css');
const release = read('config/et_erp_release.php');
const middleware = read('app/Http/Middleware/ApplyErpReleaseMetadata.php');
const c69 = read('public/erp-ui/erp-professional.js');

ok(presenter.includes('BookingProductSummaryResolver') && presenter.includes('productSummaryMarkup'), 'main booking uses lightweight product summary authority');
ok(progressive.includes('etgpRemoveLegacyMainProductEditors11390') && progressive.includes('data-et-c36-summary-only'), 'main booking retires legacy product editors');
ok(progressive.includes("label==='save transport data'") && progressive.includes('etgpMainBookingOverview11390(root)'), 'main booking removes transport save controls while preserving dedicated editor runtime');
ok(presenter.includes("/products/' . $key") || presenter.includes("/products/'.$key"), 'dedicated product destinations remain canonical');
ok(presenter.includes("$locked ? 'View'") && presenter.includes("$count > 0 ? 'Edit' : 'Open'"), 'locked and editable summary actions are distinct');
ok(presenter.includes("! preg_match('#^operations/bookings/\\d+/services/\\d+/details$#"), 'legacy service details is excluded from focused shell');
ok(focus.includes('data-et-c36-duplicate-booking-identity') && focus.includes('etC36NormalizeBookingIdentityAndProductLinks'), 'duplicate booking identity is removed by focused runtime');
ok(focus.includes("'/products/visa'"), 'explicit Visa details links canonicalize to dedicated workspace');
ok(bookingCss.includes('grid-template-columns:repeat(4,minmax(0,1fr))') && bookingCss.includes('grid-template-columns:1fr'), 'KPI grid has desktop and mobile authorities');
ok(!/\.et-ph-kv\)\{display:grid;grid-template-columns:repeat\(3/.test(bookingCss), 'conflicting three-column KPI rule is absent');
ok(/a\.et-booking-focus-btn\.primary[\s\S]*?background:var\(--et-primary\)/.test(bookingCss), 'Open Products normal primary background is active');
ok(bookingCss.includes(':visited') && bookingCss.includes(':focus-visible') && bookingCss.includes(':active') && bookingCss.includes(':disabled'), 'primary action states remain readable');
ok(release.includes("'asset_version' => 'ERP-11.3.378-C36'"), 'C36 has deterministic presentation asset revision');
ok(middleware.includes("$release['asset_version']") && middleware.includes("rawurlencode($assetVersion)"), 'professional asset URLs use presentation revision');
ok(presenter.includes("config('et_erp_release.asset_version"), 'booking assets use presentation revision');
ok(c69.includes('no pending migrations') && c69.includes('migrationUnknown'), 'C69 migration status authority is preserved');
ok(middleware.includes('normalizeMigrationPresentation') && middleware.includes("$status !== 'pending'"), 'System Health current and unknown actions fail closed server-side');
ok(middleware.includes('Air tickets are now atomic commercial records'), 'System Health migration-area legacy ticket copy is removed');
ok(presenter.includes('lockPresentationMessage') && presenter.includes('Travel Ready'), 'lock wording derives from resolved lifecycle status');
ok(presenter.includes('insertNearBookingHeader'), 'lock notice is inserted near booking header');
ok(!presenter.includes("preg_replace('/<\\/body>/i',$locked"), 'duplicate bottom lock notice is not used');
console.log('C36_BOOKING_WORKSPACE_REGRESSION=PASS (21 assertions)');
