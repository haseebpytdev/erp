<?php

use App\Http\Controllers\Operations\UnifiedGroupPackageBookingController;
use App\Http\Controllers\Operations\GroupUmrahWorkflowController;
use App\Http\Controllers\Operations\GroupUmrahVoucherController;
use App\Http\Controllers\Operations\GroupUmrahCommercialAmendmentController;
use App\Http\Controllers\Operations\GroupUmrahSalesInvoiceBridgeController;
use App\Http\Controllers\Operations\GroupUmrahSalesInvoiceWorkflowController;
use App\Http\Controllers\Reports\GroupUmrahProfitabilityController;
use App\Http\Middleware\InjectGroupPackageCreateEntry;
use App\Http\Middleware\ApplyErpReleaseMetadata;
use App\Http\Middleware\RedirectGroupUmrahDraftToUnified;
use App\Http\Middleware\PresentGroupUmrahBookingRegister;
use App\Http\Middleware\RedirectGroupUmrahLegacyWorkspace;
use App\Http\Middleware\RedirectGroupUmrahLegacyVoucher;
use App\Http\Middleware\PresentGroupUmrahSalesInvoice;
use App\Http\Middleware\PreloadAirTicketFocusedWorkspace;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Event;
use Illuminate\Routing\Events\RouteMatched;

/*
|--------------------------------------------------------------------------
| ERP-10.31.59 Unified Group Package Routes
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
    Route::get('/reports/group-umrah-profitability', [GroupUmrahProfitabilityController::class, 'index'])
        ->name('reports.group-umrah-profitability.index');

    Route::get('/reports/group-umrah-profitability/{booking}', [GroupUmrahProfitabilityController::class, 'show'])
        ->whereNumber('booking')
        ->name('reports.group-umrah-profitability.show');

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

    Route::post('/operations/group-package-bookings/{booking}/sales-invoice/workflow/{action}', GroupUmrahSalesInvoiceWorkflowController::class)
        ->whereNumber('booking')
        ->where('action', 'submit|approve|post')
        ->name('operations.bookings.group-umrah-sales-invoice.workflow');

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
| ERP-10.31.59 Dynamic Release Metadata
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
| ERP-10.31.59 Booking Register / Workspace Integration
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
| ERP-10.31.59 Legacy Voucher Redirect
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


/*
|--------------------------------------------------------------------------
| ERP-10.31.59 Group Umrah Sales Invoice Presentation — Runtime Hook
|--------------------------------------------------------------------------
|
| Native Sales Invoice routes may be registered after this ERP route file.
| RouteMatched fires after Laravel knows the final route but before that
| route's middleware stack is executed, so presentation attachment is no
| longer dependent on route-registration order.
|
*/
Event::listen(RouteMatched::class, function (RouteMatched $event): void {
    $route = $event->route;

    $name = strtolower((string) $route->getName());
    $action = strtolower((string) $route->getActionName());
    $uri = strtolower((string) $route->uri());

    $isNativeSalesInvoiceShow = (
        $name === 'sales.invoices.show'
        || (
            str_contains($action, 'salesinvoicecontroller')
            && str_ends_with($action, '@show')
        )
        || (
            preg_match('#^sales/invoices/\{[^}]+\}$#', $uri) === 1
            && in_array('GET', $route->methods(), true)
        )
    );

    if (! $isNativeSalesInvoiceShow) {
        return;
    }

    $route->middleware(
        PresentGroupUmrahSalesInvoice::class
    );
});



/*
|--------------------------------------------------------------------------
| ERP-10.31.59 Air Booking Focused Workspace — First Paint Hook
|--------------------------------------------------------------------------
|
| The visible Air workspace already hides the native ERP sidebar at runtime.
| This route hook attaches response middleware to the native Booking Show route
| so the sidebar CSS is injected into <head> before browser paint, eliminating
| the visible menu flash on page load.
|
*/
Event::listen(RouteMatched::class, function (RouteMatched $event): void {
    $route=$event->route;

    if (!in_array('GET',$route->methods(),true)) {
        return;
    }

    $name=strtolower((string)$route->getName());
    $action=strtolower((string)$route->getActionName());
    $uri=strtolower((string)$route->uri());

    $isBookingShow=(
        $name==='operations.bookings.show'
        || (
            str_contains($action,'bookingcontroller')
            && str_ends_with($action,'@show')
        )
        || preg_match(
            '#^operations/bookings/\{[^}]+\}$#',
            $uri
        )===1
    );

    if (!$isBookingShow) {
        return;
    }

    $route->middleware(
        PreloadAirTicketFocusedWorkspace::class
    );
});
