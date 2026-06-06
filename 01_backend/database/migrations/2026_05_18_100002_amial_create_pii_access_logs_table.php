<?php

/**
 * AMIAL-PII-ENCRYPTION-001 (v1.3)
 *
 * pii_access_logs — تسجيل كل عملية decrypt للـ PII.
 *
 * الفائدة:
 *   - compliance (GDPR-style: من قرأ بيانات حساسة، متى، لماذا)
 *   - اكتشاف misuse داخلي (admin يقرأ بيانات بدون مبرر)
 *   - تحقيق لاحق في حال data breach
 *
 * مهم: يُسجَّل فقط الـ admin access للـ PII الخاصة بـ users آخرين.
 * المستخدم يرى بياناته بدون audit (هذا ليس access pattern مريب).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pii_access_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_user_id'); // الـ admin
            $table->string('subject_type', 50); // user, recovery_request, etc.
            $table->unsignedBigInteger('subject_id');
            $table->string('field_name', 100); // phone, email, national_id, identity_doc
            $table->string('access_reason', 500)->nullable(); // optional: التبرير
            $table->enum('access_type', ['view', 'decrypt_file', 'export']);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['actor_user_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index(['field_name', 'created_at']);

            $table->foreign('actor_user_id')->references('id')->on('users')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pii_access_logs');
    }
};
