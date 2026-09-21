<?php

namespace Tests\Support;

use App\Models\KycDocument;
use App\Models\User;

/**
 * AMIAL-KYC-EVIDENCE-001 — **اعتمادٌ بلا وثيقة ولا إثبات ملكية ليس اعتماداً.**
 *
 * هذا المساعد لا يخفف حراس الإنتاج كي تمر الاختبارات؛ بل يبني نفس الدليل
 * الذي صار الإنتاج يشترطه: مستنداتٌ صالحة، محافظة سكن، رقم هوية قانوني
 * محفوظ على الحساب، ثم إقرارُ مراجعٍ للرقم من وثيقة هوية معتمدة.
 *
 * بذلك يبقى معنى استدعاء `establishKycEvidence()` واحداً وواضحاً:
 * «هذا الملف يملك دليلاً صالحاً يتيح اختبار ما بعد KYC». وإذا أضيف شرط
 * إثبات جديد لاحقاً يُضاف هنا مرةً واحدة بدل أن تشيخ عشرات التجهيزات.
 */
trait EstablishesKycEvidence
{
    /**
     * يبني المتطلب السابق الحقيقي لترقية العميل 1 → 2:
     * إثبات الهاتف + إقامة مراجَعة + tier=1.
     *
     * لا نستعمله للتاجر/الوكيل؛ مستويات العميل الفردي وحدها متسلسلة.
     */
    protected function establishTierOnePrerequisite(User $customer): User
    {
        $customer->forceFill([
            'is_phone_verified' => 1,
            'kyc_tier' => 1,
            'residence_governorate' => 'YE-AD',
            'verified_residence_governorate' => 'YE-AD',
            'residence_verified_at' => now(),
        ])->save();

        \Illuminate\Support\Facades\DB::table('residence_verifications')->updateOrInsert(
            ['user_id' => $customer->id],
            [
                'kyc_document_id' => null,
                'declared_governorate' => 'YE-AD',
                'evidence_type' => 'government_residence_document',
                'evidence_strength' => 'strong',
                'status' => 'verified',
                'submitted_at' => now()->subMinute(),
                'reviewed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        return $customer->fresh();
    }

    /**
     * يبني مستندات الفئة المطلوبة ويثبت ملكية الهوية بالطريق الحقيقي.
     *
     * @param  int  $tier  ٢ = هوية وجهاً وظهراً وصورة حيّة · ٣ = ومعها إثبات عنوان
     */
    protected function establishKycEvidence(
        User $customer,
        int $tier = 2,
        ?User $reviewer = null,
    ): void {
        $types = [
            KycDocument::TYPE_ID_FRONT,
            KycDocument::TYPE_ID_BACK,
            KycDocument::TYPE_SELFIE,
        ];

        if ($tier >= 3) {
            $types[] = KycDocument::TYPE_ADDRESS_PROOF;
        }

        // محافظة السكن شرطٌ لقرار KYC لأن المنطقة التشغيلية تُشتق منها.
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'residence_governorate')
            && ($customer->residence_governorate ?? null) === null) {
            $customer->forceFill([
                'residence_governorate' => \App\Support\YemenGovernorates::codes()[1] ?? 'YE-AD',
            ])->save();
        }

        foreach ($types as $type) {
            KycDocument::query()->updateOrCreate(
                ['user_id' => $customer->id, 'doc_type' => $type],
                [
                    'status' => KycDocument::STATUS_APPROVED,
                    'encrypted_path' => 'kyc/test/'.$customer->id.'-'.$type.'.enc',
                    'original_mime' => 'image/jpeg',
                    'size_bytes' => 2048,
                    'content_sha256' => hash('sha256', $customer->id.$type),
                    'document_expires_at' => now()->addYears(5),
                    'reviewed_at' => now(),
                ],
            );
        }

        $this->establishKycOwnership($customer, $reviewer);
    }

    /**
     * يثبت سؤالاً مختلفاً عن اكتمال الوثائق: هل صاحب الحساب هو صاحب الهوية؟
     *
     * نستخدم `KycOcrService::confirmFields` نفسه بدل كتابة `verified_fields`
     * مباشرة، حتى يمر الاختبار من عقد الإنتاج ويسجل أثر الإقرار كما يفعل
     * المراجع الحقيقي. لا نختلق Liveness ولا Face Match.
     */
    protected function establishKycOwnership(User $customer, ?User $reviewer = null): void
    {
        $identity = trim((string) ($customer->identification_number ?? ''));
        $digits = preg_replace(
            '/[^\d]/',
            '',
            \App\Services\EncryptionService::foldDigits($identity),
        ) ?? '';

        // AMIAL-KYC-EVIDENCE-FIXTURE-002 — المساعد السابق كان يصنع
        // `TST-{id}-IDENTITY`، وبعد تنقية غير الأرقام لا يبقى غالباً إلا
        // رقم السجل نفسه (1، 2، ...). الإنتاج يرفض أقل من MIN_DIGITS بحق؛
        // لذلك نصحح الـfixture ولا نخفض الحارس الحقيقي.
        if (mb_strlen($digits) < \App\Services\Kyc\IdentityLookupService::MIN_DIGITS) {
            $identity = '990'.str_pad((string) $customer->id, 9, '0', STR_PAD_LEFT);
            $customer->forceFill(['identification_number' => $identity])->save();
        }

        $idDocument = KycDocument::query()
            ->where('user_id', $customer->id)
            ->whereIn('doc_type', [KycDocument::TYPE_ID_FRONT, KycDocument::TYPE_ID_BACK])
            ->where('status', KycDocument::STATUS_APPROVED)
            ->orderBy('id')
            ->first();

        if (!$idDocument) {
            throw new \LogicException('لا يمكن بناء إثبات ملكية KYC بلا وثيقة هوية معتمدة.');
        }

        $reviewer ??= User::factory()->create([
            'type' => defined('ADMIN_TYPE') ? ADMIN_TYPE : 0,
            'role' => 'super_admin',
        ]);

        $declaredName = app(\App\Services\Kyc\LegalNameService::class)
            ->declared($customer);

        app(\App\Services\KycOcrService::class)->confirmFields(
            $idDocument,
            $reviewer,
            [
                'national_id' => $identity,
                'full_name' => $declaredName !== ''
                    ? $declaredName
                    : trim((string) ($customer->f_name . ' ' . $customer->l_name)),
            ],
        );
    }
}
