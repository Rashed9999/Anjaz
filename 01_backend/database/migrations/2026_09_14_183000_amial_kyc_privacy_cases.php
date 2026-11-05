<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('kyc_verification_cases')) {
            return;
        }

        Schema::create('kyc_verification_cases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();

            // standard | restricted_review | automated | in_person
            $table->string('review_mode', 32)->default('standard')->index();

            // ما الذي أثبت أن صاحب الحساب هو صاحب الهوية، لا مجرد وجود ورقة.
            // legacy_selfie_review هو الواقع الحالي ويظل اسماً صريحاً لا ادعاء Liveness.
            $table->string('ownership_method', 48)->default('legacy_selfie_review')->index();

            // collecting | evidence_ready | manual_review | verified | rejected
            $table->string('status', 32)->default('collecting')->index();

            // not_configured | pending | passed | failed | manual_review
            $table->string('liveness_status', 24)->default('not_configured')->index();
            $table->decimal('liveness_score', 7, 4)->nullable();

            // not_configured | pending | matched | not_matched | manual_review
            $table->string('face_match_status', 24)->default('not_configured')->index();
            $table->decimal('face_match_score', 7, 4)->nullable();

            $table->string('biometric_provider', 64)->nullable();
            $table->string('provider_reference', 160)->nullable()->index();

            // لا تعتمد على gender لتحديد من يرى الصورة. صاحب الحساب يختار
            // الخصوصية، والصلاحية تحدد المراجع المخول.
            $table->boolean('restricted_review')->default(false)->index();
            $table->timestamp('requested_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('decision_reason', 500)->nullable();
            $table->timestamps();

            // أثر KYC يجب ألا يختفي إذا حُذف حساب لاحقاً؛ لا cascade هنا.
            $table->index(['status', 'review_mode'], 'kyc_privacy_status_mode_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_verification_cases');
    }
};
