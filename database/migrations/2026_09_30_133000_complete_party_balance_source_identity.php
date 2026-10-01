<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('party_opening_balances')) Schema::table('party_opening_balances', function (Blueprint $table): void { if (! Schema::hasColumn('party_opening_balances','reversed_by')) $table->unsignedBigInteger('reversed_by')->nullable(); });
        if (Schema::hasTable('advance_adjustments')) {
            Schema::table('advance_adjustments', function (Blueprint $table): void {
                if (! Schema::hasColumn('advance_adjustments', 'advance_source_type')) $table->string('advance_source_type', 40)->nullable()->index();
                if (! Schema::hasColumn('advance_adjustments', 'advance_source_id')) $table->unsignedBigInteger('advance_source_id')->nullable()->index();
                if (Schema::hasColumn('advance_adjustments', 'advance_voucher_id')) $table->unsignedBigInteger('advance_voucher_id')->nullable()->change();
            });
        }
        if (Schema::hasTable('cash_voucher_allocations') && ! Schema::hasColumn('cash_voucher_allocations', 'target_type')) {
            Schema::table('cash_voucher_allocations', fn (Blueprint $table) => $table->string('target_type', 40)->index());
        }
    }

    public function down(): void
    {
        // Non-destructive: lifecycle source columns and nullable compatibility remain on rollback.
    }
};
