<?php

use App\Http\Controllers\Operations\UnifiedGroupPackageBookingController;
use App\Http\Middleware\InjectGroupPackageCreateEntry;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| ERP-10.30.3 Native Card Unified Group Package Routes
|--------------------------------------------------------------------------
|
| This file is loaded by routes/erp10286.php, which is already loaded by the
| Easy Ticket ERP routes/web.php from the earlier operational deployment.
|
*/

Route::middleware(['auth'])->group(function (): void {
    Route::get('/operations/bookings/create/group-package', [UnifiedGroupPackageBookingController::class, 'create'])
        ->name('operations.bookings.group-package-unified.create-entry');

    Route::get('/operations/bookings/group-package/unified/create', [UnifiedGroupPackageBookingController::class, 'create'])
        ->name('operations.bookings.group-package-unified.create');

    Route::post('/operations/bookings/group-package/unified', [UnifiedGroupPackageBookingController::class, 'store'])
        ->name('operations.bookings.group-package-unified.store');

    Route::get('/operations/bookings/{booking}/group-package/unified', [UnifiedGroupPackageBookingController::class, 'edit'])
        ->whereNumber('booking')
        ->name('operations.bookings.group-package-unified.edit');

    Route::put('/operations/bookings/{booking}/group-package/unified', [UnifiedGroupPackageBookingController::class, 'update'])
        ->whereNumber('booking')
        ->name('operations.bookings.group-package-unified.update');
});

/*
 * Attach the native Umrah / Package card bridge middleware to the ALREADY REGISTERED native
 * /operations/bookings/create route without replacing its controller.
 *
 * routes/erp10286.php is loaded at the end of the existing web route file, so
 * the native Create Booking route is already registered when this runs.
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
