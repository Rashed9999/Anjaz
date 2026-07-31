<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-AML-INVESTIGATION-001 — مركز التحقيقات (الفصل ١٠، التبويب ٧).
 *
 * **ما كان ناقصاً:** النظام يرصد ويعلّق ويُنبّه، ثمّ يقف. فالتنبيه يُحلّ
 * بملاحظةٍ سطرٍ واحد، والعملية المعلّقة تُعتمد أو تُرفض — ولا شيء يربط
 * عشرين تنبيهاً على عميلٍ واحد في **قضيّة** لها رقمٌ وضابطٌ مسؤول وأدلّة
 * وقرارٌ في نهايتها.
 *
 * وهذا ما يطلبه المنظّم: لا «هل رأيتم النشاط؟» بل «أروني ملفّ القضية».
 *
 * ثلاثة قرارات في هذا التصميم تستحقّ الشرح:
 *
 * ١) **رقم القضية متسلسل سنويّاً لا عشوائيّ.** المنظّم يشير إلى القضايا
 *    بأرقامها، والفجوة في التسلسل سؤالٌ يجب أن يكون له جواب. ورمزٌ عشوائيّ
 *    (ULID) يجعل «كم قضية فتحتم هذا العام؟» سؤالاً يحتاج استعلاماً بدل أن
 *    يُقرأ من آخر رقم.
 *
 * ٢) **الخطّ الزمنيّ يُضاف إليه ولا يُعدَّل.** لا عمود `updated_at` ولا مسار
 *    تعديل ولا حذف. تحقيقٌ يمكن تحرير تاريخه لا يصلح دليلاً: من يستطيع
 *    تغيير ما قيل بالأمس يستطيع أن يجعل القرار يبدو صائباً دائماً.
 *
 * ٣) **القرار محصورٌ في قائمة، والسبب إلزاميّ.** «أُغلقت القضية» بلا قرارٍ
 *    مسمّى تعني أنّ أحداً لم يقرّر شيئاً — وهو أسوأ من قرارٍ خاطئ لأنّه لا
 *    يُراجَع ولا يُتعلَّم منه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aml_investigations', function (Blueprint $t) {
            $t->id();

            // متسلسل سنويّاً: INV-2026-000001 — انظر القرار (١) أعلاه.
            $t->string('case_number', 24)->unique();

            $t->unsignedBigInteger('subject_user_id')->index();

            // من أين فُتحت: تنبيه، أو عملية معلّقة، أو قرارُ ضابطٍ ابتداءً.
            // يُحفظ المصدر لأنّ «لماذا فُتحت هذه القضية؟» أوّل سؤال يُسأل.
            $t->enum('opened_from', ['alert', 'flagged_transaction', 'manual'])->default('manual');
            $t->string('source_ulid', 26)->nullable()->index();

            $t->enum('priority', ['low', 'medium', 'high', 'critical'])->default('medium');

            $t->enum('status', [
                'open',              // فُتحت ولم تُسنَد
                'investigating',     // ضابطٌ يعمل عليها
                'pending_decision',  // انتهى الجمع وينتظر قراراً
                'closed',
                'reopened',
            ])->default('open')->index();

            $t->unsignedBigInteger('assigned_officer_id')->nullable()->index();
            $t->timestamp('assigned_at')->nullable();

            // درجة الخطر وقت الفتح تُجمَّد: القيمة الحالية تتغيّر بعد الفتح،
            // ومراجعُ القرار يحتاج ما كان أمام الضابط لا ما صار بعده.
            $t->decimal('risk_score_at_open', 6, 2)->nullable();

            $t->unsignedBigInteger('opened_by')->index();
            $t->timestamp('opened_at')->useCurrent();

            // القرار محصور — انظر (٣) أعلاه.
            $t->enum('decision', [
                'no_action',        // نشاطٌ مشروع بعد الفحص
                'warning_issued',   // نُبِّه العميل
                'account_frozen',
                'blacklisted',
                'str_filed',        // رُفع بلاغ اشتباه للمنظّم
            ])->nullable();

            $t->unsignedBigInteger('closed_by')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->text('closure_reason')->nullable();

            $t->timestamps();

            $t->index(['status', 'priority']);
            $t->index(['subject_user_id', 'status']);
        });

        Schema::create('aml_investigation_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('investigation_id')->index();

            $t->enum('event_type', [
                'opened', 'assigned', 'evidence_added', 'note_added',
                'action_taken', 'escalated', 'decision_made', 'closed', 'reopened',
            ]);

            $t->unsignedBigInteger('actor_user_id')->index();
            $t->text('note')->nullable();
            $t->json('metadata')->nullable();

            // لا `updated_at` عمداً — انظر القرار (٢) أعلاه. الصفّ يُكتب مرّة.
            $t->timestamp('created_at')->useCurrent();

            $t->index(['investigation_id', 'created_at']);
        });

        Schema::create('aml_investigation_documents', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('investigation_id')->index();
            $t->string('title', 200);
            $t->string('encrypted_path', 255);
            $t->string('original_mime', 100)->nullable();
            $t->unsignedBigInteger('size_bytes')->default(0);
            $t->string('content_sha256', 64)->nullable();
            $t->unsignedBigInteger('uploaded_by')->index();
            $t->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aml_investigation_documents');
        Schema::dropIfExists('aml_investigation_events');
        Schema::dropIfExists('aml_investigations');
    }
};
