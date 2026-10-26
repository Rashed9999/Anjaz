<?php

namespace App\Services\Merchant;

use App\Models\Merchant;
use App\Models\MerchantProfile;
use App\Models\MuwathaqAccountOpeningApplication;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;

/**
 * Bridges an already-approved legacy merchant into the isolated merchant
 * workspace. Disabled by default; failures keep the legacy portal available.
 */
final class MerchantWorkspaceBridge
{
    public function redirectFor(User $owner, Merchant $merchant, MerchantProfile $profile): ?string
    {
        if (! (bool) config('amial.merchant_workspace.enabled', false)) {
            return null;
        }

        $baseUrl = rtrim((string) config('amial.merchant_workspace.base_url', ''), '/');
        $secret = (string) config('amial.merchant_workspace.integration_secret', '');
        if ($baseUrl === '' || strlen($secret) < 32 || ! $profile->isVerified()) {
            return null;
        }

        $opening = MuwathaqAccountOpeningApplication::query()
            ->where('user_id', $owner->id)
            ->where('subject_kind', 'merchant')
            ->where('status', MuwathaqAccountOpeningApplication::STATUS_APPROVED)
            ->whereNotNull('muwathaq_decided_at')
            ->latest('muwathaq_decided_at')
            ->first();

        if (! $opening || ! $merchant->merchant_number || ! $merchant->store_name) {
            return null;
        }

        $tenantId = 'legacy_merchant_'.$merchant->getKey();
        $branchId = $tenantId.'_main';

        try {
            $this->post($baseUrl, $secret, '/api/v1/merchant/provision', [
                'tenant_id' => $tenantId,
                'merchant_number' => (string) $merchant->merchant_number,
                'approval_event_id' => (string) $opening->application_ulid,
                'approval_source' => 'muwathaq_approved',
                'approved_at' => $opening->muwathaq_decided_at->toIso8601String(),
                'owner_amial_account_id' => (string) $owner->getKey(),
                'approved' => true,
                'branch_id' => $branchId,
                'branch_name' => (string) $merchant->store_name,
            ], [
                'actor_type' => 'system',
                'actor_id' => 'amial-pay',
                'tenant_id' => $tenantId,
                'scopes' => ['merchant.provision'],
                'branch_ids' => [$branchId],
            ]);

            $grant = $this->post($baseUrl, $secret,
                '/api/v1/merchant/tenants/'.$tenantId.'/web-sso/grants',
                [], [
                    'actor_type' => 'owner',
                    'actor_id' => (string) $owner->getKey(),
                    'tenant_id' => $tenantId,
                    'scopes' => ['merchant.portal.login'],
                    'branch_ids' => [$branchId],
                ]);

            $path = $grant['redirect_path'] ?? null;
            if (! is_string($path) || ! preg_match('#^/preview/sso/[a-f0-9]{64}$#D', $path)) {
                throw new \RuntimeException('Merchant workspace returned an invalid SSO redirect.');
            }

            return $baseUrl.$path;
        } catch (\Throwable $exception) {
            Log::warning('Merchant workspace SSO bridge failed; using the legacy portal.', [
                'merchant_id' => $merchant->getKey(),
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $context @return array<string,mixed> */
    private function post(string $baseUrl, string $secret, string $path, array $payload, array $context): array
    {
        $timestamp = (string) time();
        $nonce = Str::random(40);
        $context = [
            'iss' => (string) config('amial.merchant_workspace.issuer', 'amial-pay'),
            'aud' => (string) config('amial.merchant_workspace.audience', 'amial-merchant'),
            'iat' => (int) $timestamp,
            'exp' => (int) $timestamp + 60,
            ...$context,
        ];
        $encodedContext = $this->base64Url($this->json($context));
        $body = $this->json($payload);
        $canonical = $timestamp."\n".$nonce."\nPOST\n".$path."\n".$encodedContext."\n".hash('sha256', $body);
        $signature = hash_hmac('sha256', $canonical, $secret);

        $response = Http::withBody($body, 'application/json')->withHeaders([
            'Accept' => 'application/json',
            'X-Amial-Timestamp' => $timestamp,
            'X-Amial-Nonce' => $nonce,
            'X-Amial-Context' => $encodedContext,
            'X-Amial-Signature' => $signature,
        ])->post($baseUrl.$path);

        $response->throw();

        $json = $response->json();
        if (! is_array($json)) {
            throw new \RuntimeException('Merchant workspace returned a non-JSON response.');
        }

        return $json;
    }

    /** @param array<string,mixed> $value */
    private function json(array $value): string
    {
        try {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \RuntimeException('Unable to encode merchant workspace payload.', 0, $exception);
        }
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
