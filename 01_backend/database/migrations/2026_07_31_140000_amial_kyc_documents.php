<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-KYC-DOCS-001 — مكانٌ يرفع فيه العميل هويّته.
 *
 * **الدائرة المقطوعة التي يسدّها هذا الجدول:**
 * في لوحة الدعم زرٌّ اسمه «طلب تحديث الهوية» (`requireKyc`) يضع علامةً على
 * المستخدم. ثم **لا يوجد مكانٌ يرفع إليه مستنده**. الزرّ يعمل، والموظّف
 * يطمئنّ، والعميل ينتظر شيئاً لن يأتي.
 *
 * وكل ما كان قائماً عمودٌ واحد على `users`: `identity_image_encrypted_path` —
 * صورةٌ واحدة بلا نوعٍ ولا حالةِ مراجعةٍ ولا تاريخ. فلا يُعرف أهي بطاقة أم
 * صورة شخصية، ولا مَن راجعها، ولا لماذا رُفضت، ولا ما الذي رُفع قبلها.
 *
 * **ولماذا جدولٌ لا أعمدة إضافية على `users`؟**
 *   • **التعدّد**: الهوية وجهان، ويُضاف الجواز وإثبات العنوان والصورة الحيّة.
 *   • **التاريخ**: مستندٌ يُرفض ويُعاد رفعه. والعمود يُكتب فوقه فيضيع سبب
 *     الرفض الأوّل — وهو بالضبط ما يحتاجه من يراجع الثاني.
 *   • **الانتهاء**: البطاقات تنتهي صلاحيتها، والحساب الموثَّق ببطاقةٍ منتهية
 *     غير موثَّق.
 *
 * **والملفّ نفسه لا يُخزَّن هنا** — يُشفَّر عبر `EncryptedFileStorage` ويُحفظ
 * مساره فقط. صورةُ بطاقةٍ في تخزينٍ عاديّ تُقرأ بأي وصولٍ للقرص.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('kyc_documents')) {
            return;
        }

        Schema::create('kyc_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');

            // national_id_front | national_id_back | passport | selfie | address_proof
            $table->string('doc_type', 32);

            // مسار الملفّ المشفَّر — لا الملفّ.
            $table->string('encrypted_path', 500);
            $table->string('original_mime', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);

            // بصمة المحتوى: تكشف رفع الصورة نفسها لنوعين، وتُثبت أن الملفّ
            // المفكوك هو الذي رُفع.
            $table->string('content_sha256', 64)->nullable();

            // pending | approved | rejected
            $table->string('status', 16)->default('pending');

            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();

            // تاريخ انتهاء المستند نفسه (لا الصفّ): بطاقةٌ منتهية لا توثّق.
            $table->date('document_expires_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'doc_type', 'status'], 'kyc_docs_user_type_status_idx');
            $table->index(['status', 'created_at'], 'kyc_docs_queue_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_documents');
    }
};
