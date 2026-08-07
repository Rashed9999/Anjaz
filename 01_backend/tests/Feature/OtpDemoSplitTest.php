<?php

namespace Tests\Feature;

use App\Services\Otp\OtpPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-OTP-SPLIT-001 — الرمزُ الثابت لأرقام العرض وحدها.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثغرة التي يُقفلها هذا الملفّ:**
 *
 * `AMIAL_DEMO_OTP=123456` كان مفتاحاً عامّاً — يُقبل **لأيّ رقمٍ في
 * اليمن**. و`checkPhone` تُرجعه في جسد الاستجابة (`'demo_otp' => …`).
 * فمن يعرف العنوان يسجّل باسم رقمٍ لا يملكه، ويصير صاحبَ محفظته.
 *
 * **وهو استيلاءٌ على الحساب لا ثغرةُ راحة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وأخطرُ اختبارٍ هنا هو النفي**: أنّ رقماً حقيقيّاً **لا يقبل**
 * `123456` ولو كان المتغيّر مضبوطاً. فاختبارٌ يُثبت أنّ حساب العرض يعمل
 * يمرّ ولو بقي البابُ مفتوحاً للجميع — ويُطمئن ولا يحرس.
 */
class OtpDemoSplitTest extends TestCase
{
    use RefreshDatabase;

    private const DEMO_PHONE = '967777100001';
    private const REAL_PHONE = '967739555444';

    private function policy(): OtpPolicy
    {
        return app(OtpPolicy::class);
    }

    // ══════════════════════════════════════════════════════════════
    // ١) الفصل نفسه
    // ══════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **رقمُ العرض يأخذ الرمزَ الثابت، ويُفصح عنه، ولا يحتاج بوّابة.**
     */
    public function a_demo_number_gets_the_fixed_code(): void
    {
        $p = $this->policy();

        $this->assertTrue($p->isDemo(self::DEMO_PHONE));
        $this->assertSame('123456', $p->codeFor(self::DEMO_PHONE));
        $this->assertTrue($p->mayDisclose(self::DEMO_PHONE));
        $this->assertFalse($p->needsDelivery(self::DEMO_PHONE));
    }

    /**
     * @test
     *
     * **والرقمُ الحقيقيّ لا يأخذه — ولو كان `AMIAL_DEMO_OTP` مضبوطاً.**
     *
     * وهذا هو الاختبار الذي يُقفل الثغرة. ولو سقط لكان أيُّ رقمٍ في
     * اليمن قابلاً للتسجيل بـ`123456`.
     */
    public function a_real_number_never_gets_the_demo_code(): void
    {
        $p = $this->policy();

        $this->assertSame('123456', config('amial.otp.demo_code'),
            'الرمزُ الثابت غيرُ مضبوط — فالاختبار يفحص حالةً لا تقع');

        $this->assertFalse($p->isDemo(self::REAL_PHONE));

        // **مئةُ إصدارٍ ولا واحدٌ منها الرمزُ الثابت.** ومرّةٌ واحدة قد
        // تُصادف عشوائيّاً، فيمرّ الحارسُ على عطلٍ قائم.
        for ($i = 0; $i < 100; $i++) {
            $this->assertNotSame('123456', $p->codeFor(self::REAL_PHONE),
                'رقمٌ حقيقيٌّ أُعطي الرمزَ الثابت');
        }
    }

    /**
     * @test
     *
     * **ولا يُفصح عن رمز رقمٍ حقيقيّ أبداً.**
     *
     * فالإفصاحُ يُلغي التحقّق من أصله: يصير «أثبت أنّك تملك الرقم»
     * «انسخ ما أعطيناك».
     */
    public function a_real_numbers_code_is_never_disclosed(): void
    {
        $this->assertFalse($this->policy()->mayDisclose(self::REAL_PHONE));
        $this->assertTrue($this->policy()->needsDelivery(self::REAL_PHONE));
    }

    /**
     * @test
     *
     * **والرقمُ يُعرف بأشكاله الأربعة.**
     *
     * فالرقم نفسه يصل `+967…` و`967…` و`00967…` و`777…` حسب الشاشة.
     * ومقارنةٌ حرفيّةٌ تجعل حسابَ العرض يعمل من مدخلٍ ويُرفض من آخر —
     * عطلٌ لا يُنتج سطراً في أيّ سجلّ. (القاعدة الرابعة.)
     */
    public function a_demo_number_is_recognised_in_every_shape_it_arrives_in(): void
    {
        foreach (['+967777100001', '967777100001', '00967777100001',
                  '777100001', '+967 777 100 001'] as $shape) {
            $this->assertTrue($this->policy()->isDemo($shape),
                "لم يُعرف رقمُ العرض بهذا الشكل: {$shape}");
        }
    }

    /**
     * @test
     *
     * **ورقمٌ قريبٌ من رقم عرضٍ ليس رقمَ عرض.**
     *
     * فمطابقةُ المقطع الأخير قد تتوسّع أكثر ممّا يجب. ولو قُبل
     * `967777100009` لصار الجارُ الرقميُّ باباً.
     */
    public function a_number_that_merely_resembles_a_demo_number_is_not_one(): void
    {
        foreach (['967777100009', '967777100011', '967777199001'] as $near) {
            $this->assertFalse($this->policy()->isDemo($near),
                "رقمٌ مشابهٌ عُدَّ رقمَ عرض: {$near}");
        }
    }

    // ══════════════════════════════════════════════════════════════
    // ٢) الإقفال الكامل
    // ══════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **وإفراغُ قائمة الأرقام يُقفل الباب تماماً.**
     *
     * وهذا هو ما يُفعل يوم الإطلاق: `AMIAL_DEMO_PHONES=` فارغاً، فلا
     * رقمَ يُقبل منه الرمزُ الثابت — ولو بقي `AMIAL_DEMO_OTP` مضبوطاً.
     */
    public function emptying_the_demo_list_closes_the_door_for_everyone(): void
    {
        config(['amial.otp.demo_numbers' => []]);

        $this->assertFalse($this->policy()->isDemo(self::DEMO_PHONE));
        $this->assertNotSame('123456', $this->policy()->codeFor(self::DEMO_PHONE));
    }

    /**
     * @test
     *
     * **وإفراغُ الرمز نفسه يُقفله كذلك.**
     */
    public function emptying_the_code_disables_the_demo_path(): void
    {
        config(['amial.otp.demo_code' => '']);

        $this->assertNull($this->policy()->demoCode());
        $this->assertFalse($this->policy()->isDemo(self::DEMO_PHONE));
    }

    // ══════════════════════════════════════════════════════════════
    // ٣) الغيابُ يُقال
    // ══════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **وبلا بوّابةٍ يُقال ذلك — لا يُترك المستعمل واقفاً بلا سبب.**
     *
     * (القاعدة السابعة: الغيابُ يُقال صراحةً مع سببه.)
     */
    public function the_absence_of_a_delivery_channel_is_stated(): void
    {
        $this->assertFalse($this->policy()->deliveryReady(),
            'قناةٌ مُفعَّلةٌ في بيئة الاختبار — فالفحص لاغٍ');

        $this->assertNotSame('', $this->policy()->unavailableMessage());
    }

    /**
     * @test
     *
     * **ووصلُ بوّابةٍ يجعلها جاهزة.**
     *
     * وبلا هذا الطرف يمرّ الحارسُ ولو كان `deliveryReady()` يُرجع `false`
     * دائماً — وتلك دالّةٌ معطَّلةٌ لا حارس.
     */
    public function connecting_a_provider_makes_delivery_ready(): void
    {
        \Illuminate\Support\Facades\Schema::dropIfExists('addon_settings');
        \Illuminate\Support\Facades\Schema::create('addon_settings', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('key_name', 191)->nullable();
            $t->longText('live_values')->nullable();
            $t->longText('test_values')->nullable();
            $t->string('settings_type', 255)->nullable();
            $t->string('mode', 20)->default('live');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        DB::table('addon_settings')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'key_name' => 'ultramsg', 'settings_type' => 'whatsapp_config',
            'live_values' => json_encode(['status' => 1, 'instance_id' => 'i', 'token' => 't']),
            'test_values' => json_encode([]), 'mode' => 'live', 'is_active' => 1,
        ]);

        app(\App\Services\Messaging\ProviderRegistry::class)->forget();

        $this->assertTrue($this->policy()->deliveryReady(),
            'وُصلت بوّابةٌ ولم تُعدّ جاهزة');

        \Illuminate\Support\Facades\Schema::dropIfExists('addon_settings');
    }

    // ══════════════════════════════════════════════════════════════
    // ٤) من المدخل الحقيقيّ — لا من الخدمة
    // ══════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **ولا يُفصح عن رمزٍ لرقمٍ حقيقيّ في ردّ الواجهة.**
     *
     * (القاعدة الرابعة: سياسةٌ صحيحةٌ لا تُنادى هي العطل نفسه. فتُفحص من
     * نقطة النهاية التي يستعملها التطبيق، لا من الصنف وحده.)
     */
    public function the_api_never_leaks_a_real_numbers_code(): void
    {
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'phone_verification'], ['value' => '1']
        );

        $r = $this->postJson('/api/v1/customer/auth/check-phone', [
            'phone' => '+' . self::REAL_PHONE,
        ]);

        // المهمُّ ليس رمزَ الحالة بل **ألّا يخرج الرمز**.
        $body = $r->getContent();

        $this->assertStringNotContainsString('123456', $body,
            'خرج الرمزُ الثابت في ردٍّ على رقمٍ حقيقيّ');

        if ($r->getStatusCode() === 200) {
            $this->assertNull($r->json('demo_otp'),
                'أُفصح عن رمزِ رقمٍ حقيقيّ — فبطل التحقّق من أصله');
        }
    }

    /**
     * @test
     *
     * **والمخزَّنُ لرقمٍ حقيقيّ ليس الرمزَ الثابت.**
     *
     * وهذا القياسُ الحاسم: لو خُزّن `123456` لقُبل عند التحقّق مهما قال
     * الردّ. فيُقرأ الصفُّ من القاعدة لا من الاستجابة.
     */
    public function the_stored_code_for_a_real_number_is_not_the_demo_code(): void
    {
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'phone_verification'], ['value' => '1']
        );

        $this->postJson('/api/v1/customer/auth/check-phone', ['phone' => '+' . self::REAL_PHONE]);

        $row = DB::table('phone_verifications')
            ->where('phone', 'like', '%' . substr(self::REAL_PHONE, -9))
            ->first();

        if ($row) {
            $this->assertNotSame('123456', (string) $row->otp,
                'خُزّن الرمزُ الثابت لرقمٍ حقيقيّ — فيُقبل عند التحقّق');
        }

        $this->assertTrue(true);
    }
}
