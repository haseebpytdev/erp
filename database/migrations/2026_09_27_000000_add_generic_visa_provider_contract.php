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
            $bookingColumns = Schema::getColumnListing('booking_visa_services');
            $rateColumns = Schema::hasTable('visa_rate_cards') ? Schema::getColumnListing('visa_rate_cards') : [];
            if (in_array('visa_rate_card_id', $bookingColumns, true) && in_array('provider_type', $bookingColumns, true) && in_array('id', $rateColumns, true) && in_array('provider_type', $rateColumns, true)) {
                // Preserve the linked rate's explicit provider contract first;
                // legacy rows without a usable rate remain the safe KSA mode.
                try {
                    DB::statement("UPDATE booking_visa_services b INNER JOIN visa_rate_cards r ON r.id = b.visa_rate_card_id SET b.provider_type = COALESCE(NULLIF(r.provider_type, ''), 'KSA_CHAIN') WHERE b.provider_type IS NULL OR b.provider_type = ''");
                } catch (Throwable) {
                    DB::table('booking_visa_services')->whereNull('provider_type')->orderBy('id')->chunkById(250, function ($rows): void {
                        foreach ($rows as $row) {
                            $provider = DB::table('visa_rate_cards')->where('id', (int) ($row->visa_rate_card_id ?? 0))->value('provider_type');
                            DB::table('booking_visa_services')->where('id', (int) $row->id)->update(['provider_type' => $provider ?: 'KSA_CHAIN']);
                        }
                    });
                }
            }
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
