<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\Merchant\MerchantPermissionService;
use App\Services\PharmacyService;
use App\Support\Access\AccessConstants as A;
use App\Support\Merchant\MerchantPermissions as P;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * AMIAL-ACCEPT-PHARMACY-003 — **كلُّ دورٍ لا يرى إلّا ما يملكه.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * البندُ السادسُ من قائمة القبول: **مالكُ الصيدليّة · صيدليّ · فنّيّ ·
 * كاشير** — لكلٍّ حدُّه، ولا يُنفّذ واحدٌ منهم ما ليس له.
 *
 * **والفرقُ بين الأدوار ليس تفصيلاً إداريّاً**: من يُتلف دفعةً يُخرج
 * دواءً من رصيد المخزون بلا مقابل، ومن يقرأ كلَّ المبيعات يقرأ دخلَ
 * المنشأة. **فخلطُ الأدوار يفتح البابين معاً على من أُعطي مفتاحَ البيع
 * وحدَه.**
 *
 * **ويُقاس الطرفان لكلّ دور**: ما يملكه **يعمل**، وما لا يملكه **يُردّ**.
 * فحاجزٌ يمنع الجميع ليس عزلاً بل عطل، وهو أسهلُ ما يُكتب حين يُطارَد
 * التسريب.
 */
class PharmacyAcceptanceRolesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private array $roles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create([
            'type' => 3, 'role' => A::ROLE_MERCHANT, 'zone_code' => 'SOUTH',
            'is_kyc_verified' => 1, 'phone' => '967776000006',
        ]);

        MerchantProfile::create([
            'user_id' => $this->owner->id,
            'business_type' => A::BIZ_PHARMACY,
            'business_name' => 'صيدليّةُ الأدوار',
            'verification_status' => 'verified',
            'subscription_plan' => A::PLAN_BUSINESS,
            'subscription_expires_at' => now()->addYear(),
        ]);

        app(PharmacyService::class)->getOrCreatePharmacy($this->owner, [
            'name' => 'صيدليّةُ الأدوار',
        ]);

        foreach (app(MerchantPermissionService::class)
            ->seedPharmacyRoles($this->owner) as $role) {
            $this->roles[$role->code] = $role;
        }
    }

    /** موظّفٌ يحمل دوراً بعينه داخل هذه المنشأة. */
    private function staffWith(string $roleCode): User
    {
        $this->assertArrayHasKey($roleCode, $this->roles, sprintf(
            '**دورُ «%s» غيرُ مبذورٍ للصيدليّة.** والأدوارُ المعلَنةُ: %s',
            $roleCode, implode('، ', array_keys($this->roles))));

        $u = User::factory()->create([
            'type' => 3, 'role' => 'merchant_staff', 'zone_code' => 'SOUTH',
            'phone' => '96777'.random_int(1000000, 9999999),
        ]);

        app(MerchantPermissionService::class)
            ->assign($this->owner, $u, $this->roles[$roleCode]);

        return $u;
    }

    private function can(User $u, string $permission): bool
    {
        return app(MerchantPermissionService::class)->can($u, $permission);
    }

    // ═════════════════════════════════════════════════════════════════

    /**
     * **① الأدوارُ الأربعةُ مبذورةٌ بأسمائها.**
     *
     * فدورٌ لا يُبذَر لا يُسنَد، ويبقى الموظّفُ بلا حدٍّ أو بحدِّ غيره.
     */
    /** @test */
    public function the_four_pharmacy_roles_exist(): void
    {
        $missing = array_values(array_diff(
            ['owner', 'pharmacist', 'pharmacy_technician', 'cashier'],
            array_keys($this->roles)));

        $this->assertSame([], $missing, sprintf(
            '**أدوارٌ غائبةٌ عن الصيدليّة: %s.** فلا تُسنَد، ويبقى '
            .'الموظّفُ بلا حدٍّ أو بحدِّ غيره.', implode('، ', $missing)));
    }

    /**
     * **② والكاشيرُ يبيع ولا يُتلف دفعةً ولا يقرأ كلَّ المبيعات.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **والطرفان يُقاسان معاً**: لو مُنع من البيع أيضاً لَمرّ هذا الفحصُ
     * وهو يصف **عطلاً لا عزلاً** — كاشيرٌ لا يبيع. (القاعدة الثانية:
     * حاجزٌ يمنع الجميع يُطمئن ولا يحرس.)
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function the_cashier_sells_but_neither_disposes_nor_reads_all_sales(): void
    {
        $cashier = $this->staffWith('cashier');

        $this->assertTrue($this->can($cashier, P::PHARMACY_SALE_CREATE),
            '**الكاشيرُ لا يبيع.** وهذا عطلٌ لا عزل — فعملُه كلُّه البيع.');

        $forbidden = [];

        foreach ([
            P::PHARMACY_BATCH_DISPOSE => 'إتلافُ دفعة — يُخرج دواءً من الرصيد بلا مقابل',
            P::PHARMACY_SALE_VIEW_ALL => 'قراءةُ كلّ المبيعات — أي دخلُ المنشأة',
            P::PHARMACY_PRODUCT_MANAGE => 'تعديلُ الأصناف وأسعارِها',
        ] as $perm => $why) {
            if ($this->can($cashier, $perm)) {
                $forbidden[] = "  $perm — $why";
            }
        }

        $this->assertSame([], $forbidden, sprintf(
            "**الكاشيرُ يملك ما ليس له:**\n%s\n\n"
            .'ومن أُعطي مفتاحَ البيع وحدَه لا يُفتح له بابُ المخزون '
            .'والدخل معه.', implode("\n", $forbidden)));
    }

    /**
     * **③ والصيدليُّ يوثّق الوصفةَ، والكاشيرُ لا.**
     *
     * فتوثيقُ الوصفة فعلٌ مهنيٌّ مسؤولٌ عنه صاحبُه — وتوقيعُ كاشيرٍ عليها
     * يجعل السجلَّ يقول ما لم يقع.
     */
    /** @test */
    public function only_the_pharmacist_records_a_prescription(): void
    {
        $pharmacist = $this->staffWith('pharmacist');
        $cashier = $this->staffWith('cashier');

        $this->assertTrue($this->can($pharmacist, P::PHARMACY_PRESCRIPTION_RECORD),
            '**الصيدليُّ لا يوثّق وصفة** — وهو صاحبُ هذا الفعل.');

        $this->assertFalse($this->can($cashier, P::PHARMACY_PRESCRIPTION_RECORD),
            '**الكاشيرُ يوثّق وصفةً طبّيّة.** فالسجلُّ ينسبها إلى من لا '
            .'يملك مسؤوليّتَها المهنيّة.');
    }

    /**
     * **④ والمالكُ وحدَه يُتلف دفعةً.**
     */
    /** @test */
    public function disposing_a_batch_belongs_to_the_owner_not_the_counter(): void
    {
        $this->assertTrue($this->can($this->owner, P::PHARMACY_BATCH_DISPOSE),
            '**مالكُ الصيدليّة لا يستطيع إتلافَ دفعةٍ منتهية** — فتبقى '
            .'في الرصيد وتُباع، أو يُوقَف العملُ حتّى يأتي غيرُه.');

        foreach (['cashier', 'pharmacy_technician'] as $code) {
            $this->assertFalse(
                $this->can($this->staffWith($code), P::PHARMACY_BATCH_DISPOSE),
                sprintf('**«%s» يُتلف دفعةً** — وهو إخراجُ دواءٍ من رصيد '
                    .'المخزون بلا مقابلٍ ولا موافقة.', $code));
        }
    }

    /**
     * **⑤ وموظّفُ منشأةٍ أخرى لا يُسنَد إليه دورُ هذه المنشأة.**
     *
     * والمعرّفُ يأتي من المتصفّح، فلا يُقبَل بلا فحص. (القاعدة الثامنة.)
     */
    /** @test */
    public function a_role_from_another_merchant_is_never_assigned(): void
    {
        $stranger = User::factory()->create([
            'type' => 3, 'role' => A::ROLE_MERCHANT, 'zone_code' => 'SOUTH',
            'is_kyc_verified' => 1, 'phone' => '967777000007',
        ]);

        MerchantProfile::create([
            'user_id' => $stranger->id,
            'business_type' => A::BIZ_PHARMACY,
            'business_name' => 'صيدليّةٌ غريبة',
            'verification_status' => 'verified',
            'subscription_plan' => A::PLAN_BUSINESS,
            'subscription_expires_at' => now()->addYear(),
        ]);

        $employee = User::factory()->create([
            'type' => 3, 'role' => 'merchant_staff', 'zone_code' => 'SOUTH',
            'phone' => '967778000008',
        ]);

        $this->expectException(\DomainException::class);

        // دورُ صيدليّتنا يُسنَد من قِبَل الغريب — ويجب أن يُردّ.
        app(MerchantPermissionService::class)
            ->assign($stranger, $employee, $this->roles['cashier']);
    }

    /**
     * **⑥ وموظّفُ منشأةٍ أخرى لا يفتح واجهةَ هذه الصيدليّة.**
     *
     * فالصلاحيّةُ داخل المنشأة شيء، **وعبورُ حدّ المنشأة شيءٌ آخر** —
     * والثاني خرقُ بياناتٍ لا خطأُ دور.
     */
    /** @test */
    public function staff_of_another_pharmacy_cannot_open_this_one(): void
    {
        $strangerOwner = User::factory()->create([
            'type' => 3, 'role' => A::ROLE_MERCHANT, 'zone_code' => 'SOUTH',
            'is_kyc_verified' => 1, 'phone' => '967779000009',
        ]);

        MerchantProfile::create([
            'user_id' => $strangerOwner->id,
            'business_type' => A::BIZ_PHARMACY,
            'business_name' => 'صيدليّةُ الجار',
            'verification_status' => 'verified',
            'subscription_plan' => A::PLAN_BUSINESS,
            'subscription_expires_at' => now()->addYear(),
        ]);

        $strangerPharmacy = app(PharmacyService::class)
            ->getOrCreatePharmacy($strangerOwner, ['name' => 'صيدليّةُ الجار']);

        Passport::actingAs($this->owner);

        // صنفٌ في صيدليّة الجار — ويُطلَب بمعرّفه من حسابنا.
        $foreign = app(PharmacyService::class)->addProduct($strangerPharmacy, [
            'trade_name' => 'دواءُ الجار', 'sale_price' => '80',
            'barcode' => '6291000000123',
        ]);

        $res = $this->getJson("/api/v1/amial/merchant/pharmacy/products/{$foreign->id}");

        $this->assertTrue($res->status() >= 400 || ! str_contains(
            (string) $res->getContent(), 'دواءُ الجار'), sprintf(
            '**صنفُ صيدليّةٍ أخرى قُرئ بمعرّفه (%d).** والمعرّفُ يأتي من '
            .'المتصفّح فيُغيَّر — فالنطاقُ يُشتقّ من الهويّة لا من الطلب. '
            .'(القاعدة الثامنة.)', $res->status()));
    }
}
