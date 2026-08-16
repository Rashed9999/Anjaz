<?php

namespace Tests\Feature;

use App\Services\Messaging\Contracts\MessageProvider;
use App\Services\Messaging\Contracts\SupportsFreeText;
use App\Services\Messaging\ProviderRegistry;
use Tests\TestCase;

/**
 * AMIAL-MESSAGING-002 — **مزوّدٌ لا تعرفه اللوحةُ لا يُفعَّل أبداً.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * في المشروع بابان لا واحد:
 *
 *   ① `ProviderRegistry::all()` — يكتشف الأصنافَ **بمسح المجلّد**.
 *   ② `WhatsappAdminController::PROVIDERS` — **قائمةٌ بيضاءُ ثابتة**
 *      يحكمها `in:` عند الحفظ.
 *
 * والأوّلُ يجد الصنفَ فوراً، **والثاني يرفض حفظَ إعداده بـ٤٢٢**. فمزوّدٌ
 * يُضاف إلى المجلّد وحدَه: `isEnabled()` تعود `false` أبداً — لأنّ
 * `status` لا يُكتب قطّ — ولا خطأَ في أيّ سجلّ. **يبدو مبنيّاً وهو
 * غيرُ موصولٍ به.**
 *
 * وهو نمطُ العطل الأكثر تكراراً في أميال باي، واقعاً هنا على قناة
 * الإنذار — وهي القناةُ التي لا يُعرف انكسارُ المال بدونها.
 *
 * **وهذا الحارسُ يقفل البابين معاً**، ويُضيف شرطاً ثالثاً: أن تبقى قناةٌ
 * واحدةٌ على الأقلّ صالحةً للنصّ الحرّ — فبدونها لا إنذارَ ليليٌّ إطلاقاً،
 * لأنّ Meta Cloud وTwilio يمنعان النصَّ الحرَّ خارج نافذة ٢٤ ساعة.
 */
class MessagingProviderReachabilityTest extends TestCase
{
    /** @return list<string> أسماءُ المزوّدين المكتشَفين من المجلّد */
    private function discovered(string $channel): array
    {
        $out = [];

        foreach (app(ProviderRegistry::class)->all() as $p) {
            /** @var MessageProvider $p */
            if ($p->channel() === $channel) {
                $out[] = $p->key();
            }
        }

        sort($out);

        return $out;
    }

    /** @return list<string> القائمةُ البيضاءُ في متحكّم اللوحة */
    private function whitelisted(): array
    {
        $ref = new \ReflectionClass(
            \App\Http\Controllers\Api\V1\Amial\WhatsappAdminController::class);

        /** @var list<string> $list */
        $list = $ref->getConstant('PROVIDERS');

        sort($list);

        return $list;
    }

    /**
     * @test
     *
     * **كلُّ مزوّدٍ مبنيٍّ تعرفه اللوحة.**
     */
    public function every_built_whatsapp_provider_can_be_configured_from_the_panel(): void
    {
        $missing = array_diff($this->discovered('whatsapp'), $this->whitelisted());

        $this->assertSame([], array_values($missing), sprintf(
            'مزوّدٌ مبنيٌّ ولا تعرفه اللوحة: %s — الحفظُ يُرفض بـ٤٢٢، '
            . 'و`isEnabled()` تعود false أبداً، ولا خطأَ في أيّ سجلّ.',
            implode(' · ', $missing)));
    }

    /**
     * @test
     *
     * **ولا اسمَ في اللوحة بلا صنفٍ خلفه.**
     *
     * والعكسُ أخطرُ في أثره النفسيّ: يُفعّله المشرفُ ويراه «مُفعَّلاً» في
     * الشاشة، ولا شيءَ يرسل. فاللوحةُ تقول نعم والواقعُ لا.
     */
    public function every_panel_entry_has_a_class_behind_it(): void
    {
        $orphans = array_diff($this->whitelisted(), $this->discovered('whatsapp'));

        $this->assertSame([], array_values($orphans), sprintf(
            'اسمٌ في اللوحة بلا صنف: %s — يُفعَّل ويُعرض «مُفعَّلاً» ولا يرسل شيئاً.',
            implode(' · ', $orphans)));
    }

    /**
     * @test
     *
     * **وتبقى قناةٌ صالحةٌ للنصّ الحرّ.**
     *
     * إنذارُ المصالحة الساعةَ الثانية فجراً نصٌّ حرّ. و`meta_cloud`
     * و`twilio` يمنعانه خارج نافذة الأربع والعشرين ساعة — فبلا مزوّدٍ
     * غيرِ مقيَّدٍ بالنافذة **لا إنذارَ ليليّ**، ولا يُكتشف ذلك إلّا ليلةَ
     * الحاجة.
     */
    public function at_least_one_free_text_provider_exists_for_night_alerts(): void
    {
        $free = [];

        foreach (app(ProviderRegistry::class)->all() as $p) {
            if ($p->channel() === 'whatsapp' && $p instanceof SupportsFreeText) {
                $free[] = $p->key();
            }
        }

        $this->assertNotEmpty($free,
            'لا مزوّدَ نصٍّ حرٍّ إطلاقاً — فلا إنذارَ تشغيليٍّ ممكن');

        // **والقيدُ الحقيقيُّ ليس «نصٌّ حرّ» بل «بلا نافذةِ ٢٤ ساعة».**
        $windowFree = array_intersect($free, ['ultramsg', 'wati', 'green_api']);

        $this->assertNotEmpty($windowFree,
            'كلُّ مزوّدي النصّ الحرّ مقيَّدون بنافذة ٢٤ ساعة (meta/twilio) — '
            . 'فإنذارُ ٠٢:٠٠ لن يصل، ولا يُكتشف ذلك إلّا ليلةَ الحاجة');
    }

    /**
     * @test
     *
     * **و`green_api` مبنيٌّ وموصولٌ وصالحٌ للنصّ الحرّ.**
     *
     * (الثلاثةُ معاً — فأيُّ واحدةٍ وحدَها لا تكفي.)
     */
    public function green_api_is_built_reachable_and_free_text(): void
    {
        $this->assertContains('green_api', $this->discovered('whatsapp'),
            'صنفُ green_api غيرُ مكتشَف — راجِع مجلّد Providers/Whatsapp');

        $this->assertContains('green_api', $this->whitelisted(),
            'green_api خارجَ قائمة اللوحة — فلا يُحفظ إعدادُه ولا يُفعَّل');

        $p = null;
        foreach (app(ProviderRegistry::class)->all() as $one) {
            if ($one->key() === 'green_api') {
                $p = $one;
                break;
            }
        }

        $this->assertInstanceOf(SupportsFreeText::class, $p,
            'green_api لا يدعم النصَّ الحرّ — فلا يصلح للإنذارات');
    }

    /**
     * @test
     *
     * **وحقولُه الثلاثةُ هي التي تعرضها لوحةُ GREEN-API.**
     *
     * فمن نسخ القيمَ من هناك يجدها هنا بالأسماء نفسِها، ولا يخمّن.
     */
    public function green_api_requires_exactly_what_its_console_shows(): void
    {
        $src = (string) file_get_contents(base_path(
            'app/Services/Messaging/Providers/Whatsapp/GreenApiProvider.php'));

        foreach (['api_url', 'instance_id', 'token'] as $k) {
            $this->assertStringContainsString("'{$k}'", $src,
                "الحقل «{$k}» غيرُ مطلوب — فيُفعَّل المزوّدُ ناقصاً ويصمت");
        }
    }
}
