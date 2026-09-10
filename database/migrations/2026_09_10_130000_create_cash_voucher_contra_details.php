<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cash_voucher_contra_details')) {
            return;
        }

        Schema::create('cash_voucher_contra_details', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('cash_voucher_id')->unique();
            $table->unsignedBigInteger('destination_account_id')->nullable()->index();
            $table->string('destination_account_code', 80);
            $table->string('destination_account_name', 255);
            $table->decimal('amount', 18, 2);
            $table->string('currency_code', 10);
            $table->decimal('exchange_rate', 18, 8);
            $table->decimal('base_amount', 18, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_voucher_contra_details');
    }
};
