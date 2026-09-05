<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('booking_services') && ! Schema::hasColumn('booking_services', 'vendor_id')) {
            Schema::table('booking_services', function (Blueprint $table): void {
                // Air Vendor belongs to the booking service (one PNR/service),
                // not to the whole booking and not to an accounting document.
                $table->unsignedBigInteger('vendor_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('booking_services') && Schema::hasColumn('booking_services', 'vendor_id')) {
            Schema::table('booking_services', function (Blueprint $table): void {
                $table->dropColumn('vendor_id');
            });
        }
    }
};
