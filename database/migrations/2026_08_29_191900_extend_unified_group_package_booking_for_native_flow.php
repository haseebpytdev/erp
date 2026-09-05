<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('booking_group_package_unified')) {
            Schema::table('booking_group_package_unified', function (Blueprint $table): void {
                if (! Schema::hasColumn('booking_group_package_unified', 'package_name')) {
                    $table->string('package_name', 180)->nullable()->after('package_id');
                }
                if (! Schema::hasColumn('booking_group_package_unified', 'package_code')) {
                    $table->string('package_code', 80)->nullable()->index()->after('package_name');
                }
                if (! Schema::hasColumn('booking_group_package_unified', 'vendor_id')) {
                    $table->unsignedBigInteger('vendor_id')->nullable()->index()->after('package_code');
                }
                if (! Schema::hasColumn('booking_group_package_unified', 'vendor_package_code')) {
                    $table->string('vendor_package_code', 120)->nullable()->after('vendor_id');
                }
                if (! Schema::hasColumn('booking_group_package_unified', 'vendor_voucher_no')) {
                    $table->string('vendor_voucher_no', 120)->nullable()->after('vendor_package_code');
                }
                if (! Schema::hasColumn('booking_group_package_unified', 'save_status')) {
                    $table->string('save_status', 24)->default('draft')->after('vendor_voucher_no');
                }
            });
        }

        if (Schema::hasTable('booking_group_package_passengers')) {
            Schema::table('booking_group_package_passengers', function (Blueprint $table): void {
                if (! Schema::hasColumn('booking_group_package_passengers', 'passenger_source')) {
                    $table->string('passenger_source', 80)->nullable()->after('passenger_id');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('booking_group_package_passengers') && Schema::hasColumn('booking_group_package_passengers', 'passenger_source')) {
            Schema::table('booking_group_package_passengers', function (Blueprint $table): void {
                $table->dropColumn('passenger_source');
            });
        }

        if (Schema::hasTable('booking_group_package_unified')) {
            $drop = [];
            foreach (['package_name', 'package_code', 'vendor_id', 'vendor_package_code', 'vendor_voucher_no', 'save_status'] as $column) {
                if (Schema::hasColumn('booking_group_package_unified', $column)) {
                    $drop[] = $column;
                }
            }
            if ($drop) {
                Schema::table('booking_group_package_unified', function (Blueprint $table) use ($drop): void {
                    $table->dropColumn($drop);
                });
            }
        }
    }
};
