<?php

namespace App\Services;

use App\Models\KycDocument;
use App\Models\User;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

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

    public function upload(User $user, string $docType, UploadedFile $file, ?User $operator = null): KycDocument
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

        return DB::transaction(function () use ($user, $docType, $path, $mime, $file, $sha, $operator) {
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
                'actor_type' => $operator ? 'platform_user' : 'customer',
                'actor_user_id' => $operator?->id ?? $user->id,
                'subject_type' => 'kyc_document',
                'subject_id' => (string) $doc->id,
                'action' => 'KYC_DOCUMENT_UPLOADED',
                'decision_code' => 'KYC_UPLOAD',
                'severity' => 'info',
                'context' => ['doc_type' => $docType, 'size' => $file->getSize(),
                    'subject_user_id' => $user->id, 'assisted_opening' => $operator !== null],
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
            $requiredTier = $targetTier;

            // طلب تحديث حسابٍ كان في الفئة ٣ لا يعيده افتراض لوحة قديمة إلى
            // الفئة ٢. تُستعاد الفئة السابقة فقط بعد اكتمال مستنداتها الجديدة.
            if (Schema::hasColumn('users', 'kyc_update_required')
                && (int) ($account->kyc_update_required ?? 0) === 1
                && Schema::hasColumn('users', 'kyc_update_previous_tier')) {
                $previousTier = (int) ($account->kyc_update_previous_tier ?? 0);
                if (array_key_exists($previousTier, self::REQUIRED_BY_TIER)) {
                    $requiredTier = max($requiredTier, $previousTier);
                }
            }

            if ($approve) {
                $completeness = $this->completenessFor($account, $requiredTier);
                if (!$completeness['complete']) {
                    throw new DomainException(
                        'KYC_DOCUMENTS_INCOMPLETE: ' . implode(', ', $completeness['missing'])
                    );
                }

                // ══════════════════════════════════════════════════════
                // AMIAL-KYC-INTL-001 — **والحقولُ الرقابيّةُ تُلزم حيث
                // يُبنى عليها سقفُ مال: الفئةُ الثالثة.**
                //
                // إلزامُها في الفئة الثانية يُجمّد طابورَ التحقّق على
                // مئات الحسابات المسجَّلة قبلها، وتركُها بلا إلزامٍ
                // إطلاقاً يجعلها زينةً لا امتثالاً. **فالموضعُ هو حيث
                // تُوسَّع الحدود.**
                //
                // **والرفضُ يسمّي الناقصَ بعينه** — «الملفّ ناقص» تُرسل
                // المراجعَ يبحث وتُنتج تذكرةَ دعمٍ لا إجراءً.
                // ══════════════════════════════════════════════════════
                if ($requiredTier >= 3 && ($completeness['missing_fields'] ?? []) !== []) {
                    throw new DomainException(
                        'KYC_PROFILE_INCOMPLETE: '
                        . implode(', ', $completeness['missing_fields'])
                    );
                }

                $account->is_kyc_verified = 1;
                $account->kyc_tier = max((int) ($account->kyc_tier ?? 0), $requiredTier);
                $account->kyc_tier_updated_at = now();
                // لا يُمسح طلب التحديث بمجرد رفع ملفات أو اعتماد ملفٍ مفرد؛
                // يُمسح هنا فقط، بعد قرار الحساب النهائي ومستندات مكتملة.
                foreach ([
                    'kyc_update_required',
                    'kyc_update_requested_at',
                    'kyc_update_requested_by',
                    'kyc_update_previous_tier',
                ] as $column) {
                    if (Schema::hasColumn('users', $column)) {
                        $account->setAttribute($column, in_array($column, ['kyc_update_required'], true) ? 0 : null);
                    }
                }
            } else {
                $account->is_kyc_verified = 2;
            }
            $account->save();

            // ══════════════════════════════════════════════════════════
            // AMIAL-ZONE-REG-001 — **والاعتمادُ يحسم المنطقةَ أيضاً.**
            //
            // قِيس: هذا هو **مسارُ اعتماد الهويّة الرئيس**، ولم يكن يمسّ
            // `zone_code` بحرف. و`assignFromKyc()` مبنيّةٌ ويناديها ثلاثةُ
            // مسارات إداريّةٍ **جانبيّة** — لا هذا.
            //
            // **وبلا هذا السطر يصير التوثيقُ نصفَ توثيق**: يُرفع
            // `is_kyc_verified` فيُقال للعميل «وُثِّق حسابُك»، ويبقى
            // `zone_code` على ما هو فيُرفض استقبالُه بلا سببٍ مفهوم.
            //
            // **وهو الحاجزُ الذي يشلّ عملاً سليماً** — وذاك أسوأ من ثغرة.
            //
            // ولا تُعطَّل: فشلُ إسنادٍ لا يجوز أن يُسقط قرارَ توثيقٍ مكتمل.
            if ($approve) {
                try {
                    $city = trim((string) ($account->residence_governorate ?? ''));

                    if ($city !== '') {
                        app(ZoneAssignmentService::class)
                            ->assignFromKyc($account, $city, (int) $reviewer->id);
                    } elseif ((string) $account->zone_code === ZoneAssignmentService::ZONE_UNKNOWN) {
                        // **وحالةٌ تبقى قاتلةً إن سُكت عنها:** اعتُمدت
                        // الهويّةُ ولا محافظةَ سكنٍ في الملفّ، فتبقى
                        // المنطقةُ `UNKNOWN` — **والحسابُ موثَّقٌ وممنوع**،
                        // وهي أسوأُ حالةٍ ممكنة لأنّها تبدو مكتملة.
                        //
                        // فلا تُحسم بالتخمين — **تُقال**. (القاعدة السابعة:
                        // الغيابُ يُقال صراحةً مع سببه، ولا يُملأ بصفر.)
                        $this->audit->record([
                            'actor_type' => 'admin',
                            'actor_user_id' => $reviewer->id,
                            'subject_type' => 'user',
                            'subject_id' => (string) $account->id,
                            'action' => 'KYC_ZONE_UNRESOLVED',
                            'decision_code' => 'MISSING_RESIDENCE_GOVERNORATE',
                            'reason' => 'اعتُمدت الهويّةُ ولا محافظةَ سكنٍ في الملفّ — '
                                . 'المنطقةُ غيرُ محسومةٍ والحسابُ لا يستقبل تحويلاتٍ '
                                . 'حتّى تُسنَد يدويّاً من مركز المناطق',
                            'severity' => 'warning',
                        ]);
                    }
                } catch (\Throwable $e) {
                    // ولا يُسقَط قرارُ توثيقٍ مكتملٍ بفشل إسنادٍ — الإسنادُ
                    // أثرٌ لا شرط.
                    Log::warning('zone assignment on kyc approval failed', [
                        'user_id' => $account->id, 'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->audit->record([
                'actor_type' => 'admin',
                'actor_user_id' => $reviewer->id,
                'subject_type' => 'user',
                'subject_id' => (string) $account->id,
                'action' => 'KYC_ACCOUNT_DECISION',
                'decision_code' => $approve ? 'KYC_APPROVED' : 'KYC_REJECTED',
                'reason' => $approve ? null : mb_substr(trim((string) $reason), 0, 500),
                'severity' => 'critical',
                'context' => ['target_tier' => $requiredTier],
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

        // ══════════════════════════════════════════════════════════════
        // AMIAL-KYC-INTL-001 — **والاكتمالُ مستنداتٌ وبيانات، لا مستنداتٍ
        // وحدَها.**
        //
        // كان يُقاس بالوثائق المرفوعة فقط. **وحقلٌ يُجمَع في التسجيل ولا
        // يُطالَب به عند القرار زينةٌ لا امتثال**: يُوهم بأنّ البيانَ
        // عندنا فلا يُطلَب ثانية، ثمّ يُكتشَف فراغُه يومَ يُسأل عنه.
        //
        // **وموضعُ الإلزام ها هنا لا في التسجيل**: إلزامُه في التسجيل
        // يُقفل البابَ على مئات الحسابات القائمة، وإلزامُه في الاعتماد
        // يمنع دخولَ ملفٍّ ناقصٍ إلى مرحلةٍ يُبنى عليها سقفُ مال.
        //
        // **ولا يُطالَب به في الفئة الأولى** — وهي محفظةٌ بحدٍّ أدنى،
        // وتشديدُها يمنع من لا يحتاج تشديداً.
        // ══════════════════════════════════════════════════════════════
        // ══════════════════════════════════════════════════════════════
        // **والحقولُ تُعرَض ولا تُدخل في «مكتمل».**
        //
        // كُتب هذا أوّلَ مرّةٍ فأُدخلت في `complete` — **فسقطت ستُّ حالاتٍ
        // سليمة**: الدالّةُ تُسأل عن اكتمال **الوثائق** ويقرؤها العرضُ
        // كذلك، فتحميلُها معنىً ثانياً يقلب جوابَها على كلّ من يسألها.
        //
        // **والأخطرُ عمليّاً**: مئاتُ الحسابات مسجَّلةٌ قبل هذه الحقول،
        // فإلزامُها في كلّ اعتمادٍ يُجمّد طابورَ التحقّق كلَّه —
        // **وحاجزٌ يشلّ عملاً سليماً أسوأ من ثغرة**.
        //
        // فتُعرَض ها هنا للمراجع، **ويقع الإلزامُ في الفئة الثالثة
        // وحدَها** — وهي التي تُوسَّع بها حدودُ المال. (انظر
        // `decideAccountVerification`.)
        // ══════════════════════════════════════════════════════════════
        $missingFields = \App\Support\Kyc\KycProfileFields::missingFor($user);

        return [
            'tier' => $targetTier,
            'required' => $required,
            'approved' => $approved,
            'missing' => $missing,
            'missing_fields' => $missingFields,
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
