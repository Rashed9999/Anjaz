<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-CUSTOMER-CENTER-001 — مركز العملاء (الفصل ٠٢).
 *
 * **الحالات العشر لا تُخزَّن في عمود — وهذا أهمّ قرارٍ في هذه الهجرة.**
 *
 * تطلب الوثيقة عشر حالاتٍ للعميل: نشط، غير نشط، موقوف، مجمَّد، قيد
 * المراجعة، مدرَج في القائمة السوداء، هويّة معلّقة، هويّة مرفوضة، متوفّى،
 * مغلق.
 *
 * والطريق السهل عمودٌ واحد `status` يُكتب فيه أيّها. وهو خطأ: ثمانٍ من هذه
 * الحالات **مشتقّة من حقائق قائمة في مكانٍ آخر** — التجميد في
 * `is_temp_blocked`، والقائمة السوداء في `aml_user_risk_profiles`،
 * والعقوبات في `sanction_status`، وحالة الهوية في `kyc_documents`، والمراجعة
 * في `aml_investigations`.
 *
 * وعمودٌ يُخزّنها يصير **مصدر حقيقةٍ ثانياً**: يُجمَّد الحساب من لوحة الدعم
 * ولا يُحدَّث العمود، فتقول الشاشة «نشط» والحساب مجمَّد. وهذا الانحراف
 * بالذات هو ما قضينا الجلسة نصلحه في الدفتر.
 *
 * فلا يُخزَّن هنا إلّا ما هو **قرارٌ يدويّ محض** ولا يُشتقّ من شيء: متوفّى،
 * ومغلق، وغير نشط. والباقي يُحسب عند العرض من مصادره.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_notes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->index();
            $t->unsignedBigInteger('author_id')->index();
            $t->text('body');

            // ملاحظةٌ مثبّتة تظهر أوّل ما يُفتح الملفّ: «هذا العميل اتّصل
            // ثلاث مرّات بخصوص نفس المشكلة» معلومةٌ يجب أن تُرى قبل أن يبدأ
            // الموظّف من الصفر مع كلّ مكالمة.
            $t->boolean('is_pinned')->default(false);

            // تُلحَق ولا تُعدَّل: ملاحظةٌ يمكن تحريرها بعد أن يُبنى عليها قرار
            // لا تصلح سجلّاً. والتصحيح بملاحظةٍ جديدة.
            $t->timestamp('created_at')->useCurrent();

            $t->index(['user_id', 'is_pinned']);
        });

        Schema::table('users', function (Blueprint $t) {
            if (!Schema::hasColumn('users', 'lifecycle_state')) {
                // القرارات اليدوية المحضة وحدها — انظر شرح أعلى الملفّ.
                $t->enum('lifecycle_state', ['active', 'inactive', 'deceased', 'closed'])
                    ->default('active')->index()->after('is_temp_blocked');
                $t->timestamp('lifecycle_changed_at')->nullable()->after('lifecycle_state');
                $t->text('lifecycle_reason')->nullable()->after('lifecycle_changed_at');
            }

            if (!Schema::hasColumn('users', 'limit_override')) {
                // حدٌّ خاصٌّ بعميل يتجاوز حدّ فئته.
                //
                // ويُحفَظ منفصلاً عن `kyc_tier_limits` عمداً: تعديلُ حدّ الفئة
                // يغيّر حدود كلّ من فيها، ومن أراد استثناء عميلٍ واحد فسيغيّر
                // حدود الآلاف بلا أن ينتبه.
                $t->json('limit_override')->nullable()->after('lifecycle_reason');
                $t->unsignedBigInteger('limit_override_by')->nullable()->after('limit_override');
                $t->timestamp('limit_override_at')->nullable()->after('limit_override_by');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_notes');

        Schema::table('users', function (Blueprint $t) {
            foreach ([
                'lifecycle_state', 'lifecycle_changed_at', 'lifecycle_reason',
                'limit_override', 'limit_override_by', 'limit_override_at',
            ] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
