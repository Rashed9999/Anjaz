<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccountNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * AMIAL-ACCOUNT-NUMBER-001 — رقم الحساب (8 أرقام + Luhn).
 */
class AccountNumberTest extends TestCase
{
    use RefreshDatabase;

    private AccountNumberService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(AccountNumberService::class);
    }

    /** @test */
    public function generated_number_is_8_digits_and_luhn_valid(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $num = $this->svc->generateOne();
            $this->assertMatchesRegularExpression('/^\d{8}$/', $num);
            $this->assertTrue($this->svc->luhnValid($num), "Luhn فشل لـ {$num}");
            $this->assertTrue($this->svc->isValid($num));
        }
    }

    /** @test */
    public function invalid_numbers_are_rejected(): void
    {
        $this->assertFalse($this->svc->isValid('1234567'));   // 7 خانات
        $this->assertFalse($this->svc->isValid('123456789')); // 9 خانات
        $this->assertFalse($this->svc->isValid('abcd1234'));  // حروف
        // رقم بخانة تحقّق خاطئة
        $good = $this->svc->generateOne();
        $bad = substr($good, 0, 7) . ((int)$good[7] === 0 ? '1' : '0');
        $this->assertFalse($this->svc->isValid($bad));
    }

    /** @test */
    public function new_user_gets_account_number_automatically(): void
    {
        $user = User::factory()->create();
        $this->assertNotNull($user->account_number);
        $this->assertTrue($this->svc->isValid($user->account_number));
    }

    /** @test */
    public function account_number_endpoint_repairs_a_legacy_account_without_one(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['account_number' => null])->save();

        Passport::actingAs($user);

        $this->getJson('/api/v1/amial/me/account-number')
            ->assertOk()
            ->assertJsonPath('success', true);

        $user->refresh();
        $this->assertNotEmpty($user->account_number);
        $this->assertTrue($this->svc->isValid($user->account_number));
    }

    /** @test */
    public function resolve_recipient_by_account_or_phone(): void
    {
        $user = User::factory()->create(['phone' => '+967700112233']);
        $acc = $user->account_number;

        $byAcc = $this->svc->resolveRecipient($acc);
        $this->assertNotNull($byAcc);
        $this->assertSame($user->id, $byAcc->id);

        $byPhone = $this->svc->resolveRecipient('+967700112233');
        $this->assertSame($user->id, $byPhone->id);

        // رقم غير موجود
        $this->assertNull($this->svc->resolveRecipient('+967700000000'));
    }
}
