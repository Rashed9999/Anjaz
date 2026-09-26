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

    public function test_primary_website_links_to_merchant_host_from_header_footer_home_and_business(): void
    {
        $url = 'https://' . self::HOST . '/merchant/login';
        $this->assertSame($url, PortalHost::merchantLoginUrl());

        foreach (['http://amialpay.com/', 'http://amialpay.com/business'] as $page) {
            $this->get($page)->assertOk()
                ->assertSee('href="' . $url . '"', false)
                ->assertSee('بوابة التاجر');
        }

        $html = $this->get('http://amialpay.com/')->getContent();
        preg_match('~<header[^>]*class="[^"]*site-head[^"]*".*?</header>~s', $html, $header);
        $this->assertNotEmpty($header);
        $this->assertStringContainsString('href="' . $url . '"', $header[0]);
        $this->assertStringContainsString(route('admin.auth.login'), $header[0]);
        $this->assertStringContainsString(route('login'), $header[0]);
        $this->get($url)->assertOk();
    }

    public function test_merchant_link_falls_back_to_legacy_route_before_host_setup(): void
    {
        config(['amial.hosts.merchant' => '']);
        $url = route('merchant.web.login');
        $this->assertSame($url, PortalHost::merchantLoginUrl());
        $this->get('/')->assertOk()->assertSee('href="' . $url . '"', false);
        $this->get('/business')->assertOk()->assertSee('href="' . $url . '"', false);
    }

    public function test_merchant_host_can_be_disabled_without_changing_legacy_routes(): void
    {
        config(['amial.hosts.merchant' => '']);
        $this->assertNull(PortalHost::merchant());
        $this->get('http://amialpay.com/merchant/login')->assertOk();
    }
}
