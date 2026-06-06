<?php

/**
 * AMIAL-PIN-SECURITY-001
 *
 * account_security_events: تسجيل أحداث أمن الحساب (المذكورة في قسم 9 و10 من الوثيقة).
 *
 * - فشل/نجاح PIN
 * - تغيير PIN
 * - reset PIN (مع security hold)
 * - تغيير رقم الهاتف
 * - إبطال tokens
 * - login من جهاز جديد
 *
 * مكمل لـ audit_decisions: audit_decisions للأحداث الواسعة،
 * account_security_events للأحداث الموجهة بمنظور المستخدم (يعرضها لاحقاً في
 * شاشة "أمان الحساب" — قسم 20).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_security_events', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id');

            // PIN_VERIFIED, PIN_FAILED, PIN_LOCKED, PIN_UNLOCKED, PIN_CHANGED, PIN_RESET,
            // PHONE_CHANGED, PASSWORD_CHANGED, TOKEN_REVOKED_ALL, LOGIN_NEW_DEVICE,
            // SECURITY_HOLD_APPLIED, SECURITY_HOLD_LIFTED, KYC_RESUBMITTED
            $table->string('event_type', 48);

            // معلومات تقنية (لا تحتوي PIN/password/OTP أبداً)
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('device_id', 128)->nullable();

            // ملاحظات نصية مختصرة (للعرض في شاشة الأمان)
            $table->string('note', 500)->nullable();

            // metadata منظم (مثل: failed_attempt_count, locked_for_seconds)
            $table->json('metadata')->nullable();

            // مستوى الخطورة (يحدد لون العرض في شاشة "أمان الحساب")
            $table->enum('severity', ['info', 'notice', 'warning', 'critical'])->default('info');

            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at'], 'security_events_user_idx');
            $table->index(['user_id', 'event_type'], 'security_events_user_type_idx');
            $table->index(['event_type', 'created_at'], 'security_events_type_idx');
        });

        // جدول إضافي خاص بأحداث PIN فقط — مذكور حرفياً في الوثيقة:
        // "account_pin_security_events"
        // نتركه view أو ندمج. القرار: نتركه كـ view منطقي عبر scope في الـ model.
        // (لا migration لجدول مستقل لتجنب التكرار، scope في PinSecurityEvent model يكفي.)
    }

    public function down(): void
    {
        Schema::dropIfExists('account_security_events');
    }
};
