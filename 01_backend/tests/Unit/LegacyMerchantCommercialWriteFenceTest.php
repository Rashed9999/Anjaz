<?php

namespace Tests\Unit;

use App\Http\Middleware\LegacyMerchantCommercialWriteFence;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class LegacyMerchantCommercialWriteFenceTest extends TestCase
{
    private function check(string $verb, string $path, bool $fenced): Response
    {
        config()->set('amial.merchant_workspace.fence_legacy_commercial_writes', $fenced);
        $request = Request::create($path, $verb);

        return app(LegacyMerchantCommercialWriteFence::class)->handle(
            $request,
            static fn (Request $req): Response => response('unmodified legacy behavior', 200)
        );
    }

    public function test_legacy_merchant_web_behavior_is_unchanged_by_default(): void
    {
        foreach ([
            '/merchant/data/products',
            '/merchant/data/sector/products',
            '/merchant/data/sector/catalog/options',
            '/merchant/data/sector/customers',
        ] as $path) {
            $this->assertSame(200, $this->check('POST', $path, false)->getStatusCode());
        }
    }

    public function test_opt_in_blocks_only_legacy_commercial_editor_mutations(): void
    {
        foreach ([
            '/merchant/data/products',
            '/merchant/data/sector/products/25/inventory',
            '/merchant/data/sector/catalog/categories',
            '/merchant/data/sector/customers',
        ] as $path) {
            $res = $this->check('POST', $path, true);
            $this->assertSame(409, $res->getStatusCode());
            $this->assertStringContainsString('MERCHANT_COMMERCIAL_EDITOR_MIGRATED', $res->getContent());
            $this->assertSame('no-store', $res->headers->get('Cache-Control'));
            $this->assertSame(200, $this->check('GET', $path, true)->getStatusCode());
        }
    }

    public function test_finance_cashier_account_verification_and_login_are_never_blocked(): void
    {
        foreach ([
            '/merchant/login',
            '/merchant/data/debts/customers/42/collect-cash',
            '/merchant/data/debts/collections/42/confirm',
            '/merchant/data/suppliers/42/wallet-payment',
            '/merchant/data/wallet',
            '/merchant/data/sector/returns/42/settle',
            '/api/v1/amial/merchant/sale',
            '/api/v1/internal/muwathaq/applications/01ABC/decision',
        ] as $path) {
            $this->assertSame(200, $this->check('POST', $path, true)->getStatusCode(), $path);
        }
    }

    public function test_read_routes_remain_available_and_feature_can_be_rolled_back(): void
    {
        $this->assertSame(200, $this->check('HEAD', '/merchant/data/products', true)->getStatusCode());
        $this->assertSame(200, $this->check('OPTIONS', '/merchant/data/sector/products', true)->getStatusCode());
        $this->assertSame(200, $this->check('POST', '/merchant/data/products', false)->getStatusCode());
    }
}
