<?php

namespace Tests\Feature;

use App\Services\Messaging\ProviderRegistry;
use App\Services\Messaging\Providers\Sms\SmsGateProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmsGateProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.smsgate' => [
            'enabled' => true,
            'base_url' => 'https://sms.example.test',
            'username' => 'test-user',
            'password' => 'test-secret',
            'sim_number' => 1,
            'ttl_seconds' => 300,
            'poll_attempts' => 1,
        ]]);
    }

    public function test_disabled_by_default_and_no_public_cloud_fallback(): void
    {
        config(['services.smsgate.enabled' => false]);
        $provider = app(SmsGateProvider::class);
        $this->assertFalse($provider->isEnabled());
        $this->assertFalse($provider->sendOtp('+967783545525', '735912'));
        Http::assertNothingSent();
    }

    public function test_insecure_base_url_is_rejected(): void
    {
        config(['services.smsgate.base_url' => 'http://127.0.0.1:8080']);
        $this->assertFalse(app(SmsGateProvider::class)->isEnabled());
    }

    public function test_registry_discovers_private_gateway_without_configuring_paid_providers(): void
    {
        $registry = app(ProviderRegistry::class);
        $registry->forget();
        $this->assertTrue(collect($registry->enabledFor('sms'))
            ->contains(fn ($p) => $p instanceof SmsGateProvider));
    }

    public function test_queued_message_is_not_reported_as_sent(): void
    {
        Http::fakeSequence()
            ->push(['id' => 'test-msg-1', 'state' => 'Pending'], 202)
            ->push(['id' => 'test-msg-1', 'state' => 'Pending'], 200);

        $this->assertFalse(app(SmsGateProvider::class)->sendOtp('783545525', '735912'));
        Http::assertSent(fn ($request) =>
            $request->method() === 'POST'
            && $request->url() === 'https://sms.example.test/api/3rdparty/v1/messages'
            && $request['phoneNumbers'] === ['+783545525']
            && $request['simNumber'] === 1
            && $request['textMessage']['text'] === 'رمز التحقق من أميال باي: 735912'
        );
    }

    public function test_sent_status_from_phone_allows_success(): void
    {
        Http::fakeSequence()
            ->push(['id' => 'test-msg-2', 'state' => 'Pending'], 202)
            ->push(['id' => 'test-msg-2', 'state' => 'Sent'], 200);

        $this->assertTrue(app(SmsGateProvider::class)->sendOtp('+967783545525', '735912'));
        Http::assertSent(fn ($request) =>
            $request->method() === 'GET'
            && $request->url() === 'https://sms.example.test/api/3rdparty/v1/messages/test-msg-2'
        );
    }

    public function test_failed_modem_delivery_is_not_success(): void
    {
        Http::fakeSequence()
            ->push(['id' => 'test-msg-3', 'state' => 'Pending'], 202)
            ->push(['id' => 'test-msg-3', 'state' => 'Failed'], 200);

        $this->assertFalse(app(SmsGateProvider::class)->sendOtp('+967783545525', '735912'));
    }

    public function test_rejection_or_missing_id_is_not_success(): void
    {
        Http::fake(['*' => Http::response(['message' => 'down'], 503)]);
        $this->assertFalse(app(SmsGateProvider::class)->sendOtp('+967783545525', '735912'));

        Http::fake(['*' => Http::response(['state' => 'Pending'], 202)]);
        $this->assertFalse(app(SmsGateProvider::class)->sendOtp('+967783545525', '735912'));
    }
}
