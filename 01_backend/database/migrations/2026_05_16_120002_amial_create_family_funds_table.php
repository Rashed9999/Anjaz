<?php

/**
 * AMIAL-FUND-FAMILY-001 (v0.9-B)
 *
 * family_funds: الصناديق العائلية / المشتركة.
 * كل صندوق له owner ومجموعة members، رصيد مستقل، وحالة.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_funds', function (Blueprint $table) {
            $table->id();
            $table->string('fund_ulid', 26)->unique();
            $table->string('name', 100);
            $table->string('description', 500)->nullable();
            $table->unsignedBigInteger('owner_user_id');
            $table->decimal('balance', 20, 4)->default(0);
            $table->decimal('held_balance', 20, 4)->default(0);
            $table->string('zone_code', 16)->default('SOUTH');
            $table->enum('status', ['active', 'archived', 'frozen'])->default('active');
            // اختياري: قيود مخصصة على الصرف
            $table->boolean('require_owner_approval_for_disbursement')->default(true);
            $table->decimal('max_member_contribution_per_day', 20, 4)->nullable();
            $table->timestamps();

            $table->index('owner_user_id');
            $table->index(['zone_code', 'status']);
            $table->foreign('owner_user_id')->references('id')->on('users')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_funds');
    }
};
