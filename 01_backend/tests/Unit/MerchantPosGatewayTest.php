<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Merchant;
use App\Models\User;
use App\Services\Merchant\MerchantPosGateway;
use Illuminate\Http\Client\Request as OutgoingRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class MerchantPosGatewayTest extends TestCase
{
    private function owner(int $id): User
    {
        $owner = new User();
        $owner->id = $id;
        $merchant = new Merchant();
        $merchant->id = $id + 100;
        $merchant->user_id = $id;
        $owner->setRelation('merchant', $merchant);
        return $owner;
    }

    private function configured(int $ownerId = 17): MerchantPosGateway
    {
        config()->set('amial.merchant_pos_bridge.enabled', true);
        config()->set('amial.merchant_pos_bridge.owner_ids', [(string) $ownerId]);
        config()->set('amial.merchant_pos_bridge.base_url', 'https://merchant-stage.example.test');
        config()->set('amial.merchant_pos_bridge.secret', str_repeat('s', 48));
        return app(MerchantPosGateway::class);
    }

    public function test_non_allowlisted_merchant_never_enters_independent_pos(): void
    {
        $svc = $this->configured();
        $this->assertTrue($svc->enabledFor($this->owner(17)));
        $this->assertFalse($svc->enabledFor($this->owner(18)));
        config()->set('amial.merchant_pos_bridge.enabled', false);
        $this->assertFalse($svc->enabledFor($this->owner(17)));
    }

    public function test_missing_or_foreign_merchant_identity_fails_closed(): void
    {
        $gateway = $this->configured();
        $owner = $this->owner(17);
        $owner->setRelation('merchant', null);
        try {
            $gateway->tenantId($owner);
            $this->fail('Missing merchant must not produce a tenant identity');
        } catch (\RuntimeException $e) {
            $this->assertSame('MERCHANT_POS_OWNER_DATA_INVALID', $e->getMessage());
        }
        $other = new Merchant();
        $other->id = 118;
        $other->user_id = 18;
        $owner->setRelation('merchant', $other);
        $this->expectExceptionMessage('MERCHANT_POS_OWNER_DATA_INVALID');
        $gateway->tenantId($owner);
    }

    public function test_signed_cashier_scan_keeps_provisioned_tenant_branch_and_unsigned_mobile_secret_private(): void
    {
        $svc = $this->configured();
        Http::fake(['https://merchant-stage.example.test/*' => Http::response([
            'code' => 'BARCODE_FOUND', 'data' => [
                'product_id' => '10000000-0000-4000-8000-000000000001',
                'sku' => 'MILK-1', 'name' => 'حليب',
                'price' => '125.0000', 'stock_units' => 48,
                'barcode' => '6291000000011', 'pack_quantity' => 12,
            ],
        ], 200)]);
        $hit = $svc->lookup($this->owner(17), null, '6291000000011', 'cashier', '991');
        $this->assertSame('amial-merchant', $hit['product']['source']);
        $this->assertSame('10000000-0000-4000-8000-000000000001', $hit['product']['merchant_product_id']);
        $this->assertSame('12', $hit['pack_size']);

        Http::assertSent(function (OutgoingRequest $request): bool {
            $h = fn (string $key): string => (string) ($request->header($key)[0] ?? '');
            $timestamp = $h('X-Amial-Timestamp');
            $nonce = $h('X-Amial-Nonce');
            $ctx = $h('X-Amial-Context');
            $decoded = json_decode(base64_decode(strtr($ctx, '-_', '+/'), true) ?: '', true);
            $path = '/api/v1/merchant/tenants/legacy_merchant_117/branches/legacy_merchant_117_main/catalog/barcodes/6291000000011';
            $canonical = $timestamp."\n".$nonce."\nGET\n".$path."\n".$ctx."\n".hash('sha256', '');
            return $request->method() === 'GET'
                && $request->url() === 'https://merchant-stage.example.test'.$path
                && is_array($decoded)
                && $decoded['tenant_id'] === 'legacy_merchant_117'
                && $decoded['branch_ids'] === ['legacy_merchant_117_main']
                && $decoded['actor_type'] === 'cashier'
                && $decoded['actor_id'] === '991'
                && $decoded['scopes'] === ['products.read']
                && hash_equals(hash_hmac('sha256', $canonical, str_repeat('s', 48)), $h('X-Amial-Signature'));
        });
    }

    public function test_independent_catalog_products_keep_uuid_for_flutter_cart(): void
    {
        $svc = $this->configured();
        Http::fake(['https://merchant-stage.example.test/*' => Http::response([
            'code' => 'BRANCH_CATALOG', 'data' => [
                ['id' => '10000000-0000-4000-8000-000000000002',
                    'sku' => 'SKU-2','name' => 'مياه',
                    'price' => '20.0000','quantity'=>120],
            ],
        ], 200)]);
        $rows = $svc->catalog($this->owner(17), null, 'owner', '17');
        $this->assertCount(1, $rows);
        $this->assertSame('10000000-0000-4000-8000-000000000002', $rows[0]['id']);
        $this->assertSame($rows[0]['id'], $rows[0]['merchant_product_id']);
        $this->assertSame('amial-merchant', $rows[0]['source']);
    }

    public function test_cash_sale_is_signed_and_merchant_price_is_authoritative(): void
    {
        $svc = $this->configured();
        Http::fake(['https://merchant-stage.example.test/*' => Http::response([
            'code' => 'CASH_SALE_RECORDED',
            'data' => ['sale_id'=>'20000000-0000-4000-8000-000000000001',
                'total'=>'240.0000','replayed'=>false,
                'items'=>[['name'=>'مياه','qty'=>12,'price'=>'20.0000','line_total'=>'240.0000',
                    'merchant_product_id'=>'10000000-0000-4000-8000-000000000002']]],
        ], 201)]);
        $line = ['product_id' => '10000000-0000-4000-8000-000000000002', 'quantity' => 12];
        $sale = $svc->cashSale($this->owner(17), null, [$line],
            '12345678-1234-4234-8234-123456789abc', 'cashier', '991');
        $this->assertSame('240.0000', $sale['total_amount']);
        $this->assertSame('amial-merchant', $sale['source']);
        $this->assertSame('مياه', $sale['items'][0]['name']);
        $this->assertSame('20.0000', $sale['items'][0]['price']);
        Http::assertSent(static function (OutgoingRequest $request) use ($line): bool {
            $body = json_decode($request->body(), true);
            $h = fn (string $k): string => (string) ($request->header($k)[0] ?? '');
            $path = '/api/v1/merchant/tenants/legacy_merchant_117/branches/legacy_merchant_117_main/cash-sales';
            $canon = $h('X-Amial-Timestamp')."\n".$h('X-Amial-Nonce')."\nPOST\n".
                $path."\n".$h('X-Amial-Context')."\n".hash('sha256', $request->body());
            return $request->method() === 'POST'
                && $body['currency'] === 'YER' && $body['payment_method'] === 'cash'
                && $body['lines'] === [$line]
                && hash_equals(hash_hmac('sha256', $canon, str_repeat('s', 48)), $h('X-Amial-Signature'));
        });
    }

    public function test_wallet_journey_reserves_before_payment_and_only_system_asserts_paid_reference(): void
    {
        $svc = $this->configured();
        $owner = $this->owner(17);
        $order = '20000000-0000-4000-8000-000000000017';
        $product = '10000000-0000-4000-8000-000000000017';
        Http::fake([
            'https://merchant-stage.example.test/*' => Http::sequence()
                ->push(['code'=>'ORDER_RESERVED', 'data'=>[
                    'id'=>$order,'status'=>'reserved',
                    'payment_method'=>'amial_pay',
                    'total'=>'1500.0000',
                    'expires_at'=>now()->addMinutes(15)->toIso8601String(),
                ]], 201)
                ->push(['code'=>'MERCHANT_ORDER', 'data'=>[
                    'id'=>$order,'status'=>'reserved','payment_method'=>'amial_pay',
                    'total'=>'1500.0000',
                ]], 200)
                ->push(['code'=>'ORDER_PAYMENT_AUTHORIZED','data'=>[
                    'order_id'=>$order,'payment_status'=>'authorized',
                ]], 201)
                ->push(['code'=>'ORDER_FULFILLED','data'=>[
                    'id'=>$order,'status'=>'fulfilled','payment_status'=>'authorized',
                ]], 201)
                ->push(['code'=>'WALLET_POS_RECEIPT','data'=>[
                    'order_id'=>$order,'sale_ulid'=>$order,'payment_method'=>'amial_pay',
                    'paid_transaction_id'=>'AMIALTXN123456789012',
                    'items'=>[['merchant_product_id'=>$product,'name'=>'قهوة','qty'=>2,'price'=>'750.0000']],
                    'total_amount'=>'1500.0000',
                ]], 200),
        ]);
        $reserved = $svc->reserveWalletOrder($owner, null, [
            ['product_id'=>$product,'quantity'=>2],
        ], 'test_wallet_order_key_000001', 'cashier', '991');
        $this->assertSame($order, $reserved['id']);
        $this->assertSame('1500.0000', $reserved['total']);
        $sourceOrder = $svc->walletOrder($owner, null, $order, 'cashier', '991');
        $this->assertSame($order, $sourceOrder['id']);
        $svc->authorizeWalletOrder($owner, null, $order, 'AMIALTXN123456789012', '1500.0000');
        $svc->fulfillWalletOrder($owner, null, $order, 'cashier', '991');
        $receipt = $svc->walletReceipt($owner, null, $order, 'cashier', '991');
        $this->assertSame('amial_pay', $receipt['payment_method']);
        $this->assertSame('AMIALTXN123456789012', $receipt['paid_transaction_id']);
        Http::assertSentCount(5);

        Http::assertSent(static function (OutgoingRequest $request) use ($order): bool {
            if (! str_contains($request->url(), "/orders/{$order}/payment-receipts/amial")) return false;
            $body = json_decode($request->body(), true);
            $encoded = $request->header('X-Amial-Context')[0] ?? '';
            $ctx = json_decode(base64_decode(strtr($encoded, '-_', '+/'), true) ?: '', true);
            return $request->method() === 'POST'
                && $body['amount'] === '1500.0000'
                && $body['payment_reference'] === 'AMIALTXN123456789012'
                && $body['provider'] === 'amial_pay'
                && $ctx['actor_type'] === 'system'
                && $ctx['actor_id'] === 'amial-pay'
                && $ctx['scopes'] === ['orders.payment.record'];
        });
    }

    public function test_payment_authorization_failure_never_fulfills_or_fakes_a_receipt(): void
    {
        $svc = $this->configured();
        Http::fake(['https://merchant-stage.example.test/*' => Http::response([
            'code'=>'MERCHANT_NOT_READY',
        ], 503)]);
        $this->expectExceptionMessage('MERCHANT_POS_SERVICE_UNAVAILABLE');
        try {
            $svc->authorizeWalletOrder($this->owner(17), null,
                '20000000-0000-4000-8000-000000000017',
                'AMIALTXN123456789012', '1500.0000');
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_unmapped_branch_and_insecure_endpoint_fail_closed_before_http(): void
    {
        $svc = $this->configured();
        Http::fake();
        $branch = new Branch(['is_default'=>false]);
        $this->expectException(\RuntimeException::class);
        try {
            $svc->catalog($this->owner(17), $branch, 'cashier', '991');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_post_gateway_rejects_wrong_owner_and_disallows_plain_http(): void
    {
        $svc = $this->configured(17);
        $this->assertFalse($svc->enabledFor($this->owner(25)));
        Http::fake();
        config()->set('amial.merchant_pos_bridge.base_url', 'http://merchant-stage.example.test');
        $this->expectExceptionMessage('MERCHANT_POS_BRIDGE_NOT_CONFIGURED');
        try {
            $svc->catalog($this->owner(17), null, 'cashier', '991');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_http_downtime_never_uses_legacy_product_as_fallback(): void
    {
        $svc = $this->configured();
        Http::fake(['https://merchant-stage.example.test/*' => Http::response(['code'=>'DOWN'],503)]);
        $this->expectExceptionMessage('MERCHANT_POS_SERVICE_UNAVAILABLE');
        $svc->lookup($this->owner(17), null, '6291000000011', 'cashier', '991');
    }

    public function test_http_not_found_is_safe_miss_not_legacy_fallback(): void
    {
        $svc = $this->configured();
        Http::fake(['https://merchant-stage.example.test/*' => Http::response(['code'=>'BARCODE_NOT_FOUND'],404)]);
        $this->assertNull($svc->lookup($this->owner(17), null, '6291000000011', 'cashier', '991'));
    }
}
