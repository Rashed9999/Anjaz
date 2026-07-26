<?php

namespace Tests\Feature;

use App\Services\ZoneAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-GEO-ZONE-001 — تحديد المحافظة من إحداثيات الجهاز.
 *
 * الغرض تجربةُ المستخدم: يرى اسم محافظته فيطمئنّ ويكفّ عن كتابة العنوان
 * يدوياً. وليس الأمن — ولذلك تختبر هذه المجموعة صراحةً أن النتيجة لا
 * تُسند إلى zone_code، لأن إحداثيات الجهاز تُزوَّر في دقائق.
 */
class GeoZoneResolveTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/amial/geo/resolve-zone';

    private function resolve(float $lat, float $lng): array
    {
        return $this->postJson(self::URL, ['latitude' => $lat, 'longitude' => $lng])
            ->assertOk()->json('data');
    }

    /** إحداثيات مدن حقيقية → المحافظة الصحيحة. */
    public static function cityProvider(): array
    {
        return [
            'عدن — كريتر'      => [12.7797, 45.0365, 'عدن', 'SOUTH'],
            'المكلا'           => [14.5424, 49.1242, 'حضرموت', 'SOUTH'],
            'سيئون'            => [15.9422, 48.7869, 'حضرموت', 'SOUTH'],
            'الغيضة'           => [16.2094, 52.1751, 'المهرة', 'SOUTH'],
            'حديبو — سقطرى'    => [12.6519, 54.0219, 'سقطرى', 'SOUTH'],
            'عتق'              => [14.5366, 46.8319, 'شبوة', 'SOUTH'],
            'الحوطة — لحج'     => [13.0567, 44.8819, 'لحج', 'SOUTH'],
            'صنعاء'            => [15.3694, 44.1910, 'صنعاء', 'NORTH'],
            'تعز'              => [13.5795, 44.0209, 'تعز', 'NORTH'],
            'الحديدة'          => [14.7979, 42.9545, 'الحديدة', 'NORTH'],
            'صعدة'             => [16.9402, 43.7637, 'صعدة', 'NORTH'],
        ];
    }

    /** @dataProvider cityProvider */
    public function test_real_city_coordinates_resolve_to_the_right_governorate(
        float $lat, float $lng, string $governorate, string $zone
    ): void {
        $data = $this->resolve($lat, $lng);

        $this->assertSame($governorate, $data['governorate']);
        $this->assertSame($zone, $data['zone']);
    }

    public function test_southern_coordinates_are_inside_the_service_area(): void
    {
        $data = $this->resolve(12.7855, 45.0187); // عدن

        $this->assertTrue($data['in_service_area']);
        $this->assertStringContainsString('عدن', $data['notice']);
        $this->assertStringContainsString('ضمن نطاق الخدمة', $data['notice']);
    }

    public function test_northern_coordinates_are_named_but_outside_the_service_area(): void
    {
        // الطمأنينة مطلوبة حتى عند الرفض: يعرف العميل أين هو ولماذا رُفض.
        $data = $this->resolve(15.3694, 44.1910); // صنعاء

        $this->assertSame('صنعاء', $data['governorate']);
        $this->assertFalse($data['in_service_area']);
        $this->assertStringContainsString('غير متاحة', $data['notice']);
    }

    public function test_coordinates_outside_yemen_do_not_guess_a_governorate(): void
    {
        // الرياض — أقرب مرساة قد تكون صعدة، ولا يجوز تسميتها.
        $data = $this->resolve(24.7136, 46.6753);

        $this->assertNull($data['governorate']);
        $this->assertSame('OTHER', $data['zone']);
        $this->assertFalse($data['in_service_area']);
    }

    public function test_invalid_coordinates_are_rejected(): void
    {
        $this->postJson(self::URL, ['latitude' => 999, 'longitude' => 0])->assertStatus(422);
        $this->postJson(self::URL, ['latitude' => 'اثنا عشر'])->assertStatus(422);
        $this->postJson(self::URL, [])->assertStatus(422);
    }

    public function test_gps_never_grants_an_operational_zone_by_itself(): void
    {
        // العقد الأمني: التسجيل يبدأ UNKNOWN مهما قال الجهاز عن موقعه.
        // لو منحت الإحداثيات SOUTH لكفى تطبيق موقع وهمي لتجاوز السياسة.
        $user = \App\Models\User::factory()->create(['type' => 2]);

        app(ZoneAssignmentService::class)->assignOnRegistration($user);

        $this->assertSame('UNKNOWN', $user->fresh()->zone_code);
    }

    public function test_service_reports_zone_for_coordinates_without_persisting_it(): void
    {
        $zones = app(ZoneAssignmentService::class);
        $user = \App\Models\User::factory()->create(['type' => 2, 'zone_code' => 'UNKNOWN']);

        $this->assertSame('SOUTH', $zones->zoneFromCoordinates(12.7855, 45.0187));
        $this->assertSame('UNKNOWN', $user->fresh()->zone_code, 'القراءة لا تكتب');
    }
}
