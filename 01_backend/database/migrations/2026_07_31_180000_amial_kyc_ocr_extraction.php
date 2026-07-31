<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-KYC-OCR-001 — استخراج بيانات الوثيقة (الفصل ٢٣ — OCR Center).
 *
 * **ما يحلّه:** المراجعة كانت يدويّة بالكامل — يفتح الموظّف الصورة ويقرأ
 * بعينه ويقرّر. وهذا يصمد مع عشرات العملاء لا مع آلاف، ويُدخل خطأ النسخ
 * البشريّ في أخطر حقلٍ في النظام: رقم الهوية.
 *
 * **وثلاثة قرارات هنا تحكم كلّ ما بُني فوقها:**
 *
 * ١) **المستخرَج والمُعتمَد حقلان منفصلان.** `ocr_extracted` ما قاله
 *    المحرّك، و`verified_fields` ما أقرّه إنسان. وخلطُهما يجعل قراءةَ آلةٍ
 *    تصير إقراراً موثّقاً بلا أن يقرّ أحد — وهو أخطر ما في إدخال OCR إلى
 *    مسارٍ تنظيميّ.
 *
 * ٢) **الثقة تُحفَظ لكلّ حقل لا للوثيقة.** قد يُقرأ تاريخ الميلاد بوضوح
 *    ورقم الهوية بضبابٍ في الصورة نفسها. ومعدّلٌ واحد للوثيقة يُخفي ذلك،
 *    فيمرّ الرقم الضبابيّ محمولاً على وضوح غيره.
 *
 * ٣) **الحالة تفرّق بين «لم يُشغَّل» و«شُغِّل ففشل» و«غير متاح».** الثلاثة
 *    تبدو واحدة للمراجع — لا بيانات مستخرجة — وهي مختلفة تماماً: الأولى
 *    تُعاد، والثانية تعني وثيقةً رديئة، والثالثة تعني **عطلاً في الخادم**
 *    يجب أن يُصلَح لا أن يُراجَع يدويّاً إلى الأبد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc_documents', function (Blueprint $t) {
            if (!Schema::hasColumn('kyc_documents', 'ocr_status')) {
                $t->enum('ocr_status', [
                    'not_run',        // لم يُشغَّل بعد
                    'success',        // قُرئ بثقةٍ كافية
                    'low_confidence', // قُرئ بثقةٍ دون الحدّ — يُعرَض ولا يُملأ
                    'failed',         // شُغِّل ولم يُخرج شيئاً (صورة رديئة)
                    'unavailable',    // المحرّك غير مثبَّت — عطلُ خادمٍ لا وثيقة
                ])->default('not_run')->index()->after('status');
            }

            if (!Schema::hasColumn('kyc_documents', 'ocr_extracted')) {
                // مُشفَّر: الاسم ورقم الهوية وتاريخ الميلاد بياناتٌ شخصية
                // كالصورة نفسها. وحفظُها نصّاً صريحاً بجانب ملفٍّ مشفَّر
                // يُبطل تشفير الملفّ — من يقرأ الجدول يحصل على المضمون بلا
                // أن يفكّ شيئاً.
                $t->text('ocr_extracted')->nullable()->after('ocr_status');
                $t->text('verified_fields')->nullable()->after('ocr_extracted');
            }

            if (!Schema::hasColumn('kyc_documents', 'ocr_confidence')) {
                $t->decimal('ocr_confidence', 5, 2)->nullable()->after('verified_fields');
                $t->string('ocr_engine', 60)->nullable()->after('ocr_confidence');
                $t->timestamp('ocr_ran_at')->nullable()->after('ocr_engine');
            }

            if (!Schema::hasColumn('kyc_documents', 'ocr_findings')) {
                // ملاحظاتٌ حتميّة لا تقديرية: «الوثيقة منتهية» و«الاسم لا
                // يطابق الحساب». تُحسب آلياً وتُعرَض للمراجع، ولا تقرّر عنه.
                $t->json('ocr_findings')->nullable()->after('ocr_ran_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('kyc_documents', function (Blueprint $t) {
            foreach ([
                'ocr_status', 'ocr_extracted', 'verified_fields',
                'ocr_confidence', 'ocr_engine', 'ocr_ran_at', 'ocr_findings',
            ] as $col) {
                if (Schema::hasColumn('kyc_documents', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
