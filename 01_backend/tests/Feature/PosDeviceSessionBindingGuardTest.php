<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePosDevice;
use App\Models\Merchant\PosDevice;
use App\Models\Merchant\PosDeviceSession;
use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\User;
use App\Services\Merchant\PosDeviceRegistrar;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * AMIAL-POS-DEVICES-003 — **حدُّ تسجيلٍ ليس حدَّ استعمال.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الالتفافُ الذي لا يمرّ من باب الحارس:**
 *
 * `max_pos_devices` مفروضٌ عند التسجيل، وقد أُثبت بالاختبار: الجهازُ
 * الثاني على المجّانيّة يُرفض. **ثمّ لا يحتاج المُلتفُّ أن يُسجّل ثانياً
 * أصلاً**:
 *
 *   يُسجَّل جهازٌ واحدٌ (‏مقعدٌ مدفوعٌ واحد)
 *     ⇒ يُنسخ رمزُ الجلسة إلى عشرة أجهزة
 *       ⇒ تعمل العشرةُ، ولا يمرّ أيٌّ منها بالمُسجِّل
 *
 * فاختبارٌ يسأل «هل يُرفض الجهازُ الثاني عند التسجيل؟» يمرّ **والحدُّ
 * مُلتَفٌّ عليه**. وهذا نصُّ ما حذّر منه صاحبُ المشروع: لا يُعدّ الحارسُ
 * محكَماً حتّى يُبحث عن **المسار الذي يبلغ العمليّةَ نفسَها من باب آخر**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وهذا الملفُّ يسأل أربعةَ أسئلةٍ ويُجيبها بالتشغيل:**
 *
 *   ① رمزٌ لجهازٍ ألغي — أيُكمل؟          (‏«ألغيتُ المسروق» أصادقةٌ؟)
 *   ② رمزُ الجهاز «أ» على الجهاز «ب»؟
 *   ③ الوضعُ الصامتُ — أيُغلق فعلاً حين يُرفع؟
 *   ④ وهل البوّابةُ على **كلّ** مسارٍ مصادَق، أم على ما تذكّرناه؟
 */
class PosDeviceSessionBindingGuardTest extends TestCase
{
    use RefreshDatabase;

    private function merchant(string $plan = A::PLAN_BUSINESS): User
    {
        $u = User::factory()->create([
            'type' => 3, 'role' => A::ROLE_MERCHANT, 'zone_code' => 'SOUTH',
        ]);

        MerchantProfile::create([
            'user_id' => $u->id, 'verification_status' => 'verified',
            'business_type' => A::BIZ_RETAIL, 'subscription_plan' => $plan,
        ]);

        return $u->refresh();
    }

    /** موظّفُ نقطةِ بيعٍ تحت تاجر. */
    private function cashier(User $merchant, string $posNumber = 'POS-001'): User
    {
        $staff = User::factory()->create(['type' => 4, 'role' => 'pos']);

        PosUser::create([
            'user_id' => $staff->id,
            'merchant_user_id' => $merchant->id,
            'pos_number' => $posNumber,
            'display_name' => 'كاشير',
            'is_active' => true,
        ]);

        return $staff->refresh();
    }

    private function reg(): PosDeviceRegistrar
    {
        return app(PosDeviceRegistrar::class);
    }

    /**
     * **يُشغّل البوّابةَ على طلبٍ حقيقيّ بلا مفاتيح Passport.**
     *
     * الرمزُ يُلصق بـ`withAccessToken` — وهو المسارُ الذي يسلكه Passport
     * نفسُه بعد المصادقة. فلا تحتاج المجموعةُ توليدَ مفاتيح RSA لتسأل
     * سؤالاً عن الربط.
     */
    private function pass(User $actor, ?string $tokenId, ?string $deviceHeader = null): \Symfony\Component\HttpFoundation\Response
    {
        if ($tokenId !== null) {
            $actor = $actor->withAccessToken(new \Laravel\Passport\Token(['id' => $tokenId]));
        }

        $request = Request::create('/api/v1/amial/probe', 'GET');
        $request->setUserResolver(fn () => $actor);

        if ($deviceHeader !== null) {
            $request->headers->set(EnsurePosDevice::HEADER, $deviceHeader);
        }

        return app(EnsurePosDevice::class)->handle(
            $request,
            fn () => response()->json(['ok' => true]),
        );
    }

    /** يربط رمزاً بمقعدٍ مسجَّل — كما يفعل الدخولُ تماماً. */
    private function bind(User $merchant, User $actor, string $uuid, string $tokenId): PosDevice
    {
        $device = $this->reg()->register($merchant, $uuid)['device'];

        PosDeviceSession::create([
            'access_token_id' => $tokenId,
            'pos_device_id' => $device->id,
            'merchant_user_id' => $merchant->id,
            'actor_user_id' => $actor->id,
            'started_at' => now(),
            'last_seen_at' => now(),
        ]);

        return $device;
    }

    /**
     * @test
     *
     * **الجلسةُ المربوطةُ تعمل** — وهذا الضابطُ الذي بدونه يكون كلُّ منعٍ
     * بعده منعاً بلا معنى (‏بوّابةٌ تمنع الجميع تجتاز نصفَ الفحص).
     */
    public function a_bound_session_on_a_live_device_passes(): void
    {
        $m = $this->merchant();
        $actor = $this->cashier($m);

        $this->bind($m, $actor, 'device-alpha', 'tok-alive');

        $this->assertSame(200, $this->pass($actor, 'tok-alive')->getStatusCode(),
            'جلسةٌ سليمةٌ على جهازٍ حيٍّ مُنعت — فالبوّابةُ تمنع الجميع');
    }

    /**
     * @test
     *
     * **① إلغاءُ الجهاز يقتل جلستَه القائمة.**
     *
     * ولولا هذا لكان الإلغاءُ يُخلي المقعدَ **ويترك الجهازَ يعمل** حتّى
     * ينتهي رمزُه من نفسِه — أي أنّ «ألغيتُ الجهازَ المسروق» جملةٌ كاذبةٌ
     * في اللحظة التي يُحتاج فيها صدقُها.
     */
    public function revoking_a_device_kills_its_live_session(): void
    {
        $m = $this->merchant();
        $actor = $this->cashier($m);

        $device = $this->bind($m, $actor, 'device-to-steal', 'tok-stolen');

        $this->assertSame(200, $this->pass($actor, 'tok-stolen')->getStatusCode(),
            'الجلسةُ لم تكن تعمل أصلاً — فالمنعُ التالي لا يُثبت شيئاً');

        $this->reg()->revoke($device, $m->id);

        $response = $this->pass($actor, 'tok-stolen');

        $this->assertSame(401, $response->getStatusCode(),
            '**الجهازُ الملغى ما زال يعمل** — فالإلغاءُ يُخلي المقعدَ ولا يوقف السرقة');

        $this->assertSame('POS_DEVICE_REVOKED',
            json_decode($response->getContent(), true)['code'] ?? null);
    }

    /**
     * @test
     *
     * **وجلسةٌ مختومةٌ منعٌ — لا «رمزٌ حرّ».**
     *
     * ══════════════════════════════════════════════════════════════
     * **وهذا عطلٌ وقع في هذه الشيفرة نفسِها وقِيس بالتشغيل.**
     *
     * كان `forToken` يُرشّح `whereNull('ended_at')` — وهي صياغةٌ تبدو
     * طبيعيّة. والنتيجةُ أنّ **إلغاءَ الجهاز كان يُضعف الحراسة**: تُختم
     * الجلسةُ فيُرجِع الاستعلامُ `null`، فيُقرأ الرمزُ «غيرُ مربوط» لا
     * «مربوطٌ بجهازٍ ملغى»، فيسقط في فرع الوضع الصامت **فيمرّ بـ٢٠٠**.
     *
     * أي أنّ زرَّ «ألغِ الجهاز» كان يُحرّر الرمزَ من قيده.
     */
    public function an_ended_session_is_a_denial_not_a_free_token(): void
    {
        $m = $this->merchant();
        $actor = $this->cashier($m);

        // **الجهازُ يبقى حيّاً عمداً** — فالمنعُ يجب أن يأتي من الختم وحدَه،
        // وإلّا كان الاختبارُ يقيس إلغاءَ الجهاز مرّةً أخرى.
        $this->bind($m, $actor, 'device-ended', 'tok-ended');

        PosDeviceSession::where('access_token_id', 'tok-ended')
            ->update(['ended_at' => now()]);

        $this->assertSame(401, $this->pass($actor, 'tok-ended')->getStatusCode(),
            'جلسةٌ مختومةٌ قُرئت رمزاً حرّاً — **فختمُ الجلسة يُطلق الرمزَ بدل أن يوقفه**');
    }

    /**
     * @test
     *
     * **② ورمزُ الجهاز «أ» لا يعمل على الجهاز «ب».**
     *
     * وهذا هو نسخُ الرمز بعينِه: مقعدٌ واحدٌ مدفوعٌ وعشرةُ أجهزةٍ تعمل به.
     */
    public function a_token_issued_for_one_device_is_refused_on_another(): void
    {
        $m = $this->merchant();
        $actor = $this->cashier($m);

        $this->bind($m, $actor, 'device-A', 'tok-A');

        // جهازٌ ثانٍ مسجَّلٌ بمقعده — والرمزُ ليس رمزَه.
        $this->reg()->register($m, 'device-B');

        $response = $this->pass($actor, 'tok-A', 'device-B');

        $this->assertSame(403, $response->getStatusCode(),
            '**رمزُ مقعدٍ عمل على مقعدٍ آخر** — فالحدُّ حدُّ تسجيلٍ لا حدُّ استعمال');

        $this->assertSame('POS_DEVICE_MISMATCH',
            json_decode($response->getContent(), true)['code'] ?? null);
    }

    /**
     * @test
     *
     * **وحذفُ الترويسة لا يُحرّر الرمز.**
     *
     * فلو كان الربطُ يُقرأ من الترويسة وحدَها لكان الالتفافُ **ألّا تُرسَل**.
     * والربطُ في الخادم، فالرمزُ يبقى على مقعده صامتاً كان حاملُه أو ناطقاً.
     * (القيدُ السادس: لا يُؤخذ معرِّفُ الجهاز من فلاتر دليلاً وحيداً.)
     */
    public function omitting_the_header_does_not_release_the_token_from_its_seat(): void
    {
        $m = $this->merchant();
        $actor = $this->cashier($m);

        $device = $this->bind($m, $actor, 'device-silent', 'tok-silent');
        $this->reg()->revoke($device, $m->id);

        $this->assertSame(401, $this->pass($actor, 'tok-silent')->getStatusCode(),
            'حذفُ الترويسة حرّر الرمزَ من مقعده الملغى — فالربطُ في العميل لا في الخادم');
    }

    /**
     * @test
     *
     * **③ والوضعُ الصامتُ يُغلق فعلاً حين يُرفع.**
     *
     * وضعٌ صامتٌ لا يُثبَت أنّ إغلاقَه يُنتج المنعَ ليس «مؤقّتاً» — هو
     * حارسٌ لا وجودَ له. فيُجرَّب الطرفان في اختبارٍ واحد.
     */
    public function the_shadow_mode_actually_denies_once_lifted(): void
    {
        $m = $this->merchant();
        $actor = $this->cashier($m);   // موظّفُ نقطة بيعٍ بلا أيّ ربط

        config(['amial.pos_devices.enforce_session_binding' => false]);

        $this->assertSame(200, $this->pass($actor, null)->getStatusCode(),
            'الوضعُ الصامتُ يمنع — فهو ليس صامتاً، وسيُخرج كلَّ موظّفٍ يعمل الآن');

        config(['amial.pos_devices.enforce_session_binding' => true]);

        $response = $this->pass($actor, null);

        $this->assertSame(403, $response->getStatusCode(),
            '**رفعُ الوضع الصامت لم يُنتج منعاً** — فالمفتاحُ لا يفعل شيئاً، '
            . 'والخطّةُ التي تنتظر رفعَه تنتظر ما لا يقع');

        $this->assertSame('POS_DEVICE_REQUIRED',
            json_decode($response->getContent(), true)['code'] ?? null);
    }

    /**
     * @test
     *
     * **والتاجرُ نفسُه لا يحتاج مقعداً.**
     *
     * فالمقعدُ للأجهزة العاملة على نقطة البيع، ومالكُ الحساب يدير من أيّ
     * متصفّح. وبوّابةٌ تمنعه تُقفل الحسابَ على صاحبه.
     */
    public function the_merchant_owner_is_never_asked_for_a_seat(): void
    {
        $m = $this->merchant();

        config(['amial.pos_devices.enforce_session_binding' => true]);

        $this->assertSame(200, $this->pass($m, null)->getStatusCode(),
            'التاجرُ نفسُه مُنع لغياب مقعدٍ — والمقعدُ ليس له');
    }

    /**
     * @test
     *
     * **④ والبوّابةُ على كلّ مسارٍ مصادَق — لا على ما تذكّرناه.**
     *
     * ولو قُصرت على `merchant/*` لكان الالتفافُ سطراً واحداً: يُستعمل رمزُ
     * نقطة البيع على مسارٍ غيرِ محروسٍ فيعمل بلا مقعد. **والحدُّ يُفرض حيث
     * يصل الرمزُ، لا حيث نتوقّع أن يذهب.**
     *
     * ويُقرأ من **جدول المسارات المبنيّ** لا من نصّ الملفّات — فتعليقٌ أو
     * صياغةٌ مختلفةٌ لا تُخفي مساراً (وقد أخفى تعليقٌ عربيٌّ عطلاً من قبل).
     */
    public function every_authenticated_route_carries_the_device_gate(): void
    {
        $open = [];

        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();

            $authenticated = false;

            foreach ($middleware as $m) {
                if (is_string($m) && str_starts_with($m, 'auth:api')) {
                    $authenticated = true;
                }
            }

            if (! $authenticated) {
                continue;
            }

            $gated = false;

            foreach ($middleware as $m) {
                if ($m === EnsurePosDevice::class || $m === 'amial.pos-device') {
                    $gated = true;
                }
            }

            if (! $gated) {
                $open[] = implode('|', $route->methods()).' '.$route->uri();
            }
        }

        sort($open);

        $this->assertSame([], $open, sprintf(
            "مسارٌ يقبل رمزَ مصادقةٍ ولا يفحص مقعدَ الجهاز — **فرمزُ نقطة بيعٍ "
            . "يعمل عليه بلا مقعد، وجهازٌ ألغي يبقى حيّاً فيه**:\n  %s\n\n"
            . 'أضِف `amial.pos-device` إلى مجموعته.',
            implode("\n  ", $open)));
    }
}
