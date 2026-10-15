<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_permission_overrides')) {
            return;
        }

        Schema::create('user_permission_overrides', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            /* Permission catalog table names are adaptive, so this is validated in application code. */
            $table->unsignedBigInteger('permission_id');
            $table->string('effect', 16);
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'permission_id'], 'user_permission_overrides_user_permission_unique');
            $table->index(['permission_id', 'effect'], 'user_permission_overrides_permission_effect_idx');
            $table->foreign('user_id', 'user_permission_overrides_user_fk')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('created_by', 'user_permission_overrides_created_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', 'user_permission_overrides_updated_by_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permission_overrides');
    }
};
