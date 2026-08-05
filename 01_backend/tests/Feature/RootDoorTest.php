<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-ROOT-DOOR-001 — `amialpay.com` نفسه لا يردّ 404.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **العطل:** بعد أن عمل النطاق والشهادة، كتب صاحبُ المشروع `amialpay.com`
 * فرأى:
 *
 *     404  |  NOT FOUND
 *
 * سطرٌ إنجليزيٌّ واحدٌ على صفحةٍ بيضاء. والسبب أنّ الجذر **لم يكن له مسارٌ
 * إطلاقاً** — في `routes/web.php` كان `/home` وحده، ومن يكتب اسم نطاقٍ لا
 * يكتب `/home`.
 *
 * وهو «مبنيٌّ وغير قابلٍ للوصول» في أنقى صوره: ثلاث بوّاباتٍ تعمل كلُّها،
 * ولا واحدةَ منها تُذكر في الموضع الذي يصله الناس أوّلاً.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولا يُكتفى بـ«الصفحة تردّ 200».** ذلك يمرّ على صفحةٍ فارغة. فيُفحص
 * أنّ البوّابتين **موصولتان بعنوانَيهما الصحيحين** — لأنّ رابطاً خاطئاً
 * على صفحةٍ تردّ 200 لا يُنتج خطأً في أيّ سجلّ. (القاعدة التاسعة.)
 */
class RootDoorTest extends TestCase
{
    /**
     * @test
     *
     * **الجذر يردّ صفحةً، لا 404.**
     */
    public function the_domain_root_is_not_a_404(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('أميال باي', false);
    }

    /**
     * @test
     *
     * **وبابا الويب موصولان بعنوانَيهما — لا بعنوانٍ يشبههما.**
     *
     * وخلطُهما يُرسل الصرّاف إلى بوّابةٍ ترفضه، والأدمن كذلك. ولا يظهر
     * ذلك في أيّ سجلّ: الصفحة تردّ 200 والرابط يعمل ويذهب إلى الخطأ.
     */
    public function both_web_portals_are_linked_with_their_real_paths(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('/agent/login', $html,
            'بوّابة شركات الصرافة غير مذكورةٍ في الصفحة الأولى');
        $this->assertStringContainsString('/admin/auth/login', $html,
            'لوحة الإدارة غير مذكورةٍ في الصفحة الأولى');

        // والمساران يعملان فعلاً — لا يكفي أن يُكتبا.
        $this->get('/agent/login')->assertOk();
        $this->get('/admin/auth/login')->assertOk();
    }

    /**
     * @test
     *
     * **ولا شيء من شبكةٍ خارجيّة.**
     *
     * صفحةٌ تطلب خطّاً أو إطاراً من CDN تسقط عند أوّل انقطاع — وهي أوّل
     * ما يراه الزائر، فتصير المنصّةُ كلُّها «لا تعمل» في نظره.
     */
    public function the_first_page_depends_on_no_external_host(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        foreach (['//cdn.', '//fonts.googleapis', '//cdnjs.', '//unpkg.', '//ajax.google'] as $needle) {
            $this->assertStringNotContainsString($needle, $html,
                "الصفحة الأولى تعتمد على مضيفٍ خارجيّ: {$needle}");
        }
    }

    /**
     * @test
     *
     * **و`/home` القديم لا يُترك معلَّقاً.**
     *
     * كان يُحوّل إلى لوحة الإدارة. وتركُه هكذا يُبقي بابين للشيء نفسه
     * يتباعدان مع الوقت.
     */
    public function the_old_home_path_still_leads_somewhere(): void
    {
        $this->get('/home')->assertRedirect('/');
    }
}
