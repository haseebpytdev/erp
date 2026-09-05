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
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }
}
