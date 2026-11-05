<?php

namespace Tests\Feature;

use App\Models\BusinessGateway\BusinessLink;
use App\Models\BusinessGateway\BusinessOutboxEvent;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\BusinessGateway\BusinessGatewaySignature;
use App\Services\BusinessGateway\BusinessLinkService;
use App\Services\SubscriptionService;
use App\Support\Access\BusinessFeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** AMIAL-BUSINESS-PLANS-001 — اشتراكٌ واحدٌ في أميال باي يفتح ميزات أميال أعمال. */
class BusinessEntitlementsTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;
    private BusinessLink $link;

    protected function setUp(): void
    {
        parent::setUp();
        config(['amial_business.inbound_secret' => 'in-secret', 'amial_business.webhook_secret' => 'w', 'amial_business.webhook_url' => 'https://b.test/h']);
        $this->merchant = User::factory()->create(['type' => 3, 'phone' => '+967700900001', 'zone_code' => 'SOUTH', 'is_active' => 1, 'is_kyc_verified' => 1]);
        MerchantProfile::create(['user_id' => $this->merchant->id, 'verification_status' => 'verified']);
        $this->link = app(BusinessLinkService::class)->link($this->merchant);
    }

    private function fetch(): array
    {
        $ts = (string) time();
        $nonce = Str::random(32);
        $sig = BusinessGatewaySignature::sign(BusinessGatewaySignature::inboundCanonical($ts, $nonce, 'GET', '/api/v1/business/entitlements', '', ''), 'in-secret');

        // call() لا getJson(): الأخيرة ترسل '[]' جسماً فيختلف التوقيع.
        return $this->call('GET', '/api/v1/business/entitlements', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_AMIAL_BUSINESS_TIMESTAMP' => $ts, 'HTTP_X_AMIAL_BUSINESS_NONCE' => $nonce,
            'HTTP_X_AMIAL_BUSINESS_SIGNATURE' => $sig, 'HTTP_X_AMIAL_BUSINESS_LINK' => $this->link->link_ulid,
        ])->assertOk()->json('meta');
    }

    private function available(array $meta): array
    {
        return collect($meta['features'])->where('available', true)->pluck('code')->sort()->values()->all();
    }

    #[Test]
    public function a_free_merchant_gets_the_free_features_and_limits(): void
    {
        $meta = $this->fetch();
        $this->assertSame(['free', 'free'], [$meta['plan'], $meta['status']]);
        $this->assertContains('ab.pos', $this->available($meta));
        $this->assertNotContains('ab.purchases', $this->available($meta));
        $this->assertSame(['users' => 2, 'products' => 200], $meta['limits']);
        $this->assertSame('amial_pay_app', $meta['upgrade_channel']);
    }

    #[Test]
    public function upgrading_here_opens_features_there_and_tells_amial_business(): void
    {
        $before = $this->fetch()['version'];
        app(SubscriptionService::class)->changePlan($this->merchant, 'business');

        $meta = $this->fetch();
        $this->assertSame('business', $meta['plan']);
        $this->assertContains('ab.purchases', $this->available($meta));
        $this->assertNotSame($before, $meta['version']);

        $event = BusinessOutboxEvent::where('event_type', 'entitlements.changed')->latest('id')->firstOrFail();
        $this->assertSame($meta['version'], $event->payload['data']['version']);
    }

    #[Test]
    public function an_expired_paid_plan_falls_back_to_free(): void
    {
        MerchantProfile::where('user_id', $this->merchant->id)->first()
            ->update(['subscription_plan' => 'enterprise', 'subscription_expires_at' => now()->subDay()]);

        $meta = $this->fetch();
        $this->assertSame(['free', 'expired', 'enterprise'], [$meta['plan'], $meta['status'], $meta['paid_plan']]);
        $this->assertNotContains('ab.accounting', $this->available($meta));
    }

    #[Test]
    public function an_admin_addon_opens_one_feature_and_unknown_codes_are_ignored(): void
    {
        MerchantProfile::where('user_id', $this->merchant->id)->first()->update(['extra_features' => ['ab.accounting', 'ab.nonexistent']]);

        $meta = $this->fetch();
        $fuel = collect($meta['features'])->firstWhere('code', 'ab.accounting');
        $this->assertSame([true, 'addon'], [$fuel['available'], $fuel['source']]);
        $this->assertNotContains('ab.nonexistent', collect($meta['features'])->pluck('code'));
        $this->assertContains('ab.accounting', $meta['after_expiry']['features']);
    }

    #[Test]
    public function every_feature_is_well_formed(): void
    {
        foreach (BusinessFeatureRegistry::FEATURES as $code => $f) {
            $this->assertMatchesRegularExpression('/^ab\.[a-z_]+$/', $code);
            $this->assertContains($f['min_plan'], \App\Support\Access\AccessConstants::ALL_PLANS, $code);
            $this->assertNotSame('', $f['ar']);
        }
        foreach (\App\Support\Access\AccessConstants::ALL_PLANS as $plan) {
            $this->assertArrayHasKey($plan, BusinessFeatureRegistry::LIMITS);
        }
    }
}
