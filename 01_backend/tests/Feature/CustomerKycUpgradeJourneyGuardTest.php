<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * AMIAL-CUSTOMER-KYC-ENTRY-001
 *
 * يمنع عودة العطل الذي ظهر على الجهاز:
 * - عميل غير موثق يضغط «إكمال التوثيق» ثم يُرفض محلياً لأن profile لم يحمل.
 * - «تحديث بياناتي» شاشة موجودة لكن API الذي تناديه غير مسجّل.
 * - لا يوجد مدخل مباشر لرفع المستوى إلا من نافذة خدمة مقفلة.
 */
class CustomerKycUpgradeJourneyGuardTest extends TestCase
{
    /** @test */
    public function customer_kyc_and_profile_change_routes_are_registered(): void
    {
        foreach ([
            'amial.me.verification-status',
            'amial.me.kyc.completion',
            'amial.me.profile-changes.index',
            'amial.me.profile-changes.fields',
            'amial.me.profile-changes.open',
            'amial.me.profile-changes.submit',
            'amial.me.profile-changes.cancel',
        ] as $name) {
            $this->assertNotNull(
                Route::getRoutes()->getByName($name),
                "المسار {$name} غير مسجل"
            );
        }
    }

    /** @test */
    public function app_has_a_direct_upgrade_entry_and_no_stale_profile_role_blocker(): void
    {
        $services = base_path('../02_flutter_app/lib/features/me/screens/my_services_screen.dart');
        $complete = base_path('../02_flutter_app/lib/features/kyc_verification/screens/complete_my_account_screen.dart');
        $repo = base_path('../02_flutter_app/lib/features/kyc_verification/domain/reposotories/verification_center_repo.dart');

        if (!is_file($services) || !is_file($complete) || !is_file($repo)) {
            $this->markTestSkipped('مصادر Flutter غير موجودة في هذه البيئة');
        }

        $servicesSrc = file_get_contents($services);
        $completeSrc = file_get_contents($complete);

        $this->assertStringContainsString('التوثيق ورفع المستوى', $servicesSrc);
        $this->assertStringContainsString('CompleteMyAccountScreen', $servicesSrc);
        $this->assertStringNotContainsString('profile?.type != 2', $completeSrc);
        $this->assertStringContainsString('/me/verification-status', file_get_contents($repo));
    }
}
