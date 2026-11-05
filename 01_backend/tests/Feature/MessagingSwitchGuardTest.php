<?php

namespace Tests\Feature;

use App\Services\Messaging\ProviderRegistry;
use App\Services\Otp\OtpPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-MESSAGING-002 — **طبقةٌ كاملةٌ بلا مفتاحِ تشغيل.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما قِيس:**
 *
 *     مزوّدو الرسائل   →  ١٠ أصنافٍ مكتملة، بسجلٍّ وسلسلةِ احتياط
 *     isEnabled()      →  تقرأ `addon_settings`
 *     addon_settings   →  **صفرُ صفوف**
 *     من يكتب فيه؟     →  **لا متحكّمَ واحدٌ في المشروع كلِّه**
 *
 * ⇒ كلُّ مزوّدٍ مُعطَّلٌ بالضرورة ⇒ `deliveryReady()` كاذبةٌ أبداً ⇒
 * **التسجيلُ مقفلٌ على كلّ رقمٍ حقيقيّ** — ولا سبيلَ لصاحب المشروع أن
 * يفتحه ولو أراد.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وهو أعمقُ صورةٍ من «مبنيٌّ ولا يُوصَل إليه» في هذا المشروع.** الصورُ
 * السابقةُ كلُّها دالّةٌ بلا مُنادٍ؛ وهذه **طبقةٌ بأكملها** — عشرةُ
 * مزوّدين وسجلٌّ واحتياطٌ بالأولويّة — **بلا مفتاحٍ يشغّلها**.
 *
 * وأثرُها ليس نقصَ ميزة: **التجربةُ لا تبدأ**. أرقامُ العرض وحدَها
 * تعمل، وكلُّ عميلٍ حقيقيٍّ يقف على الباب الأوّل.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ أمرٌ لا شاشة:** المفاتيحُ أسرار. وحقلُ سرٍّ في شاشةٍ يُخزَّن في
 * سجلّ الوصول ويُلتقَط في صورةٍ ويمرّ في سجلّ الخادم. والطرفيّةُ هي
 * الموضعُ الذي تُوضَع فيه الأسرارُ أصلاً — وهو نمطُ المرشد نفسُه.
 */
class MessagingSwitchGuardTest extends TestCase
{
    use RefreshDatabase;

    private function meta(array $values, bool $enable = true): int
    {
        $args = ['--set' => 'meta_cloud', '--value' => $values];

        if ($enable) {
            $args['--enable'] = true;
        }

        return Artisan::call('amial:messaging', $args);
    }

    private function policy(): OtpPolicy
    {
        app(ProviderRegistry::class)->forget();

        return app(OtpPolicy::class);
    }

    // ══════════════════════════════════════════════════════════════════
    // ① المفتاحُ موجودٌ ويشغّل
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function a_channel_can_actually_be_switched_on(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **هذا هو العطلُ بعينه.** قبل هذا الأمر لم يكن في المشروع كلِّه
        // سطرٌ واحدٌ يكتب في `addon_settings` — فالطبقةُ مبنيّةٌ ولا
        // تُشغَّل، والتسجيلُ مقفلٌ بلا سبيلٍ إلى فتحه.
        // ══════════════════════════════════════════════════════════════
        $this->assertFalse($this->policy()->deliveryReady(),
            'قناةٌ مفعّلةٌ قبل الضبط — فالحالةُ المفحوصةُ ليست هي');

        $this->assertSame(0, $this->meta([
            'access_token=T0K', 'phone_number_id=123456',
        ]));

        $this->assertTrue($this->policy()->deliveryReady(),
            'ضُبط مزوّدٌ كاملٌ ومُفعَّلٌ ولم تنفتح القناة — فالمفتاحُ لا يشغّل شيئاً');
    }

    /** @test */
    public function the_registration_door_opens_with_it(): void
    {
        // **والقياسُ على الأثر لا على العَلَم.** ما يهمّ ليس أنّ
        // `isEnabled` صارت `true`، بل أنّ **رقماً حقيقيّاً صار يُسجَّل**.
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'phone_verification'],
            ['value' => '1', 'created_at' => now(), 'updated_at' => now()]);

        $this->postJson('/api/v1/customer/auth/check-phone', ['phone' => '967771500007'])
            ->assertStatus(503);

        $this->meta(['access_token=T0K', 'phone_number_id=123456']);
        app(ProviderRegistry::class)->forget();

        $this->postJson('/api/v1/customer/auth/check-phone', ['phone' => '967771500008'])
            ->assertOk();
    }

    // ══════════════════════════════════════════════════════════════════
    // ② ولا يكذب على من يشغّله
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function an_incomplete_provider_is_refused_and_told_what_is_missing(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **و«مُفعَّل» بلا مفتاحٍ مطلوبٍ أسوأ من معطَّل**: يُختار في
        // السلسلة ثمّ يفشل، **ويمنع من بعده من أن يُجرَّب**. فالحكمُ
        // يُقال بعلّته لا بكلمة «معطَّل».
        // ══════════════════════════════════════════════════════════════
        $this->assertNotSame(0, $this->meta(['access_token=T0K']),
            'قُبل مزوّدٌ ناقصُ المفاتيح — فيُقال «مُفعَّل» ولا يُرسل');

        $this->assertStringContainsString('phone_number_id', Artisan::output(),
            'لم يُسمَّ المفتاحُ الناقص — فيُرسَل قارئُه يبحث');

        $this->assertFalse($this->policy()->deliveryReady(),
            'قناةٌ فُتحت على مزوّدٍ ناقص — فالتسجيلُ يُفتَح ثمّ يسقط عند أوّل رسالة');
    }

    /** @test */
    public function a_key_shared_by_two_channels_is_refused_not_guessed(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **و`twilio` مزوّدُ واتسابٍ ومزوّدُ رسائلَ قصيرة.** والأخذُ
        // بأوّل مطابقٍ يكتب المفاتيحَ في القناة الخطأ ثمّ يقول «مُفعَّل»
        // — فيُضبَط ما لا يُرسل، ويُبحَث عن العلّة في المزوّد لا في
        // الاختيار الصامت.
        // ══════════════════════════════════════════════════════════════
        $this->assertNotSame(0, Artisan::call('amial:messaging', [
            '--set' => 'twilio', '--value' => ['sid=S', 'token=T'], '--enable' => true,
        ]));

        $this->assertStringContainsString('channel', Artisan::output(),
            'اختير أحدُ المزوّدَين صامتاً — فقد تُكتب المفاتيحُ في القناة الخطأ');
    }

    /** @test */
    public function no_secret_is_ever_printed_back(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **وسرٌّ يُعاد عرضُه يُلتقَط في صورةٍ للشاشة ويمرّ في سجلّ
        // الخادم.** فيُقال «مضبوط» أو «ناقص» — ولا تُطبَع قيمةٌ أبداً.
        // ══════════════════════════════════════════════════════════════
        // **ويُجرَّب على المسارات الثلاثة الحيّة** — الضبط، والعرضُ
        // مُفعَّلاً، والعرضُ ناقصاً. وأوّلُ صياغةٍ قلبتُ فيها فرعَ
        // «مضبوط» في `whyNot` **فلم يسقط الحارس**: ذاك الفرعُ لا يُنادى
        // أبداً (تُنادى `whyNot` حين `isEnabled()` كاذبة وحدَها)، فكان
        // القلبُ على شيفرةٍ ميّتة والحارسُ يمرّ بلا أن يحرس.
        $this->meta(['access_token=SUPERSECRET', 'phone_number_id=123456']);

        $this->assertStringNotContainsString('SUPERSECRET', Artisan::output(),
            'طُبع السرُّ في مسار الضبط');

        Artisan::call('amial:messaging');

        $this->assertStringNotContainsString('SUPERSECRET', Artisan::output(),
            'العرضُ يُظهر أسرارَ مزوّدٍ مُفعَّل');

        // وناقصاً كذلك — وهو المسارُ الذي يقرأ `live_values` ويشرح العلّة.
        Artisan::call('amial:messaging', ['--set' => 'meta_cloud', '--value' => ['phone_number_id=']]);
        Artisan::call('amial:messaging');

        $this->assertStringNotContainsString('SUPERSECRET', Artisan::output(),
            'العرضُ يُظهر أسرارَ مزوّدٍ ناقص — وهو المسارُ الذي يقرأ القيمَ المخزَّنة');
    }

    // ══════════════════════════════════════════════════════════════════
    // ③ والحكمُ يُقال، لا يُترَك للقارئ
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_listing_states_the_verdict_and_exits_non_zero(): void
    {
        // **وقائمةٌ بلا خلاصةٍ تُقرأ حسبَ مزاج قارئها**، ورقمُ خروجٍ
        // صفرٌ على قناةٍ مقفلةٍ يجعلها تمرّ في أيّ خطّ نشر.
        $this->assertNotSame(0, Artisan::call('amial:messaging'));

        $this->assertStringContainsString('مقفلٌ على كلّ رقمٍ حقيقيّ', Artisan::output(),
            'لا خلاصةَ تقول إنّ التسجيلَ مقفل');

        $this->meta(['access_token=T0K', 'phone_number_id=123456']);
        app(ProviderRegistry::class)->forget();

        $this->assertSame(0, Artisan::call('amial:messaging'));

        $this->assertStringContainsString('مفتوحٌ للأرقام الحقيقيّة', Artisan::output());
    }

    /** @test */
    public function no_stage_runs_before_the_banner(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **وثلاثُ مراحلَ كانت تجري قبل الترويسة** — وفيها «اضغط
        // Deploy»، أخطرُ خطوةٍ في المرشد كلِّه. كنّ فوق `banner` وفوق
        // `TOTAL_STAGES=…`، فتُطبَع «Stage 1/0» ويُسأل الإنسانُ أن ينشر
        // **قبل أن يُقال له ما هذا المرشدُ أصلاً**.
        //
        // ورأسُ الملفّ يقول صراحةً: «Everything above the STAGES marker
        // is the wizard library: do not hand-edit it». فالعطلُ مخالفةٌ
        // لعقدٍ مكتوبٍ في الملفّ نفسِه.
        // ══════════════════════════════════════════════════════════════
        $lines = explode("\n", (string) file_get_contents(
            base_path('scripts/wizard-production-blockers.sh')));

        $marker = $banner = null;
        $stages = [];

        foreach ($lines as $i => $l) {
            if ($marker === null && str_starts_with($l, '# STAGES')) { $marker = $i; }
            if ($banner === null && str_starts_with($l, 'banner "')) { $banner = $i; }
            if (str_starts_with($l, 'stage "')) { $stages[] = $i; }
        }

        $this->assertNotNull($marker, 'اختفت علامةُ STAGES');
        $this->assertNotNull($banner, 'اختفت الترويسة');
        $this->assertNotEmpty($stages, 'لا مرحلةَ في المرشد');

        $early = array_values(array_filter($stages, fn ($i) => $i < $banner));

        $this->assertSame([], $early, sprintf(
            '%d مرحلةً تجري قبل الترويسة — تُطبَع «Stage n/0» ويُطلَب النشرُ '
            . 'قبل أن يعرف القارئُ ما هذا المرشد', count($early)));
    }

    /** @test */
    public function the_declared_total_matches_the_real_count(): void
    {
        // **وعدَّادٌ يقول ١٠ على ١١ مرحلةً يجعل «Stage 11/10»** — ورقمٌ
        // يتجاوز مقامَه يُقرأ عطلاً في الأداة، فيُشَكّ فيما تقوله كلِّه.
        $s = (string) file_get_contents(base_path('scripts/wizard-production-blockers.sh'));

        // **ويُقرأ آخرُ إسنادٍ لا أوّلُه.** المكتبةُ تضع `TOTAL_STAGES=0`
        // مبدئيّاً ثمّ يضبطه قسمُ المراحل — وأوّلُ مطابقٍ يقرأ الصفرَ
        // فيسقط الحارسُ على ملفٍّ سليم. (سقط عليها أوّلَ تشغيل.)
        $this->assertNotSame(0, preg_match_all('~^TOTAL_STAGES=(\d+)~m', $s, $m),
            'لا عدَّادَ مراحلَ في المرشد');

        $declared = (int) end($m[1]);

        $this->assertSame(preg_match_all('~^stage "~m', $s), $declared,
            'العدَّادُ لا يساوي عددَ المراحل — فيُطبَع «Stage 11/10»');
    }

    /** @test */
    public function the_channel_stage_comes_before_the_deploy_stage(): void
    {
        // **والترتيبُ تبعيّةٌ لا ذوق.** قناةٌ تُضبَط بعد النشر تعني نشرةً
        // تعمل ولا يسجّل عليها أحد — فيُقاس «الطاقة» على بابٍ مقفل.
        $s = (string) file_get_contents(base_path('scripts/wizard-production-blockers.sh'));

        $chan = strpos($s, 'stage "قناةُ إيصال رمز التحقّق');
        $dep = strpos($s, 'stage "النشرُ نفسُه');

        $this->assertNotFalse($chan, 'اختفت مرحلةُ قناة الإيصال');
        $this->assertNotFalse($dep, 'اختفت مرحلةُ النشر');

        $this->assertLessThan($dep, $chan,
            'القناةُ تُضبَط بعد النشر — فتُنشَر منصّةٌ لا يسجّل عليها عميلٌ حقيقيّ');
    }

    /** @test */
    public function the_wizard_asks_for_it_first(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **وأمرٌ لا يذكره المرشدُ لا يُشغَّل.** وهذه المرحلةُ أوّلُ
        // الحواجز: بلا قناةٍ لا يسجّل أحدٌ، فلا معنى لقياس الطاقة ولا
        // لإثبات الإنذار. (القاعدةُ الثانية عشرة: كلُّ تغييرٍ يُقال
        // أين يظهر ويُوصل إليه من أين.)
        // ══════════════════════════════════════════════════════════════
        $w = (string) file_get_contents(base_path('scripts/wizard-production-blockers.sh'));

        $this->assertStringContainsString('amial:messaging', $w,
            'المرشدُ لا يذكر مفتاحَ تشغيل القناة — فالحاجزُ الأوّلُ بلا خطوة');
    }
}
