<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

final class ErpProfessionalUiAssetController extends Controller
{
    public function css(): Response
    {
        $prepaint = base_path('public/erp-ui/erp-sidebar-prepaint.css');
        $base = base_path('public/erp-ui/erp-professional.css');
        $voucherUi = base_path('public/erp-ui/erp-accounting-vouchers.css');

        abort_unless(is_file($prepaint) && is_file($base) && is_file($voucherUi), 404);

        return $this->textAsset(
            file_get_contents($prepaint)."\n".file_get_contents($base)."\n".file_get_contents($voucherUi),
            'text/css; charset=UTF-8'
        );
    }

    public function js(): Response
    {
        $base = base_path('public/erp-ui/erp-professional.js');
        $finalizer = base_path('public/erp-ui/erp-professional-finalize.js');
        $ready = base_path('public/erp-ui/erp-sidebar-ready.js');

        abort_unless(is_file($base) && is_file($finalizer) && is_file($ready), 404);

        return $this->textAsset(
            file_get_contents($base)."\n".file_get_contents($finalizer)."\n".file_get_contents($ready),
            'application/javascript; charset=UTF-8'
        );
    }

    private function textAsset(string $content, string $contentType): Response
    {
        return response(
            $content,
            200,
            [
                'Content-Type' => $contentType,
                // Asset URLs already carry the ERP release version. Browser-only
                // immutable caching removes repeated authenticated round-trips on
                // every navigation without allowing a shared proxy to cache them.
                'Cache-Control' => 'private, max-age=31536000, immutable',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }
}
