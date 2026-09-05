<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('booking_group_umrah_invoice_links')) {
            return;
        }

        Schema::create('booking_group_umrah_invoice_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('booking_id')->index();
            $table->string('link_type', 32)->default('base');
            $table->unsignedBigInteger('amendment_id')->nullable()->index();
            $table->string('invoice_table', 96);
            $table->unsignedBigInteger('invoice_id');
            $table->string('invoice_number', 140)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['booking_id', 'link_type', 'invoice_id'], 'bguil_booking_type_invoice_unique');
            $table->index(['invoice_table', 'invoice_id'], 'bguil_native_invoice_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_group_umrah_invoice_links');
    }
};
