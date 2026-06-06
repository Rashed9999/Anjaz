<?php

namespace App\Services\BillPay;

use Illuminate\Support\Str;

/**
 * AMIAL-BILL-PAY-001 (v0.9-C)
 *
 * StubProvider — مزود وهمي للتطوير والاختبار.
 *
 * **سلوك تحاكي مزود حقيقي:**
 *   - 90% من العمليات تنجح (deterministic)
 *   - 5% تفشل
 *   - 5% pending (تحتاج status check لاحق)
 *
 * **مفعَّل افتراضياً في local/staging، معطل في production.**
 *
 * بمجرد توقيع عقد مع مزود حقيقي، يُكتب class جديد يُطبق
 * BillProviderInterface ويُسجَّل في الـ ServiceProvider بدل الـ Stub.
 */
class StubProvider implements BillProviderInterface
{
    public function name(): string
    {
        return 'stub';
    }

    public function inquire(string $subscriberAccount, array $extra = []): BillProviderResponse
    {
        // محاكاة latency
        usleep(50000); // 50ms

        // أرقام تنتهي بـ 0 = حساب غير موجود
        if (str_ends_with($subscriberAccount, '0')) {
            return BillProviderResponse::failure(
                'Subscriber account not found',
                ['account' => $subscriberAccount],
                latency: 50,
                httpStatus: 404,
            );
        }

        return BillProviderResponse::success(
            ref: 'stub-inquire-' . Str::random(8),
            message: 'Account is valid',
            raw: ['account' => $subscriberAccount, 'balance_due' => '0'],
            latency: 50,
        );
    }

    public function pay(string $subscriberAccount, string $amount, string $orderUlid, array $extra = []): BillProviderResponse
    {
        usleep(200000); // 200ms

        // ULID hash يحدد النتيجة (deterministic)
        $hash = crc32($orderUlid);
        $bucket = $hash % 100;

        if ($bucket < 90) {
            // 90% success
            return BillProviderResponse::success(
                ref: 'stub-pay-' . Str::random(12),
                message: 'Payment processed successfully',
                raw: [
                    'account' => $subscriberAccount,
                    'amount' => $amount,
                    'order' => $orderUlid,
                    'simulated' => true,
                ],
                latency: 200,
            );
        }

        if ($bucket < 95) {
            // 5% failure
            return BillProviderResponse::failure(
                'Provider rejected payment (insufficient credit at provider)',
                ['order' => $orderUlid],
                latency: 200,
                httpStatus: 402,
            );
        }

        // 5% pending
        return BillProviderResponse::pending(
            ref: 'stub-pay-pending-' . Str::random(12),
            message: 'Awaiting provider confirmation',
            raw: ['order' => $orderUlid],
            latency: 200,
        );
    }

    public function checkStatus(string $providerReference): BillProviderResponse
    {
        usleep(50000);
        // Pending references typically resolve to success after re-check
        if (str_contains($providerReference, 'pending')) {
            return BillProviderResponse::success(
                ref: $providerReference,
                message: 'Status: completed',
                raw: ['was_pending' => true],
                latency: 50,
            );
        }
        return BillProviderResponse::success(
            ref: $providerReference,
            message: 'Status: completed (already finalized)',
            latency: 50,
        );
    }

    public function reverse(string $providerReference, string $reason): BillProviderResponse
    {
        return BillProviderResponse::success(
            ref: 'stub-reverse-' . Str::random(8),
            message: 'Reversal accepted',
            raw: ['original_ref' => $providerReference, 'reason' => $reason],
            latency: 100,
        );
    }
}
