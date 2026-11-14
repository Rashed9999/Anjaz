<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-AGENT-SUBDOMAIN-001 — مدخلٌ ودّي: agents.<النطاق> يُحيل إلى /agent/* على
 * النطاق الرئيسي، بلا تكرار مسارات ولا تطبيق مستقل.
 */
class AgentSubdomainRedirectTest extends TestCase
{
    /**
     * AMIAL-AGENT-SUBDOMAIN-002 — **`config()` وقتَ الاختبار لا يُعيد تسجيل المسارات.**
     * `routes/web.php` يبني نطاقَ `Route::domain('agents.'.$host)` من `config('app.url')`
     * **عند إقلاع التطبيق مرّةً واحدة**، لا عند كلّ طلب. فتعديلُ القيمة داخل جسم الاختبار
     * (كما كان هنا: `config(['app.url' => 'https://amialpay.com'])`) لا يغيّر النطاقَ
     * المسجَّل فعلاً — وهو مبنيٌّ على `APP_URL` الحقيقيّ في بيئة الاختبار. فكانت الاختباراتُ
     * تطلب `agents.amialpay.com` وتُقارَن بنطاقٍ آخر مسجَّل أصلاً، فتمرّ الطلباتُ كأنّها
     * لا تطابق أيّ دومين مخصَّص وتردّ 200 — لا 301.
     *
     * **فيُقرأ المضيفُ الحقيقيّ من `config('app.url')` نفسِه** — ما سُجِّلت المساراتُ عليه
     * فعلاً — بدل افتراض نطاقٍ ثابت.
     */
    private function host(): string
    {
        return (string) parse_url((string) config('app.url'), PHP_URL_HOST);
    }

    /** @test */
    public function the_agent_subdomain_root_redirects_to_the_agent_portal_login(): void
    {
        $response = $this->get('http://agents.'.$this->host().'/');

        $response->assertStatus(301);
        $this->assertSame(rtrim((string) config('app.url'), '/').'/agent/', $response->headers->get('Location'));
    }

    /** @test */
    public function a_deep_link_on_the_subdomain_keeps_its_path(): void
    {
        $response = $this->get('http://agents.'.$this->host().'/login');

        $response->assertStatus(301);
        $this->assertSame(rtrim((string) config('app.url'), '/').'/agent/login', $response->headers->get('Location'));
    }

    /** @test */
    public function the_main_domain_agent_path_still_works_directly_without_any_redirect(): void
    {
        $response = $this->get(rtrim((string) config('app.url'), '/').'/agent/login');

        $response->assertStatus(200);
    }
}
