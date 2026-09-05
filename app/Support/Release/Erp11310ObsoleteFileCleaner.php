<?php

namespace App\Support\Release;

use Illuminate\Support\Facades\File;

/**
 * ERP-11.3.10
 *
 * One-time cleanup of files created only by superseded Chart-of-Accounts
 * overlay attempts. This deliberately does NOT delete the native accounting
 * controller/edit route or any database objects.
 */
final class Erp11310ObsoleteFileCleaner
{
    public function run(): void
    {
        try {
            $canonical = resource_path('views/accounting/chart-of-accounts/workspace.blade.php');

            // Never clean until the replacement is physically deployed.
            if (! is_file($canonical)) {
                return;
            }

            $marker = storage_path('app/erp11310-obsolete-chart-cleanup.done');
            if (is_file($marker)) {
                return;
            }

            $obsolete = [
                resource_path('views/accounting/chart-of-accounts/index-v11332.blade.php'),
                resource_path('views/accounting/chart-of-accounts/index-v11334.blade.php'),
                resource_path('views/accounting/chart-of-accounts/index-v11335.blade.php'),
                resource_path('views/accounting/chart-of-accounts/index-v11336.blade.php'),
                resource_path('views/accounting/chart-of-accounts/index-v11337.blade.php'),
            ];

            foreach ($obsolete as $path) {
                if (is_file($path)) {
                    File::delete($path);
                }
            }

            File::ensureDirectoryExists(dirname($marker));
            File::put(
                $marker,
                "ERP-11.3.10 obsolete Chart workspace files cleaned at ".now()->toDateTimeString().PHP_EOL
            );
        } catch (\Throwable $e) {
            // Cleanup must never take the ERP offline.
            report($e);
        }
    }
}
