<?php

namespace App\Services;

use App\Models\KycDocument;
use App\Models\User;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-KYC-DOCS-001 — رفع مستندات الهوية ومراجعتها.
 *
 * **الدائرة التي كانت مقطوعة:** زرُّ «طلب تحديث الهوية» في لوحة الدعم يضع
 * علامةً على المستخدم، ولا مكانَ يرفع إليه مستنده. الزرّ يعمل والعميل ينتظر.
 *
 * **قراران في هذه الخدمة يستحقّان الشرح:**
 *
 * 1. **الرفع الجديد يُلغي السابق من النوع نفسه.** لو بقي القديم «قيد
 *    المراجعة» لظهر للمراجع مستندان لنوعٍ واحد فلا يدري أيّهما المقصود، ولو
 *    اعتمد القديم لوثّق الحساب بصورةٍ تخلّى عنها صاحبها. فالأحدث هو النافذ.
 *
 * 2. **الاعتماد لا يرفع المستوى بنفسه.** رفعُ المستوى قرارٌ يوسّع حدود المال،
 *    ومستندٌ واحد لا يكفيه. تُحسب الاكتمالية وتُعرَض، ويبقى الرفع قراراً
 *    منفصلاً — وإلّا صار اعتمادُ صورةٍ واحدة رفعاً للحدود بلا أن ينوي أحد.
 */
class KycDocumentService
{
    /** ما يلزم لكل مستوى. المستوى 1 لا يطلب مستنداً (هاتف فقط). */
    private const REQUIRED_BY_TIER = [
        2 => [KycDocument::TYPE_ID_FRONT, KycDocument::TYPE_ID_BACK, KycDocument::TYPE_SELFIE],
        3 => [
            KycDocument::TYPE_ID_FRONT, KycDocument::TYPE_ID_BACK,
            KycDocument::TYPE_SELFIE, KycDocument::TYPE_ADDRESS_PROOF,
        ],
    ];

    private const MAX_BYTES = 8 * 1024 * 1024;

    private const ALLOWED_MIME = [
        'image/jpeg', 'image/png', 'image/heic', 'image/heif', 'application/pdf',
    ];

    public function __construct(
        private readonly EncryptedFileStorage $storage,
        private readonly AuditService $audit,
    ) {
    }

    // ── رفع ────────────────────────────────────────────────────────────

    public function upload(User $user, string $docType, UploadedFile $file): KycDocument
    {
        if (!in_array($docType, KycDocument::ALL_TYPES, true)) {
            throw new DomainException('نوع مستند غير معروف');
        }

        if ($file->getSize() > self::MAX_BYTES) {
            throw new DomainException('حجم الملفّ يتجاوز 8 ميغابايت');
        }

        $mime = (string) $file->getMimeType();
        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            // القائمة بيضاء لا سوداء: نوعٌ لم يُتوقَّع يُرفض بدل أن يمرّ.
            throw new DomainException('صيغة غير مدعومة — استعمل صورة أو PDF');
        }

        // تُحسب البصمة **قبل** التشفير: بعده يختلف الناتج لكل رفعة (متّجه
        // تهيئة عشوائيّ) فلا تُقارَن، وتضيع فائدتها في كشف التكرار.
        $sha = hash_file('sha256', $file->getRealPath());

        $path = $this->storage->encryptAndStore($file, 'kyc');

        return DB::transaction(function () use ($user, $docType, $path, $mime, $file, $sha) {
            // الأحدث هو النافذ — انظر شرح الصنف.
            KycDocument::where('user_id', $user->id)
                ->where('doc_type', $docType)
                ->where('status', KycDocument::STATUS_PENDING)
                ->update([
                    'status' => KycDocument::STATUS_REJECTED,
                    'rejection_reason' => 'استُبدل برفعٍ أحدث من صاحبه',
                    'reviewed_at' => now(),
                ]);

            $doc = KycDocument::create([
                'user_id' => $user->id,
                'doc_type' => $docType,
                'encrypted_path' => $path,
                'original_mime' => $mime,
                'size_bytes' => (int) $file->getSize(),
                'content_sha256' => $sha,
                'status' => KycDocument::STATUS_PENDING,
            ]);

            $this->audit->record([
                'actor_type' => 'customer',
                'actor_user_id' => $user->id,
                'subject_type' => 'kyc_document',
                'subject_id' => (string) $doc->id,
                'action' => 'KYC_DOCUMENT_UPLOADED',
                'decision_code' => 'KYC_UPLOAD',
                'severity' => 'info',
                'context' => ['doc_type' => $docType, 'size' => $file->getSize()],
            ]);

            return $doc;
        });
    }

    /**
     * الرفع ثمّ القراءة — بهذا الترتيب وخارج المعاملة.
     *
     * تشغيلُ المحرّك داخل `DB::transaction` يُبقي المعاملة مفتوحة ثوانيَ
     * بينما تُقرأ صورة، فتُقفل صفوفاً ويتراكم الطابور تحت الحمل. والقراءة
     * لا تُغيّر شيئاً يستحقّ ذريّةً: إن فشلت، بقي المستند مرفوعاً وحالتُه
     * تقول إنّه لم يُقرأ.
     */
    public function uploadAndRead(User $user, string $docType, UploadedFile $file): KycDocument
    {
        $doc = $this->upload($user, $docType, $file);

        return app(KycOcrService::class)->process($doc);
    }

    // ── مراجعة ─────────────────────────────────────────────────────────

    public function approve(KycDocument $doc, User $reviewer, ?string $expiresAt = null): KycDocument
    {
        $this->assertReviewable($doc, $reviewer);

        $doc->status = KycDocument::STATUS_APPROVED;
        $doc->reviewed_by = $reviewer->id;
        $doc->reviewed_at = now();
        $doc->rejection_reason = null;
        $doc->document_expires_at = $expiresAt;
        $doc->save();

        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $reviewer->id,
            'subject_type' => 'kyc_document',
            'subject_id' => (string) $doc->id,
            'action' => 'KYC_DOCUMENT_APPROVED',
            'decision_code' => 'KYC_APPROVED',
            // اعتمادُ هوية يفتح حدوداً مالية أعلى — قرارٌ حرج لا معلومة.
            'severity' => 'critical',
            'context' => ['doc_type' => $doc->doc_type, 'customer_id' => $doc->user_id],
        ]);

        return $doc->fresh();
    }

    public function reject(KycDocument $doc, User $reviewer, string $reason): KycDocument
    {
        $this->assertReviewable($doc, $reviewer);

        if (trim($reason) === '') {
            // رفضٌ بلا سبب يجعل العميل يرفع الصورة نفسها مرّةً بعد مرّة.
            throw new DomainException('سبب الرفض مطلوب — العميل يحتاج أن يعرف ما يُصلحه');
        }

        $doc->status = KycDocument::STATUS_REJECTED;
        $doc->reviewed_by = $reviewer->id;
        $doc->reviewed_at = now();
        $doc->rejection_reason = mb_substr($reason, 0, 255);
        $doc->save();

        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $reviewer->id,
            'subject_type' => 'kyc_document',
            'subject_id' => (string) $doc->id,
            'action' => 'KYC_DOCUMENT_REJECTED',
            'decision_code' => 'KYC_REJECTED',
            'reason' => mb_substr($reason, 0, 500),
            'severity' => 'warning',
            'context' => ['doc_type' => $doc->doc_type, 'customer_id' => $doc->user_id],
        ]);

        return $doc->fresh();
    }

    /**
     * القرار النهائي للحساب لا يُمنح لمجرد ضغط زر في لوحة قديمة.
     * لا بد من اكتمال المستندات المعتمدة، ومراجع آخر غير صاحب الحساب.
     */
    public function decideAccountVerification(
        User $user,
        User $reviewer,
        bool $approve,
        int $targetTier = 2,
        ?string $reason = null,
    ): User {
        if ((int) $user->id === (int) $reviewer->id) {
            throw new DomainException('FOUR_EYES_VIOLATION');
        }
        if (!array_key_exists($targetTier, self::REQUIRED_BY_TIER)) {
            throw new DomainException('فئة التحقق المطلوبة غير صحيحة');
        }
        if (!$approve && mb_strlen(trim((string) $reason)) < 5) {
            throw new DomainException('سبب رفض التحقق مطلوب وواضح للعميل');
        }

        return DB::transaction(function () use ($user, $reviewer, $approve, $targetTier, $reason) {
            $account = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($approve) {
                $completeness = $this->completenessFor($account, $targetTier);
                if (!$completeness['complete']) {
                    throw new DomainException(
                        'KYC_DOCUMENTS_INCOMPLETE: ' . implode(', ', $completeness['missing'])
                    );
                }

                $account->is_kyc_verified = 1;
                $account->kyc_tier = max((int) ($account->kyc_tier ?? 0), $targetTier);
                $account->kyc_tier_updated_at = now();
            } else {
                $account->is_kyc_verified = 2;
            }
            $account->save();

            $this->audit->record([
                'actor_type' => 'admin',
                'actor_user_id' => $reviewer->id,
                'subject_type' => 'user',
                'subject_id' => (string) $account->id,
                'action' => 'KYC_ACCOUNT_DECISION',
                'decision_code' => $approve ? 'KYC_APPROVED' : 'KYC_REJECTED',
                'reason' => $approve ? null : mb_substr(trim((string) $reason), 0, 500),
                'severity' => 'critical',
                'context' => ['target_tier' => $targetTier],
            ]);

            return $account->fresh();
        });
    }

    /**
     * لا يراجع المرء مستندَ نفسه.
     *
     * قد تبدو حالةً نادرة، لكن موظّفي المنصّة عملاء فيها أيضاً — ولهم محافظ
     * وحدود. ومن يعتمد هويّته بيده يرفع حدوده بيده.
     */
    private function assertReviewable(KycDocument $doc, User $reviewer): void
    {
        if ((int) $doc->user_id === (int) $reviewer->id) {
            throw new DomainException('FOUR_EYES_VIOLATION');
        }

        if ($doc->status !== KycDocument::STATUS_PENDING) {
            throw new DomainException('المستند رُوجع من قبل — الحالة: ' . $doc->status);
        }
    }

    // ── الاكتمالية ─────────────────────────────────────────────────────

    /** @return array{tier:int, required:array, approved:array, missing:array, complete:bool} */
    public function completenessFor(User $user, int $targetTier): array
    {
        $required = self::REQUIRED_BY_TIER[$targetTier] ?? [];

        $approved = KycDocument::where('user_id', $user->id)
            ->where('status', KycDocument::STATUS_APPROVED)
            ->get()
            // مستندٌ معتمَد ووثيقته منتهية لا يوثّق — يُحسب ناقصاً لا مكتملاً.
            ->filter(fn ($d) => $d->isUsable())
            ->pluck('doc_type')
            ->unique()
            ->values()
            ->all();

        $missing = array_values(array_diff($required, $approved));

        return [
            'tier' => $targetTier,
            'required' => $required,
            'approved' => $approved,
            'missing' => $missing,
            'complete' => $required !== [] && $missing === [],
        ];
    }

    /**
     * ما ينتظر مراجعة — أقدمه أوّلاً، فالانتظار هو ما يُشتكى منه.
     *
     * يُحمَّل صاحب المستند معه: مراجعُ يرى «العميل ٤١٧» لا يعرف من يراجع،
     * ولو فتح ملفّ كلّ رقمٍ على حدة لسجَّل اطّلاعاً على بيانات شخصية في كلّ
     * مرّة. الاسم والهاتف هنا أقلّ كشفاً من ذلك المسار وأكثر إفادة.
     */
    public function pendingQueue(int $limit = 100): array
    {
        return KycDocument::with('user:id,f_name,l_name,phone')
            ->where('status', KycDocument::STATUS_PENDING)
            ->orderBy('created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($d) => [
                'id' => (int) $d->id,
                'user_id' => (int) $d->user_id,
                'customer_name' => trim((string) ($d->user?->f_name . ' ' . $d->user?->l_name)) ?: '—',
                'customer_phone' => (string) ($d->user?->phone ?? '—'),
                'doc_type' => $d->doc_type,
                'doc_label' => KycDocument::TYPE_LABELS[$d->doc_type] ?? $d->doc_type,
                'uploaded_at' => $d->created_at?->toIso8601String(),
                'waiting_hours' => (int) $d->created_at?->diffInHours(now()),
            ])->all();
    }

    /** الملفّ مفكوكَ التشفير — للعرض على المراجع وحده. */
    public function decrypt(KycDocument $doc): string
    {
        return $this->storage->decryptToBinary($doc->encrypted_path);
    }
}
