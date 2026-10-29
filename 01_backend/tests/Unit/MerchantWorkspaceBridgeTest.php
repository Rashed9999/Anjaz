<?php

namespace Tests\Unit;

use App\Models\Merchant;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\Merchant\MerchantWorkspaceBridge;
use Illuminate\Http\Client\Request as OutboundRequest;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

final class MerchantWorkspaceBridgeTest extends TestCase
{
    public function test_disabled_bridge_never_sends_requests(): void
    {
        config()->set('amial.merchant_workspace.enabled', false);
        Http::fake();

        $url = app(MerchantWorkspaceBridge::class)->redirectFor(
            new User(), new Merchant(), new MerchantProfile()
        );

        $this->assertNull($url);
        Http::assertNothingSent();
    }

    public function test_plaintext_or_malformed_workspace_urls_fail_closed_before_any_database_lookup(): void
    {
        config()->set('amial.merchant_workspace.enabled', true);
        config()->set('amial.merchant_workspace.integration_secret', str_repeat('x', 48));
        $owner = new User();
        $merchant = new Merchant();
        $profile = new MerchantProfile(['verification_status' => 'verified']);
        Http::fake();

        foreach ([
            'http://merchant-stage.example.test',
            'https://user:pass@merchant-stage.example.test',
            'https://merchant-stage.example.test/foreign-path',
            'https://merchant-stage.example.test?redirect=evil',
            'https://merchant-stage.example.test#fragment',
        ] as $url) {
            config()->set('amial.merchant_workspace.base_url', $url);
            $this->assertNull(
                app(MerchantWorkspaceBridge::class)->redirectFor($owner, $merchant, $profile),
                'Unsafe staging URL should never trigger a login exchange: '.$url
            );
        }

        Http::assertNothingSent();
    }

    public function test_signed_json_body_and_context_match_isolated_merchant_protocol(): void
    {
        $path = '/api/v1/merchant/provision';
        $secret = str_repeat('s', 48);
        $baseUrl = 'https://merchant-stage.example.test';
        $payload = ['tenant_id' => 'legacy_merchant_7', 'approved' => true];
        $actor = [
            'actor_type' => 'system', 'actor_id' => 'amial-pay',
            'tenant_id' => 'legacy_merchant_7',
            'scopes' => ['merchant.provision'], 'branch_ids' => ['legacy_merchant_7_main'],
        ];

        Http::fake([$baseUrl.'/*' => Http::response(['code' => 'MERCHANT_PROVISIONED'], 200)]);
        $method = new ReflectionMethod(MerchantWorkspaceBridge::class, 'post');
        $result = $method->invoke(new MerchantWorkspaceBridge(), $baseUrl, $secret, $path, $payload, $actor);

        $this->assertSame('MERCHANT_PROVISIONED', $result['code']);
        Http::assertSentCount(1);
        Http::assertSent(function (OutboundRequest $request) use ($baseUrl, $path, $secret, $payload): bool {
            $header = fn (string $name): string => (string) ($request->header($name)[0] ?? '');
            $timestamp = $header('X-Amial-Timestamp');
            $nonce = $header('X-Amial-Nonce');
            $context = $header('X-Amial-Context');
            $signature = $header('X-Amial-Signature');
            $raw = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $canonical = $timestamp."\n".$nonce."\nPOST\n".$path."\n".$context."\n".hash('sha256', $raw);
            $decoded = json_decode(base64_decode(strtr($context, '-_', '+/'), true) ?: '', true);

            return $request->method() === 'POST'
                && $request->url() === $baseUrl.$path
                && $request->body() === $raw
                && is_array($decoded)
                && ($decoded['iss'] ?? null) === 'amial-pay'
                && ($decoded['aud'] ?? null) === 'amial-merchant'
                && ($decoded['actor_type'] ?? null) === 'system'
                && ($decoded['tenant_id'] ?? null) === 'legacy_merchant_7'
                && in_array('merchant.provision', $decoded['scopes'] ?? [], true)
                && strlen($nonce) >= 20
                && ctype_digit($timestamp)
                && hash_equals(hash_hmac('sha256', $canonical, $secret), $signature);
        });
    }
}
