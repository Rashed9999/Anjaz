<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-AGENT-SUBDOMAIN-001 — مدخلٌ ودّي: agents.<النطاق> يُحيل إلى /agent/* على
 * النطاق الرئيسي، بلا تكرار مسارات ولا تطبيق مستقل.
 */
class AgentSubdomainRedirectTest extends TestCase
{
    /** @test */
    public function the_agent_subdomain_root_redirects_to_the_agent_portal_login(): void
    {
        config(['app.url' => 'https://amialpay.com']);
        $response = $this->get('http://agents.amialpay.com/');

        $response->assertStatus(301);
        $this->assertSame('https://amialpay.com/agent/', $response->headers->get('Location'));
    }

    /** @test */
    public function a_deep_link_on_the_subdomain_keeps_its_path(): void
    {
        config(['app.url' => 'https://amialpay.com']);
        $response = $this->get('http://agents.amialpay.com/login');

        $response->assertStatus(301);
        $this->assertSame('https://amialpay.com/agent/login', $response->headers->get('Location'));
    }

    /** @test */
    public function the_main_domain_agent_path_still_works_directly_without_any_redirect(): void
    {
        config(['app.url' => 'https://amialpay.com']);
        $response = $this->get('http://amialpay.com/agent/login');

        $response->assertStatus(200);
    }
}
