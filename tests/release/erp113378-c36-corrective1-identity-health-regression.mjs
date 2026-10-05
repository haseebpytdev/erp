import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const ok = (condition, label) => { if (!condition) throw new Error(label); console.log(`PASS ${label}`); };

const progressive = read('public/erp11390/general-progressive-step1.js');
const focus = read('public/erp11335/booking-focus.js');
const presenter = read('app/Services/Operations/BookingWorkspaceShellPresenter.php');
const middleware = read('app/Http/Middleware/ApplyErpReleaseMetadata.php');

ok(progressive.includes('etgpSuppressDuplicateNativeBookingIdentity11390'), 'native booking identity suppression is executable');
ok(progressive.includes("setAttribute('data-et-c36-native-identity-suppressed','1')"), 'suppression is narrowly marked on native identity block');
ok(progressive.includes("'etgp-booking-reference'"), 'progressive Booking Workspace owns the primary identity');
ok(progressive.includes('etgpSuppressDuplicateNativeBookingIdentity11390(content,reference)'), 'native duplicate is suppressed during main mount');
ok(focus.includes('data-et-c36-duplicate-booking-identity'), 'focused runtime preserves duplicate-identity guard');
ok(focus.includes('data-et-booking-focus-menu') && focus.includes('pageRegisterLink.parentNode.insertBefore(toolbar,pageRegisterLink)'), 'Menu and Booking Register remain left-side actions');
ok(presenter.includes('lockPresentationMessage') && presenter.includes("default => 'Confirmed'") && presenter.includes('booking — editing is locked'), 'confirmed lock wording derives from resolved status');
ok(presenter.includes('insertNearBookingHeader') && !presenter.includes("$locked.\"\\n</body>"), 'lock notice remains near header without bottom duplicate');
ok(middleware.includes('normalizeMigrationPresentation'), 'System Health current presentation is server-normalized');
ok(middleware.includes("$status !== 'pending'"), 'current and unknown states remove active upgrade controls');
ok(middleware.includes('data-et-migration-presentation'), 'migration presentation status is emitted as executable markup');
ok(middleware.includes('upgrade pending'), 'pending migration action remains available');
ok(middleware.includes('could not be verified'), 'unknown migration state remains fail-closed');
console.log('C36_CORRECTIVE1_REGRESSION=PASS (12 assertions)');
