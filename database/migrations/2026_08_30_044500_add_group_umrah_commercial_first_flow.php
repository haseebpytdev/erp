<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('booking_group_package_unified')) {
            return;
        }

        Schema::table('booking_group_package_unified', function (Blueprint $table): void {
            if (! Schema::hasColumn('booking_group_package_unified', 'booked_pax')) {
                $table->unsignedInteger('booked_pax')->default(1);
            }
            if (! Schema::hasColumn('booking_group_package_unified', 'commercial_saved_at')) {
                $table->timestamp('commercial_saved_at')->nullable();
            }
        });

        try {
            $bookingIds = DB::table('booking_group_package_unified')->pluck('booking_id');
            foreach ($bookingIds as $bookingId) {
                $assigned = Schema::hasTable('booking_group_package_passengers')
                    ? (int) DB::table('booking_group_package_passengers')->where('booking_id', $bookingId)->count()
                    : 0;

                DB::table('booking_group_package_unified')
                    ->where('booking_id', $bookingId)
                    ->update(['booked_pax' => max(1, $assigned)]);
            }
        } catch (\Throwable) {
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('booking_group_package_unified')) {
            return;
        }

        $drop = [];
        foreach (['booked_pax', 'commercial_saved_at'] as $column) {
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
};
