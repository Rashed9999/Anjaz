<?php

namespace Tests\Feature;

use App\Aml\TransactionContext;
use App\Models\Aml\AmlFlaggedTransaction;
use App\Models\Aml\AmlRule;
use App\Models\Aml\AmlRuleEvaluation;
use App\Models\Aml\AmlUserRiskProfile;
use App\Models\User;
use App\Services\AmlScreeningService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-AML-001 (v1.4) — اختبارات.
 */
class AmlScreeningServiceTest extends TestCase
{
    use RefreshDatabase;

    private AmlScreeningService $service;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AmlScreeningService::class);
        $this->user = User::factory()->create([
            'created_at' => now()->subMonths(6), // not "new"
        ]);
    }

    private function makeContext(array $overrides = []): TransactionContext
    {
        return new TransactionContext(
            actorUserId: $overrides['actor_user_id'] ?? $this->user->id,
            counterpartyUserId: $overrides['counterparty_user_id'] ?? null,
            transactionType: $overrides['transaction_type'] ?? 'send_money',
            amount: $overrides['amount'] ?? '500.00',
            timestamp: $overrides['timestamp'] ?? now(),
            transactionUlid: $overrides['transaction_ulid'] ?? \Str::ulid()->toString(),
        );
    }

    private function createRule(array $attrs): AmlRule
    {
        return AmlRule::create(array_merge([
            'code' => 'TEST_RULE_' . uniqid(),
            'name_ar' => 'قاعدة اختبار',
            'rule_type' => 'max_single_transaction',
            'applies_to' => 'send_money,safe_payment',
            'parameters' => [],
            'action_on_match' => 'flag',
            'risk_score_contribution' => 10,
            'priority' => 100,
            'is_active' => true,
        ], $attrs));
    }

    /** @test */
    public function allows_transaction_when_no_rules_match()
    {
        $this->createRule([
            'rule_type' => 'max_single_transaction',
            'parameters' => ['threshold_amount' => '10000'],
            'action_on_match' => 'block',
        ]);

        $decision = $this->service->screen($this->makeContext(['amount' => '500']));

        $this->assertEquals('allow', $decision->finalAction);
        $this->assertEquals(0, $decision->totalRiskScore);
        $this->assertEmpty($decision->triggeredRules);
    }

    /**
     * A configured AML flow with no active coverage must not fail open.
     *
     * ══════════════════════════════════════════════════════════════════
     * **AMIAL-AML-COVERAGE-002 — والفجوةُ تُصنَع بقاعدةٍ لا تغطّي، لا بجدولٍ فارغ.**
     *
     * كان الاختبارُ يحذف القواعدَ **كلَّها** ثمّ يتوقّع حجزاً. وهذا يخلط
     * حالتين مختلفتين تماماً:
     *
     *   جدولٌ فارغٌ تماماً      ⇒ **النظامُ لم يُضبَط** (‏بذرةٌ لم تُشغَّل)
     *   قواعدُ موجودةٌ لا تغطّي ⇒ **فجوةُ تغطيةٍ حقيقيّة**
     *
     * والخلطُ بينهما جعل كلَّ حركةِ مالٍ تُحجَز على أيّ قاعدةٍ نظيفة —
     * ٢٩١ اختباراً ساقطاً، وكلَّ تحويلٍ متوازٍ يُخرج `AmlHeldException`.
     *
     * فالمقصدُ باقٍ كما أراده كاتبُه، والتركيبةُ صارت تُنتج الفجوةَ التي
     * يعنيها: **قاعدةٌ فعّالةٌ لنوعٍ آخر**.
     */
    public function test_holds_a_screened_transaction_when_no_active_rule_covers_it(): void
    {
        AmlRule::query()->delete();

        // قاعدةٌ فعّالةٌ **لنوعٍ آخر** — فالنظامُ مضبوطٌ وهذا النوعُ مكشوف.
        $this->createRule([
            'code' => 'COVERS_ANOTHER_TYPE',
            'applies_to' => 'bill_pay',
            'rule_type' => 'max_single_transaction',
            'parameters' => ['threshold_amount' => '5000'],
        ]);

        config()->set('amial.aml.hold_when_uncovered', true);

        $decision = $this->service->screen($this->makeContext([
            'transaction_type' => 'send_money',
        ]));

        $this->assertSame('hold', $decision->finalAction);
        $this->assertSame('AML_COVERAGE_GAP', $decision->triggeredRules[0]['code']);
        $this->assertDatabaseHas('aml_flagged_transactions', [
            'transaction_type' => 'send_money', 'initial_decision' => 'hold',
        ]);
    }

    /**
     * @test
     *
     * **ونظامٌ بلا قاعدةٍ واحدةٍ يُقال إنّه غيرُ مضبوط — ولا يُوقف المال.**
     *
     * ══════════════════════════════════════════════════════════════════
     * فجدولٌ فارغٌ تماماً معناه **بذرةٌ لم تُشغَّل** لا خطرٌ مرصود. وحجزُ
     * كلّ ريالٍ حينئذٍ ليس رقابةً — هو **انقطاعٌ كاملٌ للمنتج** سببُه إعدادٌ
     * ناقص: لا تحويل، ولا سحبٌ من وكيل، ولا دفعٌ لتاجر.
     *
     * **ولا يُسكَت عنه**: يُرفَع عطلٌ يراه الأدمنُ في مركز الأعطال، فيُكتشف
     * بالشاشة لا بتوقّف المنتج. (‏حارسٌ يقتل المنتجَ ليس حارساً — هو عطلٌ ثانٍ.)
     */
    public function an_unseeded_system_says_so_instead_of_freezing_all_money(): void
    {
        AmlRule::query()->delete();
        config()->set('amial.aml.hold_when_uncovered', true);

        $before = \Illuminate\Support\Facades\DB::table('system_errors')->count();

        $decision = $this->service->screen($this->makeContext([
            'transaction_type' => 'send_money',
        ]));

        $this->assertSame('allow', $decision->finalAction,
            'جدولُ قواعدَ فارغٌ أوقف المال — **وهو بذرةٌ لم تُشغَّل لا خطرٌ مرصود**');

        $this->assertGreaterThan($before,
            \Illuminate\Support\Facades\DB::table('system_errors')->count(),
            'مرّ النظامُ غيرُ المضبوط **بلا أثرٍ في مركز الأعطال** — '
            . 'فلا أحدَ يعلم أنّ الفحصَ لا يجري');
    }

    /** @test */
    public function blocks_when_amount_exceeds_max_single_tx()
    {
        $this->createRule([
            'rule_type' => 'max_single_transaction',
            'parameters' => ['threshold_amount' => '5000'],
            'action_on_match' => 'block',
            'risk_score_contribution' => 100,
        ]);

        $decision = $this->service->screen($this->makeContext(['amount' => '6000']));

        $this->assertEquals('block', $decision->finalAction);
        $this->assertEquals(100, $decision->totalRiskScore);
        $this->assertCount(1, $decision->triggeredRules);
    }

    /** @test */
    public function holds_when_velocity_exceeded()
    {
        // Setup: قاعدة velocity (3 معاملات/دقيقة)
        $rule = $this->createRule([
            'rule_type' => 'velocity',
            'parameters' => ['max_count' => 3, 'window_minutes' => 1],
            'action_on_match' => 'hold',
            'risk_score_contribution' => 50,
        ]);

        // أنشئ 3 evaluations سابقة
        for ($i = 0; $i < 3; $i++) {
            AmlRuleEvaluation::create([
                'transaction_ulid' => 'TX_' . $i,
                'transaction_type' => 'send_money',
                'actor_user_id' => $this->user->id,
                'amount' => '100',
                'rule_id' => $rule->id,
                'rule_code' => $rule->code,
                'matched' => false,
                'contributed_risk_score' => 0,
                'created_at' => now()->subSeconds(30),
            ]);
        }

        // الـ 4th transaction → velocity (current + 3 سابقة = 4 > 3)
        $decision = $this->service->screen($this->makeContext(['amount' => '100']));

        $this->assertEquals('hold', $decision->finalAction);
        $this->assertEquals(50, $decision->totalRiskScore);
    }

    /** @test */
    public function flags_off_hours_large_transaction()
    {
        $this->createRule([
            'rule_type' => 'off_hours',
            'parameters' => ['start_hour' => 2, 'end_hour' => 5, 'min_amount' => '1000'],
            'action_on_match' => 'flag',
            'risk_score_contribution' => 30,
        ]);

        // 3 AM, مبلغ كبير
        $threeAM = Carbon::create(2026, 5, 18, 3, 30, 0);
        $decision = $this->service->screen($this->makeContext([
            'amount' => '2000',
            'timestamp' => $threeAM,
        ]));

        $this->assertEquals('flag', $decision->finalAction);
    }

    /** @test */
    public function does_not_flag_off_hours_small_amount()
    {
        $this->createRule([
            'rule_type' => 'off_hours',
            'parameters' => ['start_hour' => 2, 'end_hour' => 5, 'min_amount' => '1000'],
            'action_on_match' => 'flag',
        ]);

        $threeAM = Carbon::create(2026, 5, 18, 3, 0, 0);
        $decision = $this->service->screen($this->makeContext([
            'amount' => '50', // تحت min_amount
            'timestamp' => $threeAM,
        ]));

        $this->assertEquals('allow', $decision->finalAction);
    }

    /** @test */
    public function holds_new_account_high_value()
    {
        $newUser = User::factory()->create(['created_at' => now()->subDays(3)]);

        $this->createRule([
            'rule_type' => 'new_account_high_value',
            'parameters' => ['max_account_age_days' => 7, 'min_amount' => '3000'],
            'action_on_match' => 'hold',
            'risk_score_contribution' => 50,
        ]);

        $decision = $this->service->screen($this->makeContext([
            'actor_user_id' => $newUser->id,
            'amount' => '5000',
        ]));

        $this->assertEquals('hold', $decision->finalAction);
    }

    /** @test */
    public function does_not_match_old_account_high_value()
    {
        // user الافتراضي مر عليه 6 شهور
        $this->createRule([
            'rule_type' => 'new_account_high_value',
            'parameters' => ['max_account_age_days' => 7, 'min_amount' => '3000'],
            'action_on_match' => 'hold',
        ]);

        $decision = $this->service->screen($this->makeContext(['amount' => '5000']));

        $this->assertEquals('allow', $decision->finalAction);
    }

    /** @test */
    public function block_takes_priority_over_flag()
    {
        // قاعدة flag
        $this->createRule([
            'rule_type' => 'max_single_transaction',
            'parameters' => ['threshold_amount' => '1000'],
            'action_on_match' => 'flag',
            'risk_score_contribution' => 20,
        ]);
        // قاعدة block
        $this->createRule([
            'rule_type' => 'max_single_transaction',
            'parameters' => ['threshold_amount' => '5000'],
            'action_on_match' => 'block',
            'risk_score_contribution' => 100,
            'code' => 'MAX_BLOCK',
        ]);

        // معاملة 6000 → كلاهما match، block يفوز
        $decision = $this->service->screen($this->makeContext(['amount' => '6000']));

        $this->assertEquals('block', $decision->finalAction);
        $this->assertEquals(120, $decision->totalRiskScore); // 20 + 100
        $this->assertCount(2, $decision->triggeredRules);
    }

    /** @test */
    public function creates_flagged_transaction_for_non_allow_decisions()
    {
        $this->createRule([
            'rule_type' => 'max_single_transaction',
            'parameters' => ['threshold_amount' => '1000'],
            'action_on_match' => 'hold',
        ]);

        $ulid = \Str::ulid()->toString();
        $this->service->screen($this->makeContext([
            'amount' => '5000',
            'transaction_ulid' => $ulid,
        ]));

        $this->assertEquals(1, AmlFlaggedTransaction::count());
        $flag = AmlFlaggedTransaction::first();
        $this->assertEquals($ulid, $flag->transaction_ulid);
        $this->assertEquals('hold', $flag->initial_decision);
        $this->assertEquals('pending_review', $flag->current_status);
    }

    /** @test */
    public function flag_action_marks_auto_resolved_and_executed()
    {
        $this->createRule([
            'rule_type' => 'max_single_transaction',
            'parameters' => ['threshold_amount' => '1000'],
            'action_on_match' => 'flag',
        ]);

        $this->service->screen($this->makeContext(['amount' => '2000']));

        $flag = AmlFlaggedTransaction::first();
        $this->assertEquals('auto_resolved', $flag->current_status);
        $this->assertTrue($flag->transaction_executed);
    }

    /** @test */
    public function whitelist_does_not_bypass_a_hard_aml_rule()
    {
        AmlUserRiskProfile::create([
            'user_id' => $this->user->id,
            'current_risk_score' => 0,
            'risk_level' => 'low',
            'manual_override' => 'whitelist',
            'override_reason' => 'Trusted business',
        ]);

        $this->createRule([
            'rule_type' => 'max_single_transaction',
            'parameters' => ['threshold_amount' => '100'],
            'action_on_match' => 'block',
        ]);

        $decision = $this->service->screen($this->makeContext(['amount' => '50000']));

        $this->assertEquals('block', $decision->finalAction);
        $this->assertDatabaseHas('aml_flagged_transactions', [
            'transaction_type' => 'send_money', 'initial_decision' => 'block',
        ]);
    }

    /** @test */
    public function blacklist_blocks_immediately()
    {
        AmlUserRiskProfile::create([
            'user_id' => $this->user->id,
            'current_risk_score' => 100,
            'risk_level' => 'critical',
            'manual_override' => 'blacklist',
            'override_reason' => 'Confirmed fraud',
        ]);

        $decision = $this->service->screen($this->makeContext(['amount' => '10']));

        $this->assertEquals('block', $decision->finalAction);
        $this->assertEquals(100, $decision->totalRiskScore);
    }

    /** @test */
    public function inactive_rules_are_skipped()
    {
        $this->createRule([
            'rule_type' => 'max_single_transaction',
            'parameters' => ['threshold_amount' => '1000'],
            'action_on_match' => 'block',
            'is_active' => false,
        ]);

        $decision = $this->service->screen($this->makeContext(['amount' => '5000']));
        $this->assertEquals('allow', $decision->finalAction);
    }

    /** @test */
    public function rule_applies_to_filtering_works()
    {
        // قاعدة تنطبق فقط على bill_pay
        $this->createRule([
            'rule_type' => 'max_single_transaction',
            'parameters' => ['threshold_amount' => '100'],
            'action_on_match' => 'block',
            'applies_to' => 'bill_pay',
        ]);

        // معاملة send_money → لا match
        //
        // ══════════════════════════════════════════════════════════════
        // **AMIAL-AML-COVERAGE-002 — والنتيجةُ صارت `hold` لا `allow`.**
        //
        // والمقصدُ المُختبَرُ لم يتغيّر: قاعدةُ `bill_pay` **لا تُطابق**
        // `send_money`. والدليلُ عليه أدقُّ من قبل: الحكمُ الآن
        // `AML_COVERAGE_GAP` — أي أنّ المحرّكَ لم يجد قاعدةً تغطّي النوع،
        // وهو نفسُه إثباتُ أنّ المرشِّح رفض القاعدة.
        //
        // وكان `allow` يُرمّز السلوكَ القديم: **نوعٌ مراقَبٌ بلا تغطيةٍ
        // يمرّ**. وهو ما أُصلح — فالتأكيدُ يتبع السياسةَ الجديدة.
        $decision = $this->service->screen($this->makeContext([
            'amount' => '5000',
            'transaction_type' => 'send_money',
        ]));
        $this->assertEquals('hold', $decision->finalAction);
        $this->assertSame('AML_COVERAGE_GAP', $decision->triggeredRules[0]['code'],
            'حُجزت لمطابقةِ قاعدةٍ لا لفجوةِ تغطية — **فالمرشِّحُ لم يرفض قاعدةَ bill_pay**');

        // معاملة bill_pay → block
        $decision = $this->service->screen($this->makeContext([
            'amount' => '500',
            'transaction_type' => 'bill_pay',
        ]));
        $this->assertEquals('block', $decision->finalAction);
    }

    /** @test */
    public function profile_is_updated_on_each_screen()
    {
        $this->createRule([
            'rule_type' => 'max_single_transaction',
            'parameters' => ['threshold_amount' => '1000'],
            'action_on_match' => 'flag',
            'risk_score_contribution' => 20,
        ]);

        $this->service->screen($this->makeContext(['amount' => '500'])); // allow
        $this->service->screen($this->makeContext(['amount' => '2000'])); // flag
        $this->service->screen($this->makeContext(['amount' => '3000'])); // flag

        $profile = AmlUserRiskProfile::find($this->user->id);
        $this->assertEquals(3, $profile->total_transactions);
        $this->assertEquals(2, $profile->total_flagged);
        $this->assertGreaterThan(0, (float)$profile->current_risk_score);
    }

    /** @test */
    public function evaluations_are_recorded()
    {
        $rule = $this->createRule([
            'rule_type' => 'max_single_transaction',
            'parameters' => ['threshold_amount' => '1000'],
            'action_on_match' => 'flag',
        ]);

        $this->service->screen($this->makeContext(['amount' => '5000']));

        $this->assertEquals(1, AmlRuleEvaluation::count());
        $eval = AmlRuleEvaluation::first();
        $this->assertTrue($eval->matched);
        $this->assertEquals($rule->id, $eval->rule_id);
    }
}
