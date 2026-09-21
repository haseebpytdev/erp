<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('booking_itinerary_segments') || Schema::hasColumn('booking_itinerary_segments', 'booking_service_id')) {
            return;
        }

        Schema::table('booking_itinerary_segments', function (Blueprint $table): void {
            $table->unsignedBigInteger('booking_service_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        // Intentionally non-destructive: the host schema may already have
        // booking_service_id when this unshipped migration is rolled back.
        // Schema state alone cannot prove ownership, so never drop a column
        // or index that may pre-date this migration.
    }
};
