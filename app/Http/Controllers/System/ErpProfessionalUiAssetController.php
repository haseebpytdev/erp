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
        $freshTheme = [
            base_path('public/erp-theme/et-core.css'),
            base_path('public/erp-theme/et-shell.css'),
        ];
        $module = strtolower((string) request()->query('module', ''));
        $role = strtolower((string) request()->query('role', 'standard'));
        $moduleFiles = [
            'dashboard' => 'dashboard.css', 'operations' => 'booking.css',
            'purchase' => 'registers.css', 'sales' => 'sales-invoice.css',
            'accounting' => 'accounting.css', 'reports' => 'accounting.css',
            'travel' => 'travel-masters.css', 'master-data' => 'travel-masters.css',
        ];
        // Available module authorities: base_path('public/erp-theme/et-focused-shell.css'),
        // base_path('public/erp-theme/modules/dashboard.css'),
        // base_path('public/erp-theme/modules/booking.css'),
        // base_path('public/erp-theme/modules/registers.css'),
        // base_path('public/erp-theme/modules/sales-invoice.css'),
        // base_path('public/erp-theme/modules/accounting.css'),
        // base_path('public/erp-theme/modules/travel-masters.css').
        if (isset($moduleFiles[$module])) {
            $freshTheme[] = base_path('public/erp-theme/modules/'.$moduleFiles[$module]);
        }
        if ($role === 'focused') {
            $freshTheme[] = base_path('public/erp-theme/et-focused-shell.css');
        }
        if ($role === 'register') {
            $freshTheme[] = base_path('public/erp-theme/modules/registers.css');
        }

        foreach ($freshTheme as $path) abort_unless(is_file($path), 404);

        return $this->textAsset(
            implode("\n", array_map(static fn (string $path): string => file_get_contents($path), $freshTheme)),
            'text/css; charset=UTF-8'
        );
    }

    public function js(): Response
    {
        $registerWorkspaceUi = base_path('public/erp-ui/erp-register-workspace.js');
        $base = base_path('public/erp-ui/erp-professional.js');
        $finalizer = base_path('public/erp-ui/erp-professional-finalize.js');
        // Retired runtime marker (not loaded): $ready = base_path('public/erp-ui/erp-sidebar-ready.js');
        $passengerRemove = base_path('public/erp-ui/erp-passenger-remove.js');
        $freshShell = base_path('public/erp-theme/js/shell.js');
        $freshFocusedShell = base_path('public/erp-theme/js/focused-shell.js');
        $module = strtolower((string) request()->query('module', ''));
        $role = strtolower((string) request()->query('role', 'standard'));

        abort_unless(
            is_file($registerWorkspaceUi)
            && is_file($base)
            && is_file($finalizer)
            && is_file($passengerRemove)
            && is_file($freshShell)
            && is_file($freshFocusedShell),
            404
        );

        $moduleScripts = '';
        if ($role === 'register' && in_array($module, ['purchase', 'accounting', 'reports', 'operations', 'sales'], true)) {
            $moduleScripts .= "\n".file_get_contents($registerWorkspaceUi);
        }
        if (in_array($module, ['operations'], true)) {
            $moduleScripts .= "\n".file_get_contents($passengerRemove);
        }

        return $this->textAsset(
            // Shared shell and finalizer run before unrelated workspace
            // enhancements; server-rendered navigation remains authoritative.
            file_get_contents($freshShell)
            ."\n".file_get_contents($freshFocusedShell)
            ."\n".file_get_contents($base)
            ."\n".file_get_contents($finalizer)
            .$moduleScripts,
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
