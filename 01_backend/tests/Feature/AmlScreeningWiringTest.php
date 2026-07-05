<?php

namespace Tests\Feature;

use App\Exceptions\AmlBlockedException;
use App\Exceptions\AmlHeldException;
use App\Models\Aml\AmlRule;
use App\Models\EMoney;
use App\Models\User;
use App\Traits\TransactionTrait;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-AUDIT-FIX-001 — التحقق أن محرّك AML موصولٌ فعلاً بمسار التحويل.
 *
 * قبل الإصلاح: كان المحرّك يتيماً وغير قابل للتشغيل (صنف AmlDecision مفقود
 * لمخالفة PSR-4). الآن: يُفحص كل تحويل قبل تحريك المال، block/hold يوقفان
 * قبل أي خصم ويُسجَّلان، shadow يراقب دون إيقاف، flag ينفّذ ويُسجَّل.
 */
class AmlScreeningWiringTest extends TestCase
{
    use RefreshDatabase;

    /** كائن يستخدم TransactionTrait لاستدعاء التحويل مباشرةً. */
    private object $tx;
    private User $sender;
    private User $recipient;

    protected function setUp(): void
    {
        parent::setUp();
        config(['amial.aml.enabled' => true]);

        $this->tx = new class {
            use TransactionTrait;
        };

        $this->sender = User::factory()->create(['type' => 2, 'zone_code' => 'SOUTH', 'phone' => '967771700001']);
        $this->recipient = User::factory()->create(['type' => 2, 'zone_code' => 'SOUTH', 'phone' => '967771700002']);

        foreach ([$this->sender, $this->recipient] as $u) {
            EMoney::create([
                'user_id' => $u->id, 'current_balance' => '100000.0000',
                'charge_earned' => '0', 'pending_balance' => '0', 'held_balance' => '0',
                'zone_code' => 'SOUTH', 'version' => 0,
            ]);
        }
    }

    private function rule(string $action, string $threshold, bool $shadow = false): void
    {
        AmlRule::create([
            'code' => 'TEST_MAX_' . strtoupper($action),
            'name_ar' => 'اختبار حد', 'description_ar' => 'x',
            'rule_type' => 'max_single_transaction',
            'applies_to' => 'send_money',
            'parameters' => ['threshold_amount' => $threshold],
            'action_on_match' => $action,
            'risk_score_contribution' => 90,
            'priority' => 10, 'is_active' => true, 'shadow_mode' => $shadow,
        ]);
    }

    private function send(string $amount): ?string
    {
        return $this->tx->customer_send_money_transaction(
            $this->sender->id, $this->recipient->id, $amount, '0'
        );
    }

    public function test_no_rules_allows_transaction(): void
    {
        $id = $this->send('5000');
        $this->assertNotNull($id);
        $this->assertSame('95000.0000', (string) EMoney::where('user_id', $this->sender->id)->value('current_balance'));
    }

    public function test_active_block_rule_stops_transaction_and_records_flag(): void
    {
        $this->rule('block', '10000');

        try {
            $this->send('20000'); // فوق الحد
            $this->fail('توقّعنا AmlBlockedException');
        } catch (AmlBlockedException $e) {
            $this->assertTrue($e->decision->isBlocked());
        }

        // لم يُخصم أي مال (block قبل التحويل)
        $this->assertSame('100000.0000', (string) EMoney::where('user_id', $this->sender->id)->value('current_balance'));
        // سُجِّلت المعاملة المشبوهة رغم عدم التنفيذ (لم تُلغَ مع rollback)
        $this->assertDatabaseHas('aml_flagged_transactions', [
            'actor_user_id' => $this->sender->id,
            'initial_decision' => 'block',
        ]);
    }

    public function test_active_hold_rule_holds_transaction(): void
    {
        $this->rule('hold', '10000');

        $this->expectException(AmlHeldException::class);
        try {
            $this->send('15000');
        } finally {
            // لا خصم
            $this->assertSame('100000.0000', (string) EMoney::where('user_id', $this->sender->id)->value('current_balance'));
        }
    }

    public function test_shadow_rule_never_blocks(): void
    {
        $this->rule('block', '10000', shadow: true); // نفس قاعدة الحظر لكن في وضع الظل

        $id = $this->send('20000'); // فوق الحد لكن shadow → يمرّ
        $this->assertNotNull($id);
        $this->assertSame('80000.0000', (string) EMoney::where('user_id', $this->sender->id)->value('current_balance'));

        // ومع ذلك سُجِّل قرار الظل (ماذا كان سيحدث)
        $this->assertDatabaseHas('aml_shadow_decisions', [
            'user_id' => $this->sender->id,
            'would_be_action' => 'block',
        ]);
    }

    public function test_flag_rule_executes_but_records(): void
    {
        $this->rule('flag', '10000');

        $id = $this->send('20000'); // flag → ينفّذ المال لكن يُسجَّل
        $this->assertNotNull($id);
        $this->assertSame('80000.0000', (string) EMoney::where('user_id', $this->sender->id)->value('current_balance'));
        $this->assertDatabaseHas('aml_flagged_transactions', [
            'actor_user_id' => $this->sender->id,
            'initial_decision' => 'flag',
        ]);
    }

    public function test_disabled_flag_skips_screening_entirely(): void
    {
        config(['amial.aml.enabled' => false]);
        $this->rule('block', '10000');

        $id = $this->send('20000'); // المحرّك معطّل → يمرّ رغم قاعدة الحظر
        $this->assertNotNull($id);
        $this->assertDatabaseMissing('aml_flagged_transactions', ['actor_user_id' => $this->sender->id]);
    }
}
