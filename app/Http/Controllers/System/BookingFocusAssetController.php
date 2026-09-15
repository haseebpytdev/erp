<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

final class BookingFocusAssetController extends Controller
{
    public function __invoke(): Response
    {
        $path = base_path('public/erp11335/booking-focus.js');

        abort_unless(is_file($path), 404);

        return response(
            file_get_contents($path),
            200,
            [
                'Content-Type' => 'application/javascript; charset=UTF-8',
                // Booking focus is requested through a versioned private route.
                // A short browser cache removes repeated authenticated fetches
                // while keeping rollout changes recoverable without a long-lived
                // immutable contract on this legacy version token.
                'Cache-Control' => 'private, max-age=300, must-revalidate',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }
}
