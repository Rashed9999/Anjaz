<?php

/**
 * AMIAL-RECOVERY-001
 *
 * account_recovery_requests: طلبات استرداد حساب أو تغيير رقم.
 *
 * سيناريوهان (قسم 10 من الوثيقة):
 *   1. المستخدم يملك الرقم القديم (self-service):
 *      OTP-قديم + OTP-جديد + PIN + security_hold + risk_score
 *   2. المستخدم فقد الرقم القديم (admin-mediated):
 *      رفع هوية + نموذج → review → approve/reject
 *
 * موانع (لا تغيير رقم لـ):
 *   - تاجر موثق أو وكيل بدون مراجعة admin
 *   - حساب عليه نزاع دفع آمن مفتوح
 *   - حساب تغيّر رقمه ضمن آخر X أيام
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_recovery_requests', function (Blueprint $table) {
            $table->id();

            // ULID للمرجع الخارجي
            $table->string('request_ulid', 26)->unique();

            // المستخدم الذي يطلب الاسترداد
            $table->unsignedBigInteger('user_id');

            // نوع الطلب
            $table->enum('request_type', [
                'phone_change_self',        // يملك الرقم القديم
                'phone_change_lost_phone',  // فقد الرقم
                'pin_reset_admin',          // إعادة PIN عبر admin (للحالات الخاصة)
            ])->index();

            // الأرقام
            $table->string('old_phone', 20);
            $table->string('new_phone', 20)->nullable();

            // حالة الطلب
            $table->enum('status', [
                'pending_otp',              // ينتظر تأكيد OTP
                'pending_review',           // بانتظار مراجعة admin
                'approved',                 // أُوافق (الـ security_hold مُفعّل)
                'rejected',                 // مرفوض
                'cancelled',                // ألغاه المستخدم
                'expired',                  // انتهت صلاحية الطلب
            ])->default('pending_otp')->index();

            // مرفقات الهوية (للسيناريو 2) — مسارات في private storage
            $table->json('identification_documents')->nullable();

            // ملاحظات للمستخدم (سبب الرفض، إلخ)
            $table->string('user_notes', 500)->nullable();

            // ملاحظات admin (داخلي، لا تُعرض للمستخدم)
            $table->text('admin_notes')->nullable();

            // معاملات الـ OTP (للسيناريو 1)
            $table->string('otp_old_phone', 6)->nullable();   // hash؟ — نخزن كنص لأنه مؤقت ولا حساس بعد expire
            $table->string('otp_new_phone', 6)->nullable();
            $table->timestamp('otp_expires_at')->nullable();
            $table->boolean('otp_old_verified')->default(false);
            $table->boolean('otp_new_verified')->default(false);

            // risk score (سياسة قسم 10)
            $table->unsignedTinyInteger('risk_score')->nullable(); // 0-100

            // admin الذي راجع (للسيناريو 2)
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            // معلومات تقنية
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->timestamp('expires_at'); // الطلب تلقائياً يـ expire بعد 24h لو لم يكتمل

            $table->timestamps();

            // فهارس
            $table->index(['user_id', 'status'], 'recovery_user_status_idx');
            $table->index('status', 'recovery_status_idx');
            $table->index('expires_at', 'recovery_expires_idx');

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('reviewed_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_recovery_requests');
    }
};
