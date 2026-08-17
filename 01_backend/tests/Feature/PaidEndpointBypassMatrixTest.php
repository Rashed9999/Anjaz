<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\User;
use App\Support\Access\AccessConstants as A;
use App\Support\Access\CapabilityRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * AMIAL-ENTITLEMENTS-008 — **الطلبُ المباشر، لا ما تُظهره الشاشة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **القاعدةُ الإلزاميّة من صاحب المشروع، حرفاً:**
 *
 *   «لا تعتبر Feature محمية لأن شاشة Flutter تخفيها.
 *    أثبت المنع من Backend بطلب مباشر.»
 *
 * فهذا الملفُّ **لا يقرأ شيفرةً ولا يسأل خدمة**. يُصادِق تاجراً حقيقيّاً
 * على باقةٍ حقيقيّة، ويرسل الطلبَ إلى المسار كما يرسله من فتح الطرفيّة —
 * ويقرأ **رمزَ الاستجابة**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والمساراتُ تُقرأ من جدول المسارات المبنيّ** لا من قائمةٍ مكتوبةٍ
 * بيدي: قائمةٌ مكتوبةٌ تشيخ مع أوّل مسارٍ يُضاف، **فيمرّ الحارسُ وهو
 * لا يعرف بوجوده**.
 */
class PaidEndpointBypassMatrixTest extends TestCase
{
    use RefreshDatabase;

    /**
     * كلُّ مسارٍ يحمل `capability:<code>` مع القدرة التي يحرسها.
     *
     * @return array<int,array{uri:string,method:string,code:string}>
     */
    private function gatedRoutes(): array
    {
        $out = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $m) {
                if (! is_string($m) || ! str_starts_with($m, 'capability:')) {
                    continue;
                }

                $code = substr($m, strlen('capability:'));

                // **مسارٌ بمعامِلٍ لا يُطرق** — `{id}` يحتاج صفّاً حقيقيّاً،
                // وطرقُه بمعرّفٍ مخترَعٍ يُنتج ٤٠٤ قبل الحارس فيُقرأ نجاحاً
                // كاذباً. فتُقتصر المصفوفةُ على ما يُطرق بلا بذرٍ إضافيّ.
                if (str_contains($route->uri(), '{')) {
                    continue;
                }

                $methods = array_values(array_diff($route->methods(), ['HEAD', 'OPTIONS']));

                $out[] = [
                    'uri' => '/'.ltrim($route->uri(), '/'),
                    'method' => $methods[0] ?? 'GET',
                    'code' => $code,
                ];
            }
        }

        return $out;
    }

    private function rank(string $plan): int
    {
        return (int) (array_flip(A::ALL_PLANS)[$plan] ?? 0);
    }

    /** أدنى باقةٍ تفتح القدرة — و`null` إن كانت مجّانيّة. */
    private function minPlanOf(string $code): ?string
    {
        foreach (CapabilityRegistry::all() as $cap) {
            $a = $cap->toArray();

            if ($a['code'] === $code) {
                $min = $a['min_plan'] ?? null;

                return ($min !== null && $this->rank($min) > 0) ? $min : null;
            }
        }

        return null;
    }

    /** أنواعُ النشاط التي تنطبق عليها القدرة — أو الكلُّ. */
    private function businessTypesOf(string $code): array
    {
        foreach (CapabilityRegistry::all() as $cap) {
            $a = $cap->toArray();

            if ($a['code'] === $code) {
                $types = $a['business_types'] ?? null;

                return empty($types) ? A::ALL_BUSINESS_TYPES : $types;
            }
        }

        return A::ALL_BUSINESS_TYPES;
    }

    private function merchant(string $biz, string $plan, ?int $expiredDays = null): User
    {
        $u = User::factory()->create([
            'type' => 3, 'role' => A::ROLE_MERCHANT, 'zone_code' => 'SOUTH',
        ]);

        MerchantProfile::create([
            'user_id' => $u->id,
            'verification_status' => 'verified',
            'business_type' => $biz,
            'subscription_plan' => $plan,
            'subscription_expires_at' => $expiredDays === null ? null : now()->subDays($expiredDays),
        ]);

        return $u->refresh();
    }

    private function hit(User $actor, array $route)
    {
        $method = strtolower($route['method']);

        return $this->actingAs($actor, 'api')->json($method, $route['uri'], []);
    }

    /**
     * @test
     *
     * **① طلبٌ مباشرٌ من باقةٍ أدنى يجب أن يُردّ بـ٤٠٢.**
     *
     * ولا يكفي «الشاشةُ تُخفي الزرّ»: من فتح الطرفيّة يرسل الطلبَ نفسَه.
     *
     * **وما ليس ٤٠٢ ولا ٤٠٣ يُعدّ ثغرة** — و٢٠٠ أوضحُها. أمّا ٤٢٢ (‏نقصُ
     * حقلٍ) فهي **ثغرةٌ أيضاً**: معناها أنّ الطلبَ تجاوز الحارسَ ووصل
     * إلى التحقّق من المدخلات، فمن أرسل الحقولَ الصحيحةَ يمرّ.
     */
    public function a_lower_plan_is_refused_by_the_server_on_a_direct_request(): void
    {
        $bypasses = [];
        $checked = 0;

        foreach ($this->gatedRoutes() as $route) {
            $min = $this->minPlanOf($route['code']);

            if ($min === null) {
                continue;   // قدرةٌ مجّانيّةٌ — لا يُتوقّع منعٌ بالباقة
            }

            // نوعُ نشاطٍ **تنطبق عليه** القدرة — وإلّا كان المنعُ لسببٍ آخر
            // فيُقرأ «الباقةُ تحرس» وهي لا تحرس.
            $biz = $this->businessTypesOf($route['code'])[0];

            foreach (A::ALL_PLANS as $plan) {
                if ($this->rank($plan) >= $this->rank($min)) {
                    continue;
                }

                $status = $this->hit($this->merchant($biz, $plan), $route)->getStatusCode();
                $checked++;

                if (! in_array($status, [402, 403], true)) {
                    $bypasses[] = sprintf('%s %s — %s على %s ردَّ %d (يحتاج %s)',
                        $route['method'], $route['uri'], $route['code'], $plan, $status, $min);
                }
            }
        }

        // **الصفرُ يُقرأ «فُحص فلم يُوجد» لا «لم يُفحص»** (القاعدة السابعة).
        $this->assertGreaterThan(20, $checked,
            'المصفوفةُ فحصت أقلَّ من عشرين تركيبة — **فالصمتُ ليس نجاحاً**');

        $this->assertSame([], $bypasses, sprintf(
            "قدرةٌ مدفوعةٌ عملت على باقةٍ دونها **بطلبٍ مباشرٍ إلى الخادم** "
            . "(‏فُحصت %d تركيبة):\n  %s",
            $checked, implode("\n  ", $bypasses)));
    }

    /**
     * @test
     *
     * **② والباقةُ الكافيةُ لا تُمنَع.**
     *
     * وهذا الضابطُ الذي بدونه يكون الأوّلُ بلا معنى: بوّابةٌ تردّ ٤٠٢
     * على الجميع تجتاز الاختبارَ الأوّلَ كاملاً **وتُعطّل المنتج**.
     */
    public function the_entitled_plan_is_not_refused(): void
    {
        $wrong = [];
        $checked = 0;

        foreach ($this->gatedRoutes() as $route) {
            $min = $this->minPlanOf($route['code']);

            if ($min === null) {
                continue;
            }

            $biz = $this->businessTypesOf($route['code'])[0];
            $status = $this->hit($this->merchant($biz, A::PLAN_ENTERPRISE), $route)->getStatusCode();
            $checked++;

            if (in_array($status, [402], true)) {
                $wrong[] = sprintf('%s %s — %s مُنع على «enterprise» وأدناها %s',
                    $route['method'], $route['uri'], $route['code'], $min);
            }
        }

        $this->assertGreaterThan(5, $checked, 'لم يُفحص شيءٌ يُذكر');

        $this->assertSame([], $wrong, sprintf(
            "بوّابةٌ منعت باقةً تستحقّ — **فالحارسُ يُعطّل المنتجَ لا يحميه**:\n  %s",
            implode("\n  ", $wrong)));
    }

    /**
     * @test
     *
     * **③ والاشتراكُ المنتهي يُمنع بطلبٍ مباشر.**
     *
     * فالانتهاءُ محسوبٌ في الخدمة — **والسؤالُ هنا هل يصل إلى المسار**.
     * وخدمةٌ تحسب صحيحاً وبوّابةٌ لا تسألها تُنتج تاجراً يعمل بباقةٍ
     * انتهت منذ شهر.
     */
    public function an_expired_subscription_is_refused_on_a_direct_request(): void
    {
        $bypasses = [];
        $checked = 0;

        foreach ($this->gatedRoutes() as $route) {
            $min = $this->minPlanOf($route['code']);

            if ($min === null || $this->rank($min) < 2) {
                continue;   // نقتصر على المدفوع بوضوحٍ فوق «البداية»
            }

            $biz = $this->businessTypesOf($route['code'])[0];

            // اشتراكُ «enterprise» **انتهى قبل ثلاثة أيّام**.
            $expired = $this->merchant($biz, A::PLAN_ENTERPRISE, expiredDays: 3);
            $status = $this->hit($expired, $route)->getStatusCode();
            $checked++;

            if (! in_array($status, [402, 403], true)) {
                $bypasses[] = sprintf('%s %s — %s عمل باشتراكٍ منتهٍ (ردّ %d)',
                    $route['method'], $route['uri'], $route['code'], $status);
            }
        }

        $this->assertGreaterThan(3, $checked, 'لم تُفحص قدرةٌ واحدةٌ فوق «البداية»');

        $this->assertSame([], $bypasses, sprintf(
            "اشتراكٌ منتهٍ ما زال يفتح قدرةً مدفوعة (‏فُحصت %d):\n  %s",
            $checked, implode("\n  ", $bypasses)));
    }

    /**
     * @test
     *
     * **④ وتاجرٌ من نوعِ نشاطٍ آخر يُمنع.**
     *
     * قدرةٌ مقصورةٌ على الوقود يجب ألّا تعمل لصيدليّة **ولو كانت باقتُها
     * أعلى**: الباقةُ تشتري ما ينطبق على نشاطها لا كلَّ شيء.
     */
    public function a_wrong_business_type_is_refused_even_on_the_top_plan(): void
    {
        $leaks = [];
        $checked = 0;

        foreach ($this->gatedRoutes() as $route) {
            $types = $this->businessTypesOf($route['code']);

            if (count($types) >= count(A::ALL_BUSINESS_TYPES)) {
                continue;   // قدرةٌ عامّةٌ — لا نوعَ خاطئٌ لها
            }

            $other = null;

            foreach (A::ALL_BUSINESS_TYPES as $b) {
                if (! in_array($b, $types, true)) {
                    $other = $b;
                    break;
                }
            }

            if ($other === null) {
                continue;
            }

            $status = $this->hit($this->merchant($other, A::PLAN_ENTERPRISE), $route)->getStatusCode();
            $checked++;

            if (! in_array($status, [402, 403, 404], true)) {
                $leaks[] = sprintf('%s %s — %s (لـ%s) عملت لـ%s وردّت %d',
                    $route['method'], $route['uri'], $route['code'],
                    implode('/', $types), $other, $status);
            }
        }

        $this->assertGreaterThanOrEqual(2, $checked,
            'لا قدرةَ مقصورةً على نشاطٍ فُحصت بلا معامِلٍ في مسارها — '
            . '**فالنتيجةُ صمتٌ لا جواب**');

        $this->assertSame([], $leaks, sprintf(
            "قدرةٌ مقصورةٌ على نشاطٍ عملت لنشاطٍ آخر (‏فُحصت %d):\n  %s",
            $checked, implode("\n  ", $leaks)));
    }

    /**
     * @test
     *
     * **⑤ وتاجرٌ آخرُ لا يرى بيانات غيره.**
     *
     * نطاقُ البيانات يُشتقّ من الهويّة لا من الطلب (القاعدة الثامنة).
     * ويُقاس هنا على مسارٍ ماليٍّ حسّاس: أجهزةُ نقاط البيع.
     */
    public function one_merchant_never_sees_another_merchants_devices(): void
    {
        $a = $this->merchant(A::BIZ_RETAIL, A::PLAN_BUSINESS);
        $b = $this->merchant(A::BIZ_RETAIL, A::PLAN_BUSINESS);

        app(\App\Services\Merchant\PosDeviceRegistrar::class)
            ->register($a, 'device-of-merchant-a');

        $response = $this->actingAs($b, 'api')
            ->getJson('/api/v1/amial/merchant/pos-devices');

        $response->assertStatus(200);

        $this->assertSame([], $response->json('data.devices'),
            '**تاجرٌ رأى جهازَ تاجرٍ آخر** — فالنطاقُ يُقرأ من الطلب لا من الهويّة');
    }

    /**
     * @test
     *
     * **⑥ وموظّفُ نقطة البيع لا يُسجّل جهازاً ولا يُلغيه.**
     *
     * فالمقعدُ مورِدٌ في باقة **صاحب الحساب**، وإتاحتُه لكاشيرٍ تجعله
     * يستنفد حدَّ متجرٍ كامل — أو يُلغي جهازَ زميله في ورديّةٍ أخرى.
     */
    public function a_pos_employee_cannot_register_or_revoke_a_device(): void
    {
        $owner = $this->merchant(A::BIZ_RETAIL, A::PLAN_BUSINESS);

        $staff = User::factory()->create(['type' => 4, 'role' => 'pos']);

        PosUser::create([
            'user_id' => $staff->id, 'merchant_user_id' => $owner->id,
            'pos_number' => 'POS-001', 'display_name' => 'كاشير', 'is_active' => true,
        ]);

        $device = app(\App\Services\Merchant\PosDeviceRegistrar::class)
            ->register($owner, 'device-owned-by-shop')['device'];

        $this->actingAs($staff->refresh(), 'api')
            ->postJson('/api/v1/amial/merchant/pos-devices', ['device_uuid' => 'staff-added-device'])
            ->assertStatus(403);

        $this->actingAs($staff->refresh(), 'api')
            ->deleteJson('/api/v1/amial/merchant/pos-devices/'.$device->id)
            ->assertStatus(403);

        $this->assertNull($device->refresh()->revoked_at,
            'موظّفٌ ألغى جهازَ المتجر');
    }
}
