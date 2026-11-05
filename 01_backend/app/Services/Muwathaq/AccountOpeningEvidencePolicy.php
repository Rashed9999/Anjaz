<?php

namespace App\Services\Muwathaq;

use App\Models\MuwathaqAccountOpeningApplication;

/**
 * مصدر الحقيقة داخل أميال باي لمتطلبات أدلة فتح الحساب.
 *
 * موثّق يقرر صحة الدليل، وهذه السياسة تمنع القناة أو لوحة الإدارة من
 * تجاوز متطلبات النوع/المستوى قبل التفعيل المالي.
 */
final class AccountOpeningEvidencePolicy
{
    /** @return array<string,array{label:string,document:bool}> */
    public function requirements(MuwathaqAccountOpeningApplication $application): array
    {
        return match ($application->verification_path) {
            MuwathaqAccountOpeningService::PATH_WOMEN_PROGRESSIVE => $this->women(
                max(1, min(3, (int) ($application->requested_tier ?: 1)))
            ),
            MuwathaqAccountOpeningService::PATH_STANDARD_CUSTOMER => [
                'phone' => ['label' => 'إثبات الهاتف', 'document' => false],
                'residence' => ['label' => 'إثبات السكن', 'document' => false],
                'identity_document' => ['label' => 'وثيقة الهوية', 'document' => true],
            ],
            MuwathaqAccountOpeningService::PATH_MERCHANT_SECTOR => [
                'owner_identity' => ['label' => 'هوية مالك المنشأة', 'document' => true],
                'business_registration' => ['label' => 'سجل أو ترخيص المنشأة', 'document' => true],
                'operating_address' => ['label' => 'موقع النشاط التشغيلي', 'document' => true],
            ],
            MuwathaqAccountOpeningService::PATH_AGENT_OPERATION => [
                'admin_provisioning' => ['label' => 'إحالة الإدارة', 'document' => false],
                'owner_identity' => ['label' => 'هوية الوكيل', 'document' => true],
                'operating_address' => ['label' => 'موقع الوكيل التشغيلي', 'document' => true],
            ],
            default => [],
        };
    }

    public function accepts(MuwathaqAccountOpeningApplication $application, string $kind): bool
    {
        return array_key_exists($kind, $this->requirements($application));
    }

    /** @return list<string> */
    public function missingKinds(MuwathaqAccountOpeningApplication $application): array
    {
        $required = $this->requirements($application);
        $rows = $application->evidences()
            ->where('state', \App\Models\MuwathaqOpeningEvidence::STATE_SUBMITTED)
            ->get()
            ->keyBy('kind');

        return collect($required)->filter(function (array $rule, string $kind) use ($rows): bool {
            $evidence = $rows->get($kind);
            return $evidence === null || ($rule['document'] && empty($evidence->file_encrypted_path));
        })->keys()->values()->all();
    }

    /** @return array<string,array{label:string,document:bool}> */
    private function women(int $tier): array
    {
        $base = [
            'phone' => ['label' => 'إثبات الهاتف', 'document' => false],
            'residence' => ['label' => 'إثبات السكن', 'document' => false],
        ];

        if ($tier >= 2) {
            $base['identity_document'] = ['label' => 'وثيقة الهوية (من دون إلزام بصورة شخصية)', 'document' => true];
        }
        if ($tier >= 3) {
            $base['ownership_assurance'] = ['label' => 'ضمان الملكية الحضوري أو البيومتري الاختياري', 'document' => false];
        }

        return $base;
    }
}
