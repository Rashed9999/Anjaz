<?php

/**
 * AMIAL-REFACTOR-CORE-001 + AMIAL-ZONE-001 (preparatory)
 *
 * audit_decisions: سجل كل قرار اتخذه النظام بشأن عملية حساسة.
 *
 * كل عملية مالية / تغيير PIN / تغيير رقم / login فاشل / رفض zone
 * يكتب صفاً واحداً هنا. غير قابل للتعديل (append-only logically).
 *
 * مرتبط بـ:
 *  - معيار قبول 5: "كل عملية مالية لديها audit أو log decision"
 *  - قسم 4: decision_code, decision_reason في APIs
 *  - قسم 22: Audit log في API requirements
 *
 * نستخدم actor_user_id + subject_user_id لأن actor ليس دائماً = subject
 * (مثال: admin يعدل user → actor=admin, subject=user).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_decisions', function (Blueprint $table) {
            $table->id();

            // ULID للقرار نفسه — يُربط في responses ولـ tracing
            $table->string('decision_id', 32)->unique();

            // من اتخذ القرار: user/admin/system
            $table->enum('actor_type', ['user', 'admin', 'system', 'agent', 'merchant'])
                ->default('system');
            $table->unsignedBigInteger('actor_user_id')->nullable();

            // على من وقع القرار
            $table->enum('subject_type', ['user', 'transaction', 'wallet', 'merchant', 'session', 'pin'])
                ->default('user');
            $table->string('subject_id', 64)->nullable();

            // الفعل: TRANSACTION_APPROVED, TRANSACTION_DENIED_INSUFFICIENT_BALANCE,
            // ZONE_BLOCKED, PIN_VERIFIED, PIN_FAILED, PIN_LOCKED,
            // TERMS_ACCEPTED, IDEMPOTENCY_REPLAY, etc.
            $table->string('action', 64);

            // الرمز المختصر (machine-readable) — يطابق response codes في الـ API
            $table->string('decision_code', 64);

            // العلة المقروءة (مختصرة، آمنة للـ log — لا أرقام بطاقات/PINs)
            $table->string('reason', 255)->nullable();

            // context منظم: ip, user_agent, zone, amount (دون PII حساس)
            $table->json('context')->nullable();

            // ربط بعملية مالية إن وجدت
            $table->string('transaction_id', 64)->nullable();

            // ربط بـ idempotency_key إن وجد
            $table->string('idempotency_key', 128)->nullable();

            // zone code وقت اتخاذ القرار (snapshot)
            $table->string('zone_code', 16)->nullable();

            // مستوى الخطورة (يفيد لـ monitoring)
            $table->enum('severity', ['info', 'notice', 'warning', 'critical'])->default('info');

            $table->timestamp('created_at')->useCurrent();
            // لا updated_at — append-only

            // فهارس للاستعلام
            $table->index(['actor_user_id', 'created_at'], 'audit_actor_created_idx');
            $table->index(['subject_id', 'created_at'], 'audit_subject_created_idx');
            $table->index(['decision_code', 'created_at'], 'audit_code_created_idx');
            $table->index('transaction_id', 'audit_transaction_idx');
            $table->index('idempotency_key', 'audit_idempotency_idx');
            $table->index(['severity', 'created_at'], 'audit_severity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_decisions');
    }
};
