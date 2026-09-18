<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\Amial\FamilyFundController;
use App\Http\Controllers\Api\V1\Amial\SafePaymentController;
use App\Models\BillProvider;
use App\Models\BillService;
use App\Models\FamilyFundMember;
use App\Models\SafePayment;
use App\Models\User;
use App\Services\BillPayService;
use App\Services\FamilyFundService;
use App\Services\KycTierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * AMIAL-CROSS-TIER-GUARD-001
 *
 * خدمات Tier 2+ لا يكفي أن يكون منشئ العملية مؤهلاً لها؛ الطرف المقابل
 * نفسه يجب أن يكون مؤهلاً أيضاً. هذا يحرس الحالة التي ظهرت في التطبيق:
 * Tier 2/3 يحاول إرسال دفع آمن أو إشراك/صرف صندوق عائلة إلى Tier 0/1.
 *
 * الاختبار موجّه لهذه الحدود فقط، ولا يعيد اختبار كل النظام المالي.
 */
class KycCrossTierServiceGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('amial.encryption.pii_key', base64_encode(random_bytes(32)));
        config()->set('amial.encryption.blind_index_key', base64_encode(random_bytes(32)));
    }

    /** @test */
    public function tier_2_and_3_cannot_create_safe_payment_to_tier_0_or_1(): void
    {
        $controller = app(SafePaymentController::class);

        foreach ([2, 3] as $senderTier) {
            foreach ([0, 1] as $recipientTier) {
                $buyer = $this->customerAtTier($senderTier);
                $seller = $this->customerAtTier($recipientTier);

                $before = SafePayment::count();
                $request = $this->requestAs($buyer, [
                    'seller_phone' => $seller->phone,
                    // لا يصل التنفيذ إلى فحص الـ token أو PIN: يجب أن يسقط
                    // أولاً عند أهلية الطرف المقابل.
                    'seller_verification_token' => str_repeat('A', 26),
                    'pin' => '1234',
                    'title' => 'بيع تجريبي',
                    'description' => 'اختبار منع الدفع الآمن للطرف غير المؤهل.',
                    'amount' => '1000',
                ]);

                $response = $controller->create($request);
                $body = $response->getData(true);

                $this->assertSame(422, $response->getStatusCode(), "Tier {$senderTier} -> Tier {$recipientTier} must be rejected");
                $this->assertSame('CREATE_FAILED', $body['code'] ?? null);
                $this->assertSame($before, SafePayment::count(), 'Rejected cross-tier safe payment must not create a record');
            }
        }
    }

    /** @test */
    public function eligible_tier_2_or_3_seller_passes_safe_payment_counterparty_check(): void
    {
        $controller = app(SafePaymentController::class);

        foreach ([2, 3] as $sellerTier) {
            $buyer = $this->customerAtTier(2);
            $seller = $this->customerAtTier($sellerTier);
            $request = $this->requestAs($buyer, ['seller_phone' => $seller->phone]);

            $response = $controller->verifySeller($request);
            $body = $response->getData(true);

            $this->assertSame(200, $response->getStatusCode(), "Tier {$sellerTier} seller should pass the counterparty gate");
            $this->assertSame('SELLER_VERIFIED', $body['code'] ?? null);
        }
    }

    /** @test */
    public function high_tier_owner_cannot_invite_tier_0_or_1_to_family_fund(): void
    {
        $controller = app(FamilyFundController::class);
        $service = app(FamilyFundService::class);

        foreach ([2, 3] as $ownerTier) {
            $owner = $this->customerAtTier($ownerTier);
            $fund = $service->create($owner, "صندوق اختبار {$ownerTier}");

            foreach ([0, 1] as $inviteeTier) {
                $invitee = $this->customerAtTier($inviteeTier);
                $before = FamilyFundMember::where('fund_id', $fund->id)->count();

                $response = $controller->invite(
                    $this->requestAs($owner, ['phone' => $invitee->phone, 'role' => 'member']),
                    $fund->fund_ulid,
                );
                $body = $response->getData(true);

                $this->assertSame(422, $response->getStatusCode());
                $this->assertSame('INVITEE_KYC_TIER_REQUIRED', $body['code'] ?? null);
                $this->assertSame(
                    $before,
                    FamilyFundMember::where('fund_id', $fund->id)->count(),
                    'Ineligible invitee must not be added to the fund',
                );
            }
        }
    }

    /** @test */
    public function high_tier_owner_cannot_disburse_family_fund_to_tier_0_or_1(): void
    {
        $controller = app(FamilyFundController::class);
        $service = app(FamilyFundService::class);

        foreach ([2, 3] as $ownerTier) {
            $owner = $this->customerAtTier($ownerTier);
            $fund = $service->create($owner, "صندوق صرف {$ownerTier}");

            foreach ([0, 1] as $beneficiaryTier) {
                $beneficiary = $this->customerAtTier($beneficiaryTier);
                $request = $this->requestAs($owner, [
                    'beneficiary_user_id' => $beneficiary->id,
                    'amount' => '1000',
                    'note' => 'اختبار رفض الصرف لمستوى غير مؤهل',
                ]);

                $response = $controller->proposeDisbursement($request, $fund->fund_ulid);
                $body = $response->getData(true);

                $this->assertSame(422, $response->getStatusCode());
                $this->assertSame('DISBURSE_FAILED', $body['code'] ?? null);
            }
        }
    }

    /** @test */
    public function bill_pay_is_tier_1_and_the_service_enforces_it_before_money_moves(): void
    {
        $kyc = app(KycTierService::class);

        $tier0 = $this->customerAtTier(0);
        try {
            $kyc->assertFeatureAllowed($tier0, 'bill_pay');
            $this->fail('Tier 0 unexpectedly received bill_pay');
        } catch (\RuntimeException) {
            $this->assertTrue(true);
        }

        foreach ([1, 2, 3] as $tier) {
            $user = $this->customerAtTier($tier);
            $limits = $kyc->assertFeatureAllowed($user, 'bill_pay');
            $this->assertSame($tier, (int) $limits['tier']);
        }

        // ويُقاس حدّ التنفيذ نفسه، لا خريطة KYC وحدها. النماذج غير
        // محفوظة عمداً: Tier 0 يجب أن يُرفض قبل التحقق من المزوّد أو
        // إنشاء hold/ledger/order.
        $this->expectException(\RuntimeException::class);
        app(BillPayService::class)->createAndExecute(
            $tier0,
            new BillProvider(),
            new BillService(),
            null,
            '777000000',
            '1000',
        );
    }

    /** @test */
    public function tier_0_and_1_cannot_use_donations_family_fund_or_safe_payment_features(): void
    {
        $kyc = app(KycTierService::class);

        foreach ([0, 1] as $tier) {
            $user = $this->customerAtTier($tier);

            foreach (['donations', 'family_fund', 'safe_payment'] as $feature) {
                try {
                    $kyc->assertFeatureAllowed($user, $feature);
                    $this->fail("Tier {$tier} unexpectedly received {$feature}");
                } catch (\RuntimeException) {
                    $this->assertTrue(true);
                }
            }
        }
    }

    /** @test */
    public function tier_2_and_3_are_eligible_for_premium_customer_features(): void
    {
        $kyc = app(KycTierService::class);

        foreach ([2, 3] as $tier) {
            $user = $this->customerAtTier($tier);
            foreach (['donations', 'family_fund', 'safe_payment'] as $feature) {
                $limits = $kyc->assertFeatureAllowed($user, $feature);
                $this->assertSame($tier, (int) $limits['tier']);
            }
        }
    }

    private function customerAtTier(int $tier): User
    {
        return User::factory()->create([
            'type' => 2,
            'role' => 'customer',
            'kyc_tier' => $tier,
            'is_kyc_verified' => $tier >= 2 ? 1 : 0,
            'is_phone_verified' => $tier >= 1 ? 1 : 0,
            'zone_code' => 'SOUTH',
            'residence_governorate' => 'YE-AD',
            'verified_residence_governorate' => 'YE-AD',
            'residence_verified_at' => now(),
            'sanction_status' => 'clear',
            'is_active' => 1,
        ]);
    }

    private function requestAs(User $user, array $data = []): Request
    {
        $request = Request::create('/_targeted-test', 'POST', $data);
        $request->setUserResolver(static fn () => $user);
        return $request;
    }
}
