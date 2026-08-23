<?php

namespace Tests\Feature;

use App\Services\Messaging\Contracts\MessageProvider;
use App\Services\Messaging\Contracts\SupportsFreeText;
use App\Services\Messaging\ProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AMIAL-MESSAGING-001 — عقدُ المزوّدين يُنفَّذ، لا يُوصَف.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمن الذي يمنعه هذا الملفّ:**
 *
 * كان في `SmsModule` و`WhatsappModule` تسعةُ مزوّدين، ولكلٍّ فرعٌ في
 * `match` و`switch` وسطرٌ في ثابتٍ وحلقة. فإضافةُ مزوّدٍ يمنيٍّ واحدٍ —
 * وهي **أوّلُ خطوةٍ في رفع مانع الإطلاق `AMIAL_DEMO_OTP`** — كانت تعني
 * تعديل أربعة مواضع. ومن يعدّل ثلاثةً ينسى الرابع **بلا خطأ ترجمة**:
 * يُقال «المزوّد مُفعَّل» في الشاشة، ولا يُرسِل.
 *
 * فيُقاس المبدأ لا يُدَّعى: يُزرع مزوّدٌ حقيقيٌّ أثناء الاختبار، ويُتأكَّد
 * أنّه عمل **بلا تعديل ملفٍّ واحدٍ قائم**.
 */
class MessagingContractTest extends TestCase
{
    use RefreshDatabase;

    private const PLANTED = __DIR__ . '/../../app/Services/Messaging/Providers/Whatsapp/ZzPlantedTestProvider.php';

    /**
     * `addon_settings` جدولُ 6cash الأصليّ لا هجرةٌ في هذا المشروع —
     * فيُنشأ للاختبار كما يفعل `WhatsappOtpTest`.
     */
    protected function setUp(): void
    {
        parent::setUp();

                // ══════════════════════════════════════════════════════════
        // AMIAL-TEST-DDL-LEAK-001 — **عزلٌ بحذف الصفوف لا بحذف الجدول.**
        //
        // كان هنا `dropIfExists` ثمّ `Schema::create('addon_settings')`
        // بمخطَّطٍ محلّيّ، وتعليقُه: «جدولُ قاعدة 6cash (ليس هجرة)». وقد
        // **صار هجرةً** في `2026_07_26_000006` ولم يُنزَع البناءُ المحلّيّ.
        //
        // وثمنُه اثنان:
        //
        //   ① **المخطَّطُ المحلّيُّ يخالف الإنتاج**: `key_name` ١٩١ قابلٌ
        //      للفراغ و**بلا فهرسٍ فريد**. فما كان يُختبَر جدولٌ لا يمنع
        //      التكرار، والإنتاجُ يمنعه.
        //
        //   ② **و`tearDown` يحذف الجدول ولا يُعيده.** وDDL لا يتراجع مع
        //      معاملة `RefreshDatabase` — فيختفي **لبقيّة العمليّة**،
        //      وتسقط كلُّ اختباراتِ الإعدادات التي تليه. ولم يظهر
        //      متتابعاً إلّا بحظِّ الترتيب؛ وأوّلُ إعادةِ ترتيبٍ كشفته.
        //
        // **والعزلُ لا يُنزَع مع العلّة.** هذا الصنفُ يعتمد على جدولٍ
        // نظيفٍ في كلّ اختبار، وكان يناله بإعادة الإنشاء. فيُنال الآن
        // بحذف الصفوف: العزلُ نفسُه، والمخطَّطُ مخطَّطُ الإنتاج.
        // ══════════════════════════════════════════════════════════
        DB::table('addon_settings')->delete();
    }

    protected function tearDown(): void
    {
        @unlink(self::PLANTED);
        parent::tearDown();
    }

    private function registry(): ProviderRegistry
    {
        $r = app(ProviderRegistry::class);
        $r->forget();

        return $r;
    }

    private function enable(string $key, string $type, array $values): void
    {
        DB::table('addon_settings')->updateOrInsert(
            ['key_name' => $key, 'settings_type' => $type],
            ['id' => (string) \Illuminate\Support\Str::uuid(),
             'live_values' => json_encode($values + ['status' => 1]),
             'test_values' => json_encode([]), 'mode' => 'live', 'is_active' => 1]
        );
    }

    // ══════════════════════════════════════════════════════════════
    // Open/Closed — الصورة رقم ٣
    // ══════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **المزوّدون يُكتشفون بالمسح — لا بقائمةٍ تُحرَّر.**
     */
    public function the_registry_discovers_every_provider(): void
    {
        $keys = array_map(fn (MessageProvider $p) => $p->channel() . ':' . $p->key(),
            $this->registry()->all());

        foreach (['sms:twilio', 'sms:nexmo', 'sms:2factor', 'sms:msg91',
                  'whatsapp:meta_cloud', 'whatsapp:twilio', 'whatsapp:360dialog',
                  'whatsapp:wati', 'whatsapp:ultramsg',
                  // AMIAL-MESSAGING-002 — غيرُ مقيَّدٍ بنافذة ٢٤ ساعة،
                  // فهو المزوّدُ الذي تصل به إنذاراتُ ٠٢:٠٠.
                  'whatsapp:green_api'] as $expected) {
            $this->assertContains($expected, $keys, "مزوّدٌ لم يُكتشف: {$expected}");
        }

        $this->assertCount(10, $keys, 'عددُ المزوّدين تغيّر — راجع الاكتشاف');
    }

    /**
     * @test
     *
     * **وإضافةُ مزوّدٍ لا تحتاج تعديلَ سطرٍ واحدٍ قائم.**
     *
     * وهذا هو الاختبار الذي يُثبت Open/Closed. يُكتب صنفٌ جديدٌ في
     * المجلّد أثناء التشغيل — ولا يُمَسّ ثابتٌ ولا `match` ولا حلقة —
     * ثمّ يُتأكَّد أنّه اكتُشف **وأنّه أُرسل عبره فعلاً** لأنّ أولويّته
     * أسبق. فالاكتشافُ وحده لا يكفي: مزوّدٌ يُكتشف ولا يُستدعى عطلٌ صامت.
     */
    public function adding_a_provider_needs_no_edit_to_existing_code(): void
    {
        file_put_contents(self::PLANTED, <<<'PHP'
<?php
namespace App\Services\Messaging\Providers\Whatsapp;

use App\Services\Messaging\AbstractProvider;
use App\Services\Messaging\Contracts\SupportsFreeText;
use Illuminate\Support\Facades\Http;

/** مزوّدٌ مزروعٌ للاختبار — يُحذف في tearDown. */
class ZzPlantedTestProvider extends AbstractProvider implements SupportsFreeText
{
    public function key(): string { return 'planted'; }
    public function channel(): string { return 'whatsapp'; }
    public function priority(): int { return 1; }   // الأسبق عمداً
    protected function requiredKeys(): array { return ['token']; }

    public function sendOtp(string $to, string $otp): bool
    {
        return $this->attempt($to, fn () =>
            Http::post('https://planted.test/otp', ['to' => $to, 'otp' => $otp])->successful());
    }

    public function sendText(string $to, string $message): bool
    {
        return $this->attempt($to, fn () =>
            Http::post('https://planted.test/text', ['to' => $to])->successful());
    }
}
PHP);

        require_once self::PLANTED;

        $this->enable('planted', 'whatsapp_config', ['token' => 'T']);
        $this->enable('ultramsg', 'whatsapp_config', ['instance_id' => 'i', 'token' => 't']);

        Http::fake([
            'planted.test/*' => Http::response(['ok' => true], 200),
            'api.ultramsg.com/*' => Http::response(['sent' => 'true'], 200),
        ]);

        $result = $this->registry()->sendOtp('whatsapp', '+967770001111', '123456');

        $this->assertSame('success', $result);

        // **الحاسم:** أولويّتُه ١ فسبق `ultramsg` (٥٠) — ولم يُمَسّ ملفٌّ قائم.
        Http::assertSent(fn ($r) => str_contains($r->url(), 'planted.test'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'ultramsg'));
    }

    /**
     * @test
     *
     * **والأولويّة يُعلنها المزوّدُ عن نفسه — مرتَّبةً تصاعديّاً.**
     */
    public function providers_are_ordered_by_their_own_declared_priority(): void
    {
        $ps = $this->registry()->all();

        $priorities = array_map(fn (MessageProvider $p) => $p->priority(), $ps);
        $sorted = $priorities;
        sort($sorted);

        $this->assertSame($sorted, $priorities, 'المزوّدون غير مرتَّبين بأولويّاتهم');
    }

    // ══════════════════════════════════════════════════════════════
    // Interface Segregation — الصورة رقم ٥
    // ══════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **مزوّدو الرسائل القصيرة لا يُنفّذون النصّ الحرّ — ولا يُسألون عنه.**
     *
     * فواجهةٌ كبيرةٌ تُجبرهم على `sendText()` بجسدٍ فارغ، وذاك أخطرُ من
     * غيابها: يُستدعى فيُرجع نجاحاً كاذباً بلا إرسال.
     */
    public function sms_providers_do_not_carry_a_free_text_method_they_cannot_honour(): void
    {
        foreach ($this->registry()->all() as $p) {
            if ($p->channel() === 'sms') {
                $this->assertNotInstanceOf(SupportsFreeText::class, $p,
                    "مزوّدُ SMS يُعلن نصّاً حرّاً لا يُرسله: {$p->key()}");
            }
        }
    }

    /**
     * @test
     *
     * **ومن لا يُعلن `SupportsFreeText` يُتخطّى في إرسال النصّ.**
     */
    public function free_text_dispatch_skips_providers_that_do_not_support_it(): void
    {
        $this->enable('twilio', 'sms_config', ['sid' => 's', 'token' => 't']);

        $this->assertSame('not_found',
            $this->registry()->sendText('sms', '+967770001111', 'مرحباً'),
            'أُرسل نصٌّ حرٌّ عبر قناةٍ لا مزوّدَ فيها يدعمه');
    }

    // ══════════════════════════════════════════════════════════════
    // إصلاحُ عطلٍ سلوكيّ
    // ══════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **مزوّدٌ مُفعَّلٌ ومعطوبٌ لا يمنع من بعده.**
     *
     * كانت الحلقةُ القديمة تُرجع من **أوّل** مُفعَّلٍ ولو فشل:
     *
     *     if ($config['status'] === 1) { return self::meta_cloud(…); }
     *
     * فمزوّدٌ واحدٌ معطوبٌ يُقفل التسجيل على كلّ عميلٍ جديد، والسجلُّ
     * يقول «فشل meta_cloud» ولا يقول إنّ `ultramsg` كان جاهزاً ولم يُسأل.
     */
    public function a_broken_provider_does_not_block_the_one_after_it(): void
    {
        $this->enable('meta_cloud', 'whatsapp_config',
            ['access_token' => 'T', 'phone_number_id' => '1']);
        $this->enable('ultramsg', 'whatsapp_config',
            ['instance_id' => 'i', 'token' => 't']);

        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => 'down'], 500),
            'api.ultramsg.com/*' => Http::response(['sent' => 'true'], 200),
        ]);

        $this->assertSame('success',
            $this->registry()->sendOtp('whatsapp', '+967770001111', '123456'),
            'سقط المزوّد الأوّل فأُعلن العجز — ولم يُجرَّب الثاني');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'ultramsg'));
    }

    /**
     * @test
     *
     * **و«مُفعَّل» تعني «يستطيع الإرسال» لا «مؤشّرُه أخضر».**
     *
     * فمزوّدٌ `status=1` بلا `token` كان يُختار ثمّ يفشل — ويمنع غيره.
     */
    public function a_provider_missing_a_required_key_is_not_considered_enabled(): void
    {
        $this->enable('ultramsg', 'whatsapp_config', ['instance_id' => 'i']);   // بلا token

        $this->assertFalse($this->registry()->hasEnabled('whatsapp'),
            'مزوّدٌ ينقصه حقلٌ مطلوب عُدَّ مُفعَّلاً');
    }
}
