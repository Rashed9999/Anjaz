<?php

namespace App\Services\BillPay;

use App\Models\BillProvider;
use App\Services\MoneyService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * AMIAL-BILL-HUB-001 — Free Sadad adapter.
 *
 * This adapter intentionally makes no automatic retry for a payment request.
 * A retry after an uncertain HTTP outcome can recharge a customer twice. The
 * caller instead asks the documented operation-status endpoint using the same
 * TransactionID (the AMIAL order ULID) until the provider gives a final state.
 */
final class FreeSadadProvider implements BillProviderInterface, BillProviderBalanceInterface
{
    private const DEFAULT_BASE_URL = 'https://free-sadad.com/rest-api';

    private int $lastLatencyMs = 0;

    public function __construct(private readonly BillProvider $provider) {}

    public function name(): string
    {
        return 'free_sadad';
    }

    public function inquire(string $subscriberAccount, array $extra = []): BillProviderResponse
    {
        $routing = $this->routing($extra, 'inquiry');
        if ($routing['network_number'] === null || $routing['service_number'] === null) {
            return BillProviderResponse::failure('لم تُضبط بيانات استعلام الخدمة لدى فري سداد');
        }

        try {
            $response = $this->protectedPost([
                'NetworkNumber' => $routing['network_number'],
                'ServiceNumber' => $routing['service_number'],
                'MobileNumber' => $subscriberAccount,
                'TransactionID' => 'INQ-' . Str::upper(Str::random(18)),
            ]);
        } catch (ConnectionException $e) {
            // Inquiry does not move money, so a plain failure is appropriate.
            return BillProviderResponse::failure('تعذّر الاتصال بفري سداد أثناء الاستعلام');
        }

        $raw = $this->json($response);
        if (!$response->successful() || !$this->businessSuccess($raw)) {
            return BillProviderResponse::failure(
                $this->message($raw, 'تعذّر التحقق من الحساب لدى فري سداد'),
                $this->redact($raw),
                $this->latency($response),
                $response->status(),
            );
        }

        return BillProviderResponse::success(
            (string) ($raw['referenceID'] ?? $raw['transactionID'] ?? ''),
            $this->message($raw, 'تم التحقق من الحساب'),
            $this->redact($raw),
            $this->latency($response),
        );
    }

    public function pay(string $subscriberAccount, string $amount, string $orderUlid, array $extra = []): BillProviderResponse
    {
        $routing = $this->routing($extra, 'payment');
        if ($routing['network_number'] === null || $routing['service_number'] === null) {
            // This is a malformed request known before it reaches Free Sadad.
            return BillProviderResponse::failure('لم تُضبط بيانات دفع الخدمة لدى فري سداد');
        }

        $payload = [
            'NetworkNumber' => $routing['network_number'],
            'ServiceNumber' => $routing['service_number'],
            'MobileNumber' => $subscriberAccount,
            // It is the only safe key for lookup after an uncertain outcome.
            'TransactionID' => $orderUlid,
            'WebHookURL' => $this->webhookUrl(),
            'WebHookCode' => $this->credentials()['webhook_secret'],
        ];

        if ($routing['payment_mode'] === 'offer') {
            if (empty($routing['offer_code'])) {
                return BillProviderResponse::failure('لم يُضبط رمز العرض لدى فري سداد');
            }
            $payload['OfferCode'] = $routing['offer_code'];
        } else {
            $payload['Amount'] = MoneyService::normalize($amount);
        }

        $fields = $extra['fields'] ?? null;
        if (is_array($fields) && $fields !== []) {
            // Free Sadad documents this field as a JSON object for services that
            // need additional customer data. Never include AMIAL internal keys.
            $payload['Fields'] = $fields;
        }

        try {
            $response = $this->protectedPost($payload);
        } catch (ConnectionException $e) {
            // The request may have reached the provider. The caller keeps funds
            // held and queries TransactionID; it must not re-submit this payment.
            throw $e;
        }

        return $this->paymentResponse($response, $orderUlid);
    }

    public function checkStatus(string $providerReference): BillProviderResponse
    {
        try {
            $response = $this->protectedPost([
                'NetworkNumber' => 0,
                'ServiceNumber' => 2,
                // Free Sadad status lookup uses our original TransactionID.
                'TransactionID' => $providerReference,
            ]);
        } catch (ConnectionException $e) {
            throw $e;
        }

        return $this->paymentResponse($response, $providerReference);
    }

    public function reverse(string $providerReference, string $reason): BillProviderResponse
    {
        // No documented automatic-reversal endpoint is assumed. A made-up call
        // would be worse than no reversal: it could reverse an unrelated charge.
        return BillProviderResponse::failure('فري سداد لا يوفّر عكساً آلياً مهيأً لهذا التكامل');
    }

    public function balance(): BillProviderBalanceResponse
    {
        try {
            $response = $this->protectedPost([
                'NetworkNumber' => 0,
                'ServiceNumber' => 1,
            ]);
        } catch (ConnectionException $e) {
            return BillProviderBalanceResponse::unavailable('تعذّر الاتصال بفري سداد لقراءة الرصيد');
        }

        $raw = $this->json($response);
        $amount = $raw['agentBalance'] ?? null;
        if (!$response->successful() || !$this->businessSuccess($raw) || !is_numeric($amount)) {
            return BillProviderBalanceResponse::unavailable(
                $this->message($raw, 'تعذّر قراءة رصيد فري سداد'),
                $this->redact($raw),
                $this->latency($response),
                $response->status(),
            );
        }

        return BillProviderBalanceResponse::available(
            MoneyService::normalize((string) $amount),
            (string) (($this->provider->config ?? [])['currency'] ?? 'YER'),
            $this->message($raw, 'تمت قراءة الرصيد'),
            $this->redact($raw),
            $this->latency($response),
            $response->status(),
        );
    }

    private function paymentResponse(Response $response, string $transactionId): BillProviderResponse
    {
        $raw = $this->json($response);
        $latency = $this->latency($response);
        $message = $this->message($raw, 'لا توجد رسالة من فري سداد');
        $reference = (string) ($raw['referenceID'] ?? $raw['ReferenceID'] ?? $raw['transactionID'] ?? $transactionId);
        $operationStatus = $raw['operationStatus'] ?? $raw['OperationStatus'] ?? null;

        if ((string) $operationStatus === '1') {
            return BillProviderResponse::success($reference, $message, $this->redact($raw), $latency);
        }
        if ((string) $operationStatus === '0') {
            return BillProviderResponse::failure($message, $this->redact($raw), $latency, $response->status());
        }

        if ((string) $operationStatus === '-1') {
            return BillProviderResponse::pending($reference, $message, $this->redact($raw), $latency);
        }

        // The documented 4xx rejections occur before a payment operation is
        // accepted (malformed data, authentication/permission, or no provider
        // balance). Those can safely release the AMIAL hold. Any other ambiguous
        // body stays pending and is checked by TransactionID instead of retried.
        if (!$response->successful() && in_array($response->status(), [400, 401, 402, 403], true)) {
            return BillProviderResponse::failure($message, $this->redact($raw), $latency, $response->status());
        }

        return BillProviderResponse::pending($reference, $message, $this->redact($raw), $latency);
    }

    /** @return array{network_number:?int,service_number:?int,payment_mode:string,offer_code:?string} */
    private function routing(array $extra, string $purpose): array
    {
        $service = $extra['_amial']['service_routing'] ?? [];
        $providerConfig = $this->provider->config ?? [];
        $prefix = $purpose === 'inquiry' ? 'inquiry' : 'payment';

        $network = $service['network_number']
            ?? $service["{$prefix}_network_number"]
            ?? $providerConfig['network_number']
            ?? null;
        $serviceNumber = $service["{$prefix}_service_number"]
            ?? $providerConfig["{$prefix}_service_number"]
            ?? null;

        return [
            'network_number' => is_numeric($network) ? (int) $network : null,
            'service_number' => is_numeric($serviceNumber) ? (int) $serviceNumber : null,
            'payment_mode' => (string) ($service['payment_mode'] ?? $providerConfig['payment_mode'] ?? 'amount'),
            'offer_code' => isset($service['offer_code']) ? (string) $service['offer_code'] : null,
        ];
    }

    private function protectedPost(array $payload): Response
    {
        $startedAt = hrtime(true);
        $response = Http::acceptJson()
            ->asJson()
            ->timeout($this->timeout())
            ->withHeaders(['api-token' => $this->accessToken()])
            ->post($this->baseUrl(), $payload);
        $this->lastLatencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        return $response;
    }

    private function accessToken(): string
    {
        $credentials = $this->credentials();
        foreach (['username', 'account_number', 'password', 'api_token'] as $key) {
            if (empty($credentials[$key])) {
                throw new \RuntimeException('Free Sadad credentials are not configured');
            }
        }

        $signature = md5(
            $credentials['username']
            . $credentials['account_number']
            . md5($credentials['password'])
            . $credentials['api_token']
        );

        $response = Http::acceptJson()
            ->asJson()
            ->timeout($this->timeout())
            ->post(rtrim($this->baseUrl(), '/') . '/login', [
                'AccountNumber' => $credentials['account_number'],
                'UserName' => $credentials['username'],
                'Token' => $signature,
            ]);

        $raw = $this->json($response);
        $token = $raw['access_token'] ?? null;
        if (!$response->successful() || !$this->businessSuccess($raw) || !is_string($token) || $token === '') {
            throw new \RuntimeException('Free Sadad authentication failed');
        }

        return $token;
    }

    /** @return array<string, string> */
    private function credentials(): array
    {
        return $this->provider->credentials();
    }

    private function baseUrl(): string
    {
        $configured = (string) ($this->provider->endpoint_url ?: self::DEFAULT_BASE_URL);
        $parts = parse_url($configured);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? null) !== 'https' || ($host !== 'free-sadad.com' && !str_ends_with($host, '.free-sadad.com'))) {
            throw new \RuntimeException('Free Sadad endpoint is not an approved HTTPS host');
        }

        return rtrim($configured, '/');
    }

    private function timeout(): int
    {
        $timeout = (int) (($this->provider->config ?? [])['timeout_seconds'] ?? 15);
        return max(3, min($timeout, 60));
    }

    private function webhookUrl(): string
    {
        $configured = ($this->provider->config ?? [])['webhook_url'] ?? null;
        if (is_string($configured) && filter_var($configured, FILTER_VALIDATE_URL)) {
            return $configured;
        }

        return route('amial.bill-pay.free-sadad.webhook');
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        $json = $response->json();
        return is_array($json) ? $json : [];
    }

    /** @param array<string, mixed> $raw */
    private function businessSuccess(array $raw): bool
    {
        return ($raw['status'] ?? false) === true || ($raw['status'] ?? null) === 1 || ($raw['status'] ?? null) === '1';
    }

    /** @param array<string, mixed> $raw */
    private function message(array $raw, string $fallback): string
    {
        $message = $raw['message'] ?? $raw['Message'] ?? $fallback;
        return mb_substr((string) $message, 0, 500);
    }

    private function latency(Response $response): int
    {
        return $this->lastLatencyMs;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function redact(array $payload): array
    {
        foreach ($payload as $key => $value) {
            $normalized = strtolower((string) $key);
            if (in_array($normalized, ['token', 'access_token', 'password', 'api_token', 'webhookcode', 'webhook_code'], true)) {
                $payload[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $payload[$key] = $this->redact($value);
            }
        }

        return $payload;
    }
}
