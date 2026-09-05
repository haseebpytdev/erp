<?php

use App\Http\Controllers\Operations\BookingItineraryController;
use App\Http\Controllers\Operations\BookingTransportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| ERP-10.28.6 Operational Routes
|--------------------------------------------------------------------------
|
| This is the existing operational route file already loaded by routes/web.php.
| ERP-10.30.2 is chained from this known-loaded file so the new Group Package
| routes cannot be left orphaned again.
|
*/

Route::middleware(['auth'])->group(function (): void {
    Route::post('/operations/bookings/{booking}/itinerary-segments', [BookingItineraryController::class, 'store'])
        ->name('operations.bookings.itinerary-segments.store');

    Route::put('/operations/bookings/{booking}/itinerary-segments/{segment}', [BookingItineraryController::class, 'update'])
        ->name('operations.bookings.itinerary-segments.update');

    Route::delete('/operations/bookings/{booking}/itinerary-segments/{segment}', [BookingItineraryController::class, 'destroy'])
        ->name('operations.bookings.itinerary-segments.destroy');

    Route::post('/operations/bookings/{booking}/transport-segments', [BookingTransportController::class, 'store'])
        ->name('operations.bookings.transport-segments.store');

    Route::put('/operations/bookings/{booking}/transport-segments/{transport}', [BookingTransportController::class, 'update'])
        ->name('operations.bookings.transport-segments.update');

    Route::delete('/operations/bookings/{booking}/transport-segments/{transport}', [BookingTransportController::class, 'destroy'])
        ->name('operations.bookings.transport-segments.destroy');
});

// ERP-10.31.79 cumulative ERP routes.
require __DIR__.'/erp103179.php';
