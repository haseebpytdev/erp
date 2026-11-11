<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

final class GeneralBookingWorkspaceAssetController extends Controller
{
    public function js(): Response
    {
        return $this->asset(
            base_path('public/erp11386/general-workspace.js'),
            'application/javascript; charset=UTF-8'
        );
    }

    public function css(): Response
    {
        return $this->asset(
            base_path('public/erp11386/general-workspace.css'),
            'text/css; charset=UTF-8'
        );
    }

    private function asset(
        string $path,
        string $contentType
    ): Response {
        abort_unless(
            is_file($path),
            404
        );

        return response(
            file_get_contents($path),
            200,
            [
                'Content-Type' => $contentType,
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }
}
