<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cash_voucher_expense_lines')) {
            return;
        }

        Schema::create('cash_voucher_expense_lines', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('cash_voucher_id')->index();
            $table->unsignedInteger('line_no');
            $table->unsignedBigInteger('expense_account_id')->nullable()->index();
            $table->string('expense_account_code', 80);
            $table->string('expense_account_name', 255);
            $table->text('description')->nullable();
            $table->decimal('amount', 18, 2);
            $table->string('currency_code', 10);
            $table->decimal('exchange_rate', 18, 8);
            $table->decimal('base_amount', 18, 2);
            $table->timestamps();

            $table->unique(
                ['cash_voucher_id', 'line_no'],
                'cash_voucher_expense_line_order_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_voucher_expense_lines');
    }
};
