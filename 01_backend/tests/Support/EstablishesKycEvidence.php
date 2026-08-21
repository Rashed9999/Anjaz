<?php

namespace Tests\Support;

use App\Models\KycDocument;
use App\Models\User;

/**
 * AMIAL-KYC-EVIDENCE-001 — **اعتمادٌ بلا وثيقة ليس اعتماداً.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * `decideAccountVerification` صارت ترفض الاعتماد ما لم تكتمل مستنداتُ
 * الفئة المطلوبة (`KYC_DOCUMENTS_INCOMPLETE`). **والحارسُ صحيح** — وهو
 * بعينه ما وُلدت من أجله `AdminCreatedAccountReviewTest`: حسابٌ يخرج
 * موثّقاً بلا وثيقةٍ واحدة يُفرّغ لوحةَ التحقّق من معناها.
 *
 * لكنّ تسعةَ مواضعَ في المجموعة كانت تضغط «اعتماد» على حسابٍ **بلا
 * وثائق** — كُتبت قبل الحارس. فكانت تُخرج ٤٢٢، ولو نُزع الحارسُ لِتمرَّ
 * لعاد العطلُ الذي بُني الحارسُ له.
 *
 * فيُبنى الدليلُ هنا **مرّةً واحدة**، بمساره الحقيقيّ: مستنداتٌ محفوظةٌ
 * ومعتمَدة. ولا يُنسخ الشرطُ في تسعة ملفّات — فشرطٌ منسوخٌ تسعَ مرّاتٍ
 * يشيخ في ثمانٍ منها.
 */
trait EstablishesKycEvidence
{
    /**
     * مستنداتُ الفئة المطلوبة، معتمَدةً وصالحة.
     *
     * @param  int  $tier  ٢ = هويّةٌ وجهاً وظهراً وصورةٌ حيّة · ٣ = ومعها إثباتُ عنوان
     */
    protected function establishKycEvidence(User $customer, int $tier = 2): void
    {
        $types = [
            KycDocument::TYPE_ID_FRONT,
            KycDocument::TYPE_ID_BACK,
            KycDocument::TYPE_SELFIE,
        ];

        if ($tier >= 3) {
            $types[] = KycDocument::TYPE_ADDRESS_PROOF;
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
                    // لا انتهاءَ قريب: مستندٌ معتمَدٌ منتهٍ يُحسب ناقصاً
                    // (‏`isUsable`)، فتاريخٌ قريبٌ هنا يُسقط الاختبارَ بعد
                    //  شهرٍ من كتابته لا لعطلٍ بل لمرور الزمن.
                    'document_expires_at' => now()->addYears(5),
                    'reviewed_at' => now(),
                ],
            );
        }
    }
}
