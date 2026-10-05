import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const header = read('resources/views/operations/bookings/partials/booking-workspace-header-v11370.blade.php');
const index = read('resources/views/operations/bookings/additional-services/index.blade.php');
const show = read('resources/views/operations/bookings/additional-services/show.blade.php');
const hub = read('resources/views/operations/bookings/products-hub-v113304.blade.php');
const bookingCss = read('public/erp-theme/modules/booking.css');
const professional = read('public/erp-ui/erp-professional.js');
const c69 = read('app/Http/Middleware/ApplyErpReleaseMetadata.php');
const focus = read('public/erp11335/booking-focus.js');

let assertions = 0;
const ok = (value, message) => { assert.ok(value, message); assertions++; };
const primaryStart = hub.indexOf('<div class="et-ph-grid">');
const secondaryStart = hub.indexOf('<aside class="et-ph-secondary-note');
const primaryGrid = primaryStart >= 0 && secondaryStart > primaryStart ? hub.slice(primaryStart, secondaryStart) : '';
const primaryCards = primaryGrid.match(/data-et-product-card="[^"]+"/g) || [];
const primaryProductLoop = primaryGrid.match(/@foreach\(\[\[([\s\S]*?)\]\] as \$item\)/);

ok(!header.includes('>Menu</a>'), 'shared header has no fake Menu hyperlink');
ok(header.includes('data-et-booking-header-tools="1"') && header.includes('>Booking Register</a>'), 'Booking Register is a real shared-header left action');
ok(header.indexOf('et-booking-header-tools') < header.indexOf('et-booking-header-kicker'), 'left action holder precedes header identity');
ok(!header.includes('et-booking-header-menu'), 'header right has no legacy menu holder');
ok(focus.includes('data-et-booking-focus-menu') && focus.includes('insertBefore(toolbar,pageRegisterLink)'), 'booking-focus owns the real Menu and inserts it before Booking Register');
ok((header.match(/>Booking Register<\/a>/g) || []).length === 1, 'shared header contains one Booking Register control');

ok(index.includes('class="et-grid et-grid-3"'), 'Additional Services overview uses three-column grid');
ok(show.includes('class="et-grid et-grid-4"') && !show.includes('class="et-grid et-grid-5"'), 'Additional Services product actions use four-column grid');
ok(bookingCss.includes('.et-grid-4{grid-template-columns:repeat(4,minmax(0,1fr))'), 'active booking CSS defines four-column grid');
ok(bookingCss.includes('@media(max-width:900px)') && bookingCss.includes('.et-grid-4{grid-template-columns:repeat(2,minmax(0,1fr))'), 'four-column grid collapses to two columns');
ok(bookingCss.includes('.et-grid-4,') && bookingCss.includes('grid-template-columns:1fr;'), 'four-column grid collapses to one column on mobile');
ok(/\.et-summary\s*\{\s*display:grid;grid-template-columns:repeat\(4,minmax\(0,1fr\)\)/.test(bookingCss), 'summary cards use four desktop columns');
ok(index.includes("booking_reference'] ?? $state['booking']['booking_no'] ?? $state['booking']['reference_no']") && show.includes("booking_reference'] ?? $state['booking']['booking_no'] ?? $state['booking']['reference_no']"), 'Additional Services uses booking reference then booking number fallback');

ok(primaryGrid && primaryProductLoop && primaryProductLoop[1].match(/'air'|'hotel'|'transport'|'visa'/g).length === 4, 'Products Hub primary grid has exactly four cards');
ok(primaryProductLoop && ['air','hotel','transport','visa'].every(product => primaryProductLoop[1].includes(`'${product}'`)), 'Products Hub primary cards are Air, Hotel, Transport and Visa');
ok(!primaryGrid.includes('other-services') && hub.includes('data-et-secondary-product="other-services"'), 'Other Services is outside the primary grid');
ok(!hub.includes('data-et-product-card="other-services"'), 'Other Services is not presented as a fifth primary card');

ok(c69.includes("Schema::hasTable('migrations')") && c69.includes('array_diff($discovered, $recorded)'), 'C69 migration-status authority remains server-derived');
ok(professional.includes("databasePanel.dataset.etMigrationAction") && professional.includes("No Database Upgrade Required."), 'current migration status hides the upgrade action presentation-only');
ok(professional.includes("databasePanel.dataset.etMigrationAction = 'pending'") && professional.includes('migrationPending'), 'pending migration status keeps the upgrade action');
ok(professional.includes("Database upgrade availability could not be verified.") && professional.includes('migrationUnknown'), 'unknown migration status fails closed');
ok(!professional.includes('document.body.hidden') && !professional.includes('body.style.display'), 'no global page hiding workaround is introduced');

console.log(`C70_CORRECTIVE2_UI_REGRESSION=PASS (${assertions} assertions)`);
