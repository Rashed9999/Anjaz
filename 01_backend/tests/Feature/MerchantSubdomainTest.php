<?php

namespace Tests\Feature;

use App\Support\PortalHost;
use Tests\TestCase;

class MerchantSubdomainTest extends TestCase
{
    private const HOST = 'merchant.amialpay.com';

    protected function setUp(): void
    {
        parent::setUp();
        config(['amial.hosts.merchant' => self::HOST]);
    }

    public function test_root_and_short_login_link_to_the_merchant_portal(): void
    {
        $this->assertTrue(PortalHost::enabled());
        $this->get('http://' . self::HOST . '/')
            ->assertRedirect('http://' . self::HOST . '/merchant');
        $this->get('http://' . self::HOST . '/login')
            ->assertRedirect('http://' . self::HOST . '/merchant/login');
        $this->get('http://' . self::HOST . '/?plan=business')
            ->assertRedirect('http://' . self::HOST . '/merchant?plan=business');
        $this->get('http://' . self::HOST . '/merchant/login')->assertOk();
    }

    public function test_existing_links_redirect_but_post_credentials_never_cross_hosts(): void
    {
        $this->get('http://amialpay.com/merchant/login?ref=saved')
            ->assertRedirect('http://' . self::HOST . '/merchant/login?ref=saved');
        $this->post('http://amialpay.com/merchant/login', [
            'identifier' => 'merchant-owner', 'password' => 'do-not-forward',
        ])->assertStatus(421)->assertJsonPath('meta.expected_host', self::HOST);
        $this->post('http://' . self::HOST . '/login', [
            'identifier' => 'merchant-owner', 'password' => 'do-not-forward',
        ])->assertStatus(421)->assertJsonPath('meta.expected_path', '/merchant/login');
    }

    public function test_merchant_session_cookie_is_host_only_even_with_bad_shared_domain(): void
    {
        config(['session.cookie' => 'amial_platform_session',
            'session.domain' => '.amialpay.com']);

        $response = $this->get('http://' . self::HOST . '/merchant/login')->assertOk();
        $cookie = collect($response->headers->getCookies())->first(
            fn ($c) => $c->getName() === 'amial_merchant_session'
        );

        $this->assertNotNull($cookie);
        $this->assertNull($cookie->getDomain());
        $this->assertSame('amial_platform_session', config('session.cookie'));
        $this->assertSame('.amialpay.com', config('session.domain'));
    }

    public function test_admin_and_agent_do_not_open_on_the_merchant_host(): void
    {
        config(['amial.hosts.admin' => '', 'amial.hosts.agent' => '']);
        $this->get('http://' . self::HOST . '/admin/auth/login')->assertNotFound();
        $this->get('http://' . self::HOST . '/agent/login')->assertNotFound();
    }

    public function test_public_login_links_directly_to_the_merchant_host(): void
    {
        $this->get('http://amialpay.com/login')->assertOk()
            ->assertSee('http://' . self::HOST . '/merchant/login', false);
    }

    public function test_merchant_host_can_be_disabled_without_changing_legacy_routes(): void
    {
        config(['amial.hosts.merchant' => '']);
        $this->assertNull(PortalHost::merchant());
        $this->get('http://amialpay.com/merchant/login')->assertOk();
    }
}
