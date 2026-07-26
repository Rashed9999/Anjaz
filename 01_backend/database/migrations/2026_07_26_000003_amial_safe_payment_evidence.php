<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-SAFEPAY-EVIDENCE-001 — أدلّة حقيقية للدفع الآمن.
 *
 * قبلها كان عمود `attachments` مصفوفة نصوص يرسلها العميل كما يشاء: لا رفع
 * ملفات، ولا تخزين، ولا تحقّق من نوع أو حجم. أي أن «الأدلّة» كانت زينة —
 * ولا تصلح أساساً لقرار إداري يحوّل مالاً من طرف إلى آخر.
 *
 * الجدول يخزّن الملفّ الحقيقي مع:
 *   sha256   بصمة المحتوى — تُثبت أن الصورة لم تُبدَّل بعد رفعها
 *   stage    مرحلة الرفع (إنشاء/شحن/تسليم/نزاع) — الدليل بلا سياقه ناقص
 *   role     من رفعه: مشتر أم بائع
 *   ip/agent من أين رُفع — يكشف التنسيق بين طرفين متواطئين
 *
 * لا عمود تعديل ولا حذف: الدليل يُضاف ولا يُسحب. طرف يستطيع حذف دليله بعد
 * رؤيته لا يقدّم دليلاً، بل يقامر.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('safe_payment_evidence')) {
            return;
        }

        Schema::create('safe_payment_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('safe_payment_id')->index();
            $table->unsignedBigInteger('uploaded_by_user_id')->index();

            // buyer | seller | admin
            $table->string('role', 12)->index();
            // created | in_delivery | delivered | dispute | admin_review
            $table->string('stage', 20)->index();

            $table->string('path', 255);
            $table->string('original_name', 255)->nullable();
            $table->string('mime', 64);
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64)->index();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->text('note')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['safe_payment_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safe_payment_evidence');
    }
};
