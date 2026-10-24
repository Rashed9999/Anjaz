<?php

namespace App\Services\MerchantIntegration;

use DomainException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

/**
 * Amial Pay -> Merchant server-to-server adapter.
 *
 * UNSHIPPED: use only on isolated staging. Never accept actor claims directly
 * from Flutter, browser JSON or query parameters; caller must resolve them from
 * its authenticated Amial owner/POS identity and verified branch membership.
 *
 * The existing financial APIs and ledger remain entirely inside Amial Pay.
 */
final class MerchantGateway
{
    public function branchCatalog(string $tenantId, string $branchId, array $trustedActor): array
    {
        $this->checkCaller($tenantId, $branchId, $trustedActor, false);
        return $this->send(
            'GET', $this->tenantPath($tenantId).'/branches/'.$this->segment($branchId).'/catalog', [],
            $this->context($tenantId, $trustedActor, ['products.read'])
        );
    }

    public function merchantProducts(string $tenantId, array $trustedActor): array
    {
        $this->checkCaller($tenantId, null, $trustedActor, false);
        return $this->send('GET', $this->tenantPath($tenantId).'/products', [],
            $this->context($tenantId, $trustedActor, ['products.read']));
    }

    public function branchDirectory(string $tenantId, array $trustedActor): array
    {
        $this->checkCaller($tenantId, null, $trustedActor, false);
        return $this->send('GET', $this->tenantPath($tenantId).'/branches', [],
            $this->context($tenantId, $trustedActor, ['branches.read']));
    }

    public function createBranch(string $tenantId, string $name, array $trustedOwner): array
    {
        $this->checkCaller($tenantId, null, $trustedOwner, true);
        return $this->send('POST', $this->tenantPath($tenantId).'/branches', ['name' => $name],
            $this->context($tenantId, $trustedOwner, ['branches.write']));
    }

    public function cashSalesHistory(string $tenantId, string $branchId, array $trustedActor): array
    {
        $this->checkCaller($tenantId, $branchId, $trustedActor, false);
        return $this->send('GET', $this->tenantPath($tenantId).'/branches/'.$this->segment($branchId).'/sales', [],
            $this->context($tenantId, $trustedActor, ['sales.read']));
    }

    /**
     * Server requests a short-lived, one-use SSO path for the merchant owner.
     * Caller must redirect the owner's browser to Merchant HTTPS base + path.
     * NEVER disclose this path to cashiers or unauthenticated clients.
     */
    public function ownerPortalTicket(string $tenantId, array $trustedOwner): array
    {
        $this->checkCaller($tenantId, null, $trustedOwner, true);
        return $this->send('POST', $this->tenantPath($tenantId).'/web-sso/grants', [],
            $this->context($tenantId, $trustedOwner, ['merchant.portal.login']));
    }

    public function createProduct(string $tenantId, array $product, array $trustedOwner): array
    {
        $this->checkCaller($tenantId, null, $trustedOwner, true);
        return $this->send('POST', $this->tenantPath($tenantId).'/products', $product,
            $this->context($tenantId, $trustedOwner, ['products.write']));
    }

    public function receiveStock(string $tenantId, string $branchId, array $stock, array $trustedOwner): array
    {
        $this->checkCaller($tenantId, $branchId, $trustedOwner, true);
        return $this->send(
            'POST', $this->tenantPath($tenantId).'/branches/'.$this->segment($branchId).'/stock/receive',
            $stock, $this->context($tenantId, $trustedOwner, ['inventory.receive'])
        );
    }

    /**
     * Must only be called after the Muwathaq approval event is durably recorded.
     * It does not issue a wallet or authorize money transfers.
     */
    public function provisionApprovedMerchant(
        string $tenantId, string $amialAccountId, string $branchId, string $branchName
    ): array {
        return $this->send('POST', '/api/v1/merchant/provision', [
            'tenant_id' => $this->segment($tenantId),
            'owner_amial_account_id' => $this->segment($amialAccountId),
            'approved' => true,
            'branch_id' => $this->segment($branchId),
            'branch_name' => $branchName,
        ], $this->context($tenantId, [
            'actor_type' => 'system',
            'actor_id' => 'amial-provisioning',
            'branch_ids' => [],
        ], ['merchant.provision']));
    }

    /** Only a verified, ordered Muwathaq decision relayed by Amial may call this. */
    public function syncLifecycle(string $tenantId, string $eventId, int $sequence, string $status): array
    {
        if (! in_array($status, ['active', 'suspended'], true) || $sequence < 1) {
            throw new InvalidArgumentException('Invalid lifecycle state or sequence');
        }
        return $this->send('POST', $this->tenantPath($tenantId).'/lifecycle', [
            'event_id' => $eventId, 'sequence' => $sequence, 'status' => $status,
        ], $this->context($tenantId, [
            'actor_type' => 'system', 'actor_id' => 'amial-provisioning', 'branch_ids' => [],
        ], ['merchant.lifecycle']));
    }

    public function isConfigured(): bool
    {
        return config('merchant_integration.enabled') === true
            && filter_var(config('merchant_integration.base_url'), FILTER_VALIDATE_URL) !== false
            && parse_url((string) config('merchant_integration.base_url'), PHP_URL_SCHEME) === 'https'
            && strlen((string) config('merchant_integration.secret')) >= 32;
    }

    private function send(string $method, string $path, array $payload, array $context): array
    {
        if (! $this->isConfigured()) {
            throw new DomainException('Amial Merchant bridge disabled or not configured.');
        }
        if (! preg_match('#^/api/v1/merchant/[a-zA-Z0-9/_-]+$#D', $path)) {
            throw new InvalidArgumentException('Unexpected merchant API path');
        }

        // The exact raw bytes signed are the bytes transmitted; no silent
        // re-encoding by a JSON client at a later stage.
        $body = strtoupper($method) === 'GET'
            ? '' : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $encoded = rtrim(strtr(base64_encode(
            json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        ), '+/', '-_'), '=');
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(20));
        $canonical = $timestamp."\n".$nonce."\n".strtoupper($method)."\n"
            .$path."\n".$encoded."\n".hash('sha256', $body);

        $response = Http::acceptJson()
            ->withHeaders([
                'X-Amial-Timestamp' => $timestamp,
                'X-Amial-Nonce' => $nonce,
                'X-Amial-Context' => $encoded,
                'X-Amial-Signature' => hash_hmac('sha256', $canonical, config('merchant_integration.secret')),
            ])
            ->withBody($body, 'application/json')
            ->connectTimeout((int) config('merchant_integration.connect_timeout_seconds', 2))
            ->timeout((int) config('merchant_integration.timeout_seconds', 5))
            ->send(strtoupper($method), rtrim(config('merchant_integration.base_url'), '/').$path);

        if (! $response->successful()) {
            // Do not put response bodies or sensitive identity headers in logs.
            throw new RuntimeException('Merchant integration HTTP '.$response->status());
        }
        $data = $response->json();
        if (! is_array($data) || ! is_string($data['code'] ?? null)) {
            throw new RuntimeException('Merchant integration returned invalid API response');
        }
        return $data;
    }

    private function context(string $tenantId, array $actor, array $scopes): array
    {
        return [
            'iss' => 'amial-pay', 'aud' => 'amial-merchant',
            'iat' => time(), 'exp' => time() + 120,
            'actor_type' => $actor['actor_type'],
            'actor_id' => $actor['actor_id'],
            'tenant_id' => $tenantId,
            'branch_ids' => array_values($actor['branch_ids'] ?? []),
            'scopes' => $scopes,
        ];
    }

    private function checkCaller(string $tenantId, ?string $branchId, array $actor, bool $ownerOnly): void
    {
        $this->segment($tenantId);
        if ($branchId !== null) {
            $this->segment($branchId);
        }
        if (! is_string($actor['tenant_id'] ?? null) || $actor['tenant_id'] !== $tenantId
            || ! in_array($actor['actor_type'] ?? null, ['owner', 'cashier'], true)
            || ! is_string($actor['actor_id'] ?? null)
            || ! preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $actor['actor_id'])
            || $ownerOnly && ($actor['actor_type'] ?? null) !== 'owner'
            || ($actor['actor_type'] ?? null) === 'cashier'
                && (! is_array($actor['branch_ids'] ?? null)
                    || ($branchId !== null && ! in_array($branchId, $actor['branch_ids'], true))
                    || count($actor['branch_ids']) === 0)) {
            throw new DomainException('Amial actor cannot access this merchant operation.');
        }
    }

    private function tenantPath(string $tenantId): string
    {
        return '/api/v1/merchant/tenants/'.$this->segment($tenantId);
    }

    private function segment(string $value): string
    {
        if (! preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $value)) {
            throw new InvalidArgumentException('Invalid external account or branch identifier');
        }
        return $value;
    }
}
