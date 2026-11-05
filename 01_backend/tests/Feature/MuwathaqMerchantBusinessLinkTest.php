<?php

namespace Tests\Feature;

use App\Models\BusinessGateway\BusinessLink;
use App\Models\BusinessGateway\BusinessOutboxEvent;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\Muwathaq\MuwathaqProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AMIAL-BUSINESS-GATEWAY-001 — التاجر الذي يعتمده موثّق يُربط بأميال أعمال في
 * معاملة الإنشاء نفسها، والعميل لا يُربط، وتكرار الحدث لا يُنشئ رابطاً ثانياً.
 */
class MuwathaqMerchantBusinessLinkTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_approved_merchant_is_linked_and_the_owner_can_be_invited(): void
    {
        $event = $this->approval('merchant');
        $result = app(MuwathaqProvisioningService::class)->provisionApproved($event);

        $merchant = User::findOrFail($result['user_id']);
        $this->assertSame('verified', MerchantProfile::where('user_id', $merchant->id)->value('verification_status'));

        $link = BusinessLink::where('merchant_user_id', $merchant->id)->sole();
        $created = BusinessOutboxEvent::where('event_type', 'business_link.created')->sole();
        $this->assertSame($link->link_ulid, $created->payload['link_id']);
        $this->assertSame('retail', $created->payload['data']['business_type']);
        $this->assertSame('owner@shop.test', $created->payload['data']['owner']['email']);
        $this->assertTrue($created->payload['data']['can_receive_payments']);

        app(MuwathaqProvisioningService::class)->provisionApproved($event);
        $this->assertSame(1, BusinessLink::count());
        $this->assertSame(1, BusinessOutboxEvent::count());
    }

    #[Test]
    public function customers_are_not_linked(): void
    {
        app(MuwathaqProvisioningService::class)->provisionApproved($this->approval('customer'));

        $this->assertSame(0, BusinessLink::count());
        $this->assertSame(0, BusinessOutboxEvent::count());
    }

    #[Test]
    public function auto_linking_can_be_switched_off(): void
    {
        config(['amial_business.auto_link_on_approval' => false]);

        app(MuwathaqProvisioningService::class)->provisionApproved($this->approval('merchant'));

        $this->assertSame(0, BusinessLink::count());
    }

    private function approval(string $kind): array
    {
        return [
            'event_version' => 1,
            'event_type' => 'account.approved',
            'application_ulid' => (string) Str::ulid(),
            'decision_reference' => 'DEC-'.Str::random(12),
            'subject_kind' => $kind,
            'verification_path' => 'standard',
            'business_type' => $kind === 'merchant' ? 'retail' : null,
            'applicant' => ['f_name' => 'صالح', 'l_name' => 'المهري', 'phone' => '777123456', 'dial_country_code' => '+967', 'email' => 'owner@shop.test', 'gender' => 'male'],
            'subject' => ['store_name' => 'بقالة صالح', 'operating_address' => 'عدن'],
            'decision_context' => [
                'verified_phone' => true, 'verified_residence_governorate' => 'عدن', 'verified_identity' => true,
                'verified_owner_identity' => true, 'verified_business_requirements' => true,
            ],
        ];
    }
}
