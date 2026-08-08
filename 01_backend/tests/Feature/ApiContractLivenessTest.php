<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * AMIAL-API-LIVE-001 — الـAPI بين التطبيق والخادم: موجودٌ **ويردّ**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ثلاث طبقاتٍ يخلط بينها الظنّ، ولكلٍّ عطلُها:**
 *
 *   ① التطبيقُ ينادي مساراً **لا وجود له**  → 404، شاشةٌ فارغةٌ بلا سبب
 *   ② المسارُ موجودٌ و**ينهار قبل المصادقة** → 500، «حدث خطأ» بلا تفصيل
 *   ③ المسارُ يردّ و**حارسُه لا يحرس**       → بياناتُ غيرِك في يدك
 *
 * ولا تمسك الاختباراتُ الموضعيّة إلّا ما كُتب لها. فهذا يمسح **كلّ**
 * مسارات الـAPI ويسأل الأسئلة الثلاثة على كلٍّ منها.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ الطلبُ بلا مصادقة هو الفحصُ الصحيح:**
 *
 * لأنّه يفصل الطبقات. مسارٌ محميٌّ يجب أن يردّ 401 **قبل أن يلمس شيئاً**؛
 * فإن ردّ 500 فقد انهار وهو لا يعرف من الطارق — وذاك عطلٌ في الشيفرة لا
 * في البيانات، ويصيب كلَّ مستعمل.
 *
 * **وهو آمن**: RefreshDatabase يعزل، والمحميُّ يُردّ قبل أيّ أثر.
 */
class ApiContractLivenessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * مساراتٌ تُستثنى من المسح، ولكلٍّ سببُه المكتوب.
     *
     * @var array<string,string>
     */
    private const EXCLUDED = [
        // ويبهوكاتُ مزوّدين خارجيّين: تتحقّق بتوقيعٍ لا برمز جلسة، ونداؤها
        // بلا توقيعٍ يقيس المزوّدَ لا شيفرتَنا.
        'api/v1/whatsapp/webhook' => 'ويبهوك واتساب — تحقّقُه بتوقيعِ Meta',
    ];

    /**
     * @test
     *
     * **ولا مسارَ API ينهار على طلبٍ بلا مصادقة.**
     *
     * ══════════════════════════════════════════════════════════════════
     * 500 هنا تعني انهياراً قبل معرفة الطارق: عمودٌ محذوف، أو خدمةٌ
     * تُحقَن ولا تُحلّ، أو `$request->user()` يُقرأ بلا فحص. **ويصيب
     * كلَّ مستعملٍ لا واحداً**، ولا يظهر إلّا كـ«حدث خطأ» في الهاتف.
     */
    public function no_api_route_crashes_on_an_unauthenticated_request(): void
    {
        $broken = [];
        $seen = 0;

        foreach ($this->apiRoutes() as [$method, $uri, $call]) {
            $seen++;

            try {
                $res = $this->withHeaders([
                    'Accept' => 'application/json',
                    'device-id' => 'API-SWEEP-DEVICE',
                ])->json($method, '/' . $call);

                $status = $res->getStatusCode();
            } catch (\Throwable $e) {
                $broken[] = sprintf('%s /%s → استثناء: %s',
                    $method, $uri, mb_substr($e->getMessage(), 0, 120));

                continue;
            }

            if ($status >= 500) {
                $body = mb_substr((string) $res->getContent(), 0, 160);
                $broken[] = sprintf('%s /%s → %d · %s', $method, $uri, $status, $body);
            }
        }

        $this->assertGreaterThan(300, $seen,
            "مُسح {$seen} مساراً فقط — الجدولُ لم يُبنَ كما ينبغي، والفحصُ يمرّ فارغاً");

        $this->assertSame([], $broken, sprintf(
            "انهار %d مساراً من %d على طلبٍ بلا مصادقة:\n  %s",
            count($broken), $seen, implode("\n  ", array_slice($broken, 0, 15)),
        ));
    }

    /**
     * @test
     *
     * **وكلُّ مسارٍ محميٍّ يردّ 401 لا 200.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **وهذا أخطرُ الثلاثة**: مسارٌ يُفترض أنّه محميّ ويردّ 200 لمن لا
     * رمزَ له يُسرّب بياناتِ عملاءَ ومبالغَ — ولا يُنتج خطأً في أيّ
     * سجلّ. يعمل، ويعمل للجميع.
     *
     * ويُقاس عن **الوسائط المعلَنة** لا عن ظنّي: ما حمل `Authenticate:api`
     * يجب أن يردّ 401/403، وما لم يحملها فهو عامٌّ عن قصد.
     */
    public function every_guarded_route_refuses_an_unauthenticated_caller(): void
    {
        $leaking = [];
        $guarded = 0;

        foreach ($this->apiRoutes(guardedOnly: true) as [$method, $uri, $call]) {
            $guarded++;

            try {
                $res = $this->withHeaders([
                    'Accept' => 'application/json',
                    'device-id' => 'API-SWEEP-DEVICE',
                ])->json($method, '/' . $call);
            } catch (\Throwable) {
                continue;   // الانهيارُ شأنُ الاختبار الأوّل
            }

            // 401 مصادقة · 403 صلاحيّة/جهاز · 400 ترويسةٌ ناقصة ·
            // 419 جلسة · 429 حدّ — كلُّها رفضٌ قبل البيانات.
            if (in_array($res->getStatusCode(), [200, 201], true)) {
                $leaking[] = sprintf('%s /%s → %d', $method, $uri, $res->getStatusCode());
            }
        }

        $this->assertGreaterThan(300, $guarded,
            "لم يُقرأ إلّا {$guarded} مساراً محميّاً — قراءةُ الوسائط فشلت");

        $this->assertSame([], $leaking, sprintf(
            "%d مساراً محميّاً ردَّ 200 لمن لا رمزَ له:\n  %s",
            count($leaking), implode("\n  ", array_slice($leaking, 0, 15)),
        ));
    }

    /**
     * مساراتُ الـAPI جاهزةً للنداء: المتغيّراتُ مملوءةٌ بقيمةٍ صالحة.
     *
     * @return \Generator<array{0:string,1:string,2:string}>
     */
    private function apiRoutes(bool $guardedOnly = false): \Generator
    {
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/')) {
                continue;
            }

            if (isset(self::EXCLUDED[$uri])) {
                continue;
            }

            $mw = $route->gatherMiddleware();

            // **يُفحص الاسمُ المحلول والاسمُ المختصر معاً.** فـ
            // `gatherMiddleware()` قد يردّ الاسمَ المستعار لا الصنف —
            // وهو ما جعل حارساً سابقاً يعلن خمسةَ مساراتٍ محميّةٍ مكشوفة.
            $isGuarded = (bool) array_filter(
                $mw,
                static fn ($m) => is_string($m)
                    && (str_contains($m, 'Authenticate') || str_starts_with($m, 'auth:')),
            );

            if ($guardedOnly && ! $isGuarded) {
                continue;
            }

            $method = in_array('GET', $route->methods(), true)
                ? 'GET'
                : $route->methods()[0];

            if ($method === 'HEAD' || $method === 'OPTIONS') {
                continue;
            }

            // `{id}` و`{id?}` ← 1 — قيمةٌ صالحةُ الشكل. والمقصودُ بلوغُ
            // الحارس لا العثورُ على السجلّ.
            $call = preg_replace('/\{[^}]+\}/', '1', $uri);

            yield [$method, $uri, $call];
        }
    }
}
