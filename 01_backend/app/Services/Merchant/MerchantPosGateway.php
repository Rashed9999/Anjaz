<?php

namespace App\Services\Merchant;

use App\Models\Branch;
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
        return [
            'sale_ulid' => (string) $result['data']['sale_id'],
            'total_amount' => (string) $result['data']['total'],
            'payment_method' => 'cash',
            'source' => 'amial-merchant',
            'replayed' => (bool) ($result['data']['replayed'] ?? false),
        ];
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
