<?php

namespace App\Services\Admin;

use App\Models\KycDocument;
use App\Models\User;
use App\Services\Kyc\KycOwnershipGuardService;
use App\Services\Kyc\KycPrivacyService;
use App\Services\KycDocumentService;
use App\Support\YemenGovernorates;

/**
 * AMIAL-KYC-EVIDENCE-001 — **الدليلُ المعروضُ هو الدليلُ المحكومُ به.**
 *
 * الشاشة لا تعيد اختراع شروط القرار. تقرأ نفس خدمة KYC، وتعرض أيضاً
 * إثبات ملكية الهوية الذي يحرس القرار النهائي. والحالات المقيدة لا تُسرّب
 * حتى metadata أو الصور القديمة لمراجع لا يحمل مفتاحها.
 */
class KycEvidenceService
{
    public function __construct(
        private KycDocumentService $kyc,
        private \App\Services\Kyc\DocumentReuseService $reuse,
        private \App\Services\Kyc\IdentityExpiryService $expiry,
        private KycOwnershipGuardService $ownership,
        private KycPrivacyService $privacy,
    ) {}

    /**
     * الدليلُ الكاملُ لحسابٍ واحد — لأيّ نوعٍ من الثلاثة.
     *
     * @param  User|null  $reviewer  المراجعُ الحاليّ، لفحص المبدأ الرباعيّ والصلاحيات المقيدة.
     */
    public function for(User $user, int $targetTier = 2, ?User $reviewer = null): array
    {
        // AMIAL-KYC-RESTRICTED-EVIDENCE-001 — لا يكفي حجب endpoint الصورة:
        // هذه الخدمة كانت تقرأ legacy_images والـmetadata مباشرة. من لا يحمل
        // restricted.view يأخذ حالةً حمراء فقط، لا أدلة يمكن تركيب الهوية منها.
        if ($this->privacy->isRestricted($user)
            && (!$reviewer || !$reviewer->hasPlatformPermission('platform.customers.kyc.restricted.view'))) {
            return [
                'tier' => $targetTier,
                'complete' => false,
                'required' => [],
                'approved' => [],
                'missing' => [],
                'missing_fields' => [],
                'documents' => [],
                'legacy_images' => [],
                'blockers' => ['هذه الحالة في طابور مراجعة مقيد — التفاصيل محجوبة عن هذا الموظف.'],
                'reuse' => ['blockers' => [], 'warnings' => []],
                'identity_expiry' => ['state' => 'restricted', 'expires_at' => null],
                'ownership' => [
                    'ready' => false,
                    'method' => 'restricted',
                    'blockers' => ['إثبات الملكية متاح لفريق المراجعة المقيدة فقط.'],
                    'evidence' => [],
                ],
                'restricted' => true,
            ];
        }

        $completeness = $this->kyc->completenessFor($user, $targetTier);
        $ownership = $this->ownership->assess($user);

        return [
            'tier' => $targetTier,
            'complete' => (bool) $completeness['complete'],
            'required' => $this->labels($completeness['required'] ?? []),
            'approved' => $this->labels($completeness['approved'] ?? []),
            'missing' => $this->labels($completeness['missing'] ?? []),
            'missing_fields' => array_values($completeness['missing_fields'] ?? []),
            'documents' => $this->documents($user),
            'legacy_images' => $this->legacy($user),
            'blockers' => $this->blockers(
                $user, $completeness, $targetTier, $reviewer, $ownership),
            // AMIAL-KYC-REUSE-001 — **ورقةٌ واحدةٌ تفتح حسابين.**
            'reuse' => $this->reuse->findingsFor($user),
            'identity_expiry' => $this->expiry->stateOf($user),
            // AMIAL-KYC-OWNERSHIP-001 — يظهر قبل زر الاعتماد لا بعد رفضه.
            'ownership' => $ownership,
            'restricted' => $this->privacy->isRestricted($user),
        ];
    }

    /**
     * **ما يمنع الاعتمادَ الآن — قبل الضغط لا بعد الرفض.**
     *
     * ترتيب الشروط يبقى قريباً من `decideAccountVerification`: الاكتمال،
     * المحافظة، الحقول، التكرار، الانتهاء، ثم إثبات الملكية. لا نعرض نقص
     * الملكية إذا كانت الوثائق نفسها ناقصة حتى لا نكرر «لا سيلفي» مرتين.
     *
     * @return array<int,string>
     */
    private function blockers(
        User $user,
        array $completeness,
        int $tier,
        ?User $reviewer,
        array $ownership,
    ): array {
        $out = [];

        if ($reviewer && (int) $reviewer->id === (int) $user->id) {
            $out[] = 'لا يعتمد المراجعُ حسابَ نفسِه (المبدأ الرباعيّ).';
        }

        if ((int) ($user->type ?? 0) === 2) {
            try {
                app(\App\Services\KycTierService::class)
                    ->assertSequentialVerificationDecision($user, $tier);
            } catch (\DomainException $e) {
                $out[] = str_replace(
                    [' [KYC_TIER_SEQUENCE_VIOLATION]', ' [KYC_TIER_TARGET_INVALID]'],
                    '',
                    $e->getMessage(),
                );
            }
        }

        if (! $completeness['complete']) {
            $missing = $this->labels($completeness['missing'] ?? []);

            $out[] = $missing === []
                ? 'لا مستنداتٍ مطلوبةً لهذه الفئة — لا يتمّ الاعتماد منها.'
                : 'مستنداتٌ ناقصةٌ أو غيرُ معتمَدة: '.implode('، ', $missing);
        }

        $governorate = YemenGovernorates::codeFromName(
            (string) ($user->residence_governorate ?: $user->origin_governorate));

        if ($governorate === null) {
            $out[] = 'محافظة السكن غير محدَّدة — اخترها من البطاقة أوّلاً.';
        }

        if ($tier >= 3 && ($completeness['missing_fields'] ?? []) !== []) {
            $out[] = 'حقولٌ رقابيّةٌ ناقصةٌ للفئة الثالثة: '
                .implode('، ', $completeness['missing_fields']);
        }

        foreach ($this->reuse->findingsFor($user)['blockers'] as $line) {
            $out[] = $line;
        }

        $expiry = $this->expiry->stateOf($user);

        if (($expiry['state'] ?? null) === \App\Services\Kyc\IdentityExpiryService::STATE_EXPIRED) {
            $out[] = 'هويّةُ صاحب الحساب منتهيةٌ'
                .($expiry['expires_at'] ? ' منذ '.$expiry['expires_at'] : '')
                .' — لا يُوثَّق حسابٌ على ورقةٍ منتهية. اطلب إعادةَ الرفع.';
        }

        // لا نُغرق المراجع بسببين لنفس النقص: بعد اكتمال المستندات فقط
        // نعرض ما بقي لإثبات أن صاحب الحساب هو صاحب تلك المستندات.
        if ($completeness['complete'] && !($ownership['ready'] ?? false)) {
            foreach (($ownership['blockers'] ?? []) as $line) {
                if (!in_array($line, $out, true)) {
                    $out[] = $line;
                }
            }
        }

        return $out;
    }

    /** وثائقُ السجلّ الحديث بحالاتها — وكلُّها لا المعتمَدةُ وحدَها. */
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
