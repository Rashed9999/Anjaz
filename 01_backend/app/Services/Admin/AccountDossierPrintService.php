<?php

namespace App\Services\Admin;

use App\Models\KycDocument;
use App\Models\RegistrationDossier;
use App\Models\User;
use App\Services\KycDocumentService;
use App\Support\ArabicPdf;
use App\Support\YemenGovernorates;

/**
 * AMIAL-ACCOUNT-PRINT-001 — **الأرشيفُ مبنيٌّ، ولا زرَّ يطبعه من الحساب.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمنُ الذي دُفع.** قال صاحبُ المشروع: «لو أرادت الإدارةُ طباعةَ
 * معلومات التسجيل للحساب **مع صور الوثائق** لا يوجد زر — مع أنّه تمّ
 * تأسيسُ الأرشيف سابقاً».
 *
 * **وكلامُه صحيحٌ في شقّيه، وقِيس ولم يُفترَض:**
 *
 *   ① **الطباعةُ مبنيّةٌ ولا يُوصَل إليها من الحساب.**
 *      `registration-dossiers/{reference}/pdf` موجودٌ ويعمل، **وبابُه
 *      الوحيد صفحةُ «سجل ملفات فتح الحسابات»** — أي أنّ المراجعَ الواقفَ
 *      على حسابٍ بعينه في لوحة التحقّق أو مركز الحساب عليه أن يخرج،
 *      ويفتح سجلّاً آخر، ويبحث عن المرجع بين مئة. **والجدولُ المعروضُ في
 *      نافذة الحساب يطبع المرجعَ نصّاً لا رابطاً** — يراه ولا يفتحه.
 *
 *   ② **وليس في المطبوع صورةُ وثيقةٍ واحدة.** القالبُ القديم حقولٌ
 *      نصّيّةٌ فقط: لا `kyc_documents` ولا الصورُ القديمة. ووثيقةٌ بلا
 *      صورتها ليست ملفَّ فتح حساب — هي استمارةٌ فارغة.
 *
 * **وثالثةٌ لم تكن في السؤال وظهرت بالقياس:** حسابٌ سُجّل قبل أن يُبنى
 * الأرشيف — أو أُنشئ من مسارٍ لا يؤرشف — **لا ملفَّ له إطلاقاً**، فالطباعةُ
 * بالمرجع لا تجد شيئاً. فيُبنى المطبوعُ على **الحساب** لا على المرجع:
 * الهويّةُ من `users` دائماً، واللقطةُ الأرشيفيّةُ إن وُجدت، **ويُقال
 * صراحةً حين لا توجد** — ولا يُترك المراجعُ أمام ورقةٍ ناقصةٍ بلا سبب.
 * (القاعدة السابعة: الغيابُ يُقال ولا يُقرأ صفراً.)
 *
 * **والصورُ تُضمَّن ولا تُربَط.** رابطٌ في PDF يحتاج جلسةَ إدارةٍ حيّةً
 * ليُفتَح — فورقةٌ تُطبَع اليومَ وتُقرأ بعد سنةٍ في ملفٍّ ورقيّ تكون
 * صورُها مربّعاتٍ فارغة. فتُفكّ الوثيقةُ وتُكتب في الصفحة نفسِها.
 *
 * يظهر في : لوحة الإدارة ← لوحة التحقّق (زرّ «طباعة ملفّ الحساب») ·
 * ومركزُ الحساب · ومركزُ التاجر. وفي التطبيق: لا — مطبوعٌ إداريٌّ محض.
 * ويُوصل إليه من : نافذةِ الحساب مباشرةً، لا من سجلٍّ آخر.
 *
 * @see \Tests\Feature\AccountDossierPrintGuardTest
 */
class AccountDossierPrintService
{
    /** حدُّ ما يُضمَّن من صور — وورقةٌ بمئة صورةٍ لا تُطبع ولا تُقرأ. */
    public const MAX_IMAGES = 12;

    /** ما يُقبل تضمينُه صورةً؛ وغيرُه يُذكَر ولا يُرسَم. */
    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(
        private readonly KycDocumentService $kyc,
        private readonly KycEvidenceService $evidence,
    ) {}

    public function render(User $user, ?User $reviewer = null): string
    {
        return ArabicPdf::render(
            view('pdf.account-dossier', $this->data($user, $reviewer))->render(),
            ['format' => 'A4', 'margin' => 12],
        );
    }

    /** @return array<string,mixed> */
    public function data(User $user, ?User $reviewer = null): array
    {
        $dossier = RegistrationDossier::where('subject_user_id', $user->id)
            ->latest('id')->first();

        return [
            'user' => $user,
            'identity' => $this->identity($user),
            'dossier' => $dossier,
            'payload' => $dossier ? (array) $dossier->payload_encrypted : [],
            'evidence' => $this->evidence->for($user, 2, $reviewer),
            'images' => $this->images($user),
            'printed_at' => now()->format('Y-m-d H:i'),
            'printed_by' => $reviewer
                ? (trim((string) ($reviewer->f_name.' '.$reviewer->l_name)) ?: (string) $reviewer->phone)
                : '—',
        ];
    }

    /**
     * **هويّةُ الحساب من `users` دائماً** — فهي موجودةٌ ولو لم يوجد أرشيف.
     *
     * @return array<int,array{0:string,1:string}>
     */
    private function identity(User $user): array
    {
        $governorate = YemenGovernorates::name(
            YemenGovernorates::codeFromName(
                (string) ($user->residence_governorate ?: $user->origin_governorate)));

        $rows = [
            ['الاسم', trim((string) ($user->f_name.' '.$user->l_name)) ?: '—'],
            ['رقم الحساب', (string) ($user->account_number ?: '—')],
            ['رقم الجوال', (string) ($user->phone ?: '—')],
            ['نوع الحساب', $this->typeLabel((int) $user->type)],
            ['رقم الهوية', (string) ($user->identification_number ?: 'غير مسجَّل')],
            ['محافظة السكن', $governorate ?: 'غير محدَّدة'],
            ['المنطقة', (string) ($user->zone_code ?: 'غير محدَّدة')],
            ['حالة الحساب', ((int) $user->is_active === 1) ? 'نشط' : 'موقوف'],
            ['توثيق الهويّة', ((int) ($user->is_kyc_verified ?? 0) === 1)
                ? 'موثَّق' : 'غير موثَّق'],
            ['فئة التوثيق', (string) ((int) ($user->kyc_tier ?? 0))],
            ['تاريخ الإنشاء', optional($user->created_at)->format('Y-m-d H:i') ?: '—'],
        ];

        return $rows;
    }

    private function typeLabel(int $type): string
    {
        return match ($type) {
            MERCHANT_TYPE => 'تاجر / منشأة',
            AGENT_TYPE => 'وكيل',
            CUSTOMER_TYPE => 'عميل فرد',
            ADMIN_TYPE => 'موظّف منصّة',
            default => 'غير معروف ('.$type.')',
        };
    }

    /**
     * **صورُ الوثائق مضمَّنةً في الورقة** — الحديثةُ والقديمةُ معاً،
     * وكلٌّ موسومةٌ بمصدرها وحالتها.
     *
     * وما تعذّر فكُّه **يُذكَر سطراً ولا يُبتلَع**: صفحةٌ ينقصها مستندٌ
     * بلا ذكرٍ تُقرأ «لم يُرفَع»، والفرقُ بينهما تحقيقٌ كامل.
     *
     * @return array<int,array<string,mixed>>
     */
    private function images(User $user): array
    {
        $out = [];

        foreach (KycDocument::where('user_id', $user->id)->orderBy('id')->get() as $doc) {
            if (count($out) >= self::MAX_IMAGES) {
                break;
            }

            $row = [
                'label' => KycDocument::TYPE_LABELS[$doc->doc_type] ?? $doc->doc_type,
                'source' => 'سجلّ الوثائق',
                'status' => $this->statusLabel($doc),
                'uploaded_at' => optional($doc->created_at)->format('Y-m-d H:i') ?: '—',
                'data_uri' => null,
                'note' => null,
            ];

            $mime = (string) ($doc->original_mime ?: 'image/jpeg');

            if (! in_array($mime, self::IMAGE_MIMES, true)) {
                $row['note'] = 'ملفٌّ ليس صورةً ('.$mime.') — يُفتَح من اللوحة.';
                $out[] = $row;

                continue;
            }

            try {
                $binary = $this->kyc->decrypt($doc);
                $row['data_uri'] = 'data:'.$mime.';base64,'.base64_encode($binary);
            } catch (\Throwable $e) {
                // **ويُقال، ولا يُترك مربّعاً فارغاً بلا سبب.**
                $row['note'] = 'تعذّر فكُّ تشفير الملفّ — يُراجَع في اللوحة.';
            }

            $out[] = $row;
        }

        // **والقديمُ يُطبَع موسوماً ولا يُحتسَب** — كما في لوحة التحقّق.
        $legacy = $user->identification_image_fullpath ?? [];

        foreach (is_array($legacy) ? $legacy : [] as $url) {
            if (count($out) >= self::MAX_IMAGES) {
                break;
            }

            $out[] = [
                'label' => 'صورة هويّة (سجلّ قديم)',
                'source' => 'العمود القديم',
                'status' => 'لا تُحتسَب في الاكتمال',
                'uploaded_at' => '—',
                'data_uri' => null,
                'note' => (string) $url,
            ];
        }

        return $out;
    }

    private function statusLabel(KycDocument $doc): string
    {
        return match ($doc->status) {
            KycDocument::STATUS_APPROVED => $doc->isUsable() ? 'معتمَدة' : 'معتمَدة — منتهية',
            KycDocument::STATUS_REJECTED => 'مرفوضة',
            KycDocument::STATUS_SUPERSEDED => 'استُبدلت',
            default => 'تنتظر المراجعة',
        };
    }
}
