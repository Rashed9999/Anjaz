<?php

namespace App\Services\Merchant;

use App\Models\Branch;
use App\Models\Merchant;
use App\Models\MerchantProfile;
use App\Models\MuwathaqAccountOpeningApplication;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Trusted Amial Pay BFF for the independent Merchant POS.
 * Flutter never knows the Merchant S2S URL or integration secret.
 * Any outage FAILS CLOSED; commercial source-of-truth never silently reverts to 6Cash.
 */
final class MerchantPosGateway
{
    public function enabledFor(User $owner): bool
    {
        $ids = (array) config('amial.merchant_pos_bridge.owner_ids', []);
        return config('amial.merchant_pos_bridge.enabled', false)
            && in_array((string) $owner->getKey(), array_map('strval', $ids), true);
    }

    public function approved(User $owner): bool
    {
        return MerchantProfile::where('user_id', $owner->getKey())->where('verification_status', 'verified')->exists()
            && MuwathaqAccountOpeningApplication::query()
                ->where('user_id', $owner->getKey())
                ->where('subject_kind', 'merchant')
                ->where('status', MuwathaqAccountOpeningApplication::STATUS_APPROVED)
                ->whereNotNull('muwathaq_decided_at')->exists();
    }

    /**
     * Verified owner sign-in -> signed provisioning -> one-use Merchant SSO.
     * The independent login always follows the Muwathaq approval; no self-provision.
     */
    public function ownerRedirect(User $owner, Merchant $merchant, MerchantProfile $profile): string
    {
        if (! $this->enabledFor($owner) || ! $profile->isVerified() || ! $this->approved($owner)) {
            throw new RuntimeException('MERCHANT_POS_OWNER_NOT_APPROVED');
        }
        $opening = MuwathaqAccountOpeningApplication::query()
            ->where('user_id', $owner->getKey())->where('subject_kind', 'merchant')
            ->where('status', MuwathaqAccountOpeningApplication::STATUS_APPROVED)
            ->whereNotNull('muwathaq_decided_at')->latest('muwathaq_decided_at')->first();
        if (! $opening || ! $merchant->merchant_number || ! $merchant->store_name
            || $profile->business_type !== 'retail') {
            throw new RuntimeException('MERCHANT_POS_OWNER_DATA_INVALID');
        }
        $tenant = $this->tenantId($owner);
        $branch = $tenant.'_main';
        $provision = $this->send('POST', '/api/v1/merchant/provision', [
            'tenant_id' => $tenant,
            'merchant_number' => (string) $merchant->merchant_number,
            'approval_event_id' => (string) $opening->application_ulid,
            'approval_source' => 'muwathaq_approved',
            'approved_at' => $opening->muwathaq_decided_at->toIso8601String(),
            'owner_amial_account_id' => (string) $owner->getKey(),
            'approved' => true,
            'business_type' => 'retail',
            'branch_id' => $branch,
            'branch_name' => (string) $merchant->store_name,
        ], $owner, null, 'system', 'amial-pay', ['merchant.provision']);
        if (($provision['code'] ?? null) !== 'MERCHANT_PROVISIONED') {
            throw new RuntimeException('MERCHANT_POS_PROVISION_FAILED');
        }
        $grant = $this->send('POST',
            '/api/v1/merchant/tenants/'.$tenant.'/web-sso/grants',
            [], $owner, null, 'owner', (string) $owner->getKey(), ['merchant.portal.login']);
        $path = $grant['redirect_path'] ?? null;
        if (! is_string($path) || ! preg_match('#^/preview/sso/[a-f0-9]{64}$#D', $path)) {
            throw new RuntimeException('MERCHANT_POS_BAD_SSO_GRANT');
        }
        return rtrim((string) config('amial.merchant_pos_bridge.base_url'), '/').$path;
    }

    public function tenantId(User $owner): string
    {
        return 'legacy_merchant_'.$owner->getKey();
    }

    /** Pilot supports only the independently provisioned MAIN branch. */
    public function branchId(User $owner, ?Branch $branch): string
    {
        if ($branch !== null && ! (bool) $branch->is_default) {
            throw new RuntimeException('MERCHANT_BRANCH_NOT_MAPPED');
        }
        return $this->tenantId($owner).'_main';
    }

    public function catalog(User $owner, ?Branch $branch, string $actorType, string $actorId): array
    {
        $tenant = $this->tenantId($owner);
        $path = '/api/v1/merchant/tenants/'.$tenant.'/branches/'.$this->branchId($owner, $branch).'/catalog';
        $result = $this->send('GET', $path, [], $owner, $branch, $actorType, $actorId, ['products.read']);
        if (($result['code'] ?? null) !== 'BRANCH_CATALOG' || ! is_array($result['data'] ?? null)) {
            throw new RuntimeException('MERCHANT_INVALID_CATALOG_RESPONSE');
        }
        return array_map(static fn (array $row): array => [
            'id' => (string) $row['id'],
            'merchant_product_id' => (string) $row['id'],
            'sku' => (string) ($row['sku'] ?? ''),
            'name' => (string) $row['name'],
            'price' => (string) $row['price'],
            'quantity' => (int) ($row['quantity'] ?? 0),
            'is_active' => true, 'source' => 'amial-merchant',
        ], $result['data']);
    }

    public function lookup(User $owner, ?Branch $branch, string $barcode, string $actorType, string $actorId): ?array
    {
        $tenant = $this->tenantId($owner);
        $branchId = $this->branchId($owner, $branch);
        if (! preg_match('/^[A-Za-z0-9_.-]{3,64}$/D', $barcode)) {
            throw new RuntimeException('INVALID_BARCODE');
        }
        $path = '/api/v1/merchant/tenants/'.$tenant.'/branches/'.$branchId.'/catalog/barcodes/'.$barcode;
        $result = $this->send('GET', $path, [], $owner, $branch, $actorType, $actorId, ['products.read'], true);
        if ($result === null) return null;
        if (($result['code'] ?? null) !== 'BARCODE_FOUND' || ! is_array($result['data'] ?? null)) {
            throw new RuntimeException('MERCHANT_INVALID_BARCODE_RESPONSE');
        }
        $p = $result['data'];
        return ['product' => [
            'id' => (string) $p['product_id'],
            'merchant_product_id' => (string) $p['product_id'],
            'name' => (string) $p['name'], 'sku' => (string) ($p['sku'] ?? ''),
            'price' => (string) $p['price'],
            'quantity' => (int) ($p['stock_units'] ?? 0),
            'source' => 'amial-merchant', 'is_active' => true,
        ], 'pack_size' => (string) $p['pack_quantity'], 'barcode' => (string) $p['barcode']];
    }

    public function cashSale(User $owner, ?Branch $branch, array $lines, string $requestKey, string $actorType, string $actorId): array
    {
        $tenant = $this->tenantId($owner);
        $branchId = $this->branchId($owner, $branch);
        $result = $this->send('POST', '/api/v1/merchant/tenants/'.$tenant.'/branches/'.$branchId.'/cash-sales',
            ['request_key' => $requestKey, 'payment_method' => 'cash', 'currency' => 'YER', 'lines' => $lines],
            $owner, $branch, $actorType, $actorId, ['sales.create']);
        if (($result['code'] ?? null) !== 'CASH_SALE_RECORDED' || ! is_array($result['data'] ?? null)) {
            throw new RuntimeException('MERCHANT_INVALID_SALE_RESPONSE');
        }
        $receiptItems = $result['data']['items'] ?? null;
        if (! is_array($receiptItems) || $receiptItems === []) {
            throw new RuntimeException('MERCHANT_INVALID_RECEIPT_RESPONSE');
        }
        return [
            'items' => $receiptItems,
            'sale_ulid' => (string) $result['data']['sale_id'],
            'total_amount' => (string) $result['data']['total'],
            'payment_method' => 'cash',
            'source' => 'amial-merchant',
            'replayed' => (bool) ($result['data']['replayed'] ?? false),
        ];
    }

    /**
     * The Merchant service prices lines and reserves stock BEFORE Amial Pay QR
     * collection. A mobile price is never a financial authorization.
     */
    public function reserveWalletOrder(User $owner, ?Branch $branch, array $lines,
        string $key, string $actorType, string $actorId): array
    {
        $tenant = $this->tenantId($owner);
        $branchId = $this->branchId($owner, $branch);
        $path = '/api/v1/merchant/tenants/'.$tenant.'/branches/'.$branchId.'/orders';
        $result = $this->send('POST', $path, [
            'request_key' => $key, 'payment_method' => 'amial_pay',
            'currency' => 'YER', 'reservation_seconds' => 900,
            'lines' => $lines,
        ], $owner, $branch, $actorType, $actorId, ['sales.create']);

        $order = $result['data'] ?? null;
        if (($result['code'] ?? null) !== 'ORDER_RESERVED' || ! is_array($order)
            || ! is_string($order['id'] ?? null)
            || ! preg_match('/^[a-f0-9-]{36}$/D', $order['id'])
            || ($order['payment_method'] ?? null) !== 'amial_pay'
            || ! preg_match('/^[0-9]+\\.[0-9]{4}$/D', (string) ($order['total'] ?? ''))) {
            throw new RuntimeException('MERCHANT_INVALID_WALLET_ORDER_RESPONSE');
        }
        return $order;
    }

    public function walletOrder(User $owner, ?Branch $branch, string $orderId,
        string $actorType, string $actorId): array
    {
        $path = $this->orderPath($owner, $branch, $orderId);
        $result = $this->send('GET', $path, [], $owner, $branch,
            $actorType, $actorId, ['sales.read']);
        if (($result['code'] ?? null) !== 'MERCHANT_ORDER' || ! is_array($result['data'] ?? null)
            || ($result['data']['id'] ?? null) !== $orderId
            || ($result['data']['payment_method'] ?? null) !== 'amial_pay') {
            throw new RuntimeException('MERCHANT_INVALID_WALLET_ORDER_RESPONSE');
        }
        return $result['data'];
    }

    /**
     * Only Amial Pay's backend may assert an already-paid wallet reference.
     * Merchant's signed receipt endpoint is globally replay-protected.
     */
    public function authorizeWalletOrder(User $owner, ?Branch $branch, string $orderId,
        string $paidTransactionId, string $amount): void
    {
        $result = $this->send('POST', $this->orderPath($owner, $branch, $orderId)
            .'/payment-receipts/amial', [
                'provider' => 'amial_pay',
                'payment_reference' => $paidTransactionId,
                'correlation_id' => 'order_'.str_replace('-', '', $orderId),
                'currency' => 'YER',
                'amount' => $amount,
            ], $owner, $branch, 'system', 'amial-pay', ['orders.payment.record']);
        if (($result['code'] ?? null) !== 'ORDER_PAYMENT_AUTHORIZED') {
            throw new RuntimeException('MERCHANT_WALLET_AUTHORIZATION_FAILED');
        }
    }

    public function fulfillWalletOrder(User $owner, ?Branch $branch, string $orderId,
        string $actorType, string $actorId): void
    {
        $result = $this->send('POST', $this->orderPath($owner, $branch, $orderId)
            .'/fulfill', [], $owner, $branch, $actorType, $actorId, ['sales.fulfill']);
        if (($result['code'] ?? null) !== 'ORDER_FULFILLED'
            || ($result['data']['status'] ?? null) !== 'fulfilled') {
            throw new RuntimeException('MERCHANT_WALLET_FULFILLMENT_FAILED');
        }
    }

    public function walletReceipt(User $owner, ?Branch $branch, string $orderId,
        string $actorType, string $actorId): array
    {
        $result = $this->send('GET', $this->orderPath($owner, $branch, $orderId)
            .'/pos-receipt', [], $owner, $branch, $actorType, $actorId, ['sales.read']);
        $data = $result['data'] ?? null;
        if (($result['code'] ?? null) !== 'WALLET_POS_RECEIPT'
            || ! is_array($data) || ($data['order_id'] ?? null) !== $orderId
            || ! is_array($data['items'] ?? null) || $data['items'] === []
            || ($data['payment_method'] ?? null) !== 'amial_pay') {
            throw new RuntimeException('MERCHANT_INVALID_WALLET_RECEIPT');
        }
        return $data;
    }

    private function orderPath(User $owner, ?Branch $branch, string $orderId): string
    {
        if (! preg_match('/^[a-f0-9-]{36}$/D', $orderId)) {
            throw new RuntimeException('MERCHANT_INVALID_WALLET_ORDER_ID');
        }
        return '/api/v1/merchant/tenants/'.$this->tenantId($owner)
            .'/branches/'.$this->branchId($owner, $branch).'/orders/'.$orderId;
    }

    /**
     * @param array<string,mixed> $body
     * @param list<string> $scopes
     * @return array<string,mixed>|null
     */
    private function send(string $method, string $path, array $body, User $owner, ?Branch $branch, string $actorType, string $actorId, array $scopes, bool $allowNotFound = false): ?array
    {
        $base = rtrim((string) config('amial.merchant_pos_bridge.base_url', ''), '/');
        $secret = (string) config('amial.merchant_pos_bridge.secret', '');
        $parts = parse_url($base);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)
            || strlen($secret) < 32 || ! in_array($method, ['GET', 'POST'], true)) {
            throw new RuntimeException('MERCHANT_POS_BRIDGE_NOT_CONFIGURED');
        }
        $now = time();
        $timestamp = (string) $now;
        $nonce = Str::random(40);
        $context = [
            'iss' => 'amial-pay', 'aud' => 'amial-merchant',
            'iat' => $now, 'exp' => $now + 60,
            'actor_type' => $actorType, 'actor_id' => $actorId,
            'tenant_id' => $this->tenantId($owner),
            'branch_ids' => [$this->branchId($owner, $branch)],
            'scopes' => $scopes,
        ];
        $encoded = rtrim(strtr(base64_encode(json_encode($context, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $rawBody = $method === 'POST'
            ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : '';
        $canonical = $timestamp."\n".$nonce."\n".$method."\n".$path."\n".$encoded."\n".hash('sha256', $rawBody);
        $headers = [
            'Accept' => 'application/json',
            'X-Amial-Timestamp' => $timestamp,
            'X-Amial-Nonce' => $nonce,
            'X-Amial-Context' => $encoded,
            'X-Amial-Signature' => hash_hmac('sha256', $canonical, $secret),
        ];
        try {
            $http = Http::connectTimeout(2)->timeout(6)->withoutRedirecting()->withHeaders($headers);
            $response = $method === 'POST' ? $http->withBody($rawBody, 'application/json')->send('POST', $base.$path)
                : $http->get($base.$path);
        } catch (ConnectionException) {
            throw new RuntimeException('MERCHANT_POS_SERVICE_UNAVAILABLE');
        }
        if ($response->status() === 404 && $allowNotFound) return null;
        if (! $response->successful()) {
            throw new RuntimeException($response->status() === 409
                ? 'MERCHANT_POS_CONFLICT' : 'MERCHANT_POS_SERVICE_UNAVAILABLE');
        }
        $json = $response->json();
        if (! is_array($json)) throw new RuntimeException('MERCHANT_INVALID_JSON_RESPONSE');
        return $json;
    }
}
