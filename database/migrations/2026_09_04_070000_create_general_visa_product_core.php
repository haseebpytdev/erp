<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('visa_pakistani_iatas')) {
            Schema::create('visa_pakistani_iatas', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('code', 60)->nullable();
                $table->string('name', 180);
                $table->unsignedBigInteger('vendor_id')->nullable()->index();
                $table->boolean('is_active')->default(true)->index();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['name']);
            });
        }

        if (! Schema::hasTable('visa_saudi_companies')) {
            Schema::create('visa_saudi_companies', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('code', 60)->nullable();
                $table->string('name', 180);
                $table->unsignedBigInteger('pakistani_iata_id')->index();
                $table->boolean('is_active')->default(true)->index();
                $table->string('contact_number', 100)->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['name']);
                $table->index(['pakistani_iata_id', 'is_active']);
            });
        }

        if (! Schema::hasTable('visa_rate_cards')) {
            Schema::create('visa_rate_cards', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('country', 120)->default('Saudi Arabia')->index();
                $table->string('visa_type', 120)->index();
                $table->unsignedBigInteger('saudi_company_id')->index();
                $table->unsignedBigInteger('pakistani_iata_id')->index();
                $table->unsignedBigInteger('vendor_id')->nullable()->index();
                $table->string('cost_currency', 12)->default('SAR');
                $table->decimal('cost_rate', 18, 4)->default(0);
                $table->decimal('default_sale_pkr', 18, 2)->default(0);
                $table->date('effective_from')->index();
                $table->date('effective_to')->nullable()->index();
                $table->boolean('is_active')->default(true)->index();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->index(['country', 'visa_type', 'saudi_company_id', 'is_active'], 'visa_rate_lookup_idx');
            });
        }

        if (! Schema::hasTable('booking_visa_services')) {
            Schema::create('booking_visa_services', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('booking_id')->index();
                $table->unsignedBigInteger('booking_passenger_id')->index();
                $table->unsignedBigInteger('visa_rate_card_id')->nullable()->index();
                $table->string('country', 120)->default('Saudi Arabia');
                $table->string('visa_type', 120)->default('Umrah');
                $table->unsignedBigInteger('saudi_company_id')->nullable()->index();
                $table->unsignedBigInteger('pakistani_iata_id')->nullable()->index();
                $table->unsignedBigInteger('vendor_id')->nullable()->index();
                $table->string('application_reference', 180)->nullable();
                $table->string('visa_number', 180)->nullable();
                $table->string('status', 40)->default('pending')->index();
                $table->date('issue_date')->nullable();
                $table->date('expiry_date')->nullable();
                $table->decimal('sale_pkr', 18, 2)->default(0);
                $table->string('cost_currency', 12)->default('SAR');
                $table->decimal('cost_rate', 18, 4)->default(0);
                $table->decimal('exchange_rate', 18, 8)->default(0);
                $table->decimal('vendor_cost_pkr', 18, 2)->default(0);
                $table->decimal('margin_pkr', 18, 2)->default(0);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['booking_id', 'booking_passenger_id'], 'booking_visa_one_per_passenger');
                $table->index(['booking_id', 'status']);
                $table->index(['saudi_company_id', 'pakistani_iata_id'], 'booking_visa_reporting_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_visa_services');
        Schema::dropIfExists('visa_rate_cards');
        Schema::dropIfExists('visa_saudi_companies');
        Schema::dropIfExists('visa_pakistani_iatas');
    }
};
