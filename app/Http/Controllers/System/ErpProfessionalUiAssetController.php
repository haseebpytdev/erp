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

        abort_unless(
            is_file($prepaint)
            && is_file($base)
            && is_file($accountingUi)
            && is_file($registerWorkspaceUi)
            && is_file($shellSpacingUi),
            404
        );
        foreach ($freshTheme as $path) abort_unless(is_file($path), 404);

        $legacyModules = '';
        // Legacy compatibility order (scoped below): file_get_contents($base)
        // -> file_get_contents($accountingUi) -> file_get_contents($registerWorkspaceUi)
        // -> file_get_contents($shellSpacingUi).
        if (in_array($module, ['accounting', 'reports'], true)) {
            $legacyModules .= "\n".file_get_contents($accountingUi);
        }
        if (in_array($module, ['purchase', 'accounting', 'reports'], true) && $role === 'register') {
            $legacyModules .= "\n".file_get_contents($registerWorkspaceUi);
        }

        return $this->textAsset(
            file_get_contents($prepaint)
            ."\n".file_get_contents($base)
            .$legacyModules
            ."\n".file_get_contents($shellSpacingUi)
            ."\n".implode("\n", array_map(static fn (string $path): string => file_get_contents($path), $freshTheme)),
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
        $freshShell = base_path('public/erp-theme/js/shell.js');
        $freshFocusedShell = base_path('public/erp-theme/js/focused-shell.js');
        $module = strtolower((string) request()->query('module', ''));
        $role = strtolower((string) request()->query('role', 'standard'));

        abort_unless(
            is_file($registerWorkspaceUi)
            && is_file($base)
            && is_file($finalizer)
            && is_file($ready)
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
            // Sidebar preparation/finalization must run before unrelated
            // register-workspace enhancement so prepaint can resolve as early
            // as possible without changing the canonical navigation contract.
            file_get_contents($freshShell)
            ."\n".file_get_contents($freshFocusedShell)
            ."\n".file_get_contents($base)
            ."\n".file_get_contents($finalizer)
            ."\n".file_get_contents($ready)
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
