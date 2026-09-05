<?php

namespace App\Services\Admin;

use App\Models\KycDocument;
use App\Models\User;
use App\Services\KycDocumentService;
use App\Support\YemenGovernorates;

/**
 * AMIAL-KYC-EVIDENCE-001 — **الدليلُ المعروضُ هو الدليلُ المحكومُ به.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **العطلُ الذي وُلدت منه، وقِيس ولم يُفترَض:**
 *
 *     AdminHubController::verificationJson  →  'documents' =>
 *         $u->identification_image_fullpath        ← العمودُ القديم
 *
 *     KycDocumentService::decideAccountVerification →
 *         completenessFor()  يقرأ  `kyc_documents`  ← السجلُّ الحديث
 *
 * **فالمراجعُ يرى شيئاً ويقرّر على شيءٍ آخر.** وله وجهان، وكلاهما يقع:
 *
 *   ① **يرى صوراً قديمةً فيعتمد** — والوثائقُ الحديثةُ لم تُراجَع سطراً.
 *   ② **أو يرى «لا وثائق مرفوعة» وهي مرفوعة** — رفعها العميلُ من
 *      التطبيق إلى `kyc_documents`، والشاشةُ تقرأ عموداً لا يعرفه ذلك
 *      المسار. فيُترك حسابٌ مكتملٌ في الطابور، أو يُضغط «اعتماد» فيُردّ
 *      برسالةٍ لا يفهم سببَها.
 *
 * **والعلاجُ ليس قائمةً ثانية.** هذه الخدمةُ **تسأل مصدرَ القرار نفسَه**
 * (`KycDocumentService::completenessFor`) ولا تُعيد بناءَ شروطه — فقائمةٌ
 * موازيةٌ تشيخ يومَ يُضاف نوعُ مستندٍ في الخدمة ولا يظهر في الشاشة، وهو
 * العطلُ نفسُه بثوبٍ جديد.
 *
 * **والثلاثةُ سواء: عميلٌ ووكيلٌ وتاجر.** الوثائقُ الشخصيّةُ واحدةٌ لكلّ
 * من يملك محفظة — ولوحةُ التحقّق تُدرج الأنواعَ الثلاثة أصلاً، فكان
 * الدليلُ مكسوراً لثلاثتهم لا للتاجر وحدَه.
 *
 * **والقديمُ يُعرَض ولا يُحتسَب.** صورةٌ في العمود القديم ليست وثيقةً
 * معتمَدة، وطيُّها يُخفي ما قد ينفع المراجع. فتُعرَض موسومةً «قديمة — لا
 * تُحتسَب في الاكتمال». (القاعدة السابعة: الغيابُ والوجودُ يُقالان، ولا
 * يُخلطان.)
 *
 * يظهر في : لوحة الإدارة ← لوحة التحقّق: الحسابات الجديدة · ومركزُ
 * الحساب (نافذةُ المستخدم). ويُوصل إليهما من : مساحة العمل ← العملاء
 * والحسابات.
 *
 * @see \Tests\Feature\KycEvidenceGuardTest
 */
class KycEvidenceService
{
    public function __construct(private KycDocumentService $kyc) {}

    /**
     * الدليلُ الكاملُ لحسابٍ واحد — لأيّ نوعٍ من الثلاثة.
     *
     * @param  User|null  $reviewer  المراجعُ الحاليّ، لفحص المبدأ الرباعيّ.
     * @return array{tier:int,complete:bool,required:array,approved:array,missing:array,
     *               missing_fields:array,documents:array,legacy_images:array,blockers:array}
     */
    public function for(User $user, int $targetTier = 2, ?User $reviewer = null): array
    {
        $completeness = $this->kyc->completenessFor($user, $targetTier);

        return [
            'tier' => $targetTier,
            'complete' => (bool) $completeness['complete'],
            'required' => $this->labels($completeness['required'] ?? []),
            'approved' => $this->labels($completeness['approved'] ?? []),
            'missing' => $this->labels($completeness['missing'] ?? []),
            'missing_fields' => array_values($completeness['missing_fields'] ?? []),
            'documents' => $this->documents($user),
            'legacy_images' => $this->legacy($user),
            'blockers' => $this->blockers($user, $completeness, $targetTier, $reviewer),
        ];
    }

    /**
     * **ما يمنع الاعتمادَ الآن — قبل الضغط لا بعد الرفض.**
     *
     * وهو نسخةُ شروطِ `decideAccountVerification` **بترتيبها نفسِه**: زرٌّ
     * يُضغط فيُردّ يُعلّم المراجعَ أن يجرّب ويرى، وهو أبطأُ من أن يُقرأ
     * ويُصلَح. (وهو الحدُّ نفسُه الذي بُنيت به لافتةُ المحافظة.)
     *
     * @return array<int,string>
     */
    private function blockers(User $user, array $completeness, int $tier, ?User $reviewer): array
    {
        $out = [];

        if ($reviewer && (int) $reviewer->id === (int) $user->id) {
            $out[] = 'لا يعتمد المراجعُ حسابَ نفسِه (المبدأ الرباعيّ).';
        }

        if (! $completeness['complete']) {
            $missing = $this->labels($completeness['missing'] ?? []);

            $out[] = $missing === []
                // **وقائمةٌ فارغةٌ لا تُقرأ «مكتمل»** — الفئةُ بلا مستنداتٍ
                // مطلوبةٍ لا تُعتمد أصلاً، ويُقال ذلك لا يُسكَت عنه.
                ? 'لا مستنداتٍ مطلوبةً لهذه الفئة — لا يتمّ الاعتماد منها.'
                : 'مستنداتٌ ناقصةٌ أو غيرُ معتمَدة: '.implode('، ', $missing);
        }

        // نفسُ فحص `kycStatus`: محافظةُ السكن شرطٌ قبل الاعتماد.
        $governorate = YemenGovernorates::codeFromName(
            (string) ($user->residence_governorate ?: $user->origin_governorate));

        if ($governorate === null) {
            $out[] = 'محافظة السكن غير محدَّدة — اخترها من البطاقة أوّلاً.';
        }

        // والحقولُ الرقابيّةُ تُلزم في الفئة الثالثة وحدَها.
        if ($tier >= 3 && ($completeness['missing_fields'] ?? []) !== []) {
            $out[] = 'حقولٌ رقابيّةٌ ناقصةٌ للفئة الثالثة: '
                .implode('، ', $completeness['missing_fields']);
        }

        return $out;
    }

    /**
     * وثائقُ السجلّ الحديث بحالاتها — **وكلُّها لا المعتمَدةُ وحدَها.**
     *
     * فوثيقةٌ مرفوضةٌ أو منتهيةٌ خبرٌ للمراجع لا سطرٌ يُطوى: بها يعرف أنّ
     * العميلَ رفع وأنّ الرفعَ رُدّ، ولا يظنّه لم يرفع.
     */
    private function documents(User $user): array
    {
        return KycDocument::where('user_id', $user->id)
            ->orderByDesc('id')
            ->get(['id', 'doc_type', 'status', 'document_expires_at', 'rejection_reason', 'created_at'])
            ->map(fn (KycDocument $d) => [
                'id' => $d->id,
                'type' => $d->doc_type,
                'type_label' => KycDocument::TYPE_LABELS[$d->doc_type] ?? $d->doc_type,
                'status' => $d->status,
                'status_label' => match ($d->status) {
                    KycDocument::STATUS_APPROVED => $d->isUsable() ? 'معتمَدة' : 'معتمَدة — منتهية',
                    KycDocument::STATUS_REJECTED => 'مرفوضة',
                    KycDocument::STATUS_SUPERSEDED => 'استُبدلت',
                    default => 'تنتظر المراجعة',
                },
                'counts' => $d->isUsable(),
                'rejection_reason' => $d->rejection_reason,
                'expires_at' => $d->document_expires_at?->format('Y-m-d'),
                'uploaded_at' => $d->created_at?->format('Y-m-d H:i'),
            ])->values()->all();
    }

    /** الصورُ القديمةُ — تُعرَض موسومةً، ولا تدخل في الاكتمال. */
    private function legacy(User $user): array
    {
        $images = $user->identification_image_fullpath ?? [];

        return is_array($images) ? array_values($images) : [];
    }

    /** @param  array<int,string>  $types */
    private function labels(array $types): array
    {
        return array_values(array_map(
            fn (string $t) => KycDocument::TYPE_LABELS[$t] ?? $t, $types));
    }
}
