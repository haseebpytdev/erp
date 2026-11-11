<?php

use App\Http\Controllers\Operations\UnifiedGroupPackageBookingController;
use App\Http\Controllers\Operations\GroupUmrahWorkflowController;
use App\Http\Controllers\Operations\GroupUmrahVoucherController;
use App\Http\Controllers\Operations\GroupUmrahCommercialAmendmentController;
use App\Http\Controllers\Operations\GroupUmrahSalesInvoiceBridgeController;
use App\Http\Middleware\InjectGroupPackageCreateEntry;
use App\Http\Middleware\ApplyErpReleaseMetadata;
use App\Http\Middleware\RedirectGroupUmrahDraftToUnified;
use App\Http\Middleware\PresentGroupUmrahBookingRegister;
use App\Http\Middleware\RedirectGroupUmrahLegacyWorkspace;
use App\Http\Middleware\RedirectGroupUmrahLegacyVoucher;
use App\Http\Middleware\InjectGroupUmrahSalesInvoiceCreateContext;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| ERP-10.31.14 Unified Group Package Routes
|--------------------------------------------------------------------------
|
| The Group Package CREATE screen itself is rendered through the already
| existing /operations/bookings/create route by middleware when
| ?group_package=1 is present.
|
| Save/Edit endpoints intentionally live OUTSIDE the existing dynamic
| /operations/bookings/{booking} namespace to avoid route collisions.
|
*/

Route::middleware(['auth'])->group(function (): void {
    // Optional safe direct entry for diagnostics/bookmarks.
    Route::get('/operations/group-package-bookings/create', [UnifiedGroupPackageBookingController::class, 'create'])
        ->name('operations.bookings.group-package-unified.create');

    Route::post('/operations/group-package-bookings', [UnifiedGroupPackageBookingController::class, 'store'])
        ->name('operations.bookings.group-package-unified.store');

    Route::get('/operations/group-package-bookings/{booking}/edit', [UnifiedGroupPackageBookingController::class, 'edit'])
        ->whereNumber('booking')
        ->name('operations.bookings.group-package-unified.edit');

    Route::put('/operations/group-package-bookings/{booking}', [UnifiedGroupPackageBookingController::class, 'update'])
        ->whereNumber('booking')
        ->name('operations.bookings.group-package-unified.update');

    Route::post('/operations/group-package-bookings/{booking}/commercial-amendments', [GroupUmrahCommercialAmendmentController::class, 'store'])
        ->whereNumber('booking')
        ->name('operations.bookings.group-umrah-commercial-amendments.store');

    Route::get('/operations/group-package-bookings/{booking}/sales-invoice', GroupUmrahSalesInvoiceBridgeController::class)
        ->whereNumber('booking')
        ->name('operations.bookings.group-umrah-sales-invoice.bridge');

    Route::get('/operations/group-package-bookings/{booking}/voucher', [GroupUmrahVoucherController::class, 'show'])
        ->whereNumber('booking')
        ->name('operations.bookings.group-umrah-voucher.show');

    Route::post('/operations/group-package-bookings/{booking}/workflow/{action}', [GroupUmrahWorkflowController::class, 'transition'])
        ->whereNumber('booking')
        ->where('action', 'confirm|voucher-submit|voucher-approve|voucher-issue|reopen-editing')
        ->name('operations.bookings.group-umrah-workflow.transition');
});

/*
 * Attach the bridge to the ALREADY REGISTERED native New Booking GET route.
 */
foreach (Route::getRoutes()->getRoutes() as $registeredRoute) {
    if ($registeredRoute->uri() !== 'operations/bookings/create') {
        continue;
    }

    if (! in_array('GET', $registeredRoute->methods(), true)) {
        continue;
    }

    $registeredRoute->middleware(InjectGroupPackageCreateEntry::class);
}




/*
|--------------------------------------------------------------------------
| ERP-10.31.14 Native Sales Invoice Create Context
|--------------------------------------------------------------------------
|
| This ERP may expose invoice creation from the native Sales Invoice register
| rather than a standalone GET create route. The injector activates only when
| the Group Umrah bridge explicitly sends group_umrah_create=1.
|
*/
foreach (Route::getRoutes()->getRoutes() as $registeredRoute) {
    if (! in_array('GET', $registeredRoute->methods(), true)) {
        continue;
    }

    $uri = strtolower(trim((string) $registeredRoute->uri(), '/'));
    $name = strtolower((string) $registeredRoute->getName());
    $action = strtolower((string) $registeredRoute->getActionName());

    if (str_contains($uri, 'group-package-bookings')) {
        continue;
    }

    $exactRegister = $uri === 'sales/invoices';
    $controllerIndex = str_contains($action, 'salesinvoicecontroller')
        && str_ends_with($action, '@index');
    $namedRegister = str_contains($name, 'invoice')
        && (str_contains($name, 'index') || str_contains($name, 'register'));

    if ($exactRegister || $controllerIndex || $namedRegister) {
        $registeredRoute->middleware(InjectGroupUmrahSalesInvoiceCreateContext::class);
    }
}

/*
|--------------------------------------------------------------------------
| ERP-10.31.14 Dynamic Release Metadata
|--------------------------------------------------------------------------
|
| Normalize the existing legacy hard-coded ERP-10.28.4 shell/health labels
| without replacing the native Easy Ticket layout or health controller.
|
*/
foreach (Route::getRoutes()->getRoutes() as $registeredRoute) {
    if (! in_array('GET', $registeredRoute->methods(), true)) {
        continue;
    }

    $registeredRoute->middleware(ApplyErpReleaseMetadata::class);
}


foreach (Route::getRoutes()->getRoutes() as $registeredRoute) {
    if (! in_array('POST', $registeredRoute->methods(), true)) continue;
    if (! str_starts_with($registeredRoute->uri(), 'operations/bookings')) continue;
    $registeredRoute->middleware(RedirectGroupUmrahDraftToUnified::class);
}


/*
|--------------------------------------------------------------------------
| ERP-10.31.14 Booking Register / Workspace Integration
|--------------------------------------------------------------------------
|
| Keep the native Booking Register design, but present Group Umrah rows from
| unified source-of-truth tables. Direct legacy workspace opens are redirected
| to the unified editor only for Group Umrah bookings.
|
*/
foreach (Route::getRoutes()->getRoutes() as $registeredRoute) {
    if (! in_array('GET', $registeredRoute->methods(), true)) {
        continue;
    }

    if (preg_match('#^operations/bookings/\\{[^}]+\\}$#', $registeredRoute->uri())) {
        $registeredRoute->middleware(RedirectGroupUmrahLegacyWorkspace::class);
    }
}


/*
|--------------------------------------------------------------------------
| ERP-10.31.14 Legacy Voucher Redirect
|--------------------------------------------------------------------------
|
| Old /operations/bookings/{id}/travel-voucher and /voucher URLs remain valid
| for normal bookings. Unified Group Umrah bookings are redirected to the
| dedicated unified-data voucher preview.
|
*/
foreach (Route::getRoutes()->getRoutes() as $registeredRoute) {
    if (! in_array('GET', $registeredRoute->methods(), true)) {
        continue;
    }

    $uri = $registeredRoute->uri();

    if (
        preg_match('#^operations/bookings/\{[^}]+\}/travel-voucher$#', $uri)
        || preg_match('#^operations/bookings/\{[^}]+\}/voucher$#', $uri)
    ) {
        $registeredRoute->middleware(RedirectGroupUmrahLegacyVoucher::class);
    }
}
