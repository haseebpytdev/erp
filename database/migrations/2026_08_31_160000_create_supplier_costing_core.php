<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('supplier_costings')) {
            Schema::create('supplier_costings', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('costing_no', 50)->unique();
                $table->unsignedBigInteger('booking_id')->nullable()->index();
                $table->unsignedBigInteger('supplier_id')->nullable()->index();
                $table->string('supplier_name', 255)->nullable();
                $table->string('service_type', 60)->default('Air Ticket')->index();
                $table->date('cost_date');
                $table->date('due_date')->nullable();
                $table->string('supplier_invoice_no', 120)->nullable()->index();
                $table->string('supplier_reference', 190)->nullable();
                $table->string('currency_code', 10)->default('PKR');
                $table->decimal('exchange_rate', 18, 8)->default(1);
                $table->string('payment_terms', 80)->nullable();
                $table->text('remarks')->nullable();
                $table->decimal('base_cost', 18, 2)->default(0);
                $table->decimal('tax_amount', 18, 2)->default(0);
                $table->decimal('other_charges', 18, 2)->default(0);
                $table->decimal('total_cost', 18, 2)->default(0);
                $table->string('status', 30)->default('draft')->index();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('submitted_by')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->unsignedBigInteger('posted_by')->nullable();
                $table->timestamp('posted_at')->nullable();
                $table->string('posting_reference', 80)->nullable()->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('supplier_costing_lines')) {
            Schema::create('supplier_costing_lines', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('supplier_costing_id')->index();
                $table->unsignedInteger('line_no')->default(1);
                $table->string('service_type', 60)->default('Air Ticket');
                $table->string('description', 255);
                $table->unsignedBigInteger('passenger_id')->nullable()->index();
                $table->string('passenger_name', 255)->nullable();
                $table->string('supplier_service_ref', 190)->nullable();
                $table->decimal('base_cost', 18, 2)->default(0);
                $table->decimal('tax_amount', 18, 2)->default(0);
                $table->decimal('other_charges', 18, 2)->default(0);
                $table->decimal('total_cost', 18, 2)->default(0);
                $table->text('source_snapshot_json')->nullable();
                $table->timestamps();
                $table->index(['supplier_costing_id','line_no'], 'supplier_costing_line_order_idx');
            });
        }

        if (! Schema::hasTable('supplier_costing_posting_lines')) {
            Schema::create('supplier_costing_posting_lines', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('supplier_costing_id')->index();
                $table->string('posting_reference', 80)->index();
                $table->string('account_code', 80);
                $table->string('account_name', 255);
                $table->string('party_type', 30)->nullable();
                $table->unsignedBigInteger('party_id')->nullable()->index();
                $table->decimal('debit', 18, 2)->default(0);
                $table->decimal('credit', 18, 2)->default(0);
                $table->string('currency_code', 10)->default('PKR');
                $table->decimal('exchange_rate', 18, 8)->default(1);
                $table->text('narration')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('supplier_costing_activities')) {
            Schema::create('supplier_costing_activities', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('supplier_costing_id')->index();
                $table->string('action', 50);
                $table->string('from_status', 30)->nullable();
                $table->string('to_status', 30)->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('user_name', 190)->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_costing_activities');
        Schema::dropIfExists('supplier_costing_posting_lines');
        Schema::dropIfExists('supplier_costing_lines');
        Schema::dropIfExists('supplier_costings');
    }
};
