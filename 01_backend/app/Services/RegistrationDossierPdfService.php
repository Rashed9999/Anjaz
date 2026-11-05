<?php

namespace App\Services;

use App\Models\KycDocument;
use App\Models\RegistrationDossier;
use App\Models\User;
use App\Support\ArabicPdf;

class RegistrationDossierPdfService
{
    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(
        private readonly KycDocumentService $documents,
    ) {}

    public function render(RegistrationDossier $dossier, ?User $reviewer = null): string
    {
        $payload = (array) $dossier->payload_encrypted;

        return ArabicPdf::render(view('admin-views.amial.registration-dossiers.pdf', [
            'dossier' => $dossier,
            'payload' => $payload,
            'verification_images' => $this->verificationImages($dossier, $payload, $reviewer),
        ])->render(), ['format' => 'A4', 'margin' => 12]);
    }

    /** @return array<int,array<string,mixed>> */
    private function verificationImages(RegistrationDossier $dossier, array $payload, ?User $reviewer): array
    {
        if (! in_array($dossier->source, RegistrationDossier::VERIFICATION_SOURCES, true)) {
            return [];
        }

        $definitions = [
            'residence_evidence_document_id' => 'إثبات محل السكن',
            'identity_front_document_id' => 'وجه الهوية',
            'identity_back_document_id' => 'ظهر الهوية',
            'selfie_document_id' => 'صورة السيلفي الحديثة',
        ];

        $out = [];
        foreach ($definitions as $field => $label) {
            $id = (int) ($payload[$field] ?? 0);
            if ($id <= 0) continue;
            if ($field === 'selfie_document_id'
                && (!$reviewer || !$reviewer->hasPlatformPermission('platform.customers.kyc.biometric.view'))) {
                $out[] = ['label' => $label, 'data_uri' => null,
                    'note' => 'الصورة الشخصية محجوبة بصلاحية مستقلة.'];
                continue;
            }
            $doc = KycDocument::query()
                ->whereKey($id)
                ->where('user_id', $dossier->subject_user_id)
                ->first();

            if (! $doc) {
                $out[] = [
                    'label' => $label,
                    'data_uri' => null,
                    'note' => 'المستند المرتبط غير موجود في سجل الوثائق.',
                ];
                continue;
            }

            $mime = (string) ($doc->original_mime ?: 'image/jpeg');
            if (! in_array($mime, self::IMAGE_MIMES, true)) {
                $out[] = [
                    'label' => $label,
                    'data_uri' => null,
                    'note' => 'المستند ملف غير صوري ('.$mime.').',
                ];
                continue;
            }

            try {
                $out[] = [
                    'label' => $label,
                    'data_uri' => 'data:'.$mime.';base64,'.base64_encode(
                        $this->documents->decrypt($doc)
                    ),
                    'note' => null,
                ];
            } catch (\Throwable $e) {
                $out[] = [
                    'label' => $label,
                    'data_uri' => null,
                    'note' => 'تعذر فك تشفير المستند للطباعة.',
                ];
            }
        }

        return $out;
    }
}
