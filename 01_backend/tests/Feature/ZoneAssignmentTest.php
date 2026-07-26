<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ZoneAssignmentService;
use App\Services\ZonePolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-ZONE-ASSIGN-001 (v2.0) — اختبارات.
 *
 * يثبت أن الثغرة القديمة (الجميع SOUTH) مُصلَحة، وأن مستخدم صنعاء
 * لا يستطيع تنفيذ عمليات مالية.
 */
class ZoneAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private ZoneAssignmentService $zoneService;
    private ZonePolicyService $policyService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->zoneService = app(ZoneAssignmentService::class);
        $this->policyService = app(ZonePolicyService::class);
    }

    /** @test */
    public function new_user_starts_as_unknown_not_south()
    {
        // الإصلاح الأساسي: المستخدم الجديد UNKNOWN وليس SOUTH تلقائياً
        $user = User::factory()->create();
        $zone = $this->zoneService->assignOnRegistration($user);

        $this->assertEquals('UNKNOWN', $zone);
        $user->refresh();
        $this->assertEquals('UNKNOWN', $user->zone_code);
    }

    /** @test */
    public function sanaa_city_maps_to_north()
    {
        $this->assertEquals('NORTH', $this->zoneService->cityToZone('صنعاء'));
        $this->assertEquals('NORTH', $this->zoneService->cityToZone('Sanaa'));
        $this->assertEquals('NORTH', $this->zoneService->cityToZone('تعز'));
    }

    /** @test */
    public function aden_city_maps_to_south()
    {
        $this->assertEquals('SOUTH', $this->zoneService->cityToZone('عدن'));
        $this->assertEquals('SOUTH', $this->zoneService->cityToZone('Aden'));
        $this->assertEquals('SOUTH', $this->zoneService->cityToZone('المكلا'));
        $this->assertEquals('SOUTH', $this->zoneService->cityToZone('حضرموت'));
    }

    /** @test */
    public function unknown_city_maps_to_unknown()
    {
        $this->assertEquals('UNKNOWN', $this->zoneService->cityToZone('مدينة مجهولة'));
    }

    /** @test */
    public function sanaa_user_cannot_cash_out_but_can_transfer()
    {
        // مستخدم في صنعاء (NORTH) — السيناريو الذي سأل عنه المستخدم
        $sanaaUser = User::factory()->create();
        $this->zoneService->assignFromKyc($sanaaUser, 'صنعاء');
        $sanaaUser->refresh();

        $this->assertEquals('NORTH', $sanaaUser->zone_code);

        // AMIAL-ZONE-BOUNDARY-001: السحب النقدي يُرفض — هنا يعبر النقد
        // بين عملتين، وهو خطر العملتين بعينه.
        $cash = $this->policyService->authorize($sanaaUser, 'cash_out');
        $this->assertFalse($cash['allowed']);
        $this->assertEquals('TX_ZONE_BLOCKED', $cash['decision_code']);

        // أمّا التحويل فيبقى عاملاً: القيمة تنتقل داخل دفتر واحد بلا صرف،
        // فحجبه يعاقب من انتقل أو سافر بلا مقابل في الحماية.
        $ledger = $this->policyService->authorize($sanaaUser, 'send_money');
        $this->assertTrue($ledger['allowed']);
    }

    /** @test */
    public function sanaa_user_can_view_balance()
    {
        $sanaaUser = User::factory()->create();
        $this->zoneService->assignFromKyc($sanaaUser, 'صنعاء');
        $sanaaUser->refresh();

        // عرض الرصيد مسموح حتى في الشمال
        $result = $this->policyService->authorize($sanaaUser, 'view_balance');
        $this->assertTrue($result['allowed']);
        $this->assertEquals('ALLOWED_READ', $result['decision_code']);
    }

    /** @test */
    public function aden_user_can_send_money()
    {
        $adenUser = User::factory()->create();
        $this->zoneService->assignFromKyc($adenUser, 'عدن');
        $adenUser->refresh();

        $this->assertEquals('SOUTH', $adenUser->zone_code);

        $result = $this->policyService->authorize($adenUser, 'send_money');
        $this->assertTrue($result['allowed']);
        // AMIAL-ZONE-BOUNDARY-001: التحويل عملية دفتر — رمز القرار تغيّر لا الإذن
        $this->assertEquals('ALLOWED_LEDGER_ONLY', $result['decision_code']);
    }

    /** @test */
    public function unknown_zone_user_cannot_transact()
    {
        $user = User::factory()->create();
        $this->zoneService->assignOnRegistration($user); // → UNKNOWN
        $user->refresh();

        $result = $this->policyService->authorize($user, 'send_money');
        $this->assertFalse($result['allowed']);
        $this->assertEquals('ACCOUNT_ZONE_UNKNOWN', $result['decision_code']);
    }

    /** @test */
    public function admin_can_override_zone()
    {
        $user = User::factory()->create(['zone_code' => 'NORTH']);
        $admin = User::factory()->create();

        $zone = $this->zoneService->assignByAdmin($user, 'SOUTH', $admin->id, 'تحقق يدوي من الإقامة');
        $this->assertEquals('SOUTH', $zone);
        $user->refresh();
        $this->assertEquals('SOUTH', $user->zone_code);

        // الآن يستطيع التحويل
        $result = $this->policyService->authorize($user, 'send_money');
        $this->assertTrue($result['allowed']);
    }

    /** @test */
    public function admin_assign_rejects_invalid_zone()
    {
        $user = User::factory()->create();
        $admin = User::factory()->create();
        $this->expectException(\RuntimeException::class);
        $this->zoneService->assignByAdmin($user, 'WESTLAND', $admin->id, 'test');
    }

    /** @test */
    public function assignment_is_logged()
    {
        $user = User::factory()->create();
        $this->zoneService->assignFromKyc($user, 'عدن');

        $this->assertDatabaseHas('zone_assignment_logs', [
            'user_id' => $user->id,
            'assigned_zone' => 'SOUTH',
            'method' => 'kyc_verification',
        ]);
    }

    /** @test */
    public function city_normalization_handles_arabic_variants()
    {
        // أ/ا متغيرات
        $this->assertEquals(
            $this->zoneService->cityToZone('صنعاء'),
            $this->zoneService->cityToZone('صنعاءَ'), // مع تشكيل
        );
    }
}
