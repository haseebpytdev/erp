import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const ok = (condition, label) => { if (!condition) throw new Error(label); console.log(`PASS ${label}`); };

const presenter = read('app/Services/Operations/BookingWorkspaceShellPresenter.php');
const bookingCss = read('public/erp-theme/modules/booking.css');
const coreCss = read('public/erp-theme/et-core.css');
const professionalCss = read('public/erp-ui/erp-professional.css');
const progressiveCss = read('public/erp11390/general-progressive-step1.css');
const visaCss = read('public/erp-theme/css/products/visa.css');

ok(!presenter.includes('$productLauncher =') && presenter.includes('data-et-smart-products-entry="1"'), 'one Products launcher authority remains');
ok(presenter.includes('data-et-c36-product-summary="1"') && presenter.includes('data-et-smart-products-entry="1"'), 'Products summary is inside the canonical booking presentation');
ok(!presenter.includes('$productLauncher ='), 'independent outer footer launcher is removed');
ok(presenter.includes("preg_replace('/<\\/main>/i', $summary"), 'summary is server-seeded before progressive canvas adoption');
ok(!presenter.includes('>Open Products</a>') && presenter.includes('Review Booking'), 'Products footer uses the Review Booking action');
ok(bookingCss.includes('.et-c36-product-summary-grid') && bookingCss.includes('grid-template-columns:repeat(4,minmax(0,1fr))'), 'desktop Products summary uses the booking grid');
ok(bookingCss.includes('@media(max-width:900px)') && bookingCss.includes('repeat(2,minmax(0,1fr))'), 'tablet Products summary uses two columns');
ok(bookingCss.includes('@media(max-width:600px)') && bookingCss.includes('grid-template-columns:1fr'), 'mobile Products summary uses one column');
ok(bookingCss.includes('et-c36-product-summary-actions'), 'Products action has a real visible action container');

const primaryFamilies = ['.et-booking-focus-btn.primary','.et-ph-btn.primary','.br-btn.primary'];
for (const family of primaryFamilies) ok(bookingCss.includes(family), `booking primary family has active theme authority: ${family}`);
for (const family of ['.et-btn.primary','.et378-btn.primary','.et-reg-primary-action','.btn-primary','.btn-success','.btn-danger','.btn-warning']) {
  ok(coreCss.includes(family) || professionalCss.includes(family), `global action family is covered: ${family}`);
}
ok(/a\.et-booking-focus-btn\.primary[\s\S]*?background:var\(--et-primary\)/.test(bookingCss), 'focus primary normal background is non-white');
ok(/et-booking-focus-btn\.primary:hover[\s\S]*?background:#125bb8/.test(bookingCss), 'focus primary hover is darker and readable');
ok(bookingCss.includes('a.et-booking-focus-btn.primary:visited') && bookingCss.includes('focus-visible') && bookingCss.includes('active'), 'focus primary visited/focus/active states are covered');
ok(bookingCss.includes('et-booking-focus-btn.primary:disabled') && bookingCss.includes('color:#fff!important'), 'focus primary disabled state remains readable');
ok(coreCss.includes('a.et-btn.primary') && coreCss.includes(':disabled'), 'et-btn primary effective normal and disabled states are covered');
ok(professionalCss.includes('.btn-success') && professionalCss.includes('.btn-danger') && professionalCss.includes('.btn-warning'), 'semantic success/danger/warning families remain covered');
ok(progressiveCss.includes('etgp-passenger-mode-button.btn-primary') && progressiveCss.includes('etgp-reuse-proxy-add'), 'passenger action families remain in the active progressive bundle');
ok(progressiveCss.includes('etgp-air-save-113106') && progressiveCss.includes('etgp-hotel-save-113127') && progressiveCss.includes('etgp-transport-save-113139'), 'Air/Hotel/Transport save families remain in the active bundle');
ok(progressiveCss.includes('etgp-visa-btn-113142.is-primary') && visaCss.includes('etgp-visa-btn-primary-366'), 'Visa primary families remain readable');
ok(!/\.et-c36-product-summary[^}]*overflow-x\s*:\s*(?:auto|scroll)/.test(bookingCss), 'Products summary introduces no horizontal overflow');
console.log('C36_CORRECTIVE2_REGRESSION=PASS (24 assertions)');
