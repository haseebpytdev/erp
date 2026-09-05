<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('visa_rate_cards')) {
            Schema::table('visa_rate_cards', function (Blueprint $table): void {
                if (! Schema::hasColumn('visa_rate_cards', 'saudi_master_table')) {
                    $table->string('saudi_master_table', 100)->nullable()->after('saudi_company_id');
                }
                if (! Schema::hasColumn('visa_rate_cards', 'saudi_master_id')) {
                    $table->unsignedBigInteger('saudi_master_id')->nullable()->after('saudi_master_table');
                }
                if (! Schema::hasColumn('visa_rate_cards', 'pakistani_iata_master_table')) {
                    $table->string('pakistani_iata_master_table', 100)->nullable()->after('pakistani_iata_id');
                }
                if (! Schema::hasColumn('visa_rate_cards', 'pakistani_iata_master_id')) {
                    $table->unsignedBigInteger('pakistani_iata_master_id')->nullable()->after('pakistani_iata_master_table');
                }
                if (! Schema::hasColumn('visa_rate_cards', 'saudi_company_name_snapshot')) {
                    $table->string('saudi_company_name_snapshot', 180)->nullable()->after('vendor_id');
                }
                if (! Schema::hasColumn('visa_rate_cards', 'pakistani_iata_name_snapshot')) {
                    $table->string('pakistani_iata_name_snapshot', 180)->nullable()->after('saudi_company_name_snapshot');
                }
            });
        }

        if (Schema::hasTable('booking_visa_services')) {
            Schema::table('booking_visa_services', function (Blueprint $table): void {
                if (! Schema::hasColumn('booking_visa_services', 'saudi_master_table')) {
                    $table->string('saudi_master_table', 100)->nullable()->after('saudi_company_id');
                }
                if (! Schema::hasColumn('booking_visa_services', 'saudi_master_id')) {
                    $table->unsignedBigInteger('saudi_master_id')->nullable()->after('saudi_master_table');
                }
                if (! Schema::hasColumn('booking_visa_services', 'pakistani_iata_master_table')) {
                    $table->string('pakistani_iata_master_table', 100)->nullable()->after('pakistani_iata_id');
                }
                if (! Schema::hasColumn('booking_visa_services', 'pakistani_iata_master_id')) {
                    $table->unsignedBigInteger('pakistani_iata_master_id')->nullable()->after('pakistani_iata_master_table');
                }
                if (! Schema::hasColumn('booking_visa_services', 'saudi_company_name_snapshot')) {
                    $table->string('saudi_company_name_snapshot', 180)->nullable()->after('vendor_id');
                }
                if (! Schema::hasColumn('booking_visa_services', 'pakistani_iata_name_snapshot')) {
                    $table->string('pakistani_iata_name_snapshot', 180)->nullable()->after('saudi_company_name_snapshot');
                }
            });
        }

        // ERP-11.3.142 briefly introduced parallel Visa master tables. ERP-11.3.147
        // no longer uses them. Remove them only when they are truly empty, so an
        // installation that accidentally entered data is never destroyed.
        $legacyRateCount = Schema::hasTable('visa_rate_cards') ? (int) DB::table('visa_rate_cards')->count() : 0;
        $legacyBookingCount = Schema::hasTable('booking_visa_services') ? (int) DB::table('booking_visa_services')->count() : 0;
        $parallelIataEmpty = ! Schema::hasTable('visa_pakistani_iatas') || (int) DB::table('visa_pakistani_iatas')->count() === 0;
        $parallelSaudiEmpty = ! Schema::hasTable('visa_saudi_companies') || (int) DB::table('visa_saudi_companies')->count() === 0;
        if ($legacyRateCount === 0 && $legacyBookingCount === 0 && $parallelIataEmpty && $parallelSaudiEmpty) {
            Schema::dropIfExists('visa_saudi_companies');
            Schema::dropIfExists('visa_pakistani_iatas');
        }

    }

    public function down(): void
    {
        // Additive compatibility migration only. Intentionally no destructive rollback.
    }
};
