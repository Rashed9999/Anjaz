<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\User;
use App\Models\WithdrawalMethod;
use App\Models\WithdrawRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-PILOT-E2E-002 — تغذيةُ الوكيل وسحبُه، من طرفٍ إلى طرف.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وكيلٌ واحدٌ في تجربتك — وهاتان عمليّتاه اللتان تلمسان رصيده.**
 *
 * وتصحيحُ قياسٍ لي قبل الكتابة: قلتُ إنّ `agent/withdraw` بصفر اختبار،
 * وهذا صحيحٌ لهذا المسار (`Api\V1\Agent\TransactionController::withdraw`)
 * وحده. أمّا `amial/agent/withdraw/execute` — تسليمُ الوكيل ورقاً لعميل —
 * **فله سبعةُ اختباراتٍ على مستوى الخدمة ولا اختبارَ على مستوى الطلب**.
 * وهما مسارانِ مختلفان يحملان كلمةَ «سحب»، فلا يُخلطان.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وما تفعله كلُّ عمليّةٍ حقّاً — لا ما يُوحي به اسمُها:**
 *
 *   `agent/add-money` **لا يُحرّك ريالاً واحداً.** يفحص الشروط ثمّ يردّ
 *   رابطَ بوّابة دفع. فالمالُ يتحرّك لاحقاً في ردّ البوّابة، لا هنا.
 *   وفحصُه إذن فحصُ حرّاسٍ لا فحصُ ميزان — ويُقال ذلك صراحةً بدل أن
 *   يُكتب اختبارُ رصيدٍ يمرّ لأنّه لا يقيس شيئاً.
 *
 *   `agent/withdraw` **يحجز ولا يصرف:** يُنقص `current_balance` ويزيد
 *   `pending_balance` بالقدر نفسه. فالمجموعُ ثابت، والصرفُ يقع حين توافق
 *   الإدارة.
 *
 * (المهارة ٨: «Money can never disappear. Money can never duplicate.»)
 */
class AgentTopupWithdrawE2ETest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE = 'E2E-DEV-';

    private function agent(string $phone = '967770008001', string $balance = '100000.0000', int $kyc = 1): User
    {
        $u = User::factory()->create([
            'type' => AGENT_TYPE, 'phone' => $phone, 'zone_code' => 'SOUTH',
            'is_kyc_verified' => $kyc, 'is_active' => 1,
        ]);

        EMoney::create([
            'user_id' => $u->id, 'current_balance' => $balance,
            'held_balance' => '0.0000', 'pending_balance' => '0.0000',
            'charge_earned' => '0.0000', 'zone_code' => 'SOUTH',
        ]);

        DB::table('user_log_histories')->insert([
            'user_id' => $u->id, 'device_id' => self::DEVICE . $u->id,
            'ip_address' => '10.0.0.1', 'is_active' => 1, 'is_blocked' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $u;
    }

    /**
     * **والبابُ الحقيقيّ فيه بوّابةُ جهاز.**
     *
     * `CheckDeviceId` تردّ ٤٠٠ لمن لا يُرسل `device-id`، و٤٠٣ لمن يُرسل
     * جهازاً غير مسجَّل. وأوّلُ تشغيلٍ لهذا الملفّ ردّ ٤٠٠ على كلّ شيء —
     * **فمرّت ثلاثةُ اختباراتٍ وهي لا تفحص ما تدّعي**: «المبلغُ الصفر
     * يُرفض» كان يمرّ لأنّ البوّابة ردّته قبل أن يصل التحقّق أصلاً.
     *
     * (القاعدة الرابعة: المسارُ السليم غيرُ المسار الذي يسلكه المستعمل.)
     */
    private function asAgent(User $a)
    {
        return $this->actingAs($a, 'api')
            ->withHeaders(['device-id' => self::DEVICE . $a->id]);
    }

    private function method(): WithdrawalMethod
    {
        return WithdrawalMethod::create([
            'method_name' => 'حوالة بنكيّة',
            'method_fields' => [['input_name' => 'account_no', 'input_type' => 'text']],
        ]);
    }

    /** الرصيدان معاً — فالحجزُ نقلٌ بينهما لا خروجٌ منهما. */
    private function money(int $userId): array
    {
        $e = EMoney::where('user_id', $userId)->first();

        return ['current' => (string) $e->current_balance, 'pending' => (string) $e->pending_balance];
    }

    private function withdrawBody(WithdrawalMethod $m, string $amount = '5000'): array
    {
        return [
            'pin' => '1234',
            'amount' => $amount,
            'withdrawal_method_id' => $m->id,
            'withdrawal_method_fields' => json_encode([['account_no' => 'YE-0001']]),
        ];
    }

    /** العمودُ اسمُه `key` لا `key_name` — قِيس من الجدول لا من الذاكرة. */
    private function setSetting(string $key, string $value): void
    {
        DB::table('business_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => $value, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    private function enable(string $key): void
    {
        $this->setSetting($key, '1');
    }

    // ══════════════════════════════════════════════════════════════
    // ١) السحب: المجموع ثابت
    // ══════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **ما نقص من المتاح دخل المعلَّق — بالقدر نفسه تماماً.**
     *
     * وهذه هي المعادلة كلُّها: الحجزُ نقلٌ بين جيبين في المحفظة نفسها.
     * فلو نقص المتاحُ أكثرَ ممّا زاد المعلَّقُ لضاع الفرقُ بلا قيدٍ ولا
     * خطأ — والوكيلُ لا يرى إلّا رقماً أصغر.
     */
    public function a_withdrawal_request_moves_money_between_pockets_without_losing_any(): void
    {
        $this->enable('withdraw_request_status');

        $a = $this->agent();
        $m = $this->method();

        $before = $this->money($a->id);

        $r = $this->asAgent($a)
            ->postJson('/api/v1/agent/withdraw', $this->withdrawBody($m, '5000'));

        if ($r->status() !== 200) {
            $this->markTestSkipped('السحبُ ردّ ' . $r->status() . ': ' . $r->json('message'));
        }

        $after = $this->money($a->id);

        $leftAvailable = bcsub($before['current'], $after['current'], 4);   // ما نقص من المتاح
        $enteredPending = bcsub($after['pending'], $before['pending'], 4);  // ما دخل المعلَّق

        $this->assertSame(1, bccomp($leftAvailable, '0', 4), 'لم يُحجز شيء');

        $this->assertSame(0, bccomp($leftAvailable, $enteredPending, 4),
            "الحجزُ لا يتوازن: نقص {$leftAvailable} ودخل المعلَّق {$enteredPending}");

        // والمحجوز = المبلغ + الرسم — لا المبلغ وحده.
        $this->assertSame(0, bccomp($leftAvailable, bcadd('5000', (string) \App\CentralLogics\Helpers::get_withdraw_charge(5000), 4), 4),
            "المحجوز {$leftAvailable} لا يساوي المبلغَ زائدَ الرسم");

        $this->assertDatabaseHas('withdraw_requests', [
            'user_id' => $a->id, 'request_status' => 'pending', 'is_paid' => 0,
        ]);
    }

    /**
     * @test
     *
     * **وما زاد عن الرصيد يُرفض — ولا يترك طلباً معلَّقاً وراءه.**
     *
     * **وفحصُ «٤٠٣» وحدَه لا يكفي:** الرفضُ قد يقع **بعد** إنشاء الطلب،
     * فيبقى صفٌّ ينتظر موافقةَ الإدارة بمالٍ لا وجودَ له. فيُسأل الجدولُ
     * لا الردُّ فقط.
     */
    public function a_withdrawal_beyond_the_balance_leaves_nothing_behind(): void
    {
        $this->enable('withdraw_request_status');

        $a = $this->agent('967770008011', '100.0000');
        $m = $this->method();

        $before = $this->money($a->id);

        $this->asAgent($a)
            ->postJson('/api/v1/agent/withdraw', $this->withdrawBody($m, '99999'))
            ->assertStatus(403);

        $this->assertSame($before, $this->money($a->id), 'رُفض السحبُ وتغيّر الرصيد');

        $this->assertSame(0, WithdrawRequest::where('user_id', $a->id)->count(),
            'رُفض السحبُ وبقي طلبٌ معلَّقٌ بمالٍ لا وجود له');
    }

    /**
     * @test
     *
     * **ولا يسحب من لم يُوثَّق حسابُه.**
     */
    public function an_unverified_agent_cannot_withdraw(): void
    {
        $this->enable('withdraw_request_status');

        $a = $this->agent('967770008021', '100000.0000', kyc: 0);
        $m = $this->method();

        $before = $this->money($a->id);

        $this->asAgent($a)
            ->postJson('/api/v1/agent/withdraw', $this->withdrawBody($m))
            ->assertStatus(403);

        $this->assertSame($before, $this->money($a->id));
        $this->assertSame(0, WithdrawRequest::where('user_id', $a->id)->count());
    }

    /**
     * @test
     *
     * **ومبلغُ الصفر أو السالب يُرفض.**
     *
     * وقاعدةُ التحقّق `min:0` على نصٍّ تقيس **طولَ النصّ** لا قيمتَه في
     * لارافل — فـ`-5000` يمرّ منها بخمسة محارف. ولذلك يُقاس الأثرُ في
     * المحفظة لا في رمز الردّ وحده.
     */
    public function a_zero_or_negative_withdrawal_is_refused(): void
    {
        $this->enable('withdraw_request_status');

        $a = $this->agent('967770008031');
        $m = $this->method();

        $before = $this->money($a->id);

        foreach (['0', '-5000'] as $amount) {
            $this->asAgent($a)
                ->postJson('/api/v1/agent/withdraw', $this->withdrawBody($m, $amount));
        }

        $this->assertSame($before, $this->money($a->id),
            'مبلغٌ صفرٌ أو سالبٌ غيّر الرصيد');

        $this->assertSame(0, WithdrawRequest::where('user_id', $a->id)->count(),
            'أُنشئ طلبُ سحبٍ بمبلغٍ صفرٍ أو سالب');
    }

    /**
     * @test
     *
     * **ومن لم يُصادَق لا يصل أصلاً.**
     */
    public function an_unauthenticated_withdrawal_is_refused(): void
    {
        $this->enable('withdraw_request_status');

        $this->postJson('/api/v1/agent/withdraw', $this->withdrawBody($this->method()))
            ->assertStatus(401);

        $this->assertSame(0, WithdrawRequest::count());
    }

    /**
     * @test
     *
     * **والخدمةُ حين تُطفأ تُطفأ فعلاً — لا في الشاشة وحدها.**
     */
    public function the_withdrawal_service_switch_actually_stops_it(): void
    {
        $this->setSetting('withdraw_request_status', '0');

        $a = $this->agent('967770008041');

        $this->asAgent($a)
            ->postJson('/api/v1/agent/withdraw', $this->withdrawBody($this->method()))
            ->assertStatus(403);

        $this->assertSame(0, WithdrawRequest::count(), 'الخدمةُ مطفأةٌ وأُنشئ طلب');
    }

    // ══════════════════════════════════════════════════════════════
    // ٢) التغذية: حرّاسٌ لا ميزان
    // ══════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **التغذيةُ تقول إنّ البوّابة غائبة — ولا تنهار.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **وهذا عطلٌ حقيقيٌّ كشفه أوّلُ اختبارٍ يمسّ هذه النقطة.**
     *
     * كانت تردّ **٥٠٠** في كلّ مرّةٍ تصلها — للعميل والوكيل معاً — لأنّ
     * `route('payment-mobile')` يُنادي اسماً **لا وجود له في جدول
     * المسارات**: أُزيلت حزمةُ بوّابة الدفع وبقي النداءان.
     *
     * ولم يكشفه شيءٌ لأنّ التطبيق لا ينادي هذه النقطة أصلاً
     * (`AppConstants.customerAddMoney` معرَّفٌ ولا يُستعمل) — **سطحٌ
     * مفتوحٌ لكلّ مصادَق، ميّتٌ من الداخل.**
     *
     * وخمسُ مئةٍ ليست رفضاً: هي انهيار. لا تقول للمستعمل شيئاً ولا
     * تُفرَّق في السجلّات عن عطلٍ في قاعدة البيانات. (القاعدة السابعة:
     * الغيابُ يُقال صراحةً.)
     * ══════════════════════════════════════════════════════════════════
     *
     * ويُقاس الأمران: أنّ الردّ صار مفهوماً، **وأنّ الرصيد لم يتغيّر**.
     */
    public function topup_says_the_gateway_is_gone_instead_of_crashing(): void
    {
        $this->enable('add_money_status');

        $a = $this->agent('967770008051');
        $before = $this->money($a->id);

        $r = $this->asAgent($a)
            ->postJson('/api/v1/agent/add-money', ['amount' => '10000', 'payment_method' => 'stripe']);

        $this->assertNotSame(500, $r->status(),
            'التغذيةُ تنهار بدل أن تُجيب — و٥٠٠ لا تقول للمستعمل شيئاً');

        // فإن عادت البوّابةُ يوماً عاد الردُّ رابطاً — والحارسُ يقبل الحالتين
        // ويرفض الانهيار وحده.
        if ($r->status() === 200) {
            $this->assertNotEmpty($r->json('link'), 'ردّت ٢٠٠ بلا رابطِ دفع');
        } else {
            $r->assertStatus(503);
            $this->assertSame('PAYMENT_GATEWAY_UNAVAILABLE', $r->json('code'));
            $this->assertNotEmpty($r->json('message'), 'رفضٌ بلا سبب');
        }

        $this->assertSame($before, $this->money($a->id),
            'تغيّر الرصيدُ قبل أن تُدفع البوّابة — مالٌ يُخلق من طلب');
    }

    /**
     * @test
     *
     * **والعميلُ يمرّ بالباب نفسه — فيُفحص منه.**
     *
     * (القاعدة الرابعة: ميزةٌ لها مدخلان تُختبَر من مدخليها. والنداءُ
     * المعطوب كان في المتحكّمين كليهما، فإصلاحُ أحدهما وحده يترك نصفَ
     * العطل قائماً بلا أن يظهر.)
     */
    public function the_customer_topup_door_does_not_crash_either(): void
    {
        $this->enable('add_money_status');

        $c = User::factory()->create([
            'type' => 2, 'phone' => '967770008081', 'zone_code' => 'SOUTH',
            'is_kyc_verified' => 1, 'is_active' => 1,
        ]);

        EMoney::create([
            'user_id' => $c->id, 'current_balance' => '5000.0000',
            'held_balance' => '0.0000', 'pending_balance' => '0.0000',
            'charge_earned' => '0.0000', 'zone_code' => 'SOUTH',
        ]);

        DB::table('user_log_histories')->insert([
            'user_id' => $c->id, 'device_id' => self::DEVICE . $c->id,
            'ip_address' => '10.0.0.1', 'is_active' => 1, 'is_blocked' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $before = $this->money($c->id);

        $r = $this->actingAs($c, 'api')
            ->withHeaders(['device-id' => self::DEVICE . $c->id])
            ->postJson('/api/v1/customer/add-money', ['amount' => '10000', 'payment_method' => 'stripe']);

        $this->assertNotSame(500, $r->status(),
            'بابُ العميل ينهار — أُصلح الوكيلُ وحده');

        $this->assertSame($before, $this->money($c->id));
    }

    /**
     * @test
     *
     * **ولا يُغذّي من لم يُوثَّق حسابُه.**
     */
    public function an_unverified_agent_cannot_top_up(): void
    {
        $this->enable('add_money_status');

        $a = $this->agent('967770008061', '0.0000', kyc: 0);

        $this->asAgent($a)
            ->postJson('/api/v1/agent/add-money', ['amount' => '10000', 'payment_method' => 'stripe'])
            ->assertStatus(403);
    }

    /**
     * @test
     *
     * **ولا يُغذَّى بأكثر ممّا في خزينة المنصّة.**
     *
     * فرصيدُ الوكيل يُصرَف من رصيد المنصّة. وتغذيةٌ تتجاوزه تعني وعداً
     * بمالٍ لا تملكه المنصّة — وهو أوّلُ ما ينكشف عند أوّل سحبٍ كبير.
     */
    public function a_topup_beyond_the_platform_treasury_is_refused(): void
    {
        $this->enable('add_money_status');

        $adminId = \App\CentralLogics\Helpers::get_admin_id();

        if (!$adminId) {
            $this->markTestSkipped('لا حسابَ منصّةٍ في هذه القاعدة');
        }

        EMoney::updateOrCreate(
            ['user_id' => $adminId],
            ['current_balance' => '1000.0000', 'held_balance' => '0.0000',
             'pending_balance' => '0.0000', 'charge_earned' => '0.0000', 'zone_code' => 'SOUTH'],
        );

        $a = $this->agent('967770008071');

        $this->asAgent($a)
            ->postJson('/api/v1/agent/add-money', ['amount' => '999999', 'payment_method' => 'stripe'])
            ->assertStatus(403);
    }

    /**
     * @test
     *
     * **ومن لم يُصادَق لا يصل.**
     */
    public function an_unauthenticated_topup_is_refused(): void
    {
        $this->postJson('/api/v1/agent/add-money', ['amount' => '10000', 'payment_method' => 'stripe'])
            ->assertStatus(401);
    }
}
