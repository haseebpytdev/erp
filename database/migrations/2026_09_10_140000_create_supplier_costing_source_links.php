<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('supplier_costing_source_links')) {
            return;
        }

        Schema::create('supplier_costing_source_links', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('supplier_costing_id')->index();
            $table->unsignedBigInteger('supplier_costing_line_id')->unique();
            $table->unsignedBigInteger('booking_id')->index();
            $table->unsignedBigInteger('booking_service_id')->nullable()->index();
            $table->string('source_type', 100)->index();
            $table->unsignedBigInteger('source_id')->nullable()->index();
            $table->string('source_key', 190)->unique();
            $table->unsignedBigInteger('supplier_id')->index();
            $table->string('product_type', 60)->index();
            $table->decimal('source_cost_snapshot', 18, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_costing_source_links');
    }
};
