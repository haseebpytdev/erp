import fs from 'node:fs';

const read = (path) => fs.readFileSync(path, 'utf8');
const assert = (condition, message) => {
  if (!condition) throw new Error(message);
};

const removal = read('app/Http/Controllers/Operations/GeneralBookingPassengerRemovalController.php');
const routes = read('routes/erp10286.php');
const progressiveAsset = read('app/Http/Controllers/System/GeneralProgressiveBookingAssetController.php');
const bookingFocusAsset = read('app/Http/Controllers/System/BookingFocusAssetController.php');
const passengerUi = read('public/erp11390/general-passenger-remove.js');
const professionalAsset = read('app/Http/Controllers/System/ErpProfessionalUiAssetController.php');
const invoiceFocus = read('app/Http/Middleware/PresentSalesInvoiceFocusedWorkspace.php');
const invoiceFocusCss = read('public/erp-ui/erp-sales-invoice-focus.css');
const release = read('config/et_erp_release.php');

assert(routes.includes("Route::delete(\n        '/system/erp-bookings/{booking}/passengers/{passenger}'"), 'booking passenger DELETE route missing');
assert(routes.includes('GeneralBookingPassengerRemovalController'), 'passenger removal controller route missing');
assert(routes.includes('EnforceGeneralBookingEditLock::class'), 'passenger DELETE route must reuse booking edit lock');
assert(removal.includes("where('booking_id', $booking)"), 'passenger removal must be booking scoped');
assert(removal.includes("where('id', $passenger)"), 'passenger removal must target the selected booking snapshot');
assert(removal.includes("$this->locks->resolve($booking)"), 'controller must independently resolve the booking lock');
assert(removal.includes("'error' => 'booking_locked'"), 'locked passenger deletion must fail deterministically');
assert(removal.includes('Passenger Master was not changed.'), 'successful response must preserve Passenger Master contract');
assert(!removal.includes("DB::table('passengers')->delete"), 'Passenger Master must never be deleted');

assert(progressiveAsset.includes('general-passenger-remove.js'), 'GENERAL asset must include passenger removal controls');
assert(progressiveAsset.includes("'Cache-Control' => 'private, max-age=300, must-revalidate'"), 'GENERAL asset must be browser cacheable');
assert(!progressiveAsset.includes("'Pragma' => 'no-cache'"), 'GENERAL asset must not force no-cache pragma');
assert(bookingFocusAsset.includes("'Cache-Control' => 'private, max-age=300, must-revalidate'"), 'booking-focus asset must be browser cacheable');
assert(!bookingFocusAsset.includes("'Pragma' => 'no-cache'"), 'booking-focus asset must not force no-cache pragma');

assert(passengerUi.includes("method: 'DELETE'"), 'passenger UI must use authoritative DELETE endpoint');
assert(passengerUi.includes("'X-CSRF-TOKEN': csrf()"), 'passenger removal must send CSRF token');
assert(passengerUi.includes('Passenger Master will not be deleted.'), 'remove confirmation must state master safety');
assert(passengerUi.includes('window.etGeneralProgressiveStep1Sync11390'), 'passenger UI must reconcile the existing booking workspace after delete');

assert(professionalAsset.includes('erp-sales-invoice-focus.css'), 'Sales Invoice focus CSS must load through professional CSS authority');
assert(professionalAsset.indexOf('erp-sales-invoice-focus.css') < professionalAsset.indexOf('erp-shell-spacing.css'), 'unified shell spacing must remain last in the professional CSS bundle');
assert(!invoiceFocus.includes('<style data-et-sales-invoice-focus='), 'Sales Invoice middleware must not inject competing shell geometry CSS');
assert(invoiceFocus.includes("$html = $this->markHtml($html);"), 'Sales Invoice focused state must be server-marked before interaction JS');
assert(invoiceFocusCss.includes('html.et-sales-invoice-focus-prepaint .app-shell'), 'Sales Invoice focused geometry CSS missing');
assert(invoiceFocusCss.includes('.et-sales-invoice-sidebar-open'), 'Sales Invoice drawer geometry missing');

assert(release.includes("'release' => 'ERP-11.3.291'"), 'functional milestone must not bump release metadata');

console.log('ERP shell / Sales Invoice / passenger removal regression: PASS');
