<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Safe Payment lifecycle tests start with an explicitly enabled intake.
 *
 * Control Center tests separately exercise PAUSE_NEW and missing-policy
 * failures. Setting this fixture inside each RefreshDatabase transaction
 * prevents inter-suite order from affecting historic payment tests without
 * bypassing the real SafePaymentService guard in production.
 */
trait StartsWithSafePaymentIntakeOpen
{
    protected function startWithSafePaymentIntakeOpen(): void
    {
        DB::table('control_center_policies')->updateOrInsert(
            ['feature_key' => 'safe_payment'],
            ['mode' => 'RUNNING', 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]
        );

        $this->assertSame(
            'RUNNING',
            DB::table('control_center_policies')
                ->where('feature_key', 'safe_payment')
                ->value('mode'),
            'The payment lifecycle test fixture must start with new intake explicitly allowed.'
        );
    }
}
