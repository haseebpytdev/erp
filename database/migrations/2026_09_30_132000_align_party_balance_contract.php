<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('party_opening_balances')) return;
        Schema::table('party_opening_balances', function (Blueprint $t): void {
            if (!Schema::hasColumn('party_opening_balances','opening_no')) $t->string('opening_no',60)->nullable()->index();
            if (!Schema::hasColumn('party_opening_balances','balance_type')) $t->string('balance_type',40)->nullable()->index();
            if (!Schema::hasColumn('party_opening_balances','legacy_reference')) $t->string('legacy_reference',190)->nullable();
            if (!Schema::hasColumn('party_opening_balances','external_account_reference')) $t->string('external_account_reference',190)->nullable();
        });
    }
    public function down(): void {}
};
