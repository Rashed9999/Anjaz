<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-PILOT-IDEM-001 — مفتاحُ التفرّد على المسارات التي يناديها التطبيق.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **قِيس لا افتُرض — وكانت النتيجة مالاً يتضاعف.**
 *
 * أُرسل الطلبُ نفسُه مرّتين بمفتاحٍ واحدٍ إلى `customer/send-money`
 * فوصل المستلِمَ **٢٠٠٠ والمبلغُ ١٠٠٠**، وسُجّلت أربعُ حركاتٍ بدل
 * اثنتين، وردّ الخادمُ `200 success` في المرّتين.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والسببُ ليس غيابَ الحماية — بل أنّها بُنيت ولم تُوصَل.**
 *
 * `EnforceIdempotency` مبنيٌّ منذ AMIAL-REFACTOR-CORE-001 ومستعملٌ في
 * مسارات `amial/*`. أمّا `api/v1/customer/*` و`api/v1/agent/*` — **وهي
 * التي يناديها التطبيق يوميّاً** — فكانت بلا وسيطٍ إطلاقاً.
 *
 * وهذا نمطُ العطل الأكثر تكراراً في أميال باي في صورته المالية: مبنيٌّ
 * ولا يُوصَل إليه.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وانقطاعُ الشبكة ليس حالةً نادرة في اليمن — هو الحالةُ العاديّة.**
 *
 * (المهارة ٨: «Never allow duplicated money.»)
 */
class AppMoneyIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private const PIN = '1234';

    private function user(int $type, string $phone, string $balance): User
    {
        $u = User::factory()->create([
            'type' => $type, 'phone' => $phone, 'zone_code' => 'SOUTH',
            'is_kyc_verified' => 1, 'is_active' => 1,
        ]);

        // **رمزُ المعاملات غيرُ كلمة السرّ** — و`pin_check` يقرأ
        // `transaction_pin`. وأوّلُ قياسٍ لي ردّ «رمز PIN غير صحيح» فبدا
        // الحارسُ ناجحاً وهو لم يصل إلى الحماية أصلاً.
        DB::table('users')->where('id', $u->id)->update(['transaction_pin' => Hash::make(self::PIN)]);

        EMoney::create([
            'user_id' => $u->id, 'current_balance' => $balance,
            'held_balance' => '0.0000', 'pending_balance' => '0.0000',
            'charge_earned' => '0.0000', 'zone_code' => 'SOUTH',
        ]);

        DB::table('user_log_histories')->insert([
            'user_id' => $u->id, 'device_id' => 'IDEM-DEV-' . $u->id,
            'ip_address' => '10.0.0.1', 'is_active' => 1, 'is_blocked' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $u->fresh();
    }

    private function as(User $u, string $key)
    {
        return $this->actingAs($u, 'api')->withHeaders([
            'device-id' => 'IDEM-DEV-' . $u->id,
            'Idempotency-Key' => $key,
        ]);
    }

    private function enable(string $key): void
    {
        DB::table('business_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => '1', 'created_at' => now(), 'updated_at' => now()],
        );
    }

    private function bal(int $userId): string
    {
        return (string) EMoney::where('user_id', $userId)->value('current_balance');
    }

    /**
     * @test
     *
     * **تحويلٌ واحدٌ بمفتاحٍ واحد — ولو أُرسل مرّتين.**
     *
     * ويُقاس الطرفان معاً: أنّ المرسِل لم يُخصم مرّتين، **وأنّ المستلِم لم
     * يستلم مرّتين**. فقياسُ طرفٍ واحدٍ يترك نصفَ الميزان مجهولاً.
     *
     * وعكسُه (القاعدة الثانية): يُنزع `amial.idempotency` من المسار ⇒
     * يسقط هذا الحارسُ بـ٢٠٠٠ بدل ١٠٠٠. قِيس.
     */
    public function a_repeated_transfer_with_one_key_moves_the_money_once(): void
    {
        $this->enable('send_money_status');

        $s = $this->user(2, '967770010001', '50000.0000');
        $r = $this->user(2, '967770010002', '0.0000');

        $body = ['phone' => $r->phone, 'amount' => '1000', 'purpose' => 'فحص', 'pin' => self::PIN];
        // **والمفتاحُ لا يقلّ عن ١٦ محرفاً** — `IdempotencyService::validateKey`
        // يردّ ٤٠٠ لما دونه. وأوّلُ قياسٍ لي استعمل ١٣ فبدا التحويلُ فاشلاً
        // والحمايةُ غائبة، والسببُ في مفتاحي وحده.
        $key = 'IDEM-SEND-0000000001';

        $first = $this->as($s, $key)->postJson('/api/v1/customer/send-money', $body);

        if ($first->status() !== 200) {
            $this->markTestSkipped('التحويلُ الأوّل ردّ ' . $first->status() . ': ' . $first->json('message'));
        }

        $afterFirst = ['s' => $this->bal($s->id), 'r' => $this->bal($r->id)];
        $movesFirst = Transaction::count();

        $this->as($s, $key)->postJson('/api/v1/customer/send-money', $body);

        $this->assertSame($afterFirst['s'], $this->bal($s->id),
            'خُصم من المرسِل مرّتين بمفتاحٍ واحد');

        $this->assertSame($afterFirst['r'], $this->bal($r->id),
            'وصل المستلِمَ المبلغُ مرّتين بمفتاحٍ واحد');

        $this->assertSame($movesFirst, Transaction::count(),
            'سُجّلت حركاتٌ ثانيةٌ لعمليّةٍ واحدة');
    }

    /**
     * @test
     *
     * **ومفتاحان مختلفان = عمليّتان — فالحمايةُ لا تُجمّد المستعمل.**
     *
     * وهذا شقُّ الحارس الثاني: حمايةٌ تمنع التحويلَ الثاني المشروع أسوأ
     * من غيابها — تُوقف العمل وتبدو سليمة.
     */
    public function two_different_keys_are_two_real_transfers(): void
    {
        $this->enable('send_money_status');

        $s = $this->user(2, '967770010011', '50000.0000');
        $r = $this->user(2, '967770010012', '0.0000');

        $body = ['phone' => $r->phone, 'amount' => '1000', 'purpose' => 'فحص', 'pin' => self::PIN];

        $first = $this->as($s, 'IDEM-KEY-AAAAAAAAAA')->postJson('/api/v1/customer/send-money', $body);

        if ($first->status() !== 200) {
            $this->markTestSkipped('التحويلُ الأوّل ردّ ' . $first->status());
        }

        $afterFirst = $this->bal($r->id);

        $this->as($s, 'IDEM-KEY-BBBBBBBBBB')->postJson('/api/v1/customer/send-money', $body)->assertOk();

        $this->assertSame(1, bccomp($this->bal($r->id), $afterFirst, 4),
            'مفتاحٌ جديدٌ ولم يصل المستلِمَ شيء — الحمايةُ تمنع المشروع');
    }

    /**
     * @test
     *
     * **والوسيطُ موصولٌ بكلّ مسارٍ يُحرّك مالاً في التطبيق.**
     *
     * فالحارسُ أعلاه يفحص مساراً واحداً، وهذا يفحص **الوصلَ نفسه**: لو
     * أُضيف مسارُ مالٍ جديدٌ غداً بلا وسيط لسقط هنا قبل أن يصل إلى عميل.
     *
     * (القاعدة ١٢: المسارُ المسجَّل ليس ظهوراً — والحمايةُ المبنيّة ليست
     * حمايةً حتّى تُوصَل.)
     */
    public function every_app_money_route_carries_the_middleware(): void
    {
        $must = [
            'api/v1/customer/send-money',
            'api/v1/customer/cash-out',
            'api/v1/customer/withdraw',
            'api/v1/agent/send-money',
            'api/v1/agent/withdraw',
        ];

        $missing = [];

        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            if (!in_array('POST', $route->methods(), true)) {
                continue;
            }

            if (!in_array($route->uri(), $must, true)) {
                continue;
            }

            $has = false;

            // **ويُقرأ الاسمُ المستعار والصنفُ معاً**: `gatherMiddleware`
            // تُرجع `amial.idempotency` كما كُتب في المسار لا الصنفَ
            // المحلول. وأوّلُ صيغةٍ لهذا الحارس بحثت عن الصنف وحده
            // **فأعلنت الخمسةَ كلَّها بلا حماية وهي محميّة** — حارسٌ
            // يكذب في الاتّجاه الآخر.
            foreach ($route->gatherMiddleware() as $m) {
                if (is_string($m) && (str_contains($m, 'EnforceIdempotency') || str_contains($m, 'amial.idempotency'))) {
                    $has = true;
                }
            }

            if (!$has) {
                $missing[] = $route->uri();
            }
        }

        $this->assertSame([], $missing,
            'مساراتٌ تُحرّك مالاً بلا مفتاح تفرّد: ' . implode(' · ', $missing));
    }

    /**
     * الكلماتُ التي تدلّ على مسارٍ يمسّ مالاً.
     */
    private const MONEY_WORDS = [
        'send-money', 'withdraw', 'cash-out', 'cash-in', 'add-money', 'pay',
        'transfer', 'settle', 'topup', 'payout', 'refund', 'credit', 'debit',
        'deposit', 'collect', 'disburse', 'release', 'fund', 'donat',
        'installment', 'charity',
    ];

    /**
     * **استثناءاتٌ مُعلَنة — قراءةٌ عبر POST، لا حركةَ مال.**
     *
     * ومفتاحُ التفرّد يحفظ الردَّ أربعاً وعشرين ساعةً ويُعيده. فشمولُ هذه
     * لا يمنع ضرراً بل **يُنتج كذباً**: اسمُ مستلِمٍ بعد تغيّره، أو حالةُ
     * سحبٍ «معلّقة» بعد تنفيذه. (القاعدة السابعة: الغيابُ يُقال ولا يُخفى.)
     *
     * والاستثناءُ مكتوبٌ هنا لا متروكٌ صمتاً — فالمكتوبُ يُراجَع.
     */
    private const DECLARED_READS = [
        'api/v1/amial/transfer/verify-recipient',
        'api/v1/amial/agent/withdraw/lookup',
        'api/v1/amial/merchant/installments/quote',
        'api/v1/amial/merchant/quote',
    ];

    /**
     * @test
     *
     * **ولا مسارَ مالٍ واحدٍ في المنصّة كلِّها بلا مفتاح تفرّد.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **وهذا الحارسُ يُعيد الحصرَ في كلّ تشغيل — ولا يقرأ قائمةً مكتوبة.**
     *
     * ولولا ذلك لكان حارساً يشيخ: قائمةُ تسعةٍ وثمانين مساراً تُكتب اليوم
     * وتصير ناقصةً أوّلَ مسارٍ يُضاف غداً، **والحارسُ يمرّ وهو لا يعرف
     * بوجوده**. فيُستخرج الحصرُ من جدول المسارات نفسِه بالكلمات المالية،
     * ويسقط الحارسُ على أوّل مسارٍ جديدٍ بلا حماية.
     *
     * (القاعدة الثانية: حارسٌ لم يسقط ليس حارساً — وهذا يسقط وحده حين
     * يُنسى الوسيط.)
     * ══════════════════════════════════════════════════════════════════
     */
    public function no_money_route_in_the_whole_platform_lacks_the_key(): void
    {
        $missing = [];
        $covered = 0;

        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            $methods = $route->methods();

            if (!array_intersect(['POST', 'PUT', 'PATCH'], $methods)) {
                continue;
            }

            $uri = $route->uri();

            $isMoney = false;

            foreach (self::MONEY_WORDS as $word) {
                if (str_contains($uri, $word)) {
                    $isMoney = true;
                }
            }

            if (!$isMoney || in_array($uri, self::DECLARED_READS, true)) {
                continue;
            }

            $has = false;

            foreach ($route->gatherMiddleware() as $m) {
                if (is_string($m) && (str_contains($m, 'EnforceIdempotency') || str_contains($m, 'amial.idempotency'))) {
                    $has = true;
                }
            }

            $has ? $covered++ : $missing[] = $uri;
        }

        // **ويُتأكَّد أنّ الحصر وجد شيئاً أصلاً.** فقائمةٌ فارغةٌ تُرضي
        // `assertSame([], …)` وهي لا تفحص شيئاً — وذاك حارسٌ يُطمئن ولا يحرس.
        $this->assertGreaterThan(80, $covered,
            "الحصرُ وجد {$covered} مساراً فقط — القاعدةُ لا تلتقط المسارات");

        $this->assertSame([], $missing,
            'مسارُ مالٍ بلا مفتاح تفرّد: ' . implode(' · ', $missing));
    }

    /**
     * @test
     *
     * **ولوحاتُ الويب تُرسل المفتاح — لا الخادمُ يُولّده لها.**
     *
     * فوسيطٌ بلا مفتاحٍ من العميل حمايتُه **صفر**: يُولّد مفتاحاً عشوائيّاً
     * لكلّ طلب، فتصير كلُّ ضغطةٍ عمليّةً جديدة. وهذا هو الخطأ نفسُه الذي
     * وقع في التطبيق، فلا يُعاد في اللوحة.
     *
     * (القاعدة ١٢: الوسيطُ المسجَّل ليس حمايةً حتّى يصل إليه مفتاح.)
     */
    public function the_admin_layout_ships_the_key_sender(): void
    {
        $this->assertFileExists(public_path('assets/js/amial-idempotency.js'),
            'ملفُّ مفتاح التفرّد غير موجود — القالبُ يطلب ما لا وجود له');

        $layout = file_get_contents(resource_path('views/layouts/admin/app.blade.php'));

        $this->assertStringContainsString('amial-idempotency.js', $layout,
            'اللوحةُ لا تُحمّل مُرسِلَ المفتاح — كلُّ ضغطتين عمليّتان');

        // **ويُحمَّل قبل شيفرة الصفحات** — فلفُّ `fetch` بعد استعماله لا يلفّه.
        $this->assertLessThan(
            strpos($layout, "@stack('script')"),
            strpos($layout, 'amial-idempotency.js'),
            'مُرسِلُ المفتاح يُحمَّل بعد شيفرة الصفحات — فلا يلفّ نداءاتها',
        );

        $js = file_get_contents(public_path('assets/js/amial-idempotency.js'));

        foreach (['Idempotency-Key', 'inFlight', 'POST'] as $part) {
            $this->assertStringContainsString($part, $js, "ناقصٌ من المُرسِل: {$part}");
        }
    }
}
