<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('merchant_pos_device_activations')) return;

        Schema::create('merchant_pos_device_activations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_user_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id');
            $table->string('code_hash', 64)->unique();
            $table->string('display_name', 120)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->unsignedBigInteger('consumed_by_device_id')->nullable();
            $table->timestamps();

            $table->index(['merchant_user_id', 'expires_at'], 'pos_activation_merchant_expiry_idx');
            $table->foreign('merchant_user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_pos_device_activations');
    }
};
