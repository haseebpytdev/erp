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
            if (! Schema::hasColumn('booking_group_package_unified', 'editing_locked_at')) {
                $table->timestamp('editing_locked_at')->nullable();
            }
            if (! Schema::hasColumn('booking_group_package_unified', 'editing_locked_by')) {
                $table->unsignedBigInteger('editing_locked_by')->nullable();
            }
            if (! Schema::hasColumn('booking_group_package_unified', 'editing_lock_reason')) {
                $table->string('editing_lock_reason', 80)->nullable();
            }
            if (! Schema::hasColumn('booking_group_package_unified', 'editing_reopened_at')) {
                $table->timestamp('editing_reopened_at')->nullable();
            }
            if (! Schema::hasColumn('booking_group_package_unified', 'editing_reopened_by')) {
                $table->unsignedBigInteger('editing_reopened_by')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('booking_group_package_unified')) {
            return;
        }

        $columns = [
            'editing_locked_at',
            'editing_locked_by',
            'editing_lock_reason',
            'editing_reopened_at',
            'editing_reopened_by',
        ];

        $drop = array_values(array_filter(
            $columns,
            fn (string $column): bool => Schema::hasColumn('booking_group_package_unified', $column)
        ));

        if ($drop) {
            Schema::table('booking_group_package_unified', function (Blueprint $table) use ($drop): void {
                $table->dropColumn($drop);
            });
        }
    }
};
