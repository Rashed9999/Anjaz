<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_ai_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('model', 150);
            $table->char('input_digest', 64);
            $table->string('status', 24);
            // التقرير يحتوي على PII: يشفر بالكامل بواسطة cast في الموديل.
            $table->longText('report_encrypted')->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'id'], 'kyc_ai_reviews_user_latest_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_ai_reviews');
    }
};
