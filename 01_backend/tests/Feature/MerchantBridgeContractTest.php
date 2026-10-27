<?php

namespace Tests\Feature;

use App\Services\MerchantIntegration\MerchantGateway;
use DomainException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class MerchantBridgeContractTest extends TestCase
{
    private function enableBridge(): void
    {
        config()->set('merchant_integration.enabled', true);
        config()->set('merchant_integration.base_url', 'https://merchant-staging.internal');
        config()->set('merchant_integration.secret', str_repeat('x', 64));
    }

    private function cashier(): array
    {
        return [
            'actor_type' => 'cashier',
            'actor_id' => 'pos_user_001',
            'tenant_id' => 'merchant_001',
            'branch_ids' => ['branch_001'],
        ];
    }

    public function test_bridge_is_disabled_by_default_and_never_calls_remote_server(): void
    {
        Http::fake();
        config()->set('merchant_integration.enabled', false);
        $this->expectException(DomainException::class);
        try {
            app(MerchantGateway::class)->branchCatalog('merchant_001', 'branch_001', $this->cashier());
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_hmac_is_signed_against_exact_transmitted_path_and_body(): void
    {
        $this->enableBridge();
        Http::fake([
            'https://merchant-staging.internal/*' => Http::response([
                'code' => 'BRANCH_CATALOG', 'data' => [],
            ], 200),
        ]);
        $result = app(MerchantGateway::class)
            ->branchCatalog('merchant_001', 'branch_001', $this->cashier());
        $this->assertSame('BRANCH_CATALOG', $result['code']);

        Http::assertSent(function ($request): bool {
            $timestamp = $request->header('X-Amial-Timestamp')[0] ?? '';
            $nonce = $request->header('X-Amial-Nonce')[0] ?? '';
            $contextEncoded = $request->header('X-Amial-Context')[0] ?? '';
            $signature = $request->header('X-Amial-Signature')[0] ?? '';
            $path = parse_url($request->url(), PHP_URL_PATH);
            $canonical = $timestamp."\n".$nonce."\n".strtoupper($request->method())
                ."\n".$path."\n".$contextEncoded."\n".hash('sha256', $request->body());
            $ctx = json_decode(base64_decode(strtr($contextEncoded, '-_', '+/')), true);

            return is_array($ctx) && $ctx['actor_type'] === 'cashier'
                && $ctx['tenant_id'] === 'merchant_001'
                && $ctx['branch_ids'] === ['branch_001']
                && $ctx['scopes'] === ['products.read']
                && hash_equals(
                    hash_hmac('sha256', $canonical, config('merchant_integration.secret')),
                    $signature
                );
        });
    }


    public function test_cashier_cannot_forge_another_tenant_even_if_branch_matches(): void
    {
        $this->enableBridge();
        Http::fake();
        $this->expectException(DomainException::class);
        try {
            app(MerchantGateway::class)->branchCatalog('merchant_002', 'branch_001', $this->cashier());
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_branch_directory_uses_scoped_read_and_remote_is_never_financial_core(): void
    {
        $this->enableBridge();
        Http::fake([
            'https://merchant-staging.internal/*' => Http::response([
                'code' => 'BRANCHES', 'data' => [],
            ], 200),
        ]);
        $r = app(MerchantGateway::class)->branchDirectory('merchant_001', $this->cashier());
        $this->assertSame('BRANCHES', $r['code']);
        Http::assertSent(fn ($request) => str_ends_with(
            $request->url(), '/api/v1/merchant/tenants/merchant_001/branches'
        ));
    }


    public function test_owner_portal_sso_grant_is_requested_server_side_only(): void
    {
        $this->enableBridge();
        Http::fake([
            'https://merchant-staging.internal/*' => Http::response([
                'code' => 'MERCHANT_PORTAL_GRANT',
                'redirect_path' => '/preview/sso/'.str_repeat('a', 64),
                'expires_in_seconds' => 60,
            ], 200),
        ]);
        $owner = $this->cashier();
        $owner['actor_type'] = 'owner';
        $response = app(MerchantGateway::class)->ownerPortalTicket('merchant_001', $owner);
        $this->assertSame('MERCHANT_PORTAL_GRANT', $response['code']);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(),
                '/api/v1/merchant/tenants/merchant_001/web-sso/grants'));
    }

    public function test_cashier_never_receives_merchant_owner_portal_ticket(): void
    {
        $this->enableBridge();
        Http::fake();
        $this->expectException(DomainException::class);
        try {
            app(MerchantGateway::class)->ownerPortalTicket('merchant_001', $this->cashier());
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_cashier_cannot_call_owner_only_product_write(): void
    {
        $this->enableBridge();
        Http::fake();
        $this->expectException(DomainException::class);
        try {
            app(MerchantGateway::class)->createProduct('merchant_001', [
                'sku' => 'SKU-01', 'name' => 'Test', 'price' => '10',
            ], $this->cashier());
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_cashier_cannot_read_other_branch(): void
    {
        $this->enableBridge();
        Http::fake();
        $this->expectException(DomainException::class);
        try {
            app(MerchantGateway::class)->branchCatalog('merchant_001', 'another_branch', $this->cashier());
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_bridge_rejects_plain_http_even_if_enabled(): void
    {
        $this->enableBridge();
        config()->set('merchant_integration.base_url', 'http://merchant-staging.internal');
        Http::fake();
        $this->expectException(DomainException::class);
        try {
            app(MerchantGateway::class)->branchCatalog('merchant_001', 'branch_001', $this->cashier());
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_remote_error_never_returns_a_false_success(): void
    {
        $this->enableBridge();
        Http::fake([
            'https://merchant-staging.internal/*' => Http::response(['code' => 'OUT_OF_STOCK'], 503),
        ]);
        $this->expectException(RuntimeException::class);
        app(MerchantGateway::class)->branchCatalog('merchant_001', 'branch_001', $this->cashier());
    }
}
