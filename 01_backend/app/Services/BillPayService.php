<?php

namespace App\Services;

use App\Jobs\ReconcileBillPaymentOrderJob;
use App\Models\BillPaymentOrder;
use App\Models\BillProvider;
use App\Models\BillProviderRequest;
use App\Models\BillProviderWebhookEvent;
use App\Models\BillService;
use App\Models\BillServiceProduct;
use App\Models\User;
use App\Services\BillPay\BillProviderBalanceInterface;
use App\Services\BillPay\BillProviderBalanceResponse;
use App\Services\BillPay\BillProviderInterface;
use App\Services\BillPay\BillProviderResponse;
use App\Services\BillPay\FreeSadadProvider;
use App\Services\BillPay\StubProvider;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AMIAL-BILL-HUB-001
 *
 * The single orchestration boundary between an AMIAL wallet and an external
 * bill provider. It owns the state machine, idempotency, holds/releases and
 * ledger posting; neither a controller nor an admin screen may bypass it.
 */
class BillPayService
{
    use \App\Traits\PostsToLedger;

    private const TX_ATTEMPTS = 3;
    private const RECONCILIATION_DELAY_SECONDS = 30;

    public function __construct(
        private readonly FinancialGuardService $guard,
        private readonly AuditService $audit,
        private readonly ReceiptService $receipts,
        private readonly FeeService $fees,
        private readonly KycTierService $kyc,
    ) {}

    /**
     * Resolve an explicit implementation. Unknown integration types fail
     * closed; silently falling back to StubProvider in production would send
     * customers into a fake payment path.
     */
    public function resolveProvider(BillProvider $provider): BillProviderInterface
    {
        return match ($provider->integration_type) {
            'stub' => app()->environment('production')
                ? throw new \RuntimeException('المزوّد التجريبي محظور في الإنتاج')
                : new StubProvider(),
            'free_sadad' => new FreeSadadProvider($provider),
            default => throw new \RuntimeException('نوع تكامل مزوّد الفواتير غير مدعوم'),
        };
    }

    /**
     * This quote is the only bill-pay fee calculation. Product fee_amount and
     * fee_percent are legacy catalog fields and are deliberately not a second
     * fee engine.
     *
     * @return array<string, mixed>
     */
    public function quote(User $user, string $amount): array
    {
        $quote = $this->fees->calculate('BILL_PAY', $amount, [
            'zone_code' => (string) ($user->zone_code ?: 'SOUTH'),
            'applies_to' => 'customer',
        ]);

        // A biller must receive the full bill principal. Receiver/merchant fee
        // bearer semantics have no valid counterpart in this operation.
        if (($quote['bearer'] ?? 'sender') !== 'sender') {
            throw new \RuntimeException('قاعدة رسم دفع الفواتير يجب أن يتحمّلها العميل');
        }

        return $quote;
    }

    /**
     * Compatibility wrapper for the WhatsApp confirmation flow. $product is
     * intentionally retained in the signature but never read for fees.
     */
    public function previewFee(?BillServiceProduct $product, string $amount, ?User $user = null): string
    {
        if ($user) {
            return (string) $this->quote($user, $amount)['fee'];
        }

        return (string) $this->fees->calculate('BILL_PAY', $amount, [
            'zone_code' => 'SOUTH',
            'applies_to' => 'customer',
        ])['fee'];
    }

    /**
     * Create exactly one AMIAL bill order and submit it once to the provider.
     *
     * A transport exception is never treated as a provider failure. Funds stay
     * held and the batch/callback reconciliation asks for the original
     * TransactionID later. This is the critical anti-double-charge rule.
     */
    public function createAndExecute(
        User $user,
        BillProvider $provider,
        BillService $service,
        ?BillServiceProduct $product,
        string $subscriberAccount,
        string $amount,
        array $subscriberExtra = [],
        ?string $idempotencyKey = null,
    ): BillPaymentOrder {
        $this->assertInputsAreConsistent($user, $provider, $service, $product);
        $this->assertSubscriberAccount($service, $subscriberAccount);

        $amountNormalized = MoneyService::normalize($amount);

        // AMIAL-CUSTOMER-SERVICES-KYC-001:
        // «السداد» خدمة Tier 1 في KycTierService. الواجهة تشرح القفل،
        // لكن التنفيذ المالي نفسه يجب أن يرفض Tier 0 حتى عند استدعاء API
        // مباشرة أو من قناة أخرى كواتساب.
        $this->kyc->assertIndividualTransactionAllowed(
            $user,
            $amountNormalized,
            'bill_pay',
        );

        $quote = $this->quote($user, $amountNormalized);
        $fee = (string) $quote['fee'];
        $totalDebited = (string) $quote['total_debit'];
        $idempotencyKey ??= (string) Str::ulid();

        [$order, $created] = $this->createOrderAndHold(
            user: $user,
            provider: $provider,
            service: $service,
            product: $product,
            subscriberAccount: $subscriberAccount,
            subscriberExtra: $subscriberExtra,
            amount: $amountNormalized,
            fee: $fee,
            totalDebited: $totalDebited,
            quote: $quote,
            idempotencyKey: $idempotencyKey,
        );

        // An HTTP retry, WhatsApp PIN replay, or competing request gets the
        // existing business order and must never call the external provider again.
        if (!$created) {
            return $order->fresh();
        }

        $this->markProviderAttempt($order);

        try {
            $response = $this->resolveProvider($provider)->pay(
                subscriberAccount: $subscriberAccount,
                amount: $amountNormalized,
                orderUlid: $order->order_ulid,
                extra: $this->providerExtra($service, $subscriberExtra),
            );
            $this->recordProviderRequest($order, 'pay', $response);
        } catch (\Throwable $e) {
            Log::warning('Bill provider outcome is uncertain; keeping funds held', [
                'order_ulid' => $order->order_ulid,
                'provider_id' => $provider->id,
                'exception' => get_class($e),
            ]);
            $this->recordProviderException($order, 'pay', $e);
            $this->markPendingConfirmation($order, null, 'تعذّر تأكيد نتيجة المزود؛ ستتم المراجعة تلقائياً');

            return $order->fresh();
        }

        if ($response->status === 'success') {
            $this->finalizeSuccess($order, $response);
        } elseif ($response->status === 'failed') {
            // Only a definitive provider rejection reaches this branch.
            $this->refundOrder($order, $response->message ?? 'المزوّد رفض عملية الدفع');
        } else {
            $this->markPendingConfirmation($order, $response->providerReference, $response->message);
        }

        return $order->fresh();
    }

    /**
     * Checks a pending order by our order ULID, not by an external reference.
     * Free Sadad documents TransactionID as the safe status-query key.
     */
    public function reconcilePendingOrder(BillPaymentOrder $order): void
    {
        $order = BillPaymentOrder::with('provider')->find($order->id) ?? $order;
        if (!$order->isPending()) {
            return;
        }

        $this->markProviderCheck($order);

        try {
            $response = $this->resolveProvider($order->provider)->checkStatus($order->order_ulid);
            $this->recordProviderRequest($order, 'status_check', $response);
        } catch (\Throwable $e) {
            Log::warning('Bill provider status check is uncertain', [
                'order_ulid' => $order->order_ulid,
                'provider_id' => $order->provider_id,
                'exception' => get_class($e),
            ]);
            $this->recordProviderException($order, 'status_check', $e);
            $this->markPendingConfirmation($order, null, 'تعذّر الاستعلام عن حالة الدفع؛ ستعاد المحاولة تلقائياً');
            return;
        }

        if ($response->status === 'success') {
            $this->finalizeSuccess($order, $response);
        } elseif ($response->status === 'failed') {
            $this->refundOrder($order, 'أكّد المزود فشل العملية: ' . ($response->message ?? 'غير معروف'));
        } else {
            $this->markPendingConfirmation($order, $response->providerReference, $response->message);
        }
    }

    /**
     * Records an authenticated Free Sadad callback in a durable inbox. The
     * callback itself never finalizes a wallet: it queues a status query.
     *
     * @param array<string, mixed> $payload
     */
    public function acceptFreeSadadWebhook(BillProvider $provider, array $payload): BillProviderWebhookEvent
    {
        if ($provider->integration_type !== 'free_sadad') {
            throw new \RuntimeException('هذا المزود لا يقبل Webhook فري سداد');
        }

        $expectedCode = (string) ($provider->credentials()['webhook_secret'] ?? '');
        $suppliedCode = (string) ($payload['WebHookCode'] ?? $payload['webhook_code'] ?? '');
        if ($expectedCode === '' || $suppliedCode === '' || !hash_equals($expectedCode, $suppliedCode)) {
            throw new \RuntimeException('Webhook verification failed');
        }

        $transactionId = trim((string) ($payload['TransactionID'] ?? $payload['transaction_id'] ?? ''));
        if ($transactionId === '') {
            throw new \RuntimeException('Webhook transaction ID is missing');
        }

        $normalized = $this->normalizeWebhookPayload($payload);
        $fingerprint = hash('sha256', implode('|', [
            $provider->id,
            $transactionId,
            (string) ($normalized['OperationStatus'] ?? ''),
            (string) ($normalized['ReferenceID'] ?? ''),
            (string) ($normalized['price'] ?? ''),
            (string) ($normalized['message'] ?? ''),
        ]));

        $created = false;
        try {
            [$event, $created] = DB::transaction(function () use ($provider, $transactionId, $normalized, $fingerprint) {
                $order = BillPaymentOrder::query()
                    ->where('provider_id', $provider->id)
                    ->where('order_ulid', $transactionId)
                    ->lockForUpdate()
                    ->first();

                $event = BillProviderWebhookEvent::create([
                    'provider_id' => $provider->id,
                    'order_id' => $order?->id,
                    'correlation_id' => $order?->correlation_id ?? $this->currentCorrelationId(),
                    'event_fingerprint' => $fingerprint,
                    'provider_transaction_id' => $transactionId,
                    'provider_reference' => isset($normalized['ReferenceID']) ? (string) $normalized['ReferenceID'] : null,
                    'operation_status' => isset($normalized['OperationStatus']) ? (string) $normalized['OperationStatus'] : null,
                    'price' => isset($normalized['price']) && is_numeric($normalized['price'])
                        ? MoneyService::normalize((string) $normalized['price']) : null,
                    'message' => isset($normalized['message']) ? mb_substr((string) $normalized['message'], 0, 500) : null,
                    'payload' => $normalized,
                    'received_at' => now(),
                    'processing_result' => $order ? BillProviderWebhookEvent::RESULT_QUEUED : BillProviderWebhookEvent::RESULT_IGNORED,
                ]);

                return [$event, true];
            }, self::TX_ATTEMPTS);
        } catch (QueryException $e) {
            if (!$this->isUniqueViolation($e)) {
                throw $e;
            }

            $event = BillProviderWebhookEvent::where('event_fingerprint', $fingerprint)->firstOrFail();
        }

        $this->audit->record([
            'actor_type' => 'provider',
            'subject_type' => 'bill_provider_webhook_event',
            'subject_id' => (string) $event->id,
            'action' => $created ? 'BILL_PROVIDER_WEBHOOK_RECEIVED' : 'BILL_PROVIDER_WEBHOOK_DUPLICATE',
            'decision_code' => $created ? 'BILL_WEBHOOK_QUEUED' : 'BILL_WEBHOOK_REPLAY',
            'severity' => 'notice',
            'correlation_id' => $event->correlation_id,
            'context' => [
                'provider_id' => $provider->id,
                'order_id' => $event->order_id,
                'transaction_id' => $transactionId,
                'operation_status' => $event->operation_status,
            ],
        ]);

        if ($created && $event->order_id) {
            ReconcileBillPaymentOrderJob::dispatch($event->order_id, $event->id)->afterCommit();
        }

        return $event;
    }

    /**
     * Read and persist a Free Sadad balance snapshot. It is an external
     * operational observation, never an AMIAL financial authorization source.
     */
    public function refreshProviderHealth(BillProvider $provider): BillProvider
    {
        if (!$provider->hasRequiredCredentials()) {
            $provider->forceFill([
                'integration_status' => 'not_configured',
                'last_health_checked_at' => now(),
                'last_health_message' => 'بيانات اعتماد المزود غير مكتملة',
                'is_active' => false,
            ])->save();
            return $provider->fresh();
        }

        try {
            $implementation = $this->resolveProvider($provider);
            if (!$implementation instanceof BillProviderBalanceInterface) {
                throw new \RuntimeException('المزوّد لا يدعم قراءة الرصيد');
            }
            $balance = $implementation->balance();
        } catch (\Throwable $e) {
            $balance = BillProviderBalanceResponse::unavailable('تعذّر اختبار اتصال المزود');
        }

        $base = [
            'last_health_checked_at' => now(),
            'last_health_message' => mb_substr((string) ($balance->message ?? 'لا توجد رسالة'), 0, 500),
        ];

        if ($balance->available) {
            $provider->forceFill($base + [
                'integration_status' => 'ready',
                'last_success_at' => now(),
                'last_known_balance' => $balance->amount,
                'balance_currency' => $balance->currency,
                'balance_checked_at' => now(),
                'failure_streak' => 0,
            ])->save();
        } else {
            // Failing closed prevents routing new customer payments through an
            // unverified provider. Existing pending orders remain reconcilable.
            $provider->forceFill($base + [
                'integration_status' => 'degraded',
                'last_failure_at' => now(),
                'failure_streak' => ((int) $provider->failure_streak) + 1,
                'is_active' => false,
            ])->save();
        }

        return $provider->fresh();
    }

    /** @return array{0: BillPaymentOrder, 1: bool} */
    private function createOrderAndHold(
        User $user,
        BillProvider $provider,
        BillService $service,
        ?BillServiceProduct $product,
        string $subscriberAccount,
        array $subscriberExtra,
        string $amount,
        string $fee,
        string $totalDebited,
        array $quote,
        string $idempotencyKey,
    ): array {
        try {
            return DB::transaction(function () use ($user, $provider, $service, $product, $subscriberAccount, $subscriberExtra, $amount, $fee, $totalDebited, $quote, $idempotencyKey) {
                $existing = BillPaymentOrder::query()
                    ->where('user_id', $user->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    return [$existing, false];
                }

                $order = BillPaymentOrder::create([
                    'order_ulid' => (string) Str::ulid(),
                    'idempotency_key' => $idempotencyKey,
                    'correlation_id' => $this->currentCorrelationId(),
                    'user_id' => $user->id,
                    'provider_id' => $provider->id,
                    'service_id' => $service->id,
                    'product_id' => $product?->id,
                    'subscriber_account' => $subscriberAccount,
                    'subscriber_extra' => $this->safeSubscriberExtra($subscriberExtra),
                    'amount' => $amount,
                    'fee' => $fee,
                    'total_debited' => $totalDebited,
                    'funds_state' => 'held',
                    'status' => 'pending',
                    'fee_scheme_id' => $quote['scheme_id'] ?? null,
                    'fee_scheme_version' => $quote['scheme_version'] ?? null,
                    'fee_configuration_state' => $quote['fee_configuration_state'] ?? null,
                    'zone_code' => (string) ($user->zone_code ?: 'SOUTH'),
                ]);

                // Hold is zero-sum inside the wallet. The principal + fee stays
                // attributable to the customer until Free Sadad gives a final result.
                $this->guard->hold(
                    userId: $user->id,
                    amount: $totalDebited,
                    reason: "bill_pay_hold:{$order->order_ulid}",
                );

                $order->update([
                    'wallet_transaction_id' => $order->order_ulid,
                    'status' => 'processing',
                ]);

                return [$order, true];
            }, self::TX_ATTEMPTS);
        } catch (QueryException $e) {
            if (!$this->isUniqueViolation($e)) {
                throw $e;
            }

            $existing = BillPaymentOrder::where('user_id', $user->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                return [$existing, false];
            }
            throw $e;
        }
    }

    private function finalizeSuccess(BillPaymentOrder $order, BillProviderResponse $response): void
    {
        $finalized = false;

        DB::transaction(function () use ($order, $response, &$finalized) {
            $locked = BillPaymentOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->status === 'success') {
                return;
            }
            if (in_array($locked->status, ['failed', 'reversed'], true)) {
                Log::critical('Provider success conflicts with finalized bill order', [
                    'order_ulid' => $locked->order_ulid,
                    'order_status' => $locked->status,
                ]);
                return;
            }

            $this->captureHeldFunds($locked);

            // Post both sides of the final operation under the same transaction
            // as the order state. A pending order is preferable to success with
            // no ledger entry.
            $this->ledgerBillPayment(
                fromUserId: $locked->user_id,
                providerId: $locked->provider_id,
                grossAmount: (string) $locked->total_debited,
                feeAmount: (string) $locked->fee,
                sourceId: $locked->order_ulid,
                description: 'تسديد فاتورة',
            );

            $locked->update([
                'status' => 'success',
                'funds_state' => $locked->funds_state === 'held' ? 'captured' : $locked->funds_state,
                'provider_reference' => $response->providerReference ?: $locked->provider_reference,
                'provider_message' => mb_substr((string) ($response->message ?? ''), 0, 500),
                'completed_at' => now(),
                'next_reconciliation_at' => null,
            ]);
            $finalized = true;
        }, self::TX_ATTEMPTS);

        if (!$finalized) {
            return;
        }

        $fresh = $order->fresh(['provider', 'service']);
        try {
            $this->receipts->issueDebit([
                'user_id' => $fresh->user_id,
                'reference_transaction_id' => $fresh->order_ulid,
                // Existing receipt enum has no bill_payment type yet.
                'receipt_type' => 'fee_charge',
                'amount' => (string) $fresh->amount,
                'fee' => (string) $fresh->fee,
                'reference_type' => 'bill_payment_order',
                'reference_id' => $fresh->id,
                'metadata' => [
                    'provider' => $fresh->provider?->display_name_ar ?? $fresh->provider?->name,
                    'service' => $fresh->service?->display_name_ar,
                    'provider_reference' => $fresh->provider_reference,
                ],
                'zone_code' => $fresh->zone_code,
            ]);
        } catch (\Throwable $e) {
            // Receipt generation is not allowed to roll back a confirmed provider
            // charge. The operation remains traceable through the order + ledger.
            Log::error('Bill payment receipt issuance failed', ['order_ulid' => $fresh->order_ulid]);
        }

        $this->audit->record([
            'actor_type' => 'system',
            'actor_user_id' => $fresh->user_id,
            'subject_type' => 'bill_payment_order',
            'subject_id' => (string) $fresh->id,
            'action' => 'BILL_PAY_SUCCESS',
            'decision_code' => 'BILL_PAY_OK',
            'severity' => 'info',
            'transaction_id' => $fresh->order_ulid,
            'idempotency_key' => $fresh->idempotency_key,
            'correlation_id' => $fresh->correlation_id,
            'context' => [
                'amount' => $fresh->amount,
                'fee' => $fresh->fee,
                'provider_ref' => $fresh->provider_reference,
                'fee_scheme_id' => $fresh->fee_scheme_id,
                'fee_scheme_version' => $fresh->fee_scheme_version,
            ],
        ]);
    }

    /** Release a known-failed order exactly once. */
    private function refundOrder(BillPaymentOrder $order, string $reason): void
    {
        $released = false;

        DB::transaction(function () use ($order, $reason, &$released) {
            $locked = BillPaymentOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->status === 'failed') {
                return;
            }
            if (in_array($locked->status, ['success', 'reversed'], true)) {
                Log::critical('Provider failure conflicts with finalized bill order', [
                    'order_ulid' => $locked->order_ulid,
                    'order_status' => $locked->status,
                ]);
                return;
            }

            $this->releaseFunds($locked);
            $locked->update([
                'status' => 'failed',
                'funds_state' => $locked->funds_state === 'held' ? 'released' : 'legacy_refunded',
                'provider_message' => mb_substr($reason, 0, 500),
                'reversed_at' => now(),
                'reverse_reason' => mb_substr($reason, 0, 255),
                'next_reconciliation_at' => null,
            ]);
            $released = true;
        }, self::TX_ATTEMPTS);

        if (!$released) {
            return;
        }

        $fresh = $order->fresh();
        $this->audit->record([
            'actor_type' => 'system',
            'actor_user_id' => $fresh->user_id,
            'subject_type' => 'bill_payment_order',
            'subject_id' => (string) $fresh->id,
            'action' => 'BILL_PAY_FAILED_RELEASED',
            'decision_code' => 'BILL_PAY_RELEASE',
            'reason' => mb_substr($reason, 0, 255),
            'severity' => 'warning',
            'transaction_id' => $fresh->order_ulid,
            'idempotency_key' => $fresh->idempotency_key,
            'correlation_id' => $fresh->correlation_id,
            'context' => ['released' => $fresh->total_debited],
        ]);
    }

    private function captureHeldFunds(BillPaymentOrder $order): void
    {
        if ($order->funds_state === 'held') {
            $this->guard->captureHold(
                userId: $order->user_id,
                amount: (string) $order->total_debited,
                reason: "bill_pay_capture:{$order->order_ulid}",
            );
        }
        // Null means an order created by the old direct-debit implementation.
        // It has already left current_balance and must not be captured again.
    }

    private function releaseFunds(BillPaymentOrder $order): void
    {
        if ($order->funds_state === 'held') {
            $this->guard->releaseHold(
                userId: $order->user_id,
                amount: (string) $order->total_debited,
                reason: "bill_pay_release:{$order->order_ulid}",
            );
            return;
        }

        // Compatibility for orders created before the hold state machine.
        if ($order->funds_state === null || $order->funds_state === 'legacy_debited') {
            $this->guard->credit(
                userId: $order->user_id,
                amount: (string) $order->total_debited,
                reason: "bill_pay_legacy_refund:{$order->order_ulid}",
            );
        }
    }

    private function markProviderAttempt(BillPaymentOrder $order): void
    {
        DB::transaction(function () use ($order) {
            $locked = BillPaymentOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->isPending()) {
                $locked->increment('provider_attempt_count');
            }
        }, self::TX_ATTEMPTS);
    }

    private function markProviderCheck(BillPaymentOrder $order): void
    {
        DB::transaction(function () use ($order) {
            $locked = BillPaymentOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->isPending()) {
                $locked->increment('provider_attempt_count');
                $locked->last_provider_check_at = now();
                $locked->save();
            }
        }, self::TX_ATTEMPTS);
    }

    private function markPendingConfirmation(BillPaymentOrder $order, ?string $providerReference, ?string $message): void
    {
        $changed = false;
        DB::transaction(function () use ($order, $providerReference, $message, &$changed) {
            $locked = BillPaymentOrder::query()->lockForUpdate()->findOrFail($order->id);
            if (!$locked->isPending()) {
                return;
            }

            $wasPending = $locked->status === 'pending_provider_confirmation';
            $locked->update([
                'status' => 'pending_provider_confirmation',
                'provider_reference' => $providerReference ?: $locked->provider_reference,
                'provider_message' => mb_substr((string) ($message ?? $locked->provider_message ?? ''), 0, 500),
                'next_reconciliation_at' => now()->addSeconds(self::RECONCILIATION_DELAY_SECONDS),
            ]);
            $changed = !$wasPending;
        }, self::TX_ATTEMPTS);

        if ($changed) {
            $fresh = $order->fresh();
            $this->audit->record([
                'actor_type' => 'system',
                'actor_user_id' => $fresh->user_id,
                'subject_type' => 'bill_payment_order',
                'subject_id' => (string) $fresh->id,
                'action' => 'BILL_PAY_PENDING_CONFIRMATION',
                'decision_code' => 'BILL_PAY_PENDING',
                'severity' => 'notice',
                'transaction_id' => $fresh->order_ulid,
                'idempotency_key' => $fresh->idempotency_key,
                'correlation_id' => $fresh->correlation_id,
                'context' => ['funds_state' => $fresh->funds_state],
            ]);
        }
    }

    private function recordProviderRequest(BillPaymentOrder $order, string $type, BillProviderResponse $response): void
    {
        try {
            BillProviderRequest::create([
                'order_id' => $order->id,
                'provider_id' => $order->provider_id,
                'correlation_id' => $order->correlation_id ?? $this->currentCorrelationId(),
                'request_type' => $type,
                'request_payload' => $this->requestLogPayload($order),
                'response_payload' => $this->redactPayload($response->rawResponse),
                'http_status' => $response->httpStatus,
                'latency_ms' => $response->latencyMs,
                'was_successful' => $response->status === 'success',
                'error_message' => $response->status === 'success' ? null : mb_substr((string) $response->message, 0, 500),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Could not persist provider request log', ['order_ulid' => $order->order_ulid]);
        }
    }

    private function recordProviderException(BillPaymentOrder $order, string $type, \Throwable $e): void
    {
        try {
            BillProviderRequest::create([
                'order_id' => $order->id,
                'provider_id' => $order->provider_id,
                'correlation_id' => $order->correlation_id ?? $this->currentCorrelationId(),
                'request_type' => $type,
                'request_payload' => $this->requestLogPayload($order),
                'response_payload' => null,
                'http_status' => null,
                'latency_ms' => 0,
                'was_successful' => false,
                'error_message' => mb_substr(get_class($e) . ': ' . $e->getMessage(), 0, 500),
                'created_at' => now(),
            ]);
        } catch (\Throwable $ignored) {
            Log::error('Could not persist provider exception log', ['order_ulid' => $order->order_ulid]);
        }
    }

    /** @return array<string, mixed> */
    private function providerExtra(BillService $service, array $subscriberExtra): array
    {
        $rules = is_array($service->account_validation_rules) ? $service->account_validation_rules : [];
        $routing = is_array($rules['free_sadad'] ?? null) ? $rules['free_sadad'] : [];
        return array_merge($subscriberExtra, [
            '_amial' => ['service_routing' => is_array($routing) ? $routing : []],
        ]);
    }

    /** @return array<string, mixed> */
    private function safeSubscriberExtra(array $extra): array
    {
        unset($extra['_amial']);
        return $extra;
    }

    /** @return array<string, mixed> */
    private function requestLogPayload(BillPaymentOrder $order): array
    {
        return [
            'transaction_id' => $order->order_ulid,
            'subscriber_suffix' => $this->maskSubscriber($order->subscriber_account),
            'amount' => (string) $order->amount,
            'attempt' => (int) $order->provider_attempt_count,
            'correlation_id' => $order->correlation_id,
        ];
    }

    private function maskSubscriber(string $value): string
    {
        $length = mb_strlen($value);
        return $length <= 4 ? str_repeat('•', $length) : str_repeat('•', $length - 4) . mb_substr($value, -4);
    }

    private function currentCorrelationId(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        $value = app('request')->attributes->get('amial.correlation_id');
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function redactPayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            $normalized = strtolower((string) $key);
            if (in_array($normalized, ['password', 'token', 'access_token', 'api_token', 'webhookcode', 'webhook_code'], true)) {
                $payload[$key] = '[REDACTED]';
            } elseif (in_array($normalized, ['mobilenumber', 'subscriber', 'subscriber_account', 'accountnumber'], true) && is_string($value)) {
                $payload[$key] = $this->maskSubscriber($value);
            } elseif (is_array($value)) {
                $payload[$key] = $this->redactPayload($value);
            }
        }
        return $payload;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function normalizeWebhookPayload(array $payload): array
    {
        $normalized = [
            'OperationStatus' => $payload['OperationStatus'] ?? $payload['operation_status'] ?? null,
            'TransactionID' => $payload['TransactionID'] ?? $payload['transaction_id'] ?? null,
            'ReferenceID' => $payload['ReferenceID'] ?? $payload['reference_id'] ?? null,
            'price' => $payload['price'] ?? null,
            'message' => $payload['message'] ?? null,
        ];
        return $this->redactPayload($normalized);
    }

    private function assertInputsAreConsistent(User $user, BillProvider $provider, BillService $service, ?BillServiceProduct $product): void
    {
        $zone = (string) ($user->zone_code ?: 'SOUTH');
        if (!$provider->is_active || !$service->is_active) {
            throw new \RuntimeException('خدمة دفع الفواتير غير متاحة حالياً');
        }
        if ($provider->zone_code !== $zone) {
            throw new \RuntimeException('خدمة دفع الفواتير غير متاحة في منطقتك');
        }
        if ((int) $service->provider_id !== (int) $provider->id) {
            throw new \RuntimeException('الخدمة لا تتبع المزود المحدد');
        }
        if ($product && (int) $product->service_id !== (int) $service->id) {
            throw new \RuntimeException('المنتج لا يتبع خدمة الفاتورة المحددة');
        }
        if (!$provider->isReadyForPayments()) {
            throw new \RuntimeException('تكامل مزود الفواتير غير جاهز لاستقبال المدفوعات');
        }
    }

    private function assertSubscriberAccount(BillService $service, string $subscriberAccount): void
    {
        $rules = is_array($service->account_validation_rules) ? $service->account_validation_rules : [];
        $pattern = $rules['regex'] ?? null;
        if (!is_string($pattern) || trim($pattern) === '') {
            return;
        }
        set_error_handler(static fn () => true);
        $matched = preg_match($pattern, $subscriberAccount);
        restore_error_handler();
        if ($matched !== 1) {
            throw new \RuntimeException('رقم الحساب لا يطابق صيغة الخدمة');
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return str_contains((string) $e->getCode(), '23') || str_contains(strtolower($e->getMessage()), 'duplicate');
    }
}
