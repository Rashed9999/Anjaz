<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-AGENT-TABS-001 — **زرٌّ خارج الـ`nav` يرمي ولا يفعل شيئاً.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمنُ الذي قِيس:** قال صاحبُ المشروع «أزرارُ إدارة السيولة وغيرها
 * تحت المربّعات لا تعمل». وقِيس في **متصفّحٍ حقيقيّ** على بنية الصفحة
 * نفسِها (بوتستراب 5.3.3 المُضمَّن في المشروع):
 *
 *   تبويبٌ داخل `ul.nav`  → يفتح لوحتَه
 *   زرٌّ خارجَ الـ`nav`    → **`Illegal invocation`** واللوحةُ لا تتغيّر
 *
 * فبوتستراب يبحث عن أبٍ `.nav` ليحسب إخوةَ التبويب. **والضغطةُ تذهب بلا
 * أثر: لا رسالة، ولا طلبٌ يصل، ولا سطرٌ في أيّ سجلّ.** (القاعدة التاسعة.)
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والقائمةُ المنسدلة ليست منها** — `li.nav-item.dropdown` **داخل**
 * `ul.nav`، فـ`closest('.nav')` تجدها وتعمل. فالحارسُ يفرّق بينهما ولا
 * يمنع نمطاً سليماً.
 */
class AgentDashboardButtonsReachTheirTabsGuardTest extends TestCase
{
    private const VIEW = 'resources/views/agent-views/dashboard.blade.php';

    private function source(): string
    {
        $p = base_path(self::VIEW);

        $this->assertFileExists($p, 'لوحةُ الوكيل اختفت من مكانها.');

        return (string) file_get_contents($p);
    }

    /** كتلةُ لوحةِ «الرئيسيّة» — وهي الوحيدةُ التي فيها أزرارٌ خارج الـnav. */
    private function overviewPane(string $src): string
    {
        $a = strpos($src, 'id="ag-overview"');
        $b = strpos($src, 'id="ag-workspace"', (int) $a);

        $this->assertNotFalse($a, 'لوحةُ «الرئيسيّة» اختفت.');

        return substr($src, (int) $a, (int) $b - (int) $a);
    }

    /**
     * **① لا `data-bs-toggle="tab"` خارج الـ`nav`.**
     *
     * وهو العطلُ بعينه — ويُقاس على الكتلة التي لا `nav` فيها.
     */
    /** @test */
    public function no_tab_trigger_sits_outside_the_nav(): void
    {
        $pane = $this->overviewPane($this->source());

        $offenders = preg_match_all('/data-bs-toggle="tab"/', $pane);

        $this->assertSame(0, $offenders, sprintf(
            "**%d زرّاً يستدعي تبويبَ بوتستراب من خارج `ul.nav`.**\n\n"
            ."وقِيس في متصفّحٍ حقيقيّ: يرمي `Illegal invocation` ولا يتغيّر "
            ."شيء — تُضغط ولا يحدث شيء، ولا رسالة، ولا طلبٌ يصل.\n\n"
            .'والعلاجُ `data-ag-goto="#hash"` — تُوكَّل الضغطةُ إلى تبويبها '
            .'في الـ`nav` حيث يعمل بوتستراب.',
            $offenders));
    }

    /**
     * **② وكلُّ `data-ag-goto` له تبويبٌ حقيقيٌّ في الـ`nav`.**
     *
     * فتوكيلٌ إلى تبويبٍ لا وجودَ له يستبدل عطلاً صامتاً بعطلٍ صامتٍ آخر.
     */
    /** @test */
    public function every_delegated_button_points_at_a_real_nav_tab(): void
    {
        $src = $this->source();

        preg_match_all('/data-ag-goto="(#[\w-]+)"/', $src, $m);

        $this->assertNotEmpty($m[1],
            '**صفرُ أزرارٍ مُوكَّلة** — إمّا نُزعت البطاقاتُ كلُّها، وإمّا '
            .'تغيّرت الصيغةُ فصار هذا الحارسُ يفحص العدم. (القاعدة السابعة.)');

        $dead = [];

        foreach (array_unique($m[1]) as $target) {
            if (! str_contains($src, 'data-bs-target="'.$target.'"')) {
                $dead[] = $target;
            }
        }

        $this->assertSame([], $dead, sprintf(
            "**أزرارٌ تُوكِّل إلى تبويبٍ لا وجودَ له:**\n  %s",
            implode('، ', $dead)));
    }

    /**
     * **③ والمُوكِّلُ مبنيٌّ في الصفحة.**
     *
     * فسمةٌ بلا معالجٍ تجعل الأزرارَ **أهدأ** من قبل: كانت ترمي في
     * الطرفيّة، وصارت لا تفعل شيئاً بلا أثرٍ إطلاقاً.
     */
    /** @test */
    public function the_delegating_handler_actually_exists(): void
    {
        $this->assertStringContainsString("closest('[data-ag-goto]')", $this->source(),
            '**السمةُ موجودةٌ ولا معالجَ لها** — فالأزرارُ صامتةٌ تماماً، '
            .'وهو أسوأُ من رميةٍ تظهر في الطرفيّة.');
    }

    /**
     * **④ والتسوياتُ تبويبٌ أوّلٌ لا بندٌ في «المزيد».**
     *
     * ══════════════════════════════════════════════════════════════════
     * سأل صاحبُ المشروع «أين نظامُ التسويات الذي تعبنا عليه؟» —
     * و`AgentSettlementEngine` مبنيٌّ كاملاً وله خمسُ نقاطِ نهاية، وكان
     * بابُه الوحيدُ سطراً في قائمةٍ منسدلة. **والمطابقةُ بين الفرع
     * والوكيل، وبين الوكيل والمنصّة، عملٌ يوميّ** لا بندُ إعداداتٍ.
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function settlements_is_a_first_class_tab_not_a_dropdown_item(): void
    {
        $src = $this->source();

        $this->assertMatchesRegularExpression(
            '/<li class="nav-item"><button class="nav-link"[^>]*data-bs-target="#ag-settle"/u',
            $src,
            '**التسوياتُ ليست تبويباً أوّلَ** — ومحرّكُها مبنيٌّ كاملاً، '
            .'فيُسأل «أين نظامُ التسويات» عن شيءٍ موجودٍ ومدفون.');

        $this->assertStringNotContainsString(
            'class="dropdown-item" data-bs-toggle="tab" data-bs-target="#ag-settle"', $src,
            'بقيت التسوياتُ في «المزيد» أيضاً — فبابان لشيءٍ واحد.');
    }

    /**
     * **⑤ ومحفظةُ الشركة تُعرَض.**
     *
     * `own_balance` تصل في كلّ نداءٍ للإدارة العامّة، **ولم يقرأها
     * القالبُ في موضعٍ واحد**. فترى الإدارةُ «إجمالي الفروع» وتظنّه
     * رصيدَها — وهو رصيدُ غيرِها.
     */
    /** @test */
    public function the_company_wallet_is_actually_rendered(): void
    {
        // **يُقاس العرضُ لا الذِّكر.** أوّلُ صياغةٍ بحثت عن الكلمة وحدَها،
        // فمرّت التجربةُ العكسيّةُ لأنّ الكلمةَ في التعليق أيضاً — **حارسٌ
        // يقرأ شرحَ العطل فيظنّه علاجاً**، وهو نمطٌ وقع في المشروع قبلاً.
        $this->assertMatchesRegularExpression(
            '/num\(\s*m\.own_balance\s*\)/u', $this->source(),
            '**محفظةُ الشركة تُرسَل ولا تُعرَض.** وهي أوّلُ ما يُسأل قبل '
            .'شحن فرع: مِمَّ أشحن؟ فبلا الرقم يضغط المديرُ «شحن» ثمّ يقرأ '
            .'«الرصيد لا يكفي» — يُمنَع بعد القرار لا قبله.');
    }
}
