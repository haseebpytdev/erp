<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('booking_group_package_transports')) {
            return;
        }

        Schema::table('booking_group_package_transports', function (Blueprint $table): void {
            if (! Schema::hasColumn('booking_group_package_transports', 'route_master_id')) {
                $table->unsignedBigInteger('route_master_id')->nullable();
            }
            if (! Schema::hasColumn('booking_group_package_transports', 'route_source_table')) {
                $table->string('route_source_table', 120)->nullable();
            }
            if (! Schema::hasColumn('booking_group_package_transports', 'vehicle_master_id')) {
                $table->unsignedBigInteger('vehicle_master_id')->nullable();
            }
            if (! Schema::hasColumn('booking_group_package_transports', 'vehicle_source_table')) {
                $table->string('vehicle_source_table', 120)->nullable();
            }
        });
    }

    public function down(): void
    {
        // Production-safe cumulative ERP migrations remain non-destructive.
    }
};
