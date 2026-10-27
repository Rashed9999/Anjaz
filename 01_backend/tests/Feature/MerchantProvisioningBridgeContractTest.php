<?php

namespace Tests\Feature;

use App\Services\MerchantIntegration\MerchantGateway;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class MerchantProvisioningBridgeContractTest extends TestCase
{
    public function test_approved_muwathaq_identity_reaches_independent_merchant_service(): void
    {
        config()->set('merchant_integration.enabled', true);
        config()->set('merchant_integration.base_url', 'https://merchant-staging.internal');
        config()->set('merchant_integration.secret', str_repeat('x', 64));
        Http::fake(['https://merchant-staging.internal/*' =>
            Http::response(['code' => 'MERCHANT_PROVISIONED', 'tenant_id' => 'merchant_001'], 200)]);

        app(MerchantGateway::class)->provisionApprovedMerchant(
            'merchant_001', 'wallet_001', 'branch_001', 'Main',
            'AMM-0001', 'approval_event_000001', '2026-10-25T02:00:00+00:00'
        );

        Http::assertSent(function ($request): bool {
            $payload = $request->data();
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/api/v1/merchant/provision')
                && ($payload['merchant_number'] ?? null) === 'AMM-0001'
                && ($payload['approval_event_id'] ?? null) === 'approval_event_000001'
                && ($payload['approval_source'] ?? null) === 'muwathaq_approved'
                && ($payload['owner_amial_account_id'] ?? null) === 'wallet_001';
        });
    }
}
