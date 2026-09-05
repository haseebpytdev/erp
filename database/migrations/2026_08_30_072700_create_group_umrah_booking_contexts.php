<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('booking_group_umrah_contexts')) {
            return;
        }

        Schema::create('booking_group_umrah_contexts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('booking_id')->unique();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->string('customer_name', 180)->nullable();
            $table->string('customer_source', 120)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_group_umrah_contexts');
    }
};
