<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('booking_group_package_unified')) {
            Schema::create('booking_group_package_unified', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('booking_id')->unique();
                $table->unsignedBigInteger('package_id')->nullable()->index();
                $table->decimal('package_sale_price', 18, 2)->default(0);
                $table->string('discount_type', 24)->default('none');
                $table->decimal('discount_value', 18, 2)->default(0);
                $table->decimal('final_sale_price', 18, 2)->default(0);
                $table->decimal('supplier_cost', 18, 2)->default(0);
                $table->decimal('agent_commission', 18, 2)->default(0);
                $table->decimal('salesperson_commission', 18, 2)->default(0);
                $table->decimal('net_margin', 18, 2)->default(0);
                $table->string('currency_code', 8)->default('PKR');
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['package_id', 'currency_code'], 'bgpu_pkg_currency_idx');
            });
        }

        if (! Schema::hasTable('booking_group_package_passengers')) {
            Schema::create('booking_group_package_passengers', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('booking_id')->index();
                $table->unsignedBigInteger('passenger_id')->nullable()->index();
                $table->string('title', 20)->nullable();
                $table->string('first_name', 120);
                $table->string('last_name', 120)->nullable();
                $table->date('date_of_birth')->nullable();
                $table->string('passport_no', 80)->nullable();
                $table->date('passport_expiry')->nullable();
                $table->string('nationality', 100)->nullable();
                $table->string('fare_as', 20)->nullable();
                $table->string('ticket_number', 100)->nullable();
                $table->unsignedInteger('sort_order')->default(10);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('booking_group_package_flights')) {
            Schema::create('booking_group_package_flights', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('booking_id')->index();
                $table->string('segment_type', 24)->default('outbound');
                $table->string('from_code', 50);
                $table->string('to_code', 50);
                $table->string('airline_name', 120)->nullable();
                $table->string('flight_number', 40)->nullable();
                $table->date('departure_date')->nullable();
                $table->time('departure_time')->nullable();
                $table->date('arrival_date')->nullable();
                $table->time('arrival_time')->nullable();
                $table->string('cabin_class', 50)->nullable();
                $table->string('baggage', 80)->nullable();
                $table->string('pnr', 80)->nullable();
                $table->string('status', 24)->default('booked');
                $table->unsignedInteger('sort_order')->default(10);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('booking_group_package_hotels')) {
            Schema::create('booking_group_package_hotels', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('booking_id')->index();
                $table->unsignedBigInteger('hotel_id')->nullable()->index();
                $table->string('city', 120)->nullable();
                $table->string('hotel_name', 180);
                $table->date('check_in')->nullable();
                $table->date('check_out')->nullable();
                $table->string('room_type', 80)->nullable();
                $table->string('meal_plan', 80)->nullable();
                $table->unsignedInteger('rooms')->default(1);
                $table->string('confirmation_no', 120)->nullable();
                $table->string('status', 24)->default('requested');
                $table->text('notes')->nullable();
                $table->unsignedInteger('sort_order')->default(10);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('booking_group_package_transports')) {
            Schema::create('booking_group_package_transports', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('booking_id')->index();
                $table->string('from_location', 180);
                $table->string('to_location', 180);
                $table->string('vehicle_type', 100)->nullable();
                $table->date('pickup_date')->nullable();
                $table->time('pickup_time')->nullable();
                $table->string('provider_reference', 120)->nullable();
                $table->string('status', 24)->default('requested');
                $table->text('notes')->nullable();
                $table->unsignedInteger('sort_order')->default(10);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('booking_group_package_services')) {
            Schema::create('booking_group_package_services', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('booking_id')->index();
                $table->string('service_name', 180);
                $table->string('details', 255)->nullable();
                $table->unsignedInteger('quantity')->default(1);
                $table->string('status', 24)->default('included');
                $table->text('notes')->nullable();
                $table->unsignedInteger('sort_order')->default(10);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_group_package_services');
        Schema::dropIfExists('booking_group_package_transports');
        Schema::dropIfExists('booking_group_package_hotels');
        Schema::dropIfExists('booking_group_package_flights');
        Schema::dropIfExists('booking_group_package_passengers');
        Schema::dropIfExists('booking_group_package_unified');
    }
};
