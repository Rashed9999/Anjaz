<?php

namespace Tests\Feature;

use Tests\TestCase;

/** انتهاء مهلة قبول البائع جزء من حماية الرصيد، لا تعليق في الوثائق فقط. */
class SafePaymentExpiryScheduleTest extends TestCase
{
    public function test_expired_safe_payments_are_scheduled_for_refund(): void
    {
        $schedule = file_get_contents(base_path('routes/console.php'));

        $this->assertStringContainsString('ExpireSafePaymentsJob', $schedule);
        $this->assertStringContainsString('AMIAL-SAFE-PAYMENT: Refund payments', $schedule);
        $this->assertStringContainsString('->everyMinute()', $schedule);
    }
}
