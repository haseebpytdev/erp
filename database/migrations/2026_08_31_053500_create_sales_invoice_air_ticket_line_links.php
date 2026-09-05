<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_invoice_air_ticket_line_links')) {
            return;
        }

        Schema::create('sales_invoice_air_ticket_line_links', function (Blueprint $table): void {
            $table->bigIncrements('id');

            /*
             * No foreign-key constraints are declared intentionally.
             * The native ERP installation may use different PK integer widths
             * or model namespaces. These are durable snapshot/link IDs only.
             */
            $table->unsignedBigInteger('sales_invoice_id')->index();
            $table->unsignedBigInteger('sales_invoice_line_id')->nullable()->index();
            $table->unsignedBigInteger('booking_id')->index();

            $table->string('source_ticket_table', 128);
            $table->string('source_ticket_id', 100)->nullable();
            $table->string('passenger_id', 100)->nullable();

            $table->string('passenger_name', 255)->nullable();
            $table->string('fare_type', 30)->default('ADULT');
            $table->string('ticket_number', 120)->nullable();
            $table->string('pnr', 100)->nullable();

            $table->decimal('customer_sale', 18, 2)->default(0);
            $table->decimal('supplier_cost', 18, 2)->default(0);

            $table->string('rate_group_key', 191)->index();
            $table->text('source_snapshot_json')->nullable();

            $table->timestamps();

            $table->index(
                ['sales_invoice_id', 'booking_id'],
                'si_air_ticket_invoice_booking_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_invoice_air_ticket_line_links');
    }
};
