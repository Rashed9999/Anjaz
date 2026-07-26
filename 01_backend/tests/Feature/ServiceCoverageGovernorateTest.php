<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-COVERAGE-002 — تحديد المحافظة من التطبيق.
 *
 * كانت الشاشة الرئيسية تعرض «لم نتمكّن من تحديد محافظتك. حدّث عنوانك» —
 * وفيها خطآن: لا محاولة تحديد تقع أصلاً (الحقل يُقرأ من الملفّ الشخصي ولا
 * يُسأل عنه أحد)، ولا سبيل في التطبيق إلى تحديث العنوان. نصيحةٌ إلى طريق
 * مسدود تبقى معروضة إلى الأبد.
 */
class ServiceCoverageGovernorateTest extends TestCase
{
    use RefreshDatabase;

    private function customer(?string $governorate = null): User
    {
        $u = User::factory()->create([
            'type' => 2,
            'zone_code' => 'SOUTH',
            'residence_governorate' => $governorate,
        ]);

        EMoney::create([
            'user_id' => $u->id, 'current_balance' => '1000.0000',
            'held_balance' => '0.0000', 'pending_balance' => '0.0000',
            'charge_earned' => '0.0000', 'zone_code' => 'SOUTH',
        ]);

        return $u;
    }

    public function test_the_screen_says_the_governorate_is_missing_and_can_be_set(): void
    {
        $data = $this->actingAs($this->customer(), 'api')
            ->getJson('/api/v1/amial/service-coverage')
            ->assertOk()->json('data');

        $this->assertNull($data['governorate']);
        // العلَم هو ما يسمح للتطبيق بعرض زرّ بدل نصّ ميّت.
        $this->assertTrue($data['needs_governorate']);
    }

    public function test_setting_it_the_first_time_works_and_clears_the_notice(): void
    {
        $user = $this->customer();

        $this->actingAs($user, 'api')
            ->postJson('/api/v1/amial/me/governorate', ['governorate_code' => 'YE-AD'])
            ->assertOk();

        $this->assertSame('YE-AD', $user->fresh()->residence_governorate);

        $data = $this->actingAs($user->fresh(), 'api')
            ->getJson('/api/v1/amial/service-coverage')
            ->assertOk()->json('data');

        $this->assertSame('عدن', $data['governorate']);
        $this->assertFalse($data['needs_governorate']);
    }

    /**
     * العنوان بيانات KYC قُورنت بالهوية ووثيقة العنوان عند المراجعة.
     * تبديلها بضغطة يُفرغ تلك المقارنة من معناها.
     */
    public function test_changing_an_established_governorate_is_refused_and_logged(): void
    {
        $user = $this->customer('YE-AD');

        $this->actingAs($user, 'api')
            ->postJson('/api/v1/amial/me/governorate', ['governorate_code' => 'YE-SA'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'REVIEW_REQUIRED');

        $this->assertSame('YE-AD', $user->fresh()->residence_governorate);

        // الرفض الصامت أسوأ من الرفض: الطلب يُسجَّل ليراجعه الدعم.
        $this->assertDatabaseHas('audit_decisions', [
            'subject_type' => 'user',
            'subject_id' => (string) $user->id,
            'action' => 'RESIDENCE_CHANGE_REQUESTED',
        ]);
    }

    public function test_an_unknown_governorate_code_is_rejected(): void
    {
        $user = $this->customer();

        $this->actingAs($user, 'api')
            ->postJson('/api/v1/amial/me/governorate', ['governorate_code' => 'YE-ZZ'])
            ->assertStatus(422);

        $this->assertNull($user->fresh()->residence_governorate);
    }

    /** الحقل يخدم العرض لا الصلاحيات — لا يوسّع ما يستطيعه الحساب. */
    public function test_setting_it_does_not_touch_the_zone(): void
    {
        $user = $this->customer();
        $zone = $user->zone_code;

        $this->actingAs($user, 'api')
            ->postJson('/api/v1/amial/me/governorate', ['governorate_code' => 'YE-SA'])
            ->assertOk();

        $this->assertSame($zone, $user->fresh()->zone_code);
    }
}
