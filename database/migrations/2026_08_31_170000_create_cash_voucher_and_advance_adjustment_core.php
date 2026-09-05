<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cash_vouchers')) {
            Schema::create('cash_vouchers', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('voucher_no', 60)->unique();
                $table->string('voucher_type', 40)->index(); // receipt|payment|customer_advance|supplier_advance
                $table->string('direction', 10)->index(); // in|out
                $table->string('party_type', 30)->index(); // customer|supplier
                $table->unsignedBigInteger('party_id')->nullable()->index();
                $table->string('party_name', 255)->nullable();
                $table->unsignedBigInteger('booking_id')->nullable()->index();
                $table->date('voucher_date')->index();
                $table->date('value_date')->nullable();
                $table->string('currency_code', 10)->default('PKR');
                $table->decimal('exchange_rate', 18, 8)->default(1);
                $table->decimal('amount', 18, 2)->default(0);
                $table->decimal('allocated_amount', 18, 2)->default(0);
                $table->decimal('unallocated_amount', 18, 2)->default(0);
                $table->string('payment_method', 50)->default('Bank Transfer');
                $table->string('cash_bank_account_code', 80);
                $table->string('cash_bank_account_name', 255);
                $table->string('bank_name', 190)->nullable();
                $table->string('instrument_no', 120)->nullable();
                $table->string('transaction_reference', 190)->nullable()->index();
                $table->text('narration')->nullable();
                $table->string('payment_proof_path', 500)->nullable();
                $table->string('payment_proof_original_name', 255)->nullable();
                $table->string('status', 30)->default('draft')->index();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('submitted_by')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->unsignedBigInteger('posted_by')->nullable();
                $table->timestamp('posted_at')->nullable();
                $table->string('posting_reference', 90)->nullable()->unique();
                $table->unsignedBigInteger('reversed_by')->nullable();
                $table->timestamp('reversed_at')->nullable();
                $table->string('reversal_reference', 90)->nullable()->unique();
                $table->text('reversal_reason')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('cash_voucher_allocations')) {
            Schema::create('cash_voucher_allocations', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('cash_voucher_id')->index();
                $table->unsignedInteger('line_no')->default(1);
                $table->string('target_type', 40)->index(); // sales_invoice|supplier_costing
                $table->unsignedBigInteger('target_id')->index();
                $table->string('target_number', 120)->nullable()->index();
                $table->unsignedBigInteger('booking_id')->nullable()->index();
                $table->decimal('target_total_snapshot', 18, 2)->nullable();
                $table->decimal('outstanding_before_snapshot', 18, 2)->nullable();
                $table->decimal('amount', 18, 2)->default(0);
                $table->string('currency_code', 10)->default('PKR');
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->index(['cash_voucher_id','line_no'], 'cash_voucher_allocation_order_idx');
            });
        }

        if (! Schema::hasTable('cash_voucher_posting_lines')) {
            Schema::create('cash_voucher_posting_lines', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('cash_voucher_id')->index();
                $table->string('posting_reference', 90)->index();
                $table->string('entry_type', 20)->default('original')->index(); // original|reversal
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

        if (! Schema::hasTable('cash_voucher_activities')) {
            Schema::create('cash_voucher_activities', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('cash_voucher_id')->index();
                $table->string('action', 50);
                $table->string('from_status', 30)->nullable();
                $table->string('to_status', 30)->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('user_name', 190)->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('advance_adjustments')) {
            Schema::create('advance_adjustments', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('adjustment_no', 60)->unique();
                $table->unsignedBigInteger('advance_voucher_id')->index();
                $table->string('party_type', 30)->index();
                $table->unsignedBigInteger('party_id')->nullable()->index();
                $table->string('party_name', 255)->nullable();
                $table->string('target_type', 40)->index();
                $table->unsignedBigInteger('target_id')->index();
                $table->string('target_number', 120)->nullable()->index();
                $table->unsignedBigInteger('booking_id')->nullable()->index();
                $table->date('adjustment_date')->index();
                $table->decimal('amount', 18, 2)->default(0);
                $table->string('currency_code', 10)->default('PKR');
                $table->decimal('exchange_rate', 18, 8)->default(1);
                $table->text('remarks')->nullable();
                $table->string('status', 30)->default('draft')->index();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('submitted_by')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->unsignedBigInteger('posted_by')->nullable();
                $table->timestamp('posted_at')->nullable();
                $table->string('posting_reference', 90)->nullable()->unique();
                $table->unsignedBigInteger('reversed_by')->nullable();
                $table->timestamp('reversed_at')->nullable();
                $table->string('reversal_reference', 90)->nullable()->unique();
                $table->text('reversal_reason')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('advance_adjustment_posting_lines')) {
            Schema::create('advance_adjustment_posting_lines', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('advance_adjustment_id')->index();
                $table->string('posting_reference', 90)->index();
                $table->string('entry_type', 20)->default('original')->index();
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

        if (! Schema::hasTable('advance_adjustment_activities')) {
            Schema::create('advance_adjustment_activities', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('advance_adjustment_id')->index();
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
        Schema::dropIfExists('advance_adjustment_activities');
        Schema::dropIfExists('advance_adjustment_posting_lines');
        Schema::dropIfExists('advance_adjustments');
        Schema::dropIfExists('cash_voucher_activities');
        Schema::dropIfExists('cash_voucher_posting_lines');
        Schema::dropIfExists('cash_voucher_allocations');
        Schema::dropIfExists('cash_vouchers');
    }
};
