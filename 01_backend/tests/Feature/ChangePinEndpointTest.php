<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\User;
use App\Models\UserLogHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-PIN-SEPARATION-001 — مسار تغيير الرمز كما يستدعيه التطبيق فعلاً.
 *
 * `PinSeparationTest` يختبر TransactionPinService ويمرّ كلّه منذ كُتب. ومع
 * ذلك كان الخلل قائماً في المنتج، لأن الخدمة الصحيحة لم تكن موصولة بأي
 * مسار: المتحكّم القديم ينفّذ `$user->password = bcrypt($pin)` مباشرةً.
 *
 * أي أن الاختبارات كانت تُثبت صحّة شيفرة لا يستعملها أحد. ولذلك هذا الملفّ
 * يختبر نقطة النهاية نفسها — لا الخدمة — فهذا وحده ما كان سيكشف العلّة:
 *   - كلمة مرور الدخول تُمحى ويوضع مكانها أربعة أرقام.
 *   - رمز المعاملات لا يتغيّر رغم رسالة النجاح، لأن pin_check يقرأ
 *     transaction_pin أوّلاً وهو لم يُمَسّ.
 */
class ChangePinEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Pass@2026';

    private function customer(?string $pin = '1379'): User
    {
        $u = User::factory()->create([
            'type' => 2,
            'zone_code' => 'SOUTH',
            'password' => Hash::make(self::PASSWORD),
            'transaction_pin' => $pin,   // الـ cast يُعمّيه تلقائياً
        ]);

        EMoney::create([
            'user_id' => $u->id, 'current_balance' => '1000.0000',
            'held_balance' => '0.0000', 'pending_balance' => '0.0000',
            'charge_earned' => '0.0000', 'zone_code' => 'SOUTH',
        ]);

        UserLogHistory::create([
            'user_id' => $u->id,
            'device_id' => 'test-device',
            'os' => 'android',
            'device_model' => 'test',
        ]);

        return $u;
    }

    /** المسار القديم خلف CheckDeviceId — يحتاج ترويسة الجهاز. */
    private function changePin(User $u, array $body)
    {
        return $this->actingAs($u, 'api')
            ->withHeader('device-id', 'test-device')
            ->postJson('/api/v1/customer/change-pin', $body);
    }

    public function test_changing_the_pin_leaves_the_login_password_untouched(): void
    {
        $u = $this->customer();
        $before = $u->password;

        $this->changePin($u, [
            'old_pin' => '1379', 'new_pin' => '8264', 'confirm_pin' => '8264',
        ])->assertOk();

        // بيت القصيد: كلمة المرور كما كانت، ولم تصر أربعة أرقام.
        $this->assertSame($before, $u->fresh()->password);
        $this->assertTrue(Hash::check(self::PASSWORD, $u->fresh()->password));
        $this->assertFalse(Hash::check('8264', $u->fresh()->password));
    }

    public function test_the_new_pin_actually_takes_effect(): void
    {
        $u = $this->customer();

        $this->changePin($u, [
            'old_pin' => '1379', 'new_pin' => '8264', 'confirm_pin' => '8264',
        ])->assertOk();

        // كان الرمز القديم يبقى صالحاً بعد «تم التغيير بنجاح».
        $this->assertTrue(\App\CentralLogics\Helpers::pin_check($u->id, '8264'));
        $this->assertFalse(\App\CentralLogics\Helpers::pin_check($u->id, '1379'));
    }

    public function test_a_wrong_old_pin_changes_nothing(): void
    {
        $u = $this->customer();

        $this->changePin($u, [
            'old_pin' => '9999', 'new_pin' => '8264', 'confirm_pin' => '8264',
        ])->assertStatus(401);

        $this->assertTrue(\App\CentralLogics\Helpers::pin_check($u->id, '1379'));
        $this->assertTrue(Hash::check(self::PASSWORD, $u->fresh()->password));
    }

    /**
     * رفض الأرقام سهلة التخمين كان مكتوباً في الخدمة ولا أثر له في المنتج،
     * لأن المسار لم يكن يمرّ بها. وصلُها فعّله.
     */
    public function test_predictable_pins_are_now_refused(): void
    {
        $u = $this->customer();

        foreach (['1234', '0000', '4321'] as $weak) {
            $this->changePin($u, [
                'old_pin' => '1379', 'new_pin' => $weak, 'confirm_pin' => $weak,
            ])->assertStatus(403);
        }

        $this->assertTrue(\App\CentralLogics\Helpers::pin_check($u->id, '1379'));
    }

    /**
     * حساب قديم بلا transaction_pin: رمزه هو كلمة مروره (توافق 6cash).
     * التغيير ينقله إلى البنية الجديدة بلا أن يكسر دخوله.
     */
    public function test_a_legacy_account_migrates_without_losing_its_password(): void
    {
        $u = $this->customer(pin: null);

        $this->changePin($u, [
            'old_pin' => self::PASSWORD, 'new_pin' => '8264', 'confirm_pin' => '8264',
        ])->assertOk();

        $fresh = $u->fresh();
        $this->assertNotNull($fresh->transaction_pin);
        $this->assertTrue(Hash::check(self::PASSWORD, $fresh->password));
        $this->assertTrue(\App\CentralLogics\Helpers::pin_check($u->id, '8264'));
    }

    public function test_the_change_is_recorded_in_the_audit_chain(): void
    {
        $u = $this->customer();

        $this->changePin($u, [
            'old_pin' => '1379', 'new_pin' => '8264', 'confirm_pin' => '8264',
        ])->assertOk();

        $this->assertDatabaseHas('audit_decisions', [
            'subject_type' => 'pin',
            'subject_id' => (string) $u->id,
            'action' => 'PIN_CHANGED',
        ]);
    }
}
