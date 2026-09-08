<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('bookings', 'travel_status')) {
            Schema::table('bookings', function (Blueprint $table): void {
                $table->string('travel_status', 32)->default('PendingTravel')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bookings', 'travel_status')) {
            Schema::table('bookings', function (Blueprint $table): void {
                $table->dropColumn('travel_status');
            });
        }
    }
};
