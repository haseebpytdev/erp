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

                    $panel = '<div data-et-production-reset="ERP-10.31.72" style="margin:16px 0;padding:14px;border:1px solid #dbe5f0;border-radius:10px;background:#fff;">'
                        .'<div style="font-weight:800;color:#17243a;">Production Readiness</div>'
                        .'<div style="margin-top:4px;color:#6b7a90;font-size:13px;">One-time Super Admin tool to clear UAT/test transactions before staff enter live data.</div>'
                        .'<div style="margin-top:10px;"><a href="'.e($resetUrl).'" style="display:inline-block;padding:8px 12px;border-radius:6px;background:#b4232f;color:#fff;text-decoration:none;font-weight:800;font-size:12px;">Production Transaction Reset</a></div>'
                        .'<div style="margin-top:8px;"><a href="'.e(route('system.post-reset-financial-cleanup.index')).'" style="display:inline-block;padding:8px 12px;border-radius:6px;background:#1769d2;color:#fff;text-decoration:none;font-weight:800;font-size:12px;">Post-Reset Financial Cleanup</a></div>'
                        .'</div>';

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
