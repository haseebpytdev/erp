<?php

use App\Http\Controllers\Operations\UnifiedGroupPackageBookingController;
use App\Http\Middleware\InjectGroupPackageCreateEntry;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| ERP-10.30.4 Unified Group Package Routes
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
