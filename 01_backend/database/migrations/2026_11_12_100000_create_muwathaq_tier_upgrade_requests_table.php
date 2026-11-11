<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CAP-01: طلب ترقية الفئة (Tier 2) الذي يملك قراره البشري نظام «موثّق».
 * أميال باي يحتفظ بالطلب والصندوق وتطبيق القرار فقط. إضافي بالكامل: لا يمس
 * users ولا kyc_documents ولا قضايا التحقق القائمة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('muwathaq_tier_upgrade_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_ulid', 26)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('target_tier');
            $table->string('status', 24)->default('submitted')->index();
            // user_id:tier ما دام الطلب مفتوحاً، NULL عند الانتهاء؛ UNIQUE يمنع طلبين مفتوحين.
            $table->string('open_key', 40)->nullable()->unique();
            $table->json('document_ids');
            $table->string('event_ulid', 26)->unique();
            $table->json('payload');
            $table->string('delivery_status', 16)->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->unsignedInteger('last_decision_seq')->default(0);
            $table->string('decision_reference', 100)->nullable()->unique();
            $table->string('decision_reason', 1000)->nullable();
            $table->json('decision_context')->nullable();
            $table->string('apply_code', 64)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('muwathaq_tier_upgrade_requests');
    }
};
