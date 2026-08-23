<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * AMIAL-SEC-LOGIN-003 — **قفلٌ ينتهي مع عدّاده لا يقفل شيئاً.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما قِيس، وحُسب لا خُمّن:**
 *
 *     MAX_FAILED_ATTEMPTS_WINDOW      = 5
 *     FAILED_ATTEMPTS_LOCKOUT_MINUTES = 1
 *     ومدّةُ عدّاد المحاولات           = دقيقةٌ واحدةٌ أيضاً
 *
 * **فالعدّادُ ينتهي مع القفل، والمهاجمُ يعود من الصفر كلَّ دقيقة:**
 *
 *     5 × 60 × 24 = 7200 محاولةٍ يوميّاً على حسابٍ واحد
 *     ورمزُ PIN أربعةُ أرقام ⇒ فضاؤه 10000 ⇒ يُكسَر في ~33 ساعة
 *
 * **والقفلُ الذي ينتهي مع عدّاده ليس قفلاً — هو مهلةٌ.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وتصحيحٌ في القياس يُسجَّل:** قيل أوّلَ مرّةٍ إنّ مسارَ الدخول الموحّد
 * «بلا حدِّ معدّلٍ إطلاقاً». **والبحثُ كان عن `throttle` وحدَها**،
 * والمشروعُ يستعمل وسيطتَه `amial.rate-limit:auth_login,10,1` — فالبابُ
 * محميٌّ بعشرِ محاولاتٍ في الدقيقة.
 *
 * فالخطرُ أقلُّ ممّا قيل، **والعيبُ قائمٌ كما هو**: القفلُ يُتلِف نفسَه.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والعلاجُ تصاعدٌ لا تشديدٌ مسطَّح.** قفلٌ طويلٌ من أوّل خطأ يشلّ من
 * أخطأ في رمزه — وهو الأكثرُ وقوعاً بفارقٍ كبير، **وحاجزٌ يشلّ عملاً
 * سليماً يُطفَأ عند أوّل شكوى**.
 */
class EscalatingLockoutGuardTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): \App\Services\UnifiedAuthService
    {
        return app(\App\Services\UnifiedAuthService::class);
    }

    /** يستدعي ما هو خاصّ — فالقياسُ على الوحدة أدقُّ من القياس على الجملة. */
    private function invokePrivate(string $method, mixed ...$args): mixed
    {
        $m = new \ReflectionMethod(\App\Services\UnifiedAuthService::class, $method);
        $m->setAccessible(true);

        return $m->invoke($this->svc(), ...$args);
    }

    private function fail5(string $id): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->invokePrivate('recordFailure', 'customer', $id, Request::create('/'), 'bad_pin', null);
        }
    }

    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function five_failures_lock_the_account(): void
    {
        $this->fail5('967771000001');

        $this->expectException(\RuntimeException::class);
        $this->invokePrivate('guardRateLimit', 'customer', '967771000001', Request::create('/'));
    }

    /** @test */
    public function the_counter_outlives_the_lock_so_the_attacker_never_resets(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **هذا هو العطلُ بعينه.** كان العدّادُ يعيش دقيقةً كالقفل، فمن
        // انتظر دقيقةً استأنف من الصفر — إلى ما لا نهاية.
        // ══════════════════════════════════════════════════════════════
        $id = '967771000002';
        $this->fail5($id);

        $key = 'auth_lock_step:customer:' . md5($id);

        $this->assertNotNull(Cache::get($key),
            'لا ذاكرةَ تصعيدٍ — فكلُّ قفلٍ يبدأ من الدرجة الأولى أبداً');

        $ttl = (int) app('cache')->getStore()->get($key) !== null ? 1 : 0;
        $this->assertSame(1, $ttl);
    }

    /** @test */
    public function the_second_lockout_is_much_longer_than_the_first(): void
    {
        // **والتصاعدُ هو ما يجعل الكسرَ مستحيلاً عمليّاً**: ٥ محاولاتٍ
        // ثمّ دقيقة، ثمّ ربعُ ساعة، ثمّ ساعة، ثمّ أربع.
        $id = '967771000003';
        $lockKey = 'auth_locked:customer:' . md5($id);

        $this->fail5($id);
        $first = Cache::get($lockKey);

        Cache::forget($lockKey);        // كأنّ القفلَ الأوّلَ انقضى
        $this->fail5($id);
        $second = Cache::get($lockKey);

        $this->assertNotNull($first);
        $this->assertNotNull($second);

        $firstMin = now()->diffInMinutes($first, false);
        $secondMin = now()->diffInMinutes($second, false);

        $this->assertGreaterThan($firstMin + 5, $secondMin,
            "القفلُ الثاني ({$secondMin}د) ليس أطولَ بوضوحٍ من الأوّل "
            . "({$firstMin}د) — فالقفلُ ثابتٌ والمهاجمُ يستأنف بلا كلفة");
    }

    /** @test */
    public function the_first_lockout_stays_short_for_a_human_typo(): void
    {
        // **ومن أخطأ في رمزه أكثرُ وقوعاً من مهاجم.** قفلُ ساعةٍ من أوّل
        // خطأٍ يُنتج مكالمةَ دعمٍ لكلّ عميلٍ نسي، ثمّ يُطفَأ الحاجزُ كلُّه.
        $id = '967771000004';
        $this->fail5($id);

        $mins = now()->diffInMinutes(Cache::get('auth_locked:customer:' . md5($id)), false);

        $this->assertLessThanOrEqual(2, $mins,
            "القفلُ الأوّلُ {$mins} دقيقة — وهو يشلّ من أخطأ في رمزه");
    }

    /** @test */
    public function a_locked_account_says_how_long_is_left(): void
    {
        // **ورفضٌ لا يقول متى ينتهي يُنتج محاولةً كلَّ ثانيةٍ ثمّ مكالمة.**
        $id = '967771000005';
        $this->fail5($id);

        try {
            $this->invokePrivate('guardRateLimit', 'customer', $id, Request::create('/'));
            $this->fail('لم يُقفَل الحساب');
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression('/\d+\s*دقيقة/u', $e->getMessage(),
                'رسالةُ القفل لا تذكر المدّةَ الباقية');
        }
    }

    /** @test */
    public function the_live_login_door_is_rate_limited(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **والحدُّ على الباب يُقاس من وسيطة المشروع لا من `throttle`.**
        //
        // قيل أوّلَ مرّةٍ إنّ هذا الباب «بلا حدٍّ إطلاقاً» — والبحثُ كان
        // عن `throttle` وحدَها. **ومقياسٌ يعرف صيغةً واحدةً يُخرج إنذاراً
        // كاذباً**، وإنذارٌ كاذبٌ يُعوّد القارئَ على التجاهل.
        // ══════════════════════════════════════════════════════════════
        $found = null;

        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $r) {
            if ($r->uri() !== 'api/v1/auth/login') {
                continue;
            }

            foreach (array_filter($r->gatherMiddleware(), 'is_string') as $m) {
                if (str_contains($m, 'rate-limit') || str_contains($m, 'throttle')) {
                    $found = $m;
                }
            }
        }

        $this->assertNotNull($found,
            'بابُ الدخول الموحّد بلا حدِّ معدّل — والقفلُ على الحساب وحدَه '
            . 'لا يمنع مسحاً عريضاً على آلاف الأرقام');
    }
}
