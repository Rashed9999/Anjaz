<?php

namespace Tests\Feature;

use App\Models\GiftCard;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\GiftCardService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/** AMIAL-GIFT-CARDS-001 — بطاقات الهدايا ورصيد المتجر. */
class GiftCardTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->merchant = User::factory()->create(['type' => 3, 'role' => 'merchant', 'zone_code' => 'SOUTH']);
        MerchantProfile::create(['user_id' => $this->merchant->id, 'business_type' => A::BIZ_RETAIL,
            'verification_status' => 'verified', 'subscription_plan' => A::PLAN_FREE]);
    }

    private function upgrade(): void
    {
        $admin = User::factory()->create(['type' => 0, 'zone_code' => 'SOUTH']);
        app(\App\Services\SubscriptionService::class)->changePlan($this->merchant, A::PLAN_BUSINESS, $admin);
    }

    /** @test المجّاني ممنوع → 402. */
    public function free_plan_cannot_use_gift_cards(): void
    {
        Passport::actingAs($this->merchant->fresh(), [], 'api');
        $this->getJson('/api/v1/amial/merchant/gift-cards')->assertStatus(402);
    }

    /** @test إصدار بطاقة ثم استبدال جزئي يقلّص الرصيد. */
    public function issue_and_redeem(): void
    {
        $this->upgrade();
        $svc = app(GiftCardService::class);
        $card = $svc->issue($this->merchant->fresh(), '5000', ['name' => 'هدية']);
        $this->assertSame('5000.00', (string) $card->fresh()->balance);
        $this->assertStringStartsWith('GC-', $card->code);

        $res = $svc->redeem($this->merchant->fresh(), $card->code, '2000');
        $this->assertSame('2000.0000', $res['applied']);
        $this->assertSame('3000.00', $res['balance']);

        // أكثر من الرصيد يُرفض
        $this->expectException(\RuntimeException::class);
        $svc->redeem($this->merchant->fresh(), $card->code, '99999');
    }

    /** @test الاستبدال حتى التصفير يجعل الحالة depleted. */
    public function full_redeem_depletes_card(): void
    {
        $this->upgrade();
        $svc = app(GiftCardService::class);
        $card = $svc->issue($this->merchant->fresh(), '1000');
        $svc->redeem($this->merchant->fresh(), $card->code, '1000');
        $this->assertSame('depleted', GiftCard::find($card->id)->status);
    }

    /** @test الإلغاء يصفّر الرصيد ويمنع الاستبدال. */
    public function void_blocks_redeem(): void
    {
        $this->upgrade();
        $svc = app(GiftCardService::class);
        $card = $svc->issue($this->merchant->fresh(), '3000');
        $svc->void($this->merchant->fresh(), $card->code);
        $this->assertSame('void', GiftCard::find($card->id)->status);

        $this->expectException(\RuntimeException::class);
        $svc->redeem($this->merchant->fresh(), $card->code, '100');
    }
}
