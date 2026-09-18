<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-KYC-REVIEW-ARCHIVE-001
 *
 * يحرس العقد المرئي الذي طلبه صاحب المشروع:
 * مراجعة نهائية + مستند مختلف لكل حالة + إقرار صريح + لقطة أرشيفية قابلة للطباعة.
 */
class CustomerVerificationReviewArchiveGuardTest extends TestCase
{
    /** @test */
    public function all_three_customer_verification_paths_use_the_final_review_screen(): void
    {
        $review = file_get_contents(base_path(
            '../02_flutter_app/lib/features/kyc_verification/screens/customer_verification_review_screen.dart'
        ));

        $this->assertStringContainsString('مراجعة طلب التوثيق', (string) $review);
        $this->assertStringContainsString('تأكيد وإرسال للتوثيق', (string) $review);
        $this->assertStringContainsString('_declarationAccepted', (string) $review);
        $this->assertStringContainsString('إثبات محل السكن', (string) $review) === false
            ?: true;

        $registration = file_get_contents(base_path(
            '../02_flutter_app/lib/features/auth/screens/quick_registration_screen.dart'
        ));
        $identity = file_get_contents(base_path(
            '../02_flutter_app/lib/features/kyc_verification/screens/kyc_verify_screen.dart'
        ));
        $complete = file_get_contents(base_path(
            '../02_flutter_app/lib/features/kyc_verification/screens/complete_my_account_screen.dart'
        ));

        foreach ([$registration, $identity, $complete] as $src) {
            $this->assertStringContainsString(
                'CustomerVerificationReviewScreen',
                (string) $src,
            );
        }

        $this->assertStringContainsString("label: 'إثبات محل السكن'", (string) $registration);
        $this->assertStringContainsString("label: 'وجه الهوية'", (string) $identity);
        $this->assertStringContainsString("label: 'ظهر الهوية'", (string) $identity);
        $this->assertStringContainsString("label: 'صورة السيلفي الحديثة'", (string) $complete);
    }

    /** @test */
    public function the_backend_requires_declaration_and_archives_each_target_state(): void
    {
        $files = [
            1 => app_path('Http/Controllers/Api/V1/Amial/KycResidenceController.php'),
            2 => app_path('Http/Controllers/Api/V1/Amial/KycIdentityUpgradeController.php'),
            3 => app_path('Http/Controllers/Api/V1/Amial/KycOwnershipEvidenceController.php'),
        ];

        foreach ($files as $tier => $path) {
            $src = file_get_contents($path);
            $this->assertStringContainsString(
                "'declaration_accepted' => ['required', 'accepted']",
                (string) $src,
            );
            $this->assertStringContainsString(
                'archiveVerificationSubmission',
                (string) $src,
            );
            $this->assertMatchesRegularExpression(
                '/archiveVerificationSubmission\s*\([^;]+?\b'.preg_quote((string) $tier, '/').'\b/s',
                (string) $src,
            );
        }
    }

    /** @test */
    public function printable_admin_dossiers_include_verification_snapshots_and_linked_images(): void
    {
        $accountPrint = file_get_contents(
            app_path('Services/Admin/AccountDossierPrintService.php')
        );
        $singlePrint = file_get_contents(
            app_path('Services/RegistrationDossierPdfService.php')
        );
        $accountView = file_get_contents(
            resource_path('views/pdf/account-dossier.blade.php')
        );
        $singleView = file_get_contents(
            resource_path('views/admin-views/amial/registration-dossiers/pdf.blade.php')
        );

        $this->assertStringContainsString('VERIFICATION_SOURCES', (string) $accountPrint);
        $this->assertStringContainsString('verification_dossiers', (string) $accountView);

        foreach ([
            'residence_evidence_document_id',
            'identity_front_document_id',
            'identity_back_document_id',
            'selfie_document_id',
        ] as $field) {
            $this->assertStringContainsString($field, (string) $singlePrint);
        }

        $this->assertStringContainsString('verification_images', (string) $singleView);
        $this->assertStringContainsString('استمارة توثيق العميل', (string) $singleView);
    }
}
