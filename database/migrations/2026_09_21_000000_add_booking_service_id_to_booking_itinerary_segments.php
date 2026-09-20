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
        if (Schema::hasTable('booking_itinerary_segments') && Schema::hasColumn('booking_itinerary_segments', 'booking_service_id')) {
            Schema::table('booking_itinerary_segments', function (Blueprint $table): void {
                $table->dropIndex(['booking_service_id']);
                $table->dropColumn('booking_service_id');
            });
        }
    }
};
