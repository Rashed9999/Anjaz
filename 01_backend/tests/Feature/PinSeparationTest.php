<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TransactionPinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-PIN-SECURITY-001
 *
 * PinSeparationTest — يتأكد من فصل PIN عن password.
 *
 * يغطي AUDIT 1.5 ومتطلبات قسم 9 من الوثيقة.
 */
class PinSeparationTest extends TestCase
{
    use RefreshDatabase;

    private TransactionPinService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(TransactionPinService::class);
    }

    /** @test */
    public function setting_pin_does_not_touch_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('OriginalPassword123'),
        ]);

        $originalPasswordHash = $user->password;

        $this->svc->setPin($user, '4827');

        $user->refresh();

        // password لم يتغير
        $this->assertSame($originalPasswordHash, $user->password);
        // PIN تم تعيينه
        $this->assertNotNull($user->transaction_pin);
        // وهما قيمتان مختلفتان
        $this->assertNotSame($user->password, $user->transaction_pin);
    }

    /** @test */
    public function correct_pin_verifies(): void
    {
        $user = User::factory()->create();
        $this->svc->setPin($user, '4827');
        $user->refresh();

        $this->assertTrue($this->svc->verify($user, '4827'));
    }

    /** @test */
    public function wrong_pin_fails_and_increments_counter(): void
    {
        $user = User::factory()->create();
        $this->svc->setPin($user, '4827');
        $user->refresh();

        $this->assertFalse($this->svc->verify($user, '0000'));

        $user->refresh();
        $this->assertSame(1, $user->pin_failed_attempts);
    }

    /** @test */
    public function pin_locks_after_5_failed_attempts(): void
    {
        $user = User::factory()->create();
        $this->svc->setPin($user, '4827');

        for ($i = 0; $i < 5; $i++) {
            $user->refresh();
            $this->svc->verify($user, '9999');
        }

        $user->refresh();
        $this->assertNotNull($user->pin_locked_until);
        $this->assertTrue($user->pin_locked_until->isFuture());

        // حتى الـ PIN الصحيح يُرفض أثناء القفل
        $this->assertFalse($this->svc->verify($user, '4827'));
    }

    /** @test */
    public function successful_pin_resets_failed_counter(): void
    {
        $user = User::factory()->create();
        $this->svc->setPin($user, '4827');

        // 3 محاولات فاشلة
        for ($i = 0; $i < 3; $i++) {
            $user->refresh();
            $this->svc->verify($user, '9999');
        }
        $user->refresh();
        $this->assertSame(3, $user->pin_failed_attempts);

        // محاولة ناجحة
        $this->svc->verify($user, '4827');

        $user->refresh();
        $this->assertSame(0, $user->pin_failed_attempts);
        $this->assertNull($user->pin_locked_until);
    }

    /** @test */
    public function pin_format_validation_rejects_short_pin(): void
    {
        $user = User::factory()->create();
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->setPin($user, '12'); // أقل من 4
    }

    /** @test */
    public function pin_format_validation_rejects_non_numeric(): void
    {
        $user = User::factory()->create();
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->setPin($user, 'abcd');
    }

    /** @test */
    public function weak_pins_are_rejected(): void
    {
        $user = User::factory()->create();

        $weakPins = ['0000', '1111', '1234', '4321', '0123'];
        foreach ($weakPins as $weak) {
            $caught = false;
            try {
                $this->svc->setPin($user, $weak);
            } catch (\InvalidArgumentException $e) {
                $caught = true;
            }
            $this->assertTrue($caught, "Weak PIN '{$weak}' should have been rejected");
        }
    }

    /** @test */
    public function change_pin_requires_correct_old_pin(): void
    {
        $user = User::factory()->create();
        $this->svc->setPin($user, '4827');
        $user->refresh();

        $result = $this->svc->changePin($user, oldPin: '0000', newPin: '5678');
        $this->assertFalse($result);

        // PIN لم يتغير
        $user->refresh();
        $this->assertTrue($this->svc->verify($user, '4827'));
    }

    /** @test */
    public function change_pin_works_with_correct_old_pin(): void
    {
        $user = User::factory()->create();
        $this->svc->setPin($user, '4827');
        $user->refresh();

        $result = $this->svc->changePin($user, oldPin: '4827', newPin: '5678');
        $this->assertTrue($result);

        $user->refresh();
        $this->assertTrue($this->svc->verify($user, '5678'));
        $this->assertFalse($this->svc->verify($user, '4827'));
    }

    /** @test */
    public function changing_pin_to_same_pin_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->svc->setPin($user, '4827');
        $user->refresh();

        $result = $this->svc->changePin($user, oldPin: '4827', newPin: '4827');
        $this->assertFalse($result);
    }
}
