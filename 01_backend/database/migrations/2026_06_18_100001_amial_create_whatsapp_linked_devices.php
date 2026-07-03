<?php

/**
 * AMIAL-WA-002 — ربط حساب واتساب بحساب أميال (Section 4 + 5 من المواصفة).
 *
 * مبدأ المواصفة: "واتساب قناة تشغيل رسمية" — يجب أن يكون لكل رقم واتساب
 * جلسة موثوقة (Trusted Session) أُنشئت عبر تحقّق OTP صريح، قبل تنفيذ
 * أيّ أمر حسّاس (حتى عرض الرصيد).
 *
 * ملاحظة صدق هندسي: WhatsApp Cloud API لا يُعطي "Device Fingerprint"
 * حقيقياً (لا وصول لمعرّف الجهاز الفعلي). الحقل device_fingerprint هنا
 * هو hash لرقم واتساب + user agent المتاح فقط — وهو أفضل ما يمكن تتبّعه
 * عبر هذه القناة، وليس فحصاً أمنياً على مستوى الجهاز الفعلي.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_linked_devices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('whatsapp_number', 20); // E.164 بدون +
            $table->string('device_fingerprint', 64)->nullable();

            $table->string('status', 16)->default('pending');
            // pending | active | revoked

            $table->unsignedTinyInteger('risk_score')->default(0); // 0-100
            $table->timestamp('otp_verified_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->string('revoke_reason', 255)->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'whatsapp_number'], 'wld_user_wa_unique');
            $table->index('whatsapp_number', 'wld_wa_number');
            $table->index('status', 'wld_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_linked_devices');
    }
};
