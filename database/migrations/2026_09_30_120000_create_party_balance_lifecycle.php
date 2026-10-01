<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('party_opening_balances', function (Blueprint $t): void {
            $t->id(); $t->string('opening_no',60)->unique(); $t->string('party_type',20); $t->unsignedBigInteger('party_id'); $t->string('party_name');
            $t->string('balance_type',40); $t->date('opening_date'); $t->decimal('amount',18,2); $t->string('currency_code',12)->default('PKR'); $t->decimal('exchange_rate',18,8)->default(1); $t->unsignedBigInteger('branch_id'); $t->unsignedBigInteger('company_id')->nullable();
            $t->string('legacy_reference',190)->nullable(); $t->string('external_account_reference',190)->nullable(); $t->text('narration')->nullable(); $t->string('supporting_document')->nullable(); $t->string('status',30)->default('draft'); $t->unsignedBigInteger('posting_journal_id')->nullable(); $t->string('posting_reference',90)->nullable()->unique(); $t->string('reversal_reference',90)->nullable()->unique();
            $t->unsignedBigInteger('created_by')->nullable(); $t->unsignedBigInteger('submitted_by')->nullable(); $t->timestamp('submitted_at')->nullable(); $t->unsignedBigInteger('approved_by')->nullable(); $t->timestamp('approved_at')->nullable(); $t->unsignedBigInteger('posted_by')->nullable(); $t->timestamp('posted_at')->nullable(); $t->timestamp('reversed_at')->nullable(); $t->text('reversal_reason')->nullable(); $t->timestamps(); $t->index(['party_type','party_id']); $t->index(['status','opening_date']);
        });
        Schema::create('party_opening_balance_posting_lines', function (Blueprint $t): void { $t->id(); $t->foreignId('party_opening_balance_id')->constrained()->cascadeOnDelete(); $t->unsignedInteger('line_no'); $t->string('account_code'); $t->string('account_name'); $t->string('party_type')->nullable(); $t->unsignedBigInteger('party_id')->nullable(); $t->decimal('debit',18,2)->default(0); $t->decimal('credit',18,2)->default(0); $t->decimal('base_debit',18,2)->default(0); $t->decimal('base_credit',18,2)->default(0); $t->string('currency_code',12); $t->decimal('exchange_rate',18,8)->default(1); $t->string('entry_type',20)->default('original'); $t->text('narration')->nullable(); $t->timestamps(); });
        Schema::create('party_opening_balance_activities', function (Blueprint $t): void { $t->id(); $t->foreignId('party_opening_balance_id')->constrained()->cascadeOnDelete(); $t->string('action',40); $t->string('from_status')->nullable(); $t->string('to_status')->nullable(); $t->unsignedBigInteger('user_id')->nullable(); $t->text('remarks')->nullable(); $t->timestamps(); });
    }
    public function down(): void { /* Opening balance history is never destructively dropped. */ }
};
