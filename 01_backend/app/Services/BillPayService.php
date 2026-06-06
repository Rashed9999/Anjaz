<?php

namespace App\Services;

use App\Models\BillPaymentOrder;
use App\Models\BillProvider;
use App\Models\BillProviderRequest;
use App\Models\BillService;
use App\Models\BillServiceProduct;
use App\Models\User;
use App\Services\BillPay\BillProviderInterface;
use App\Services\BillPay\StubProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AMIAL-BILL-PAY-001 (v0.9-C)
 *
 * BillPayService — orchestration لدفع الفواتير.
 *
 * **التدفق الكامل (قسم 19 من الوثيقة):**
 *   1. inquire للحساب (اختياري)
 *   2. خلق order (status=pending)
 *   3. خصم من المحفظة (داخل DB::transaction)
 *   4. order.status=processing
 *   5. استدعاء provider.pay()
 *   6. حسب الـ response:
 *      - success: order.status=success، إصدار إيصال
 *      - pending: order.status=pending_provider_confirmation، schedule status check
 *      - failed: order.status=failed، إعادة المبلغ للمحفظة (refund)
 *
 * **مبادئ:**
 *   - كل request/response للمزود مُسجَّل في bill_provider_requests
 *   - لا success إلا بتأكيد المزود
 *   - failure تلقائية تُرجع المبلغ (atomic مع status change)
 *   - يعمل فقط مع SOUTH users (defense-in-depth)
 */
class BillPayService
{
    use \App\Traits\PostsToLedger;

    public function __construct(
        private readonly FinancialGuardService $guard,
        private readonly AuditService $audit,
        private readonly ReceiptService $receipts,
    ) {}

    /**
     * Resolve provider implementation. في v0.9-C كلها Stub.
     * v1.0 سيُسجَّل provider حقيقي حسب الـ provider->integration_type.
     */
    public function resolveProvider(BillProvider $provider): BillProviderInterface
    {
        return match ($provider->integration_type) {
            'stub' => new StubProvider(),
            // 'http' => app(\App\Services\BillPay\HttpProvider::class, ['provider' => $provider]),
            default => new StubProvider(),
        };
    }

    /**
     * إنشاء وتنفيذ order بشكل atomic.
     */
    public function createAndExecute(
        User $user,
        BillProvider $provider,
        BillService $service,
        ?BillServiceProduct $product,
        string $subscriberAccount,
        string $amount,
        array $subscriberExtra = [],
    ): BillPaymentOrder {
        // Pre-conditions
        if ($user->zone_code !== 'SOUTH') {
            throw new \RuntimeException('Only SOUTH users can pay bills');
        }
        if (!$provider->is_active) {
            throw new \RuntimeException('Provider is not active');
        }
        if (!$service->is_active) {
            throw new \RuntimeException('Service is not active');
        }
        if ($provider->zone_code !== 'SOUTH') {
            throw new \RuntimeException('Provider not available in SOUTH zone');
        }

        $amountNormalized = MoneyService::normalize($amount);
        $fee = $this->calculateFee($product, $amountNormalized);
        $totalDebited = MoneyService::add($amountNormalized, $fee);

        // 1. أنشئ الـ order و خصم في DB::transaction واحد
        $order = DB::transaction(function () use ($user, $provider, $service, $product, $subscriberAccount, $amountNormalized, $fee, $totalDebited, $subscriberExtra) {
            $order = BillPaymentOrder::create([
                'order_ulid' => (string) Str::ulid(),
                'user_id' => $user->id,
                'provider_id' => $provider->id,
                'service_id' => $service->id,
                'product_id' => $product?->id,
                'subscriber_account' => $subscriberAccount,
                'subscriber_extra' => $subscriberExtra,
                'amount' => $amountNormalized,
                'fee' => $fee,
                'total_debited' => $totalDebited,
                'status' => 'pending',
                'zone_code' => 'SOUTH',
            ]);

            // خصم من المحفظة (lock + insufficient_balance check)
            $this->guard->debit(
                userId: $user->id,
                amount: $totalDebited,
                reason: "bill_pay:{$service->code}",
            );

            // علم الـ order بالـ wallet tx id (لو احتجنا reverse لاحقاً)
            $order->update([
                'wallet_transaction_id' => $order->order_ulid,
                'status' => 'processing',
            ]);

            return $order;
        });

        // 2. استدعاء المزود (خارج DB::transaction — قد يطول)
        $providerImpl = $this->resolveProvider($provider);
        $providerResponse = null;

        try {
            $providerResponse = $providerImpl->pay(
                subscriberAccount: $subscriberAccount,
                amount: $amountNormalized,
                orderUlid: $order->order_ulid,
                extra: $subscriberExtra,
            );

            // سجل الـ request
            BillProviderRequest::create([
                'order_id' => $order->id,
                'provider_id' => $provider->id,
                'request_type' => 'pay',
                'request_payload' => [
                    'subscriber' => $subscriberAccount,
                    'amount' => $amountNormalized,
                    'order_ulid' => $order->order_ulid,
                ],
                'response_payload' => $providerResponse->rawResponse,
                'http_status' => $providerResponse->httpStatus,
                'latency_ms' => $providerResponse->latencyMs,
                'was_successful' => $providerResponse->success,
                'error_message' => $providerResponse->success ? null : $providerResponse->message,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // فشل تقني (timeout, exception)
            Log::error('BillPay provider call failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            BillProviderRequest::create([
                'order_id' => $order->id,
                'provider_id' => $provider->id,
                'request_type' => 'pay',
                'request_payload' => ['subscriber' => $subscriberAccount, 'amount' => $amountNormalized],
                'response_payload' => null,
                'http_status' => null,
                'latency_ms' => 0,
                'was_successful' => false,
                'error_message' => mb_substr($e->getMessage(), 0, 500),
                'created_at' => now(),
            ]);

            // عكس الخصم (refund) — provider لم يستقبل الأموال
            $this->refundOrder($order, 'Provider call failed: ' . mb_substr($e->getMessage(), 0, 200));
            return $order->fresh();
        }

        // 3. تحديث حالة الـ order حسب الـ response
        if ($providerResponse->status === 'success') {
            $this->finalizeSuccess($order, $providerResponse);
        } elseif ($providerResponse->status === 'pending') {
            $order->update([
                'status' => 'pending_provider_confirmation',
                'provider_reference' => $providerResponse->providerReference,
                'provider_message' => mb_substr($providerResponse->message ?? '', 0, 500),
            ]);
            // ملاحظة: job دوري يفحص الـ pending orders كل دقيقة (سيُضاف لـ console)
        } else {
            // failed → refund
            $this->refundOrder($order, $providerResponse->message ?? 'Provider rejected payment');
        }

        return $order->fresh();
    }

    /**
     * تأكيد order ناجح + إصدار إيصال.
     */
    private function finalizeSuccess(BillPaymentOrder $order, $providerResponse): void
    {
        $order->update([
            'status' => 'success',
            'provider_reference' => $providerResponse->providerReference,
            'provider_message' => mb_substr($providerResponse->message ?? '', 0, 500),
            'completed_at' => now(),
        ]);

        $this->receipts->issueDebit([
            'user_id' => $order->user_id,
            'reference_transaction_id' => $order->order_ulid,
            'receipt_type' => 'fee_charge', // الـ schema لا تحتوي bill_payment receipt_type
            'amount' => (string)$order->amount,
            'fee' => (string)$order->fee,
            'reference_type' => 'bill_payment_order',
            'reference_id' => $order->id,
            'metadata' => [
                'provider' => $order->provider->name ?? null,
                'service' => $order->service->display_name_ar ?? null,
                'subscriber' => $order->subscriber_account,
                'provider_ref' => $order->provider_reference,
            ],
            'zone_code' => 'SOUTH',
        ]);

        $this->audit->record([
            'actor_type' => 'system',
            'actor_user_id' => $order->user_id,
            'subject_type' => 'bill_payment_order',
            'subject_id' => (string)$order->id,
            'action' => 'BILL_PAY_SUCCESS',
            'decision_code' => 'BILL_PAY_OK',
            'severity' => 'info',
            'context' => [
                'amount' => $order->amount,
                'provider_ref' => $order->provider_reference,
            ],
        ]);

        // AMIAL-LEDGER-001 (v2.1): قيد محاسبي لدفع الفاتورة
        $this->safeLedgerPost(fn() => $this->ledgerBillPayment(
            fromUserId: $order->user_id,
            providerId: $order->provider_id ?? 0,
            grossAmount: MoneyService::add((string)$order->amount, (string)$order->fee),
            feeAmount: (string)$order->fee,
            sourceId: $order->order_ulid,
            description: 'تسديد فاتورة',
        ));
    }

    /**
     * عكس الخصم (refund) لـ order فاشل.
     */
    private function refundOrder(BillPaymentOrder $order, string $reason): void
    {
        if ($order->status === 'failed') {
            return; // already refunded
        }

        DB::transaction(function () use ($order, $reason) {
            // إرجاع المبلغ + الرسوم للمحفظة
            $this->guard->credit(
                userId: $order->user_id,
                amount: (string)$order->total_debited,
                reason: "bill_pay_refund:{$order->order_ulid}",
            );

            $order->update([
                'status' => 'failed',
                'provider_message' => mb_substr($reason, 0, 500),
                'reversed_at' => now(),
                'reverse_reason' => mb_substr($reason, 0, 255),
            ]);

            $this->audit->record([
                'actor_type' => 'system',
                'actor_user_id' => $order->user_id,
                'subject_type' => 'bill_payment_order',
                'subject_id' => (string)$order->id,
                'action' => 'BILL_PAY_FAILED_REFUNDED',
                'decision_code' => 'BILL_PAY_REFUND',
                'reason' => mb_substr($reason, 0, 255),
                'severity' => 'warning',
                'context' => ['refunded' => $order->total_debited],
            ]);
        });
    }

    /**
     * Job يستدعي هذه الدالة دورياً للـ pending orders.
     */
    public function reconcilePendingOrder(BillPaymentOrder $order): void
    {
        if ($order->status !== 'pending_provider_confirmation') {
            return;
        }
        if (!$order->provider_reference) {
            return;
        }

        $providerImpl = $this->resolveProvider($order->provider);
        try {
            $response = $providerImpl->checkStatus($order->provider_reference);
            BillProviderRequest::create([
                'order_id' => $order->id,
                'provider_id' => $order->provider_id,
                'request_type' => 'status_check',
                'request_payload' => ['ref' => $order->provider_reference],
                'response_payload' => $response->rawResponse,
                'http_status' => $response->httpStatus,
                'latency_ms' => $response->latencyMs,
                'was_successful' => $response->success,
                'error_message' => $response->success ? null : $response->message,
                'created_at' => now(),
            ]);

            if ($response->status === 'success') {
                $this->finalizeSuccess($order, $response);
            } elseif ($response->status === 'failed') {
                $this->refundOrder($order, 'Provider confirmed failure: ' . $response->message);
            }
            // pending → نتركها للمرة القادمة
        } catch (\Throwable $e) {
            Log::error('Reconcile failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }

    private function calculateFee(?BillServiceProduct $product, string $amount): string
    {
        if (!$product) return '0';
        $fixed = (string)($product->fee_amount ?? '0');
        $percentFee = bcmul($amount, bcdiv((string)($product->fee_percent ?? '0'), '100', 6), 4);
        return MoneyService::normalize(MoneyService::add($fixed, $percentFee));
    }
}
