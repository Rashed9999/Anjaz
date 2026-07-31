<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-AML-REGREPORT-001 — التقارير التنظيمية STR/CTR (الفصل ١٠، التبويب ٨).
 *
 * **لماذا هذا هو البند التنظيميّ الأهمّ في الفصل كلّه:** رصدُ النشاط
 * المشبوه واجبٌ داخليّ، أمّا **الإبلاغ عنه** فواجبٌ قانونيّ. ومنصّةٌ ترصد
 * ولا تُبلّغ تكون قد جمعت الدليل على المخالفة واحتفظت به لنفسها — وهو
 * وضعٌ أسوأ أمام المنظّم من ألّا ترصد أصلاً.
 *
 * **والفرق بين البلاغين جوهريّ ويحكم التصميم:**
 *
 *   • **STR** (بلاغ اشتباه) — تقديريّ: يُرفع حين يقتنع ضابط الامتثال بعد
 *     تحقيق. فيُشترط أن يكون له تحقيقٌ خلفه، وإلّا صار «بلاغاً» بلا أساس.
 *
 *   • **CTR** (بلاغ عملة) — **غير تقديريّ**: كلّ عملية فوق الحدّ تُبلَّغ،
 *     مشبوهةً كانت أو لا. ولذلك لا يملك أحدٌ في هذا التصميم «إلغاء» بلاغ
 *     عملة — يُولَّد ويُرسَل. وخيارُ عدم الإرسال هو بالضبط ما يجعل الحدّ بلا
 *     معنى.
 *
 * **والمُرسَل لا يُعدَّل.** بعد الإرسال يصير لدى المنظّم نسخة؛ وتعديلُ نسختنا
 * يجعل النسختين تختلفان، فيصير سجلّنا شهادةً على أنفسنا بأنّنا غيّرنا ما
 * أرسلناه. التصحيح يكون ببلاغٍ جديد يشير إلى الأوّل، لا بتحرير القديم.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aml_regulatory_reports', function (Blueprint $t) {
            $t->id();

            // متسلسل سنويّ حسب النوع: STR-2026-000001 / CTR-2026-000001.
            $t->string('report_number', 24)->unique();

            $t->enum('report_type', ['STR', 'CTR'])->index();

            $t->enum('status', [
                'draft',              // يُحرَّر
                'pending_submission', // اكتمل وينتظر الإرسال
                'submitted',          // أُرسل — لا يُعدَّل بعدها
            ])->default('draft')->index();

            $t->unsignedBigInteger('subject_user_id')->index();
            $t->unsignedBigInteger('investigation_id')->nullable()->index();
            $t->string('transaction_ulid', 26)->nullable()->index();

            $t->decimal('amount', 20, 4)->default(0);
            $t->string('currency', 3)->default('YER');

            // نافذة التغطية: بلاغ العملة قد يجمع عملياتِ يوم، والاشتباه قد
            // يغطّي شهوراً. وبلا النافذة لا يُعرف ما الذي شمله البلاغ.
            $t->timestamp('period_start')->nullable();
            $t->timestamp('period_end')->nullable();

            // متن البلاغ كما وُلِّد. يُحفَظ كاملاً لا يُعاد بناؤه عند الحاجة:
            // إعادةُ البناء بعد سنة تُنتج نصّاً من بياناتٍ تغيّرت، فلا يطابق
            // ما أُرسل.
            $t->json('content');

            $t->unsignedBigInteger('generated_by')->index();
            $t->timestamp('generated_at')->useCurrent();

            $t->unsignedBigInteger('submitted_by')->nullable();
            $t->timestamp('submitted_at')->nullable();

            // مرجع المنظّم: بلاغٌ «مُرسَل» بلا مرجعٍ من الجهة المستقبِلة
            // ادّعاءٌ لا إثبات.
            $t->string('external_reference', 120)->nullable();
            $t->text('submission_note')->nullable();

            // تصحيحٌ يشير إلى سابقه بدل تحرير القديم — انظر شرح أعلى الملفّ.
            $t->unsignedBigInteger('supersedes_report_id')->nullable()->index();

            $t->timestamps();

            $t->index(['report_type', 'status']);
            $t->index(['subject_user_id', 'report_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aml_regulatory_reports');
    }
};
