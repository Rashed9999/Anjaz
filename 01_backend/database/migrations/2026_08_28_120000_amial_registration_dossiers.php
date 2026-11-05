<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-REGISTRATION-DOSSIER-001
 *
 * ملف التسجيل مستقل عن الحساب: الموظف يجمع الدليل، والعميل يؤكد رقمَه،
 * ثم يقرر مراجعٌ آخر. لا تُخزّن بيانات النموذج الحساسة كنص مكشوف.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('registration_dossiers', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 26)->unique();
            $table->string('subject_type', 16)->index(); // customer | merchant
            $table->unsignedBigInteger('subject_user_id')->nullable()->index();
            $table->string('source', 24)->index(); // self_service | staff_assisted | paper_archive
            $table->string('state', 32)->default('awaiting_customer_confirmation')->index();
            // Hash فقط للربط عند تأكيد OTP؛ الرقم نفسه داخل payload المشفّر.
            $table->char('phone_hash', 64)->index();
            $table->longText('payload_encrypted');
            $table->string('paper_form_encrypted_path', 500)->nullable();
            $table->string('paper_form_mime', 120)->nullable();
            $table->char('paper_form_sha256', 64)->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable()->index();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['subject_type', 'phone_hash', 'state'], 'registration_dossiers_claim_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_dossiers');
    }
};
