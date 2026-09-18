<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\OTPController;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\Kyc\ResidenceVerificationService;
use App\Services\Geo\YemenRegionsService;
use App\Services\KycTierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PilotCustomerPhoneOtpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'amial.otp.pilot_customer_phone_enabled' => true,
            'amial.otp.pilot_customer_phone_code' => '123456',
            'amial.operational_governorates' => ['YE-AD'],
        ]);
    }

    private function customer(): User
    {
        return User::factory()->create([
            'type' => 2,
            'role' => 'customer',
            'phone' => '+967777123456',
            'is_phone_verified' => 0,
            'is_kyc_verified' => 0,
            'kyc_tier' => 0,
            'zone_code' => 'UNKNOWN',
            'verified_residence_governorate' => null,
            'residence_verified_at' => null,
        ]);
    }

    private function requestAs(User $user, string $uri, array $data = []): Request
    {
        $request = Request::create($uri, 'POST', $data);
        $request->setUserResolver(static fn () => $user);

        return $request;
    }

    /** @test */
    public function pilot_phone_otp_is_123456_and_needs_no_provider(): void
    {
        $user = $this->customer();
        $controller = app(OTPController::class);

        $response = $controller->checkOtp(
            $this->requestAs($user, '/api/v1/customer/check-otp')
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue((bool) $response->getData(true)['pilot_mode']);
        $this->assertSame('123456', $response->getData(true)['demo_otp']);

        $this->assertSame(
            '123456',
            (string) DB::table('phone_verifications')
                ->where('phone', $user->phone)
                ->value('otp'),
        );
    }

    /** @test */
    public function phone_verification_alone_keeps_the_customer_unverified(): void
    {
        $user = $this->customer();
        $controller = app(OTPController::class);

        $controller->checkOtp(
            $this->requestAs($user, '/api/v1/customer/check-otp')
        );

        $verified = $controller->verifyOtp(
            $this->requestAs($user, '/api/v1/customer/verify-otp', [
                'otp' => '123456',
            ])
        );

        $this->assertSame(200, $verified->getStatusCode());

        $fresh = $user->fresh();
        $this->assertSame(1, (int) $fresh->is_phone_verified);
        $this->assertSame(0, (int) $fresh->kyc_tier);
        $this->assertSame(0, app(KycTierService::class)->effectiveTier($fresh));
    }

    /** @test */
    public function approved_operational_residence_completes_partial_verification(): void
    {
        $user = $this->customer();
        $user->is_phone_verified = 1;
        $user->save();

        $document = KycDocument::create([
            'user_id' => $user->id,
            'doc_type' => KycDocument::TYPE_ADDRESS_PROOF,
            'status' => KycDocument::STATUS_APPROVED,
            'encrypted_path' => 'kyc/'.Str::random(12).'.enc',
            'size_bytes' => 1024,
            'ocr_status' => 'not_run',
            'reviewed_at' => now(),
        ]);

        $service = app(ResidenceVerificationService::class);
        $selection = app(YemenRegionsService::class)->resolveDistrict(
            'YE-AD',
            29,
        );
        $submitted = $service->submit(
            $user->fresh(),
            'YE-SN',
            $selection,
            'دار سعد',
            'قرب المستشفى',
            'lease_contract',
            $document,
        );

        $reviewer = User::factory()->create([
            'type' => 0,
            'role' => 'admin',
            'is_active' => 1,
        ]);

        $service->decide(
            (int) $submitted['verification_id'],
            $reviewer,
            ResidenceVerificationService::STATUS_VERIFIED,
        );

        $fresh = $user->fresh();
        $this->assertSame(1, (int) $fresh->is_phone_verified);
        $this->assertSame(1, (int) $fresh->kyc_tier);
        $this->assertSame(1, app(KycTierService::class)->effectiveTier($fresh));
        $this->assertSame(
            'عميل موثق جزئيا',
            app(KycTierService::class)->getUserTierInfo($fresh)['tier_name'],
        );
    }

    /** @test */
    public function unsupported_residence_does_not_block_registration_or_partial_verification(): void
    {
        config(['amial.operational_governorates' => ['YE-AD']]);

        $user = $this->customer();
        $user->is_phone_verified = 1;
        $user->save();

        $document = KycDocument::create([
            'user_id' => $user->id,
            'doc_type' => KycDocument::TYPE_ADDRESS_PROOF,
            'status' => KycDocument::STATUS_APPROVED,
            'encrypted_path' => 'kyc/'.Str::random(12).'.enc',
            'size_bytes' => 1024,
            'ocr_status' => 'not_run',
            'reviewed_at' => now(),
        ]);

        $service = app(ResidenceVerificationService::class);
        $selection = app(YemenRegionsService::class)->resolveDistrict(
            'YE-SN',
            13,
        );
        $submitted = $service->submit(
            $user->fresh(),
            'YE-TA',
            $selection,
            'حي الجامعة',
            'قرب الجامعة',
            'lease_contract',
            $document,
        );

        $reviewer = User::factory()->create([
            'type' => 0,
            'role' => 'admin',
            'is_active' => 1,
        ]);

        $service->decide(
            (int) $submitted['verification_id'],
            $reviewer,
            ResidenceVerificationService::STATUS_VERIFIED,
        );

        $fresh = $user->fresh();

        // التوثيق نجح رغم أن السكن خارج نطاق التشغيل الحالي.
        $this->assertSame(1, (int) $fresh->kyc_tier);
        $this->assertSame(1, app(KycTierService::class)->effectiveTier($fresh));

        // لكن الخدمة المالية نفسها تبقى خلف سياسة التغطية.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('خارج نطاق تشغيل أميال الحالي');

        app(KycTierService::class)->assertFeatureAllowed($fresh, 'send_money');
    }

}
