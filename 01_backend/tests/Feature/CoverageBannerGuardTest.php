<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-COVERAGE-005 — التغطية داخل الخدمة، لا لافتة دائمة في الرئيسية.
 *
 * قرار المنتج الحالي:
 * - محافظة السكن الموثقة في KYC هي المصدر الافتراضي.
 * - لا GPS عند فتح التطبيق.
 * - «موقعي الحالي» خيار مؤقت داخل الخدمة التي تحتاج وجوداً مكانياً.
 * - استخدام الموقع الحالي لا يغيّر عنوان السكن الموثق.
 */
class CoverageBannerGuardTest extends TestCase
{
    /** @test */
    public function home_is_quiet_and_never_requests_location_or_renders_coverage_banner(): void
    {
        $home = file_get_contents(base_path(
            '../02_flutter_app/lib/features/home/screens/amial_customer_home_screen.dart'
        ));

        $this->assertStringNotContainsString('_coverageBanner', $home);
        $this->assertStringNotContainsString('_coverageNotice', $home);
        $this->assertStringNotContainsString('Geolocator.requestPermission', $home);
        $this->assertStringNotContainsString('SetGovernorateSheet', $home);
    }

    /** @test */
    public function cash_out_owns_the_optional_current_location_choice(): void
    {
        $withdraw = file_get_contents(base_path(
            '../02_flutter_app/lib/features/withdraw/screens/withdraw_request_screen.dart'
        ));
        $card = file_get_contents(base_path(
            '../02_flutter_app/lib/features/coverage/widgets/service_coverage_card.dart'
        ));

        $this->assertStringContainsString('ServiceCoverageCard', $withdraw);
        $this->assertStringContainsString("capability: 'cash_out'", $withdraw);
        $this->assertStringContainsString('coverage_use_current', $card);
        $this->assertStringContainsString('Geolocator.requestPermission', $card);
    }

    /** @test */
    public function backend_uses_residence_by_default_and_marks_current_location_as_temporary_source(): void
    {
        $ctl = file_get_contents(base_path(
            'app/Http/Controllers/Api/V1/Amial/ServiceCoverageController.php'
        ));

        $this->assertStringContainsString("residence_governorate", $ctl);
        $this->assertStringContainsString("$source = 'residence_profile'", $ctl);
        $this->assertStringContainsString("$source = 'current_location'", $ctl);
        $this->assertStringContainsString("'source' => $source", $ctl);
    }

    /** @test */
    public function current_location_lookup_never_writes_the_kyc_residence(): void
    {
        $card = file_get_contents(base_path(
            '../02_flutter_app/lib/features/coverage/widgets/service_coverage_card.dart'
        ));

        $this->assertStringContainsString('/api/v1/amial/geo/resolve-zone', $card);
        $this->assertStringContainsString('/api/v1/amial/service-coverage', $card);

        foreach ([
            'residence_governorate',
            'verified_residence_governorate',
            'residence_verified_at',
        ] as $field) {
            $this->assertStringNotContainsString(
                "'{$field}':",
                $card,
                "الموقع الحالي لا يجوز أن يكتب {$field} في KYC"
            );
        }
    }
}
