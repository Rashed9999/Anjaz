<?php

namespace Tests\Feature;

use App\Models\Merchant\MerchantRole;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\Fuel\FuelTankService;
use App\Services\FuelStationService;
use App\Services\Merchant\MerchantPermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * AMIAL-FUEL-VERTICAL-001 · المرحلة ٨ — **العقدُ الذي تبني عليه الشاشات**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * شاشاتُ فلاتر تُرسم من `GET /fuel/me/permissions` و`GET /fuel/ops`. فإن
 * تغيّر شكلُ الردّ **رُسمت شاشةٌ فارغةٌ بلا خطأ في أيّ سجلّ** — وهذا نمطُ
 * العطل الأكثر تكراراً في هذا المشروع.
 *
 * **والحدُّ الحقيقيّ هنا لا في الواجهة:** إخفاءُ الزرّ ليس أماناً، ومن
 * يعرف المسار ينادي بلا زرّ. فتُقاس الأبوابُ نفسُها: أيصل الكاشيرُ إلى
 * اعتماد سعر؟ أيصل موظّفُ المخزون إلى الدفتر؟
 */
class FuelVerticalApiReachabilityTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/amial/merchant/fuel';

    private User $merchant;
    private User $cashier;
    private $station;

    protected function setUp(): void
    {
        parent::setUp();

        $fuel = app(FuelStationService::class);
        $tanks = app(FuelTankService::class);
        $perm = app(MerchantPermissionService::class);

        $this->merchant = User::factory()->create(['type' => 3, 'zone_code' => 'SOUTH']);
        MerchantProfile::create([
            'user_id' => $this->merchant->id, 'verification_status' => 'verified',
        ]);

        $this->station = $fuel->getOrCreateStation($this->merchant, [
            'station_name' => 'محطة العقد',
        ]);
        $pump = $fuel->addPump($this->station, ['pump_number' => 1]);
        $product = $fuel->addProduct($this->station, [
            'name' => 'بنزين', 'price_per_liter' => '1000',
        ]);
        $tank = $tanks->addTank($this->station, [
            'tank_number' => 1, 'fuel_product_id' => $product->id,
            'capacity_liters' => '10000',
        ]);
        $tanks->addNozzle($pump, [
            'nozzle_number' => 1, 'fuel_product_id' => $product->id,
            'tank_id' => $tank->id,
        ]);

        $perm->seedFuelRoles($this->merchant);

        $this->cashier = User::factory()->create(['type' => 3, 'zone_code' => 'SOUTH']);
        $perm->assign(
            $this->merchant, $this->cashier,
            MerchantRole::where('merchant_user_id', $this->merchant->id)
                ->where('code', 'cashier')->firstOrFail(),
        );
    }

    private function asUser(User $u): void
    {
        Passport::actingAs($u, [], 'api');
    }

    /**
     * @test
     *
     * **عقدُ `/me/permissions` — تُبنى منه القائمةُ الجانبيّة كلُّها.**
     *
     * فمفتاحٌ ناقصٌ هنا يُخرج لوحةً فارغةً في وجه المالك، بلا خطأٍ في أيّ
     * سجلّ. (مبنيٌّ ولا يُوصَل إليه.)
     */
    public function the_permissions_contract_carries_what_the_screen_needs(): void
    {
        $this->asUser($this->merchant);

        $r = $this->getJson(self::BASE . '/me/permissions');

        $r->assertOk()
            ->assertJsonStructure(['success', 'data' => [
                'is_owner', 'permissions', 'catalogue',
            ]]);

        $d = $r->json('data');

        $this->assertTrue($d['is_owner'],
            'المالك لا يُعرَّف مالكاً في الردّ — فلن تُرسم له أقسامٌ');

        $this->assertNotEmpty($d['permissions'],
            'قائمة الصلاحيات فارغة للمالك — واللوحة تُرسم منها');

        $this->assertNotEmpty($d['catalogue'],
            'الفهرس فارغ — فشاشة الأدوار تعرض رموزاً لا أسماء');

        // **الفهرسُ يحمل اسماً ومجموعةً لكلّ فعل** — وإلّا عُرض الرمزُ خاماً.
        $first = reset($d['catalogue']);
        $this->assertArrayHasKey('group', $first);
        $this->assertArrayHasKey('name', $first);
    }

    /**
     * @test
     *
     * **عقدُ `/ops` — مركزُ العمليّات في نداءٍ واحد.**
     */
    public function the_ops_contract_carries_the_live_state(): void
    {
        $this->asUser($this->merchant);

        $r = $this->getJson(self::BASE . '/ops');

        $r->assertOk()->assertJsonStructure(['data' => [
            'station', 'pumps', 'tanks',
            'unlinked_nozzles', 'pending_deliveries',
            'open_variances', 'pending_prices',
        ]]);

        $d = $r->json('data');

        // **«لا وردية» تُقال صراحةً مع طريق الخروج** — لا `null` صامت.
        $this->assertNull($d['shift'], 'لا وردية مفتوحة والردّ يقول غير ذلك');
        $this->assertNotEmpty($d['shift_note'],
            'لا وردية ولا سطرَ يقول ماذا يفعل المستعمل — بابٌ مغلقٌ بلا مخرج');

        $this->assertNotEmpty($d['pumps'], 'المضخّات لا تصل إلى الشاشة');
        $this->assertNotEmpty($d['tanks'], 'الخزّانات لا تصل إلى الشاشة');
    }

    /**
     * @test
     *
     * **والكاشيرُ يُمنع من الأبواب لا من الأزرار.**
     *
     * ══════════════════════════════════════════════════════════════════
     * فإخفاءُ الزرّ في فلاتر ليس أماناً: من يعرف المسار ينادي بلا زرّ.
     * وهذا الحارسُ يطرق البابَ نفسَه.
     */
    public function a_cashier_is_refused_at_the_door_not_at_the_button(): void
    {
        $this->asUser($this->cashier);

        // ما يملكه: يرى المضخّات (fuel.pump.view) — فمركزُ العمليّات يُفتح.
        $this->getJson(self::BASE . '/ops')->assertOk();

        // ما لا يملكه — **وكلٌّ يُرفض برسالةٍ مفهومة لا بـ٥٠٠**.
        foreach ([
            ['GET', '/tanks', 'الخزانات'],
            ['GET', '/deliveries', 'التوريدات'],
            ['GET', '/stock-variances', 'فروقات المخزون'],
            ['GET', '/roles', 'الأدوار'],
            ['POST', '/prices/propose', 'اقتراح سعر'],
        ] as [$method, $path, $label]) {
            $res = $method === 'GET'
                ? $this->getJson(self::BASE . $path)
                : $this->postJson(self::BASE . $path, []);

            $this->assertSame(422, $res->status(), sprintf(
                'الكاشير وصل إلى «%s» (%s %s) — والحدُّ في الخادم لا في الشاشة',
                $label, $method, $path,
            ));

            $this->assertFalse($res->json('success'));

            $this->assertNotEmpty($res->json('message'),
                "رفضٌ بلا رسالة على «{$label}» — والمستعمل يرى فراغاً");
        }
    }

    /**
     * @test
     *
     * **وصلاحيّاتُ الكاشير المُعادة هي ما تُرسم به شاشتُه.**
     *
     * فلو ردّ الخادمُ قائمةً أوسعَ لرُسم زرٌّ يُرفض عند الضغط — **يَعِد ثمّ
     * يُخلف**، وهو أسوأ من غيابه.
     */
    public function the_cashier_sees_only_what_he_can_actually_do(): void
    {
        $this->asUser($this->cashier);

        $d = $this->getJson(self::BASE . '/me/permissions')->assertOk()->json('data');

        $this->assertFalse($d['is_owner'], 'الكاشير يُعرَّف مالكاً — فيرى كلَّ شيء');

        $perms = $d['permissions'];

        $this->assertContains('fuel.sale.create', $perms,
            'الكاشير لا يملك البيع — وهذا كلُّ عمله');

        foreach ([
            'fuel.price.approve', 'fuel.delivery.post',
            'staff.manage', 'ledger.view', 'settlement.request',
        ] as $forbidden) {
            $this->assertNotContains($forbidden, $perms, sprintf(
                'الردّ يمنح الكاشير «%s» — فسيُرسم زرٌّ يُرفض عند الضغط',
                $forbidden,
            ));
        }
    }
}
