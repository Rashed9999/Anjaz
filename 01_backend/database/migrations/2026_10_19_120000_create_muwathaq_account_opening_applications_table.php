<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * طلب فتح الحساب هو الحد الفاصل بين التسجيل والمال.
 *
 * لا تنسخ هذه الطاولة صور الهوية أو بيانات KYC؛ تلك تبقى في مخزن الوثائق
 * المشفّر. تحفظ فقط حالة الطلب والمرجع الذي يعيده نظام «موثّق».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('muwathaq_account_opening_applications', function (Blueprint $table) {
            $table->id();
            $table->string('application_ulid', 26)->unique();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('subject_kind', 32)->index();
            $table->string('verification_path', 64)->index();
            $table->string('business_type', 64)->nullable()->index();
            $table->unsignedTinyInteger('requested_tier')->nullable();
            $table->string('status', 32)->default('submitted')->index();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_reference', 100)->nullable()->unique();
            $table->string('decision_reason', 1000)->nullable();
            $table->json('decision_context')->nullable();
            $table->timestamps();

            $table->index(['subject_kind', 'status'], 'muwathaq_application_subject_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('muwathaq_account_opening_applications');
    }
};
