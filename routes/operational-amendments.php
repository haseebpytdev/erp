<?php

use App\Http\Controllers\Operations\OperationalAmendmentController;
use Illuminate\Support\Facades\Route;

Route::post('/operations/bookings/{booking}/operational-amendments', [OperationalAmendmentController::class, 'store'])
    ->whereNumber('booking')
    ->middleware(['auth'])
    ->name('bookings.operational-amendments.store');
