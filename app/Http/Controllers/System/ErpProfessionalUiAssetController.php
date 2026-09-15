<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ErpProfessionalUiAssetController extends Controller
{
    public function tesseract(string $type, string $asset): BinaryFileResponse
    {
        $roots = ['dist' => 'dist', 'core' => 'core', 'lang-data' => 'lang-data'];
        abort_unless(isset($roots[$type]) && preg_match('/^[A-Za-z0-9._-]+$/', $asset), 404);
        $path = base_path('public/erp-ui/vendor/tesseract/'.$roots[$type].'/'.$asset);
        abort_unless(is_file($path), 404);
        $mime = str_ends_with($asset, '.wasm') ? 'application/wasm' : (str_ends_with($asset, '.gz') ? 'application/gzip' : (str_ends_with($asset, '.js') ? 'application/javascript; charset=UTF-8' : 'application/octet-stream'));
        return response()->file($path, ['Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=31536000, immutable']);
    }

    public function css(): Response
    {
        $prepaint = base_path('public/erp-ui/erp-sidebar-prepaint.css');
        $base = base_path('public/erp-ui/erp-professional.css');
        $accountingUi = base_path('public/erp-ui/erp-accounting-vouchers.css');
        $registerWorkspaceUi = base_path('public/erp-ui/erp-booking-register-reference.css');
        $shellSpacingUi = base_path('public/erp-ui/erp-shell-spacing.css');

        abort_unless(
            is_file($prepaint)
            && is_file($base)
            && is_file($accountingUi)
            && is_file($registerWorkspaceUi)
            && is_file($shellSpacingUi),
            404
        );

        return $this->textAsset(
            file_get_contents($prepaint)
            ."\n".file_get_contents($base)
            ."\n".file_get_contents($accountingUi)
            ."\n".file_get_contents($registerWorkspaceUi)
            ."\n".file_get_contents($shellSpacingUi),
            'text/css; charset=UTF-8'
        );
    }

    public function js(): Response
    {
        $registerWorkspaceUi = base_path('public/erp-ui/erp-register-workspace.js');
        $base = base_path('public/erp-ui/erp-professional.js');
        $finalizer = base_path('public/erp-ui/erp-professional-finalize.js');
        $ready = base_path('public/erp-ui/erp-sidebar-ready.js');
        $passengerRemove = base_path('public/erp-ui/erp-passenger-remove.js');

        abort_unless(
            is_file($registerWorkspaceUi)
            && is_file($base)
            && is_file($finalizer)
            && is_file($ready)
            && is_file($passengerRemove),
            404
        );

        return $this->textAsset(
            file_get_contents($registerWorkspaceUi)
            ."\n".file_get_contents($base)
            ."\n".file_get_contents($finalizer)
            ."\n".file_get_contents($ready)
            ."\n".file_get_contents($passengerRemove),
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
