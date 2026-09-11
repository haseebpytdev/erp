<?php

namespace App\Http\Middleware;

use App\Support\Release\Erp11310ObsoleteFileCleaner;
use App\Support\Release\Erp11330StabilizationCleaner;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * ERP-11.3.30
 *
 * Keeps legacy hard-coded release labels from reporting ERP-10.28.4 forever.
 *
 * This middleware does not alter business data. It only normalizes rendered
 * HTML metadata/status labels to the currently deployed release.
 *
 * Database status is only upgraded visually when the required tables/columns
 * really exist.
 */
class ApplyErpReleaseMetadata
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        // ERP-11.3.10: remove only superseded overlay files after the
        // canonical Chart workspace has been deployed successfully.
        app(Erp11310ObsoleteFileCleaner::class)->run();
        app(Erp11330StabilizationCleaner::class)->run();


        /*
         * ERP-11.3.30 — Dashboard native-finance synchronization.
         *
         * Operational dashboard widgets remain on their existing native data
         * sources. Only accounting-derived values are synchronized from posted
         * journal_entries / journal_lines after the native dashboard renders.
         */
        try {
            $response = app(PresentDashboardFinancialSnapshot::class)->handle(
                $request,
                static fn () => $response
            );
        } catch (\Throwable $e) {
            report($e);
        }

        if (! method_exists($response, 'getContent') || ! method_exists($response, 'setContent')) {
            return $response;
        }

        $contentType = strtolower((string) $response->headers->get('Content-Type', ''));

        if ($contentType !== '' && ! str_contains($contentType, 'text/html')) {
            return $response;
        }

        $html = (string) $response->getContent();

        if ($html === '') {
            return $response;
        }

        $release = config('et_erp_release', []);
        $version = (string) ($release['version'] ?? 'v1.1.33.0-ERP11.3');
        $package = (string) ($release['package'] ?? 'ERP-11.3 Unified Travel ERP');
        $packageDetail = (string) ($release['package_detail'] ?? 'Native one-page Group Package flow + dynamic release health');

        /*
         * Exact legacy labels currently visible in the live ERP shell and
         * System Health page.
         */
        $replacements = [
            'v1.1.28.4-ERP10.28.4' => $version,
            'ERP-10.28.4 Group Package Operational-Only Service Pricing' => $package,
            'ERP-10.28.4 Group Package' => $package,
            'Operational-Only Service Pricing' => $packageDetail,
        ];

        $html = str_replace(
            array_keys($replacements),
            array_values($replacements),
            $html
        );

        $html = $this->injectProfessionalUi($request, $html, $version);

        /*
         * Only calculate DB readiness on the actual System Health page.
         */
        if (
            str_contains($html, 'System Health &amp; Updates')
            || str_contains($html, 'System Health & Updates')
            || str_contains($html, 'Safe web-based application maintenance')
        ) {
            $databaseReady = $this->databaseReady($release);

            if ($databaseReady) {
                $html = str_replace(
                    'Database is current through ERP-10.1.',
                    'Database is current through ERP-11.3.',
                    $html
                );

                $html = str_replace(
                    'Database is current through ERP-10.1',
                    'Database is current through ERP-11.3',
                    $html
                );
            } else {
                $html = str_replace(
                    'Database is current through ERP-10.1.',
                    'Database upgrade required for ERP-11.3 Payments, Receipts & Advance Adjustment core tables.',
                    $html
                );

                $html = str_replace(
                    'Database is current through ERP-10.1',
                    'Database upgrade required for ERP-11.3 Payments, Receipts & Advance Adjustment core tables',
                    $html
                );
            }

            /*
             * ERP-10.31.72
             * Add a safe maintenance entry point without replacing the native
             * System Health controller/view.
             */
            try {
                if (
                    ! str_contains(
                        $html,
                        'data-et-production-reset="ERP-10.31.72"'
                    )
                    && app('router')->has(
                        'system.production-data-reset.index'
                    )
                ) {
                    $resetUrl = route(
                        'system.production-data-reset.index'
                    );

                    $panel = '<section data-et-production-reset="ERP-10.31.72" data-et-dangerous-actions="true">'
                        .'<div class="et-dangerous-kicker">Advanced</div>'
                        .'<div class="et-dangerous-title">Dangerous Actions</div>'
                        .'<p class="et-dangerous-copy">Restricted production reset and financial reconciliation tools. Use only through an approved maintenance procedure.</p>'
                        .'<div class="et-dangerous-actions"><a href="'.e($resetUrl).'">Production Transaction Reset</a>'
                        .'<a href="'.e(route('system.post-reset-financial-cleanup.index')).'">Post-Reset Financial Cleanup</a></div>'
                        .'</section>';

                    if (str_contains($html, '</main>')) {
                        $html = str_replace(
                            '</main>',
                            $panel.'</main>',
                            $html
                        );
                    } else {
                        $html = str_replace(
                            '</body>',
                            $panel.'</body>',
                            $html
                        );
                    }
                }
            } catch (\Throwable) {
            }
        }

        $response->setContent($html);

        return $response;
    }

    private function injectProfessionalUi(Request $request, string $html, string $version): string
    {
        if (str_contains($html, 'data-et-professional-ui=')) {
            return $html;
        }

        $path = strtolower(trim($request->path(), '/'));
        $routeName = strtolower((string) optional($request->route())->getName());
        if (
            str_starts_with($path, 'voucher/')
            || str_contains($path, '/print')
            || str_contains($routeName, '.print')
            || str_contains($routeName, '.pdf')
        ) {
            return $html;
        }

        $module = $this->uiModule($path);
        $marker = e($version);
        $styleUrl = e(route('system.erp-assets.erp-professional-css').'?v='.rawurlencode($version));
        $scriptUrl = e(route('system.erp-assets.erp-professional-js').'?v='.rawurlencode($version));
        $assets = '<link rel="stylesheet" href="'.$styleUrl.'" data-et-professional-ui="'.$marker.'">'
            .'<script src="'.$scriptUrl.'" defer data-et-professional-ui-script="'.$marker.'"></script>';

        $html = preg_replace_callback(
            '/<body\b([^>]*)>/i',
            static function (array $match) use ($module): string {
                $attributes = $match[1];
                $classes = 'et-ui-professional et-ui-module-'.$module;
                if (preg_match('/\bclass\s*=\s*(["\'])(.*?)\1/i', $attributes, $classMatch)) {
                    $replacement = 'class='.$classMatch[1].trim($classMatch[2].' '.$classes).$classMatch[1];
                    $attributes = preg_replace('/\bclass\s*=\s*(["\'])(.*?)\1/i', $replacement, $attributes, 1) ?? $attributes;
                } else {
                    $attributes .= ' class="'.$classes.'"';
                }
                return '<body'.$attributes.' data-et-ui-module="'.$module.'">';
            },
            $html,
            1
        ) ?? $html;

        if (stripos($html, '</head>') !== false) {
            return preg_replace('/<\/head>/i', $assets.'</head>', $html, 1) ?? $html;
        }

        return $assets.$html;
    }

    private function uiModule(string $path): string
    {
        return match (true) {
            $path === '', $path === 'dashboard', str_starts_with($path, 'dashboard/') => 'dashboard',
            str_starts_with($path, 'administration/') => 'administration',
            str_starts_with($path, 'organization/') => 'organization',
            str_starts_with($path, 'master-data/travel'), str_contains($path, 'visa-management') => 'travel',
            str_starts_with($path, 'master-data/') => 'master-data',
            str_starts_with($path, 'operations/'), str_starts_with($path, 'bookings/') => 'operations',
            str_starts_with($path, 'sales/') => 'sales',
            str_starts_with($path, 'purchase/'), str_starts_with($path, 'purchases/') => 'purchase',
            str_starts_with($path, 'accounting/reports') => 'reports',
            str_starts_with($path, 'accounting/') => 'accounting',
            str_starts_with($path, 'system/') => 'system',
            default => 'foundation',
        };
    }

    private function databaseReady(array $release): bool
    {
        try {
            foreach (($release['required_tables'] ?? []) as $table) {
                if (! Schema::hasTable($table)) {
                    return false;
                }
            }

            foreach (($release['required_columns'] ?? []) as $table => $columns) {
                if (! Schema::hasTable($table)) {
                    return false;
                }

                foreach ($columns as $column) {
                    if (! Schema::hasColumn($table, $column)) {
                        return false;
                    }
                }
            }

            return true;
        } catch (\Throwable) {
            /*
             * Do not break the ERP page merely because schema introspection
             * failed. The old health message will be replaced with the safe
             * "upgrade required" label instead.
             */
            return false;
        }
    }
}
