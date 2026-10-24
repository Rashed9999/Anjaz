<?php

namespace Tests\Feature;

use App\Console\Commands\EnsureDemoMerchants;
use App\Services\Otp\DemoNumberRegistry;
use App\Services\Otp\OtpPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemoAccountRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'amial.otp.demo_code' => '123456',
            'amial.otp.pilot_customer_phone_enabled' => false,
        ]);
    }

    /** @test */
    public function normal_accounts_do_not_get_the_global_pilot_phone_code_by_default(): void
    {
        $this->assertFalse((bool) config('amial.otp.pilot_customer_phone_enabled'));
        $this->assertNull(app(OtpPolicy::class)->pilotCustomerPhoneCode());
    }

    /** @test */
    public function every_bootstrap_merchant_sector_is_a_known_demo_identity(): void
    {
        $accounts = EnsureDemoMerchants::demoNumbers();
        DemoNumberRegistry::register($accounts);

        $policy = app(OtpPolicy::class);
        $this->assertCount(6, $accounts);

        foreach ($accounts as $account) {
            $this->assertTrue($policy->isDemo($account['phone']),
                "رقم قطاع العرض غير مسجّل: {$account['label']}");
        }
    }

    /** @test */
    public function re_running_bootstrap_never_reactivates_a_demo_number_closed_by_admin(): void
    {
        $account = EnsureDemoMerchants::demoNumbers()[0];
        DemoNumberRegistry::register([$account]);

        DB::table('otp_demo_numbers')->where('phone', $account['phone'])->update([
            'is_active' => false,
        ]);
        OtpPolicy::forget();

        DemoNumberRegistry::register([$account]);

        $this->assertSame(0, (int) DB::table('otp_demo_numbers')
            ->where('phone', $account['phone'])->value('is_active'));
        $this->assertFalse(app(OtpPolicy::class)->isDemo($account['phone']));
    }
}
