<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

final class GeneralProgressiveBookingAssetController extends Controller
{
    public function js(): Response
    {
        $base = base_path('public/erp11390/general-progressive-step1.js');
        $passengerRemoval = base_path('public/erp11390/general-passenger-remove.js');

        abort_unless(is_file($base) && is_file($passengerRemoval), 404);

        return $this->textAsset(
            file_get_contents($base)."\n".file_get_contents($passengerRemoval),
            'application/javascript; charset=UTF-8'
        );
    }

    public function css(): Response
    {
        $path = base_path('public/erp11390/general-progressive-step1.css');
        abort_unless(is_file($path), 404);

        return $this->textAsset(
            file_get_contents($path),
            'text/css; charset=UTF-8'
        );
    }

    private function textAsset(string $content, string $contentType): Response
    {
        return response(
            $content,
            200,
            [
                'Content-Type' => $contentType,
                // These authenticated assets are requested through stable
                // versioned URLs. Keep a short private browser cache so normal
                // booking navigation does not force an authenticated round-trip
                // on every page while still allowing controlled rollout changes.
                'Cache-Control' => 'private, max-age=300, must-revalidate',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }
}
