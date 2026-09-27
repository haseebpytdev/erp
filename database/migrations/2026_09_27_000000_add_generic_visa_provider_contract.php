<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['visa_rate_cards', 'booking_visa_services'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                if (! Schema::hasColumn($table, 'provider_type')) {
                    $blueprint->string('provider_type', 24)->default('KSA_CHAIN');
                }
                if (! Schema::hasColumn($table, 'vendor_name_snapshot')) {
                    $blueprint->string('vendor_name_snapshot', 180)->nullable();
                }
            });
        }

        if (Schema::hasTable('visa_rate_cards')) {
            foreach (['saudi_company_id', 'pakistani_iata_id', 'saudi_master_table', 'saudi_master_id', 'pakistani_iata_master_table', 'pakistani_iata_master_id'] as $column) {
                if (Schema::hasColumn('visa_rate_cards', $column)) {
                    try {
                        DB::statement("ALTER TABLE visa_rate_cards MODIFY {$column} " . ($column === 'saudi_master_table' || $column === 'pakistani_iata_master_table' ? 'VARCHAR(255)' : ($column === 'saudi_master_id' || $column === 'pakistani_iata_master_id' ? 'BIGINT UNSIGNED' : 'BIGINT UNSIGNED')) . ' NULL');
                    } catch (Throwable) {
                        // Safe Database Upgrade may run on a driver that does not
                        // support MODIFY; the additive columns remain usable.
                    }
                }
            }
            DB::table('visa_rate_cards')->whereNull('provider_type')->update(['provider_type' => 'KSA_CHAIN']);
        }
        if (Schema::hasTable('booking_visa_services')) {
            DB::table('booking_visa_services')->whereNull('provider_type')->update(['provider_type' => 'KSA_CHAIN']);
        }
    }

    public function down(): void
    {
        foreach (['visa_rate_cards', 'booking_visa_services'] as $table) {
            if (! Schema::hasTable($table)) continue;
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                if (Schema::hasColumn($table, 'vendor_name_snapshot')) $blueprint->dropColumn('vendor_name_snapshot');
                if (Schema::hasColumn($table, 'provider_type')) $blueprint->dropColumn('provider_type');
            });
        }
    }
};
