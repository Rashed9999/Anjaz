<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The legacy merchant-workspace financial bridge was retired when Amial
 * Business v9 became the sole merchant engine. Financial data now crosses
 * the signed /api/v1/business gateway, never the old owner/tenant bridge.
 *
 * These tests deliberately verify both sides of that security boundary:
 * retired endpoints must not reappear, and every replacement financial
 * endpoint must reject unauthenticated server-to-server requests.
 */
final class MerchantWorkspaceFinancialReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_retired_merchant_workspace_financial_route_is_not_registered(): void
    {
        $uri = 'api/v1/amial/merchant-workspace/financial';

        $this->assertFalse(
            collect(Route::getRoutes())->contains(
                static fn ($route) => $route->uri() === $uri
                    && in_array('POST', $route->methods(), true)
            ),
            'The retired workspace money bridge must not be exposed after the Business v9 cutover.'
        );

        // Even an old client with its previous payload must not enter the
        // payment system by calling the retired endpoint.
        $this->postJson('/'.$uri, ['section' => 'wallet'])->assertNotFound();
        $this->postJson('/'.$uri, ['section' => 'sector'])->assertNotFound();
    }

    public function test_replacement_business_financial_routes_require_server_signature(): void
    {
        config()->set('amial_business.inbound_secret', str_repeat('s', 64));

        foreach ([
            'amial.business.link',
            'amial.business.wallet',
            'amial.business.fees.quote',
            'amial.business.entitlements',
            'amial.business.payment-intents.create',
            'amial.business.credit.handover',
            'amial.business.customer-debts.upsert',
            'amial.business.refunds.request',
        ] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, 'Replacement business route missing: '.$name);
            $this->assertContains(
                'amial.business.signature',
                $route->gatherMiddleware(),
                'Business money or entitlement endpoint without the shared HMAC guard: '.$name
            );
        }

        $this->getJson('/api/v1/business/wallet')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'BUSINESS_SIGNATURE_EXPIRED');

        $this->postJson('/api/v1/business/payment-intents', ['reference' => 'old-workspace'])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'BUSINESS_SIGNATURE_EXPIRED');

        $this->postJson('/api/v1/business/credit/handover', ['reference' => 'old-workspace'])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'BUSINESS_SIGNATURE_EXPIRED');
    }
}
