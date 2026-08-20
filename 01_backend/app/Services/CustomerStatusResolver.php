<?php

namespace App\Services;

use App\Models\Aml\AmlInvestigation;
use App\Models\Aml\AmlUserRiskProfile;
use App\Models\KycDocument;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-CUSTOMER-CENTER-001 — حالة العميل تُحسب ولا تُخزَّن.
 *
 * الوثيقة (الفصل ٠٢) تطلب عشر حالات. وثمانٍ منها **حقائق قائمة في مكانٍ
 * آخر** — التجميد، والقائمة السوداء، والعقوبات، وحالة الهوية، والتحقيق
 * المفتوح. وعمودٌ يُخزّنها يصير مصدر حقيقةٍ ثانياً ينحرف عن الأوّل: يُجمَّد
 * الحساب من لوحة الدعم ولا يُحدَّث العمود، فتقول الشاشة «نشط» والحساب
 * مجمَّد.
 *
 * ══════════════════════════════════════════════════════════════
 * **والأولويّة بين الحالات ليست ترتيباً اعتباطياً:**
 *
 * عميلٌ مدرَجٌ في القائمة السوداء **وهويّته معلّقة** يجب أن يُعرَض
 * «مدرَج في القائمة السوداء»، لا «هويّة معلّقة». فالأخيرة تدعو الموظّف إلى
 * أن يطلب منه مستنداً ويكمل معه، والأولى تقول له: قف.
 *
 * فالقاعدة أنّ **الأشدّ تقييداً يظهر**، لا الأحدث ولا الأول. وأيّ ترتيبٍ
 * آخر يجعل الشاشة تُخفي أخطر ما تعرفه عن العميل خلف أقلّه أهمّية.
 * ══════════════════════════════════════════════════════════════
 */
class CustomerStatusResolver
{
    public const ACTIVE = 'ACTIVE';
    public const INACTIVE = 'INACTIVE';
    public const SUSPENDED = 'SUSPENDED';
    public const FROZEN = 'FROZEN';
    public const UNDER_REVIEW = 'UNDER_REVIEW';
    public const BLACKLISTED = 'BLACKLISTED';
    public const KYC_PENDING = 'KYC_PENDING';
    public const KYC_REJECTED = 'KYC_REJECTED';
    public const DECEASED = 'DECEASED';
    public const CLOSED = 'CLOSED';

    public const LABELS = [
        self::ACTIVE => 'نشط',
        self::INACTIVE => 'غير نشط',
        self::SUSPENDED => 'موقوف',
        self::FROZEN => 'مجمَّد',
        self::UNDER_REVIEW => 'قيد المراجعة',
        self::BLACKLISTED => 'مدرَج في القائمة السوداء',
        self::KYC_PENDING => 'هويّة معلّقة',
        self::KYC_REJECTED => 'هويّة مرفوضة',
        self::DECEASED => 'متوفّى',
        self::CLOSED => 'مغلق',
    ];

    /** لونٌ لكلّ حالة — الأشدّ أحمر، ولا يُترك للواجهة أن تجتهد. */
    public const SEVERITY = [
        self::CLOSED => 'dark',
        self::DECEASED => 'dark',
        self::BLACKLISTED => 'danger',
        self::FROZEN => 'danger',
        self::KYC_REJECTED => 'warning',
        self::UNDER_REVIEW => 'warning',
        self::SUSPENDED => 'warning',
        self::KYC_PENDING => 'info',
        self::INACTIVE => 'secondary',
        self::ACTIVE => 'success',
    ];

    /**
     * @return array{status: string, label: string, severity: string, reasons: list<string>}
     */
    public function resolve(User $user): array
    {
        // كلّ الأسباب تُجمع لا الفائز وحده: موظّفٌ يرى «مجمَّد» ولا يعرف أنّ
        // هويّته مرفوضة أيضاً سيرفع التجميد ثمّ يتفاجأ بأنّ الحساب ما زال
        // محدوداً.
        $reasons = [];
        $status = null;

        $note = function (string $candidate, string $why) use (&$status, &$reasons): void {
            $reasons[] = $why;
            $status ??= $candidate;   // الأوّل يفوز — والترتيب من الأشدّ
        };

        // ١) نهائيّ: لا شيء بعده.
        if ($user->lifecycle_state === 'closed') {
            $note(self::CLOSED, 'الحساب مغلق نهائياً');
        }
        if ($user->lifecycle_state === 'deceased') {
            $note(self::DECEASED, 'مُعلَّم كمتوفّى — لا تُنفَّذ عليه عمليات');
        }

        // ٢) القائمة السوداء والعقوبات: «قف» لا «أكمل معه».
        if (Schema::hasColumn('users', 'sanction_status') && $user->sanction_status === 'blocked') {
            $note(self::BLACKLISTED, 'مطابقة عقوبات مؤكَّدة');
        }
        if (AmlUserRiskProfile::where('user_id', $user->id)
            ->where('manual_override', 'blacklist')->exists()) {
            $note(self::BLACKLISTED, 'مُدرَج يدوياً في القائمة السوداء');
        }

        // ٣) التجميد.
        if ((int) ($user->is_temp_blocked ?? 0) === 1) {
            $note(self::FROZEN, 'الحساب مجمَّد مؤقتاً');
        }

        // «موقوف» قرارٌ تشغيليّ مستقل عن «غير نشط». وضعه بعد التجميد وقبل
        // التوثيق يحفظ أولوية المنع: لا يظهر للصرّاف "هوية معلّقة" بينما
        // الحساب موقوف صراحةً.
        if ($user->lifecycle_state === 'suspended') {
            $note(self::SUSPENDED, 'الحساب موقوف مؤقتاً بقرار تشغيلي');
        }

        // ٤) تحقيقٌ مفتوح: يُعرَض ولا يمنع — التحقيق ليس إدانة.
        if (Schema::hasTable('aml_investigations')
            && AmlInvestigation::where('subject_user_id', $user->id)->open()->exists()) {
            $note(self::UNDER_REVIEW, 'عليه تحقيق مفتوح في مكافحة غسل الأموال');
        }

        // ٥) الهوية. والمرفوضة قبل المعلّقة: الرفض قرارٌ اتُّخذ، والتعليق
        //    انتظار — ومن يرى «معلّقة» ينتظر، ومن يرى «مرفوضة» يتصرّف.
        if (Schema::hasTable('kyc_documents')) {
            $latestRejected = KycDocument::where('user_id', $user->id)
                ->where('status', KycDocument::STATUS_REJECTED)
                ->orderByDesc('reviewed_at')->first();

            $hasApproved = KycDocument::where('user_id', $user->id)
                ->where('status', KycDocument::STATUS_APPROVED)->exists();

            if ($latestRejected && !$hasApproved) {
                $note(self::KYC_REJECTED, 'آخر مستند هويّة رُفض: ' . ($latestRejected->rejection_reason ?: '—'));
            }
        }

        // **القراءة الصارمة `=== 1` لا `(bool)`** — وهذا فرقٌ يهمّ.
        //
        // العمود `tinyint` وقيمته الافتراضية **٣** لا صفر. والكود الذي يحرس
        // المال يقرؤه `!= 1` (التحويل، والنزاعات، والسحب) — أي أنّ العميل
        // الجديد **ممنوع**. أمّا شاشات الموظّفين فكانت تقرؤه `(bool)`، و٣
        // صادقةٌ منطقياً — فتُظهر «موثَّق ✓» لعميلٍ يمنعه النظام.
        //
        // وأثرُ ذلك يوميّ: موظّف الدعم يرى «موثَّق» فيقول للعميل إنّ مشكلته
        // في مكانٍ آخر، والمانعُ هو الهويّة بعينها.
        if ((int) ($user->is_kyc_verified ?? 0) !== 1) {
            $note(self::KYC_PENDING, 'الهويّة غير موثَّقة بعد');
        }

        // ٦) اليدويّ غير الحرج.
        if ($user->lifecycle_state === 'inactive') {
            $note(self::INACTIVE, 'أُوقف يدوياً عن النشاط');
        }

        return [
            'status' => $status ?? self::ACTIVE,
            'label' => self::LABELS[$status ?? self::ACTIVE],
            'severity' => self::SEVERITY[$status ?? self::ACTIVE],
            // كلّ ما يخصّ العميل، لا سبب الحالة الظاهرة وحده.
            'reasons' => $reasons,
        ];
    }
}
