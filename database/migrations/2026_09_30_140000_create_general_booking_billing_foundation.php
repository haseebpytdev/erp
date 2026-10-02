<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('general_booking_billing_batches')) {
            Schema::create('general_booking_billing_batches', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('booking_id');
                $table->unsignedInteger('batch_no');
                $table->string('batch_type', 24);
                $table->string('status', 32)->default('draft');
                $table->char('currency_code', 3)->default('PKR');
                $table->decimal('exchange_rate', 18, 8)->default(1);
                $table->char('source_snapshot_hash', 64)->nullable();
                $table->decimal('customer_subtotal', 18, 2)->default(0);
                $table->decimal('discount_total', 18, 2)->default(0);
                $table->decimal('customer_total', 18, 2)->default(0);
                $table->decimal('supplier_cost_total', 18, 2)->default(0);
                $table->decimal('agent_commission_total', 18, 2)->default(0);
                $table->decimal('salesperson_commission_total', 18, 2)->default(0);
                $table->decimal('margin_total', 18, 2)->default(0);
                $table->unsignedInteger('lock_version')->default(0);
                foreach (['created_by', 'updated_by', 'submitted_by', 'approved_by', 'rejected_by', 'cancelled_by'] as $column) {
                    $table->unsignedBigInteger($column)->nullable();
                }
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('rejected_at')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->text('cancellation_reason')->nullable();
                $table->timestamp('invoice_created_at')->nullable();
                $table->timestamps();
                $table->unique(['booking_id', 'batch_no'], 'gbbb_booking_batch_unique');
                $table->index(['booking_id', 'status'], 'gbbb_booking_status_idx');
                $table->index(['booking_id', 'batch_type', 'status'], 'gbbb_booking_type_status_idx');
            });
        }

        if (! Schema::hasTable('general_booking_billing_batch_items')) {
            Schema::create('general_booking_billing_batch_items', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('batch_id');
                $table->unsignedBigInteger('booking_id');
                $table->unsignedInteger('line_no');
                $table->string('product_type', 40);
                $table->string('source_key', 191);
                $table->string('source_table', 96)->nullable();
                $table->unsignedBigInteger('source_id')->nullable();
                $table->unsignedBigInteger('booking_service_id')->nullable();
                $table->unsignedBigInteger('booking_passenger_id')->nullable();
                $table->unsignedBigInteger('product_service_id')->nullable();
                $table->string('description_snapshot', 500)->nullable();
                $table->decimal('quantity', 14, 3)->default(1);
                $table->decimal('unit_price', 18, 2)->default(0);
                $table->decimal('sale_amount', 18, 2)->default(0);
                $table->decimal('supplier_cost_snapshot', 18, 2)->default(0);
                $table->decimal('margin_snapshot', 18, 2)->default(0);
                $table->char('currency_code', 3)->default('PKR');
                $table->decimal('exchange_rate', 18, 8)->default(1);
                $table->string('revenue_mapping_key_snapshot', 80)->nullable();
                $table->json('product_snapshot')->nullable();
                $table->char('source_hash', 64);
                $table->timestamps();
                $table->unique(['batch_id', 'line_no'], 'gbbbi_batch_line_unique');
                $table->unique(['batch_id', 'source_key'], 'gbbbi_batch_source_unique');
                $table->index(['booking_id', 'product_type'], 'gbbbi_booking_product_idx');
                $table->index(['source_table', 'source_id'], 'gbbbi_source_idx');
                $table->index('booking_service_id', 'gbbbi_service_idx');
            });
        }

        if (! Schema::hasTable('general_booking_invoice_links')) {
            Schema::create('general_booking_invoice_links', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('booking_id');
                $table->unsignedBigInteger('batch_id');
                $table->string('link_type', 24);
                $table->unsignedInteger('invoice_sequence');
                $table->unsignedBigInteger('sales_invoice_id');
                $table->string('invoice_no_snapshot', 60)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->unique('batch_id', 'gbil_batch_unique');
                $table->unique('sales_invoice_id', 'gbil_invoice_unique');
                $table->unique(['booking_id', 'invoice_sequence'], 'gbil_booking_sequence_unique');
                $table->index(['booking_id', 'link_type'], 'gbil_booking_type_idx');
            });
        }

        $this->addForeignKeyIfCompatible('general_booking_billing_batch_items', 'batch_id', 'general_booking_billing_batches', 'id', 'gbbbi_batch_fk');
        $this->addForeignKeyIfCompatible('general_booking_invoice_links', 'batch_id', 'general_booking_billing_batches', 'id', 'gbil_batch_fk');
        $this->addForeignKeyIfCompatible('general_booking_invoice_links', 'sales_invoice_id', 'sales_invoices', 'id', 'gbil_invoice_fk');
        $this->addForeignKeyIfCompatible('general_booking_billing_batches', 'booking_id', 'bookings', 'id', 'gbbb_booking_fk');
        $this->addForeignKeyIfCompatible('general_booking_billing_batch_items', 'booking_id', 'bookings', 'id', 'gbbbi_booking_fk');
        $this->addForeignKeyIfCompatible('general_booking_invoice_links', 'booking_id', 'bookings', 'id', 'gbil_booking_fk');
    }

    public function down(): void
    {
        Schema::dropIfExists('general_booking_invoice_links');
        Schema::dropIfExists('general_booking_billing_batch_items');
        Schema::dropIfExists('general_booking_billing_batches');
    }

    private function addForeignKeyIfCompatible(string $table, string $column, string $foreignTable, string $foreignColumn, string $name): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column) || ! Schema::hasTable($foreignTable) || ! Schema::hasColumn($foreignTable, $foreignColumn)) {
            return;
        }
        try {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $foreignTable, $foreignColumn, $name): void {
                $blueprint->foreign($column, $name)->references($foreignColumn)->on($foreignTable)->restrictOnDelete();
            });
        } catch (\Throwable) {
            // Mixed legacy installations may use incompatible key types. The
            // unique/index contracts remain authoritative in that case.
        }
    }
};
