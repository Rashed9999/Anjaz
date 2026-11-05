<?php

namespace App\Services\Kyc;

use App\Models\User;
use App\Services\EncryptionService;
use App\Services\PiiAccessAuditService;

/**
 * AMIAL-KYC-DUP-001 — **رقمُ الهويّة يُسأل قبل الاعتماد.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الطلب:** «فلتر في الهويّات قبل اعتماد التوثيق — يُدخَل رقمُ الهويّة،
 * فإذا سُجّلت سابقاً تظهر لمن، وإذا لا فعندها تُعتمد».
 *
 * **وقِيس أوّلاً، فنصفُ الفكرة مبنيٌّ سلفاً:** `DocumentReuseService`
 * يقارن أرقامَ الهويّات ويُغلق الطريقَ عند التطابق، وهو موصولٌ بلوحة
 * التحقّق عبر `KycEvidenceService::blockers()`.
 *
 * **والعطلُ أنّ ما يُقارَن لا وجودَ له:**
 *
 *     `->national_id =` في المشروع كلِّه   →  صفرُ إسناد
 *     `national_id_blind_index`            →  فارغٌ لكلّ حساب
 *     `verified_fields` في متحكّمٍ أو قالب  →  صفر
 *     رقمُ الهويّة في التسجيل               →  `sometimes|nullable`
 *
 * فالمقارنةُ لا تقع إلّا إن قرأ OCR الرقمَ — وصورةُ هويّةٍ يمنيّةٍ باهتةٍ
 * لا يقرؤها. **والمراجعُ ينظر إلى البطاقة والرقمُ أمام عينيه، ولا خانةَ
 * يستقبله بها النظام.** فهذه الخدمةُ هي تلك القناة: تجعل عينَ الإنسان
 * مصدرَ البيانات الذي يفتقده الفحصُ الآليّ.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وثلاثةُ حدودٍ تحكمها، ولكلٍّ ثمنٌ لو غاب:**
 *
 *   ① **لا يُغلَق الطريقُ على كلّ تطابق.** صاحبُ متجرٍ له حسابُ عميلٍ
 *      شخصيٌّ بهويّته نفسِها أمرٌ مشروعٌ وشائع. فالتطابقُ **لحسابٍ آخر**
 *      يُقال ويُترك القرارُ للمراجع، ولا يُمنَع آليّاً — وحاجزٌ يشلّ
 *      عملاً سليماً أسوأ من ثغرةٍ تُكتشَف بتدقيق.
 *
 *   ② **«تظهر لمن» تكشف هويّةَ شخصٍ ثالثٍ لموظّف.** فلا يخرج من هنا
 *      اسمٌ كاملٌ ولا هاتفٌ ولا رقمُ هويّةٍ خام — بل اسمٌ مقنَّعٌ وحالةُ
 *      الحساب وتاريخُه ومعرّفُه. **وكلُّ بحثٍ يُسجَّل في
 *      `pii_access_logs`** حتّى الذي لا يجد شيئاً: من بحث عن هويّةٍ
 *      بعينها فقد استعلم عن صاحبها، وُجد أو لم يوجد.
 *
 *   ③ **والرقمُ يُخزَّن لا يُرمى.** فما يكتبه المراجعُ يملأ العمودَ
 *      المفهرَس، فيقوى الفحصُ الآليُّ مع كلّ اعتمادٍ بدل أن يبقى معطّلاً.
 *      وهذا ما تفعله `remember()`.
 *
 * يظهر في : لوحة الإدارة ← التحقّق من التجّار ← بطاقةُ «فحصُ الهويّة».
 * وفي التطبيق: لا — إشارةُ احتيالٍ لا تُعرَض لمن قد يكون مصدرَها.
 * ويُوصل إليه من : زرِّ «افحص» بجانب حقل رقم الهويّة في اللوحة نفسِها.
 *
 * @see \Tests\Feature\IdentityLookupGuardTest
 */
class IdentityLookupService
{
    /**
     * **أقصرُ رقمٍ يُقبل للبحث.**
     *
     * «1» و«12» تصادفاتٌ لا انتحال، والبحثُ بها يُخرج نصفَ القاعدة
     * فيُعوّد المراجعَ أن يتجاهل النتيجة. وهو الحدُّ نفسُه الذي يستعمله
     * `DocumentReuseService`.
     */
    public const MIN_DIGITS = 5;

    public function __construct(
        private readonly EncryptionService $enc,
        private readonly PiiAccessAuditService $pii,
    ) {}

    /**
     * **هل هذه الهويّةُ مسجَّلةٌ سابقاً؟ ولمن؟**
     *
     * @param  User  $subject  الحسابُ قيدَ المراجعة — يُستثنى من النتائج.
     * @param  User  $reviewer  من يبحث — يُسجَّل في أثر الاطّلاع.
     * @return array{ok:bool,reason:?string,digits:?string,masked:?string,
     *               found:bool,matches:array<int,array<string,mixed>>,verdict:string}
     */
    public function search(string $raw, User $subject, User $reviewer): array
    {
        $digits = preg_replace('/[^\d]/', '', EncryptionService::foldDigits(trim($raw)));

        if (mb_strlen((string) $digits) < self::MIN_DIGITS) {
            return $this->refuse('رقمُ الهويّة أقصرُ من '.self::MIN_DIGITS
                .' أرقام — ورقمٌ قصيرٌ يُطابق الغرباء فلا يُبحَث به.');
        }

        $index = $this->enc->blindIndex($digits, 'national_id');

        if ($index === null) {
            return $this->refuse('تعذّر حسابُ بصمة البحث — راجع إعدادَ التشفير.');
        }

        // ② **الأثرُ يُكتب قبل النتيجة ومهما كانت** — فبحثٌ لم يجد شيئاً
        // استعلامٌ عن شخصٍ أيضاً. وأثرٌ يُكتب عند الوجود وحدَه يُخفي
        // مَن يفتّش عن الناس ولا يجد.
        // **و`access_type` عمودٌ محصورٌ بأربع قيَم** (`view` · `decrypt_file`
        // · `export` · `search`). وأوّلُ صياغةٍ مرّرت `kyc_duplicate_lookup`
        // فقُطعت في القاعدة، **و`logAccess` تبتلع الاستثناء عمداً** لئلّا
        // يُمنَع موظّفٌ من خدمة عميل — فلم يُكتب أثرٌ ولم يظهر خطأ. ولم
        // يكشفه إلّا الحارسُ أدناه. فالنوعُ من القائمة، والتفصيلُ في السبب.
        $this->pii->logAccess(
            (int) $reviewer->id, 'user', (int) $subject->id, 'national_id',
            'search',
            'فحصُ تكرار رقم الهويّة قبل اعتماد التوثيق');

        $rows = User::query()
            ->where('national_id_blind_index', $index)
            ->where('id', '!=', $subject->id)
            ->orderBy('id')
            ->limit(20)
            ->get(['id', 'type', 'is_active', 'is_kyc_verified', 'created_at',
                'national_id_masked', 'f_name', 'l_name', 'phone_masked']);

        $matches = $rows->map(fn (User $u) => [
            'id' => $u->id,
            // ② اسمٌ مقنَّعٌ — يكفي ليعرف المراجعُ أنّه شخصٌ آخرُ حقيقيّ،
            // ولا يكفي ليصير البحثُ أداةَ استعلامٍ عن الناس.
            'name_masked' => $this->maskName($u),
            'kind' => $this->kindOf($u),
            'is_active' => (bool) $u->is_active,
            'is_verified' => (bool) $u->is_kyc_verified,
            'registered_at' => $u->created_at?->format('Y-m-d'),
        ])->all();

        return [
            'ok' => true,
            'reason' => null,
            'digits' => $digits,
            'masked' => $this->enc->maskNationalId($digits),
            'found' => $matches !== [],
            'matches' => $matches,

            // ① **حكمٌ يُقرأ، لا منعٌ يقع.** والقرارُ للمراجع.
            'verdict' => $matches === []
                ? 'CLEAR'
                : 'DUPLICATE',
        ];
    }

    /**
     * ③ **يُحفَظ ما كتبه المراجع** — فيقوى الفحصُ الآليُّ بعده.
     *
     * ولا يُكتب فوق رقمٍ قائمٍ مختلف: تصحيحُ رقمِ هويّةٍ قرارٌ له بابُه
     * (طلبُ تغيير بيانات)، وكتابتُه من هنا صامتةً تُضيع الأصل.
     *
     * @return array{stored:bool,reason:?string}
     */
    public function remember(string $digits, User $subject): array
    {
        $existing = preg_replace('/[^\d]/', '',
            EncryptionService::foldDigits((string) $subject->identification_number));

        if ($existing !== '' && $existing !== $digits) {
            return ['stored' => false, 'reason' => 'للحساب رقمُ هويّةٍ مختلفٌ '
                .'مسجَّلٌ سلفاً — يُغيَّر من طلب تغيير البيانات لا من هنا.'];
        }

        if ($existing === $digits) {
            return ['stored' => false, 'reason' => 'الرقمُ نفسُه مسجَّلٌ سلفاً.'];
        }

        $subject->identification_number = $digits;
        $subject->save();

        return ['stored' => true, 'reason' => null];
    }

    /** @return array<string,mixed> */
    private function refuse(string $why): array
    {
        return ['ok' => false, 'reason' => $why, 'digits' => null, 'masked' => null,
            'found' => false, 'matches' => [], 'verdict' => 'REFUSED'];
    }

    /**
     * اسمٌ يكفي للتمييز ولا يكفي للتعريف — أوّلُ حرفٍ من كلّ جزء.
     *
     * **وحسابٌ بلا اسمٍ يُقال إنّه بلا اسم** ولا يُعرَض فراغاً يُقرأ
     * عطلاً في الشاشة. (القاعدة السابعة.)
     */
    private function maskName(User $u): string
    {
        $parts = array_filter([$u->f_name, $u->l_name],
            fn ($p) => trim((string) $p) !== '');

        if ($parts === []) {
            return 'حسابٌ بلا اسمٍ مسجَّل';
        }

        return implode(' ', array_map(
            fn ($p) => mb_substr(trim((string) $p), 0, 1).'…', $parts));
    }

    private function kindOf(User $u): string
    {
        return match ((int) $u->type) {
            ADMIN_TYPE => 'إدارة',
            AGENT_TYPE => 'وكيل',
            CUSTOMER_TYPE => 'عميل',
            MERCHANT_TYPE => 'تاجر',
            4 => 'موظّف نقطة بيع',
            default => 'نوعٌ غيرُ معروف ('.(int) $u->type.')',
        };
    }
}
