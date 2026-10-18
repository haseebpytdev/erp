<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('our_bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('bank_name', 190);
            $table->string('account_title', 190);
            $table->string('account_last_digits', 6);
            $table->string('currency_code', 12);
            $table->unsignedBigInteger('linked_ledger_id');
            $table->string('linked_ledger_code', 80);
            $table->string('linked_ledger_name', 255);
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->boolean('use_for_ai_receipt_matching')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['company_id', 'branch_id']);
            $table->index(['linked_ledger_id', 'is_active']);
            $table->index(['is_active', 'use_for_ai_receipt_matching']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('our_bank_accounts');
    }
};
