<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('bookings', 'public_voucher_token')) {
            Schema::table('bookings', function (Blueprint $table): void {
                $table->string('public_voucher_token', 64)->nullable()->unique();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bookings', 'public_voucher_token')) {
            Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn('public_voucher_token'));
        }
    }
};
