<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cash_vouchers')) {
            return;
        }

        Schema::table('cash_vouchers', function (Blueprint $table): void {
            if (! Schema::hasColumn('cash_vouchers', 'company_id')) {
                $table->unsignedBigInteger('company_id')->nullable()->after('booking_id');
            }
            if (! Schema::hasColumn('cash_vouchers', 'branch_id')) {
                $table->unsignedBigInteger('branch_id')->nullable()->after('company_id');
            }
        });

        Schema::table('cash_vouchers', function (Blueprint $table): void {
            $table->index(['company_id', 'branch_id'], 'cash_vouchers_company_branch_idx');
            $table->index(['status', 'branch_id'], 'cash_vouchers_status_branch_idx');
            $table->index(['voucher_type', 'branch_id'], 'cash_vouchers_type_branch_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cash_vouchers')) {
            return;
        }

        Schema::table('cash_vouchers', function (Blueprint $table): void {
            $table->dropIndex('cash_vouchers_type_branch_idx');
            $table->dropIndex('cash_vouchers_status_branch_idx');
            $table->dropIndex('cash_vouchers_company_branch_idx');
            if (Schema::hasColumn('cash_vouchers', 'branch_id')) {
                $table->dropColumn('branch_id');
            }
            if (Schema::hasColumn('cash_vouchers', 'company_id')) {
                $table->dropColumn('company_id');
            }
        });
    }
};
