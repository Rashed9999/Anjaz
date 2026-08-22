<?php

namespace App\Support\Kyc;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-KYC-INTL-002 — **جردُ حقول «اعرف عميلك» — مصدرٌ واحدٌ للحقيقة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ صنفٌ لا قائمةٌ في كلّ موضع:** الحقلُ الواحد يمرّ بأربعة مواضع —
 * قاعدةُ التحقّق، والحفظ، وشاشةُ الاعتماد، والتطبيق. **وقائمتان تفترقان
 * بحقلٍ تُنتجان حقلاً يُرسَل ولا يصل**: النموذجُ يسأل عنه، والمستعملُ
 * يكتبه، ولا شيء يحفظه — ولا خطأَ في أيّ سجلّ.
 *
 * **وهذا العطلُ بعينه وقع في هذا المشروع من قبل**: `'metadata' => [...]`
 * في سبعةَ عشرَ موضعاً و`record()` تقرأ `context` — فسقط `staff_id`
 * و`branch_id` و`opening_float` كلُّها بصمت. السجلُّ يقول «فُتحت
 * ورديّة» ولا يقول من ولا أين ولا بكم.
 *
 * **فالجردُ ها هنا، ويُقرأ ولا يُكرَّر.**
 */
final class KycProfileFields
{
    /**
     * مصادرُ الدخل — **من النموذج نفسِه لا من اجتهاد**.
     *
     * (استثمار · إيجارات · بيع أصول · راتب · ميراث · تجارة · أخرى)
     */
    public const INCOME_SOURCES = [
        'salary', 'business', 'investment', 'rent', 'asset_sale',
        'inheritance', 'remittance', 'other',
    ];

    /** الغرضُ من فتح الحساب — سؤالٌ رقابيٌّ لا تكميليّ. */
    public const ACCOUNT_PURPOSES = [
        'savings', 'salary', 'business', 'remittance', 'payments', 'other',
    ];

    /**
     * الحقولُ النصّيّةُ التي تُنقل كما وصلت.
     *
     * **ولا يدخلها `is_pep` ولا `monthly_income`** — لكلٍّ منهما معالجةٌ
     * تخصّه، ونقلُهما مع النصوص يُفسد معناهما (انظر أدناه).
     */
    public const TEXT_FIELDS = [
        'name_en', 'father_name', 'grandfather_name', 'country_of_birth',
        'dual_nationality', 'id_place_of_issue', 'marital_status',
        'residence_district', 'residence_area', 'residence_landmark',
        'housing_type', 'employer_name', 'job_title', 'work_address',
        'income_source', 'account_purpose',
        'kin2_name', 'kin2_phone', 'kin2_relation',
    ];

    /**
     * ينقل ما وصل من الطلب إلى الحساب — **ولا يكتب ما لم يصل**.
     *
     * فكتابةُ فراغٍ فوق قيمةٍ قائمةٍ تمحو بياناً بلا أن يطلب أحدٌ محوَه،
     * وهي أخطرُ من ألّا يُحفَظ الجديد.
     */
    public static function fill(User $user, Request $request): bool
    {
        $touched = false;

        foreach (self::TEXT_FIELDS as $field) {
            if (! Schema::hasColumn('users', $field) || ! $request->filled($field)) {
                continue;
            }

            $user->{$field} = trim((string) $request->input($field));
            $touched = true;
        }

        // ══════════════════════════════════════════════════════════════
        // **والدخلُ رقمٌ ومعه عملتُه — ولا تُفترَض.**
        //
        // «١٠٠٠٠٠» بلا عملةٍ رقمٌ صحيحٌ بمعنىً مجهول: بالريال اليمنيّ
        // دخلٌ متواضع، وبالدولار ثروة. **والتحويلُ الصامتُ يستبدل كذبةً
        // بأطولَ منها عمراً** — وهو الدرسُ نفسُه من عملة الباقات.
        // ══════════════════════════════════════════════════════════════
        if (Schema::hasColumn('users', 'monthly_income') && $request->filled('monthly_income')) {
            $user->monthly_income = (float) $request->input('monthly_income');
            $touched = true;

            if (Schema::hasColumn('users', 'monthly_income_currency')) {
                $user->monthly_income_currency = strtoupper(trim(
                    (string) $request->input('monthly_income_currency', 'YER'),
                ));
            }
        }

        // ══════════════════════════════════════════════════════════════
        // **و«لم يُسأل» ليس «لا».**
        //
        // `is_pep` ثلاثيُّ الحالة: `null` لم يُسأل · `false` سُئل فأنكر ·
        // `true` أقرّ. **والفرقُ هو كلُّ ما يحتاجه المدقّق**: الأوّلُ
        // ثغرةٌ في الإجراء، والثاني إجابةٌ تُراجَع.
        //
        // فلا يُكتب `false` لغياب الحقل — وذاك يُحوّل ثغرةً إلى إجابةٍ
        // مطمئنّة، وهو أسوأُ ما يقع في ملفّ امتثال. (القاعدة السابعة.)
        // ══════════════════════════════════════════════════════════════
        if (Schema::hasColumn('users', 'is_pep') && $request->has('is_pep')) {
            $isPep = filter_var($request->input('is_pep'), FILTER_VALIDATE_BOOLEAN);
            $user->is_pep = $isPep;
            $touched = true;

            // **وإقرارٌ بلا منصبٍ ناقص** — «نعم» وحدَها لا تُحقَّق.
            if (Schema::hasColumn('users', 'pep_position')) {
                $user->pep_position = $isPep && $request->filled('pep_position')
                    ? trim((string) $request->input('pep_position'))
                    : ($isPep ? $user->pep_position : null);
            }
        }

        if ($touched && Schema::hasColumn('users', 'kyc_fields_updated_at')) {
            // **وبيانٌ بلا تاريخٍ لا يُراجَع** — فالمدقّقُ يسأل «منذ متى».
            $user->kyc_fields_updated_at = now();
        }

        return $touched;
    }

    /**
     * ما ينقص هذا الحسابَ من الحقول الرقابيّة — **يُقال ولا يُخمَّن**.
     *
     * وهذه هي البوّابةُ الحقيقيّة: القاعدةُ تقبل الفراغَ، **وشاشةُ
     * الاعتماد هي التي تطالب**. فإلزامُ الحقول في التسجيل يُقفل البابَ
     * على مئات الحسابات القائمة؛ وإلزامُها في الاعتماد يمنع دخولَ
     * ملفٍّ ناقصٍ إلى مرحلةٍ يُبنى عليها سقفُ مال.
     *
     * @return list<string> أسماءُ الحقول الناقصة بالعربيّة
     */
    public static function missingFor(User $user): array
    {
        $required = [
            'name_en' => 'الاسم بالإنجليزيّة (لا فحصَ عقوباتٍ بدونه)',
            'father_name' => 'اسم الأب',
            'grandfather_name' => 'اسم الجدّ',
            'residence_district' => 'المديريّة',
            'income_source' => 'مصدر الدخل',
            'account_purpose' => 'الغرض من فتح الحساب',
        ];

        $missing = [];

        foreach ($required as $field => $label) {
            if (Schema::hasColumn('users', $field)
                && trim((string) ($user->{$field} ?? '')) === '') {
                $missing[] = $label;
            }
        }

        // **والمنصبُ السياسيُّ يُسأل عنه ولو كان الجوابُ لا.** غيابُ
        // الجواب ليس جواباً بالنفي.
        if (Schema::hasColumn('users', 'is_pep') && $user->is_pep === null) {
            $missing[] = 'الإفصاح عن المنصب السياسيّ (لم يُسأل بعد)';
        }

        if (Schema::hasColumn('users', 'is_pep') && $user->is_pep
            && trim((string) ($user->pep_position ?? '')) === '') {
            $missing[] = 'المنصب السياسيّ المُقرّ به';
        }

        return $missing;
    }
}
