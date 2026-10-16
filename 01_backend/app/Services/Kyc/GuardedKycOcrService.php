<?php

namespace App\Services\Kyc;

use App\Models\KycDocument;
use App\Models\User;
use App\Services\EncryptedFileStorage;
use App\Services\KycOcrService;
use App\Services\Ocr\IdFieldExtractor;
use App\Services\Ocr\OcrDriverInterface;
use DomainException;

/**
 * AMIAL-KYC-RESTRICTED-OCR-001 — النص المستخرج من الهوية PII مثل الصورة.
 * إخفاء الصورة وترك OCR مكشوفاً يسرّب الاسم ورقم الهوية من باب ثانٍ.
 */
class GuardedKycOcrService extends KycOcrService
{
    public function __construct(
        EncryptedFileStorage $storage,
        IdFieldExtractor $extractor,
        OcrDriverInterface $driver,
        private readonly KycPrivacyService $privacy,
    ) {
        parent::__construct($storage, $extractor, $driver);
    }

    public function forReviewer(KycDocument $doc): array
    {
        $this->assertAccess($doc, false);

        return parent::forReviewer($doc);
    }

    public function confirmFields(KycDocument $doc, User $reviewer, array $fields): KycDocument
    {
        $this->privacy->assertReviewerAccess((int) $doc->user_id, $reviewer, true);

        return parent::confirmFields($doc, $reviewer, $fields);
    }

    public function process(KycDocument $doc): KycDocument
    {
        // رفع العميل لنفس مستنده لا يحتاج صلاحية موظف. أمّا إعادة OCR من
        // لوحة الإدارة لحالة مقيدة فهي فعل مراجعة، فتحتاج مفتاح القرار.
        if ($this->privacy->isRestricted((int) $doc->user_id)) {
            $reviewer = auth('user')->user();

            if ($reviewer instanceof User && (int) $reviewer->id !== (int) $doc->user_id) {
                $this->privacy->assertReviewerAccess((int) $doc->user_id, $reviewer, true);
            }
        }

        return parent::process($doc);
    }

    private function assertAccess(KycDocument $doc, bool $decision): void
    {
        if (!$this->privacy->isRestricted((int) $doc->user_id)) {
            return;
        }

        $reviewer = auth('user')->user();
        if (!$reviewer instanceof User) {
            throw new DomainException('KYC_RESTRICTED_VIEW_REQUIRED');
        }

        $this->privacy->assertReviewerAccess((int) $doc->user_id, $reviewer, $decision);
    }
}
