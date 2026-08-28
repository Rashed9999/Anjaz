<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\User;
use App\Services\KycGeoConsistencyService;
use App\Support\YemenGovernorates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\EstablishesKycEvidence;
use Tests\Support\OpensAccountsFromHub;
use Tests\TestCase;

/**
 * AMIAL-ZONE-GAP-001 / AMIAL-GOVERNORATES-001
 *
 * اليمن فيه بنكان مركزيان وعملتان تحملان اسم «الريال» بسعرين. النظام يحسب
 * بوحدة واحدة، فأي عملية عابرة للخط مراجحةُ عملة على حساب سيولة المنصّة —
 * متوازنة في الدفاتر وخاسرة في الواقع. هذه المجموعة تحرس أربع ثغرات كانت
 * تجعل سياسة المناطق شكلية أو مشلولة.
 */
class ZoneEnforcementGapsTest extends TestCase
{
    use OpensAccountsFromHub;
    use RefreshDatabase;
    use EstablishesKycEvidence;

    private function admin(): User
    {
        // AMIAL-ADMIN-DOORS-002 — **نوعُ الحساب لم يعد يفتح الباب.**
        //
        // ويُمنَح الدورُ ولا يُبدَّل التأكيد: هذه الاختباراتُ تُثبت
        // **قواعدَ عمل** (٤٢٢/٢٠٠)، فلو قُرئ ردُّها ٤٠٣ لمرّت لسببٍ
        // خاطئ — وتوقّفت عن حراسة القاعدة التي كُتبت لها.
        $u = User::factory()->create(['type' => ADMIN_TYPE]);
        app(\App\Services\PlatformRoleService::class)
            ->assign($u, \App\Services\PlatformRoleService::ADMIN);

        return $u->refresh();
    }

    private function createViaHub(array $extra = []): User
    {
        // AMIAL-KYC-DOSSIER-FIXTURE-001 — **الهاتفُ يُلتقط قبل أن يُرسَل.**
        //
        // كان يُولَّد داخلَ المصفوفة، وملفُّ «اعرف عميلك» يشتقّ منه رقمَ
        // الهويّة — فلو وُلّد مرّتين لاختلف الرقمان وسقط الاتّساق.
        $phone = $extra['phone'] ?? '77' . random_int(1000000, 9999999);

        $this->actingAs($this->admin(), 'user')
            ->postJson('/admin/amial/hub/customers/users', array_merge([
                'f_name' => 'اختبار', 'l_name' => 'مناطق',
                'phone' => $phone,
                'password' => 'Passw0rd!123',
            ] + $this->kycDossier($phone), $extra))->assertSuccessful();

        return User::latest('id')->first();
    }

    // ===================== الثغرة ١: الاعتماد لا يُسند منطقة =====================

    public function test_kyc_approval_assigns_the_operational_zone(): void
    {
        // العطل: assignFromKyc موجودة ولم يستدعِها مسار الاعتماد قطّ، فيُعتمد
        // العميل ويبقى UNKNOWN — وسياسة المناطق تمنع عنه كل عملية مالية أبداً.
        $user = $this->createViaHub(['residence_governorate' => 'YE-AD']);
        $this->assertSame('UNKNOWN', $user->zone_code);

        // اعتمادٌ بلا وثيقة مرفوض بحقّ — يُبنى الدليلُ أوّلاً.
        $this->establishKycEvidence($user);
        $this->actingAs($this->admin(), 'user')
            ->postJson("/admin/amial/hub/users/{$user->id}/kyc", ['status' => 1])
            ->assertSuccessful();

        $this->assertSame('SOUTH', $user->fresh()->zone_code);
    }

    public function test_approval_of_a_northern_residence_does_not_grant_south(): void
    {
        $user = $this->createViaHub(['residence_governorate' => 'YE-SN']); // صنعاء

        // اعتمادٌ بلا وثيقة مرفوض بحقّ — يُبنى الدليلُ أوّلاً.
        $this->establishKycEvidence($user);
        $this->actingAs($this->admin(), 'user')
            ->postJson("/admin/amial/hub/users/{$user->id}/kyc", ['status' => 1])
            ->assertSuccessful();

        $this->assertNotSame('SOUTH', $user->fresh()->zone_code);
    }

    public function test_approval_without_a_governorate_leaves_zone_unknown(): void
    {
        // لا نخمّن SOUTH عند غياب البيانات — ذلك الافتراض الخطير بعينه.
        $user = $this->createViaHub();

        // اعتمادٌ بلا وثيقة مرفوض بحقّ — يُبنى الدليلُ أوّلاً.
        $this->establishKycEvidence($user);
        $this->actingAs($this->admin(), 'user')
            ->postJson("/admin/amial/hub/users/{$user->id}/kyc", ['status' => 1])
            ->assertSuccessful();

        $this->assertSame('UNKNOWN', $user->fresh()->zone_code);
    }

    // ===================== الثغرة ٢: حساب اللوحة يُمنح SOUTH =====================

    public function test_hub_created_account_does_not_start_in_south(): void
    {
        $user = $this->createViaHub(['residence_governorate' => 'YE-SN']);

        $this->assertSame('UNKNOWN', $user->zone_code, 'الإنشاء لا يمنح منطقة تشغيلية');
        $this->assertSame('YE-SN', $user->residence_governorate);
    }

    // ===================== الثغرة ٣: الواجهة القديمة بلا حراسة =====================

    /** @dataProvider legacyMoneyRoutes */
    public function test_legacy_money_route_is_zone_guarded(string $route): void
    {
        // كانت routes/api/v1/api.php خالية تماماً من amial.zone، وفيها
        // send-money وcash-out وadd-money وcash-in الوكيل.
        $user = User::factory()->create(['type' => 2, 'zone_code' => 'NORTH']);
        EMoney::create([
            'user_id' => $user->id, 'current_balance' => '100000.0000',
            'held_balance' => '0.0000', 'pending_balance' => '0.0000',
            'charge_earned' => '0.0000', 'zone_code' => 'NORTH',
        ]);

        // المسارات القديمة خلف CheckDeviceId — نُسجّل جهازاً حتى يصل الطلب
        // إلى حارس المناطق فعلاً، وإلا اختبرنا الوسيط الخطأ.
        \App\Models\UserLogHistory::create([
            'user_id' => $user->id, 'device_id' => 'test-device', 'is_active' => 1,
        ]);

        $response = $this->actingAs($user, 'api')
            ->withHeader('device-id', 'test-device')
            ->postJson($route, []);

        $this->assertSame(
            403, $response->status(),
            "المسار {$route} يجب أن يُرفض لحساب خارج المنطقة التشغيلية"
        );
        $this->assertSame('TX_ZONE_BLOCKED', $response->json('code'));
    }

    public static function legacyMoneyRoutes(): array
    {
        return [
            // AMIAL-ZONE-BOUNDARY-001: send-money عملية دفتر فلا تُحجَب —
            // الحراسة على مسارات النقد، وهي المقصودة بهذا الاختبار.
            'سحب نقدي'       => ['/api/v1/customer/cash-out'],
            'شحن رصيد'       => ['/api/v1/customer/add-money'],
            'طلب سحب'        => ['/api/v1/customer/withdraw'],
        ];
    }

    // ===================== جدول المحافظات =====================

    public function test_governorates_table_is_complete_and_public(): void
    {
        $data = $this->getJson('/api/v1/amial/geo/governorates')->assertOk()->json('data');

        $this->assertCount(22, $data, 'اليمن 22 محافظة بأمانة العاصمة وسقطرى');

        $names = array_column($data, 'name');
        foreach (['عدن', 'حضرموت', 'صنعاء', 'أمانة العاصمة', 'سقطرى', 'تعز', 'المهرة'] as $expected) {
            $this->assertContains($expected, $names);
        }
    }

    public function test_governorate_list_marks_the_service_area(): void
    {
        $data = collect($this->getJson('/api/v1/amial/geo/governorates')->json('data'))
            ->keyBy('code');

        $this->assertTrue($data['YE-AD']['in_service_area'], 'عدن ضمن النطاق');
        $this->assertFalse($data['YE-SN']['in_service_area'], 'صنعاء خارجه');
        // المحافظات خارج النطاق تُعرض أيضاً: من أصله صنعاء ويسكن عدن يحتاجها.
        $this->assertArrayHasKey('YE-SN', $data->all());
    }

    public function test_free_text_governorate_names_resolve_to_codes(): void
    {
        // ما يكتبه الناس فعلاً وما يظهر في الهويات.
        $cases = [
            'عدن' => 'YE-AD', 'Aden' => 'YE-AD', 'كريتر' => 'YE-AD',
            'صنعا' => 'YE-SN', 'sanaa' => 'YE-SN',
            'الحديده' => 'YE-HU', 'Hodeidah' => 'YE-HU',
            'المكلا' => 'YE-HD', 'سيئون' => 'YE-HD',
            'حجه' => 'YE-HJ', 'Taizz' => 'YE-TA',
            'مديرية صيرة - عدن' => 'YE-AD',
        ];

        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, YemenGovernorates::codeFromName($input), "فشل: {$input}");
        }

        $this->assertNull(YemenGovernorates::codeFromName('القاهرة'));
        $this->assertNull(YemenGovernorates::codeFromName(''));
    }

    public function test_operational_area_is_configurable_not_hardcoded(): void
    {
        // خريطة السيطرة تتغيّر؛ تغييرها يجب أن يكون إعداداً لا إصداراً.
        $this->assertTrue(YemenGovernorates::isOperational('YE-AD'));

        config(['amial.operational_governorates' => ['YE-AD', 'YE-TA']]);

        $this->assertTrue(YemenGovernorates::isOperational('YE-TA'));
        $this->assertFalse(YemenGovernorates::isOperational('YE-HD'));
    }

    // ===================== المطابقة البشرية =====================

    public function test_matching_origin_and_residence_inside_area_is_clean(): void
    {
        $user = User::factory()->create([
            'type' => 2, 'origin_governorate' => 'YE-AD', 'residence_governorate' => 'YE-AD',
        ]);

        $result = app(KycGeoConsistencyService::class)->evaluate($user);

        $this->assertSame(KycGeoConsistencyService::LEVEL_OK, $result['level']);
        $this->assertTrue($result['operational']);
        $this->assertSame('SOUTH', $result['suggested_zone']);
    }

    public function test_origin_outside_but_residence_inside_is_review_not_block(): void
    {
        // النازح من إب الساكن في عدن عميل شرعي — الرفض الآلي يطرد عملاء حقيقيين.
        $user = User::factory()->create([
            'type' => 2, 'origin_governorate' => 'YE-IB', 'residence_governorate' => 'YE-AD',
        ]);

        $result = app(KycGeoConsistencyService::class)->evaluate($user);

        $this->assertSame(KycGeoConsistencyService::LEVEL_REVIEW, $result['level']);
        $this->assertTrue($result['operational']);
        $this->assertContains(
            'origin_differs_from_residence',
            array_column($result['flags'], 'code')
        );
    }

    public function test_residence_outside_the_area_blocks(): void
    {
        $user = User::factory()->create([
            'type' => 2, 'origin_governorate' => 'YE-AD', 'residence_governorate' => 'YE-SN',
        ]);

        $result = app(KycGeoConsistencyService::class)->evaluate($user);

        $this->assertSame(KycGeoConsistencyService::LEVEL_BLOCK, $result['level']);
        $this->assertFalse($result['operational']);
    }

    public function test_missing_residence_blocks_rather_than_guessing(): void
    {
        $user = User::factory()->create(['type' => 2]);

        $result = app(KycGeoConsistencyService::class)->evaluate($user);

        $this->assertSame(KycGeoConsistencyService::LEVEL_BLOCK, $result['level']);
        $this->assertContains('residence_missing', array_column($result['flags'], 'code'));
    }

    public function test_device_location_contradicting_residence_is_flagged(): void
    {
        $user = User::factory()->create([
            'type' => 2, 'origin_governorate' => 'YE-AD', 'residence_governorate' => 'YE-AD',
        ]);

        $result = app(KycGeoConsistencyService::class)->evaluate($user, [
            'latitude' => 15.3694, 'longitude' => 44.1910, // صنعاء
        ]);

        $codes = array_column($result['flags'], 'code');
        $this->assertContains('device_differs_from_residence', $codes);
        $this->assertContains('device_outside_operational_area', $codes);
        // إشارة لا حكم: لا يمنع الاعتماد وحده.
        $this->assertSame(KycGeoConsistencyService::LEVEL_REVIEW, $result['level']);
    }

    public function test_reviewer_sees_the_comparison_in_account_detail(): void
    {
        $user = $this->createViaHub([
            'origin_governorate' => 'YE-IB',
            'residence_governorate' => 'YE-AD',
        ]);

        $geo = $this->actingAs($this->admin(), 'user')
            ->getJson("/admin/amial/hub/users/{$user->id}/detail.json")
            ->assertOk()
            ->json('geo_check');

        $this->assertSame('إب', $geo['origin']['name']);
        $this->assertSame('عدن', $geo['residence']['name']);
        $this->assertTrue($geo['residence']['operational']);
        $this->assertFalse($geo['origin']['operational']);
        $this->assertNotEmpty($geo['flags']);
    }
}
