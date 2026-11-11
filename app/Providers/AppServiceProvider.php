<?php

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        View::composer('*', function ($view): void {
            $company = null;
            $branch = null;

            try {
                if (DB::getSchemaBuilder()->hasTable('companies')) {
                    $company = \App\Models\Company::query()->first();
                }
                if (DB::getSchemaBuilder()->hasTable('branches')) {
                    $branch = \App\Models\Branch::query()->first();
                }
            } catch (\Throwable $e) {
                // Keep installer/pre-migration state resilient.
            }

            $view->with('erpCompany', $company);
            $view->with('erpBranch', $branch);
        });
    }
}
