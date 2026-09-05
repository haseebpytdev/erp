<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('booking_group_package_commercial_amendments')) return;

        Schema::create('booking_group_package_commercial_amendments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('booking_id')->index();
            $table->unsignedInteger('amendment_no');
            $table->string('amendment_type', 32)->default('add_pax');
            $table->unsignedInteger('previous_booked_pax');
            $table->unsignedInteger('additional_pax');
            $table->unsignedInteger('resulting_booked_pax');
            $table->decimal('additional_sale_price', 18, 2)->default(0);
            $table->string('discount_type', 24)->default('none');
            $table->decimal('discount_value', 18, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('additional_final_sale', 18, 2)->default(0);
            $table->decimal('additional_supplier_cost', 18, 2)->default(0);
            $table->decimal('additional_agent_commission', 18, 2)->default(0);
            $table->decimal('additional_salesperson_commission', 18, 2)->default(0);
            $table->decimal('additional_net_margin', 18, 2)->default(0);
            $table->string('vendor_reference', 120)->nullable();
            $table->text('notes')->nullable();
            $table->string('accounting_action', 48)->default('revise_existing_invoice');
            $table->string('accounting_status', 32)->default('pending');
            $table->unsignedInteger('invoice_count_before')->default(0);
            $table->decimal('invoice_total_before', 18, 2)->nullable();
            $table->unsignedBigInteger('invoice_id_before')->nullable();
            $table->string('invoice_number_before', 120)->nullable();
            $table->string('invoice_status_before', 48)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('accounted_at')->nullable();
            $table->timestamps();

            $table->unique(['booking_id', 'amendment_no'], 'bgpca_booking_amendment_unique');
            $table->index(['booking_id', 'accounting_status'], 'bgpca_booking_accounting_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_group_package_commercial_amendments');
    }
};
