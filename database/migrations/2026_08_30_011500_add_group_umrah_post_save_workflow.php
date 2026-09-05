<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('booking_group_package_unified')) {
            return;
        }

        Schema::table('booking_group_package_unified', function (Blueprint $table): void {
            if (! Schema::hasColumn('booking_group_package_unified', 'booking_workflow_status')) {
                $table->string('booking_workflow_status', 32)->default('draft');
            }
            if (! Schema::hasColumn('booking_group_package_unified', 'voucher_status')) {
                $table->string('voucher_status', 32)->default('not_prepared');
            }
            if (! Schema::hasColumn('booking_group_package_unified', 'accounting_status')) {
                $table->string('accounting_status', 32)->default('pending');
            }
            if (! Schema::hasColumn('booking_group_package_unified', 'payment_status')) {
                $table->string('payment_status', 32)->default('pending');
            }
            if (! Schema::hasColumn('booking_group_package_unified', 'operational_hash')) {
                $table->string('operational_hash', 64)->nullable();
            }
            if (! Schema::hasColumn('booking_group_package_unified', 'voucher_approved_hash')) {
                $table->string('voucher_approved_hash', 64)->nullable();
            }
            if (! Schema::hasColumn('booking_group_package_unified', 'confirmed_at')) {
                $table->timestamp('confirmed_at')->nullable();
            }
            if (! Schema::hasColumn('booking_group_package_unified', 'confirmed_by')) {
                $table->unsignedBigInteger('confirmed_by')->nullable();
            }
            if (! Schema::hasColumn('booking_group_package_unified', 'voucher_submitted_at')) {
                $table->timestamp('voucher_submitted_at')->nullable();
            }
            if (! Schema::hasColumn('booking_group_package_unified', 'voucher_submitted_by')) {
                $table->unsignedBigInteger('voucher_submitted_by')->nullable();
            }
            if (! Schema::hasColumn('booking_group_package_unified', 'voucher_approved_at')) {
                $table->timestamp('voucher_approved_at')->nullable();
            }
            if (! Schema::hasColumn('booking_group_package_unified', 'voucher_approved_by')) {
                $table->unsignedBigInteger('voucher_approved_by')->nullable();
            }
            if (! Schema::hasColumn('booking_group_package_unified', 'voucher_issued_at')) {
                $table->timestamp('voucher_issued_at')->nullable();
            }
            if (! Schema::hasColumn('booking_group_package_unified', 'voucher_issued_by')) {
                $table->unsignedBigInteger('voucher_issued_by')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('booking_group_package_unified')) {
            return;
        }

        $columns = [
            'booking_workflow_status', 'voucher_status', 'accounting_status', 'payment_status',
            'operational_hash', 'voucher_approved_hash', 'confirmed_at', 'confirmed_by',
            'voucher_submitted_at', 'voucher_submitted_by', 'voucher_approved_at',
            'voucher_approved_by', 'voucher_issued_at', 'voucher_issued_by',
        ];

        $drop = array_values(array_filter($columns, fn (string $column): bool =>
            Schema::hasColumn('booking_group_package_unified', $column)
        ));

        if ($drop) {
            Schema::table('booking_group_package_unified', function (Blueprint $table) use ($drop): void {
                $table->dropColumn($drop);
            });
        }
    }
};
