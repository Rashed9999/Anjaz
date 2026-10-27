<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\MerchantProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MerchantWorkspaceFinancialReadTest extends TestCase
{
    use RefreshDatabase;

    private function signedRead(int $ownerId, string $tenantId, string $nonce): \Illuminate\Testing\TestResponse
    {
        $path = '/api/v1/amial/merchant-workspace/financial';
        $body = json_encode(['section' => 'wallet'], JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $context = [
            'iss' => 'amial-merchant', 'aud' => 'amial-pay', 'iat' => (int) $timestamp,
            'exp' => (int) $timestamp + 60, 'actor_type' => 'owner',
            'actor_id' => (string) $ownerId, 'tenant_id' => $tenantId,
            'scopes' => ['merchant.financial.read'],
        ];
        $encoded = rtrim(strtr(base64_encode(json_encode($context, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $canonical = $timestamp."\n".$nonce."\nPOST\n".$path."\n".$encoded."\n".hash('sha256', $body);

        return $this->call('POST', $path, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_AMIAL_TIMESTAMP' => $timestamp,
            'HTTP_X_AMIAL_NONCE' => $nonce,
            'HTTP_X_AMIAL_CONTEXT' => $encoded,
            'HTTP_X_AMIAL_SIGNATURE' => hash_hmac('sha256', $canonical, config('amial.merchant_workspace.integration_secret')),
        ], $body);
    }

    public function test_financial_read_requires_a_valid_signature_and_verified_owner_tenant(): void
    {
        config()->set('amial.merchant_workspace.integration_secret', str_repeat('s', 64));
        $this->postJson('/api/v1/amial/merchant-workspace/financial', ['section' => 'wallet'])
            ->assertUnauthorized();

        $owner = User::factory()->create();
        MerchantProfile::create(['user_id' => $owner->id, 'verification_status' => 'verified']);
        $merchant = new Merchant();
        $merchant->user_id = $owner->id;
        $merchant->merchant_number = 'BRIDGE-01';
        $merchant->save();
        $tenant = 'legacy_merchant_'.$merchant->id;

        $this->signedRead($owner->id, 'legacy_merchant_other', str_repeat('n', 32))
            ->assertForbidden()->assertJsonPath('code', 'MERCHANT_OWNER_SCOPE_INVALID');
        $this->signedRead($owner->id, $tenant, str_repeat('o', 32))
            ->assertOk()->assertJsonPath('data.source', 'amial_pay_ledger');
        $this->signedRead($owner->id, $tenant, str_repeat('o', 32))
            ->assertStatus(409)->assertJsonPath('code', 'REPLAYED_INTEGRATION_REQUEST');
    }
}
