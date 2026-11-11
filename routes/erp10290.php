<?php

use App\Http\Controllers\Operations\UnifiedGroupPackageBookingController;
use App\Http\Middleware\InjectGroupPackageCreateEntry;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::get('/operations/bookings/group-package/unified/create', [UnifiedGroupPackageBookingController::class, 'create'])
        ->name('operations.bookings.group-package-unified.create');

    // Friendly entry path under the existing Create Booking area.
    Route::get('/operations/bookings/create/group-package', [UnifiedGroupPackageBookingController::class, 'create'])
        ->name('operations.bookings.group-package-unified.create-entry');

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
 * Safely augment the EXISTING native /operations/bookings/create route.
 * We do not replace its controller/action. This only adds middleware that
 * inserts a Group Package CTA into the already-rendered native page.
 */
foreach (Route::getRoutes()->getRoutes() as $route) {
    if ($route->uri() !== 'operations/bookings/create') {
        continue;
    }

    if (! in_array('GET', $route->methods(), true)) {
        continue;
    }

    $route->middleware(InjectGroupPackageCreateEntry::class);
}
