<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-KYC-INTL-001 — **حقولُ «اعرف عميلك» الرقابيّة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **المصدر:** نموذجُ فتح حساب أفراد في بنك عدن للتمويل الأصغر، أرسله
 * صاحبُ المشروع وطلب تطبيقَه. وقِيست الفجوةُ حقلاً حقلاً قبل كتابة
 * هجرةٍ واحدة — **فالنموذجُ فيه أكثرُ من ثلاثين حقلاً وليس كلُّها
 * لازماً لمحفظة، وكلُّ حقلٍ زائدٍ يُطيل التسجيلَ ويرفع الانسحاب.**
 *
 * **فأُخذ ما يخدم أحدَ أمرين، ورُدّ ما عداه:**
 *
 *   ① **إلزامٌ رقابيّ** — لا يقوم امتثالٌ بدونه
 *   ② **قرارُ مخاطرَ يُبنى عليه سقف** — دخلٌ ومصدرُه وغرضُ الحساب
 *
 * **ورُدّت أربعة صراحةً** لئلّا يُعاد النظرُ فيها من الصفر: هاتفُ
 * المنزل، والمؤهّلُ العلميّ، ونوعُ الحساب المصرفيّ (جارٍ/توفير — ولا
 * مقابلَ له في محفظة)، وبريدُ جهة العمل. لا واحدَ منها يخدم محفظةً ولا
 * رقابة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وأخطرُ ما في النموذج — وأوّلُ ما نُقل: `is_pep`.**
 *
 * «هل تشغل أنت أو أحدُ أقاربك منصباً سياسيّاً؟» ليس سؤالاً تكميليّاً —
 * هو **الحقلُ الذي تقوم عليه العنايةُ الواجبةُ المشدّدة** في كلّ نظامٍ
 * لمكافحة غسل الأموال. وغيابُه يعني أنّ المنصّةَ لا تستطيع أن تقول إنّها
 * فحصت، لا أنّها فحصت فلم تجد. (القاعدة السابعة.)
 *
 * **وثلاثيُّ الحالة لا ثنائيّ:** `null` = **لم يُسأل** · `0` = سُئل
 * فأنكر · `1` = أقرّ. **و«لم يُسأل» ليس «لا»** — والفرقُ هو كلُّ ما
 * يحتاجه المدقّق: الأوّلُ ثغرةٌ في الإجراء، والثاني إجابةٌ تُراجَع.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والاسمُ الرباعيُّ يُخزَّن مفكَّكاً لا مدموجاً.**
 *
 * كان التطبيقُ يجمع أربعةَ حقولٍ في `l_name` بفواصل. **ومطابقةُ قوائم
 * العقوبات تحتاج المقاطعَ منفصلة** — «راشد محمد عوض» و«راشد عوض محمد»
 * شخصان، والدمجُ يُخفي الفرق. ومعه `name_en`: **لا فحصَ عقوباتٍ بلا
 * صيغةٍ لاتينيّة**، فالقوائمُ الدوليّةُ كلُّها بها.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والعنوانُ يُهيكَل ولا يبقى نصّاً حرّاً.**
 *
 * `address` نصٌّ حرٌّ لا يُقارَن ولا يُفهرَس ولا يُبنى عليه تحقّقٌ من
 * السكن. والنموذجُ يفصله: مديريّة · حيّ · أقرب معلَم · نوع السكن.
 * **والمديريّةُ خاصّةً** تُبنى عليها تغطيةُ الخدمة داخل المحافظة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولا عمودَ إلزاميّاً واحداً.** كلُّها `nullable` عمداً: مئاتُ
 * الحسابات قائمةٌ اليوم بلا هذه البيانات، وعمودٌ إلزاميٌّ يُسقط الهجرةَ
 * أو يملأ الفراغَ بقيمةٍ مخترَعة. **والفراغُ يُقال فراغاً** — وشاشةُ
 * الاعتماد هي التي تطالب به، لا القاعدة.
 */
return new class extends Migration
{
    /** الحقلُ ← تعريفُه. تُقرأ مرّةً في الإضافة ومرّةً في الحذف. */
    private const STRINGS = [
        // ① الهويّة — ومطابقةُ العقوبات تحتاج المقاطعَ منفصلةً ولاتينيّة
        'name_en' => 150,
        'father_name' => 60,
        'grandfather_name' => 60,
        'country_of_birth' => 60,
        'dual_nationality' => 60,
        'id_place_of_issue' => 80,
        'marital_status' => 20,

        // ② العنوان مُهيكَلاً — والمديريّةُ تُبنى عليها تغطيةُ الخدمة
        'residence_district' => 80,
        'residence_area' => 120,
        'residence_landmark' => 150,
        'housing_type' => 20,

        // ③ العمل ومصدرُ المال — وعليهما يُبنى السقف
        'employer_name' => 150,
        'job_title' => 80,
        'work_address' => 200,
        'income_source' => 30,
        'account_purpose' => 60,

        // ④ مرجعٌ ثانٍ — النموذجُ يطلب اثنين وعندنا واحد
        'kin2_name' => 150,
        'kin2_phone' => 30,
        'kin2_relation' => 60,

        // ⑤ والمنصبُ السياسيُّ حين يُقرّ به
        'pep_position' => 200,
    ];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (self::STRINGS as $column => $length) {
                if (! Schema::hasColumn('users', $column)) {
                    $table->string($column, $length)->nullable();
                }
            }

            // **والدخلُ رقمٌ يُحسب عليه، لا نصٌّ يُقرأ.** فسقفُ المعاملات
            // المتوقَّع يُشتقّ منه، ونصٌّ حرٌّ («مئة ألف تقريباً») لا
            // يُقارَن بشيء. والعملةُ تُقال ولا تُفترَض — القاعدةُ نفسُها
            // التي حكمت أسعارَ الباقات.
            if (! Schema::hasColumn('users', 'monthly_income')) {
                $table->decimal('monthly_income', 15, 2)->nullable();
            }

            if (! Schema::hasColumn('users', 'monthly_income_currency')) {
                $table->string('monthly_income_currency', 3)->nullable();
            }

            // **وثلاثيُّ الحالة: `null` لم يُسأل · 0 أنكر · 1 أقرّ.**
            // و«لم يُسأل» ليس «لا» — والفرقُ هو كلُّ ما يحتاجه المدقّق.
            if (! Schema::hasColumn('users', 'is_pep')) {
                $table->boolean('is_pep')->nullable();
            }

            // ومتى أُقرّ به — فإقرارٌ بلا تاريخٍ لا يُراجَع.
            if (! Schema::hasColumn('users', 'kyc_fields_updated_at')) {
                $table->timestamp('kyc_fields_updated_at')->nullable();
            }
        });

        // **ويُفهرَس ما يُبحث به فعلاً**: مطابقةُ العقوبات تمرّ على
        // `name_en`، والعنايةُ المشدّدةُ تسأل عن `is_pep` وحدَها.
        Schema::table('users', function (Blueprint $table) {
            if (! $this->hasIndex('users', 'users_name_en_idx')) {
                $table->index('name_en', 'users_name_en_idx');
            }

            if (! $this->hasIndex('users', 'users_is_pep_idx')) {
                $table->index('is_pep', 'users_is_pep_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['users_name_en_idx', 'users_is_pep_idx'] as $index) {
                if ($this->hasIndex('users', $index)) {
                    $table->dropIndex($index);
                }
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $columns = array_merge(array_keys(self::STRINGS), [
                'monthly_income', 'monthly_income_currency', 'is_pep',
                'kyc_fields_updated_at',
            ]);

            $table->dropColumn(array_values(array_filter(
                $columns, fn ($c) => Schema::hasColumn('users', $c),
            )));
        });
    }

    private function hasIndex(string $table, string $index): bool
    {
        return collect(\Illuminate\Support\Facades\DB::select(
            "SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index],
        ))->isNotEmpty();
    }
};
