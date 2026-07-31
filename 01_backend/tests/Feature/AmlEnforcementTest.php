<?php

namespace Tests\Feature;

use App\Exceptions\AmlBlockedException;
use App\Models\Aml\AmlRule;
use App\Models\EMoney;
use App\Models\User;
use App\Traits\TransactionTrait;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-AML-ENFORCE-001 — القاعدة المنفَّذة تمنع فعلاً، والمراقِبة لا تمنع.
 *
 * **ما كان:** كل قواعد AML تُبذر في `shadow_mode = true` — تُقيَّم وتُسجَّل ولا
 * توقف شيئاً. ومن ضمنها `MAX_SINGLE_TX_HARD`: الحدّ الأقصى المطلق للمعاملة.
 *
 * أي أن المنصّة كانت **بلا حدٍّ أقصى إطلاقاً**: تمرّ معاملةٌ بمليون ويُسجَّل في
 * `aml_shadow_decisions` أنها «كانت ستُمنع». وهذا أسوأ من غياب القاعدة —
 * يراها المشغّل في الشاشة فيظنّ الحدّ قائماً.
 *
 * **والتمييز الذي بُني عليه القرار:** «الإطلاق الآمن» (observe ← enforce) صحيحٌ
 * للقواعد التي قد تُخطئ — السرعة والتوقيت والحساب الجديد كلّها تقديرات، وإيقاف
 * بريءٍ بها أسوأ من تمريره. أمّا الحدّ الأقصى فليس تقديراً بل سياسة.
 *
 * ويُختبر الاتّجاهان: أن المنفَّذة تمنع، **وأن المراقِبة لا تمنع**. فقاعدةٌ
 * ظنّها المشغّل مراقِبةً وهي تمنع تُوقف عمل الناس بلا أن يعرف أحد لماذا.
 */
class AmlEnforcementTest extends TestCase
{
    use RefreshDatabase;
    use TransactionTrait;

    protected function setUp(): void
    {
        parent::setUp();
        config(['amial.aml.enabled' => true]);
    }

    private function makeUser(string $balance = '5000000'): User
    {
        $u = User::factory()->create(['zone_code' => 'SOUTH']);
        EMoney::updateOrCreate(['user_id' => $u->id],
            ['current_balance' => $balance, 'zone_code' => 'SOUTH']);

        return $u;
    }

    private function hardCap(string $threshold, bool $shadow): AmlRule
    {
        return AmlRule::updateOrCreate(
            ['code' => 'MAX_SINGLE_TX_HARD'],
            [
                'name_ar' => 'الحد الأقصى للمعاملة الواحدة (صلب)',
                'description_ar' => 'اختبار',
                'rule_type' => 'max_single_transaction',
                'applies_to' => 'send_money',
                'parameters' => ['threshold_amount' => $threshold],
                'action_on_match' => 'block',
                'risk_score_contribution' => 100,
                'priority' => 10,
                'is_active' => true,
                'shadow_mode' => $shadow,
            ],
        );
    }

    private function transfer(User $from, User $to, string $amount): void
    {
        $this->customer_send_money_transaction(
            from_user_id: $from->id, to_user_id: $to->id,
            amount: $amount, charge: '0', note: 'aml',
        );
    }

    // ── الاتّجاه الأوّل: المنفَّذة تمنع ─────────────────────────────────

    public function test_an_enforcing_hard_cap_actually_blocks_the_transfer(): void
    {
        $this->hardCap('50000', shadow: false);
        $from = $this->makeUser();
        $to = $this->makeUser('0');

        $this->expectException(AmlBlockedException::class);

        $this->transfer($from, $to, '60000');
    }

    public function test_the_money_does_not_move_when_the_cap_blocks(): void
    {
        // منعٌ يرمي استثناءً ثم يترك المال قد تحرّك ليس منعاً. والفحص هنا
        // على الرصيد لا على الاستثناء: الاستثناء يُقال، والرصيد يُقاس.
        $this->hardCap('50000', shadow: false);
        $from = $this->makeUser('5000000');
        $to = $this->makeUser('0');

        try {
            $this->transfer($from, $to, '60000');
        } catch (AmlBlockedException) {
            // متوقَّع
        }

        $this->assertSame(0, bccomp(
            (string) EMoney::where('user_id', $from->id)->value('current_balance'),
            '5000000', 4,
        ), 'خرج المال رغم المنع');

        $this->assertSame(0, bccomp(
            (string) EMoney::where('user_id', $to->id)->value('current_balance'),
            '0', 4,
        ), 'وصل المال إلى المستلم رغم المنع');
    }

    // ── الاتّجاه الثاني: المراقِبة لا تمنع ──────────────────────────────

    public function test_a_shadow_rule_does_not_block_anything(): void
    {
        // الحدّ يُختبر من جهتيه. قاعدةٌ ظنّها المشغّل مراقِبةً وهي تمنع تُوقف
        // عمل الناس بلا أن يعرف أحد لماذا — وهو عطلٌ أصعب تشخيصاً من عكسه.
        $this->hardCap('50000', shadow: true);
        $from = $this->makeUser('5000000');
        $to = $this->makeUser('0');

        $this->transfer($from, $to, '60000');

        $this->assertSame(0, bccomp(
            (string) EMoney::where('user_id', $to->id)->value('current_balance'),
            '60000', 4,
        ), 'مُنع التحويل رغم أن القاعدة في وضع المراقبة');
    }

    // ── تحت الحدّ يمرّ في الحالتين ─────────────────────────────────────

    public function test_a_transfer_under_the_cap_passes_when_enforcing(): void
    {
        // ضابطٌ يمنع الجميع يوقف العمل لا يحرسه.
        $this->hardCap('50000', shadow: false);
        $from = $this->makeUser('5000000');
        $to = $this->makeUser('0');

        $this->transfer($from, $to, '1000');

        $this->assertSame(0, bccomp(
            (string) EMoney::where('user_id', $to->id)->value('current_balance'),
            '1000', 4,
        ), 'مُنع تحويلٌ تحت الحدّ — القاعدة تمنع الجميع');
    }

    // ── البذر: ما الذي يُشحن افتراضياً ─────────────────────────────────

    public function test_the_seeder_ships_the_hard_cap_enforcing(): void
    {
        // هذا هو العطل الأصليّ بعينه: القاعدة موجودة ونشطة وفي الظلّ، فيرى
        // المشغّل حدّاً أقصى في الشاشة ولا حدّ في الواقع.
        $this->seed(\Database\Seeders\AmlDefaultRulesSeeder::class);

        $hard = AmlRule::where('code', 'MAX_SINGLE_TX_HARD')->first();

        $this->assertNotNull($hard, 'لم تُبذر قاعدة الحدّ الأقصى');
        $this->assertFalse((bool) $hard->shadow_mode,
            'الحدّ الأقصى المطلق يُشحن في وضع المراقبة — فالمنصّة بلا حدٍّ أقصى، '
            . 'ويُسجَّل في سجلّ الظلّ أن المعاملة «كانت ستُمنع».');
    }

    public function test_the_seeder_keeps_estimating_rules_in_shadow(): void
    {
        // والعكس: قواعد السرعة والتوقيت تقديرات تُخطئ. شحنُها منفَّذةً يوقف
        // أبرياء من اليوم الأوّل قبل أن يُقرأ سطرٌ واحد من بياناتها.
        $this->seed(\Database\Seeders\AmlDefaultRulesSeeder::class);

        foreach (['VELOCITY_5MIN', 'OFF_HOURS_LARGE', 'NEW_ACCOUNT_HIGH_VALUE'] as $code) {
            $rule = AmlRule::where('code', $code)->first();
            if (!$rule) continue;

            $this->assertTrue((bool) $rule->shadow_mode,
                "القاعدة التقديرية {$code} تُشحن منفَّذة — ستوقف أبرياء "
                . 'قبل مراجعة بياناتها');
        }
    }

    public function test_reseeding_does_not_undo_an_operators_decision(): void
    {
        // المشغّل يقرأ سجلّ الظلّ أسابيع ثم يُطفئ الظلّ عن قاعدة. وإعادةُ
        // البذر عند النشر لا يجوز أن تُلغي قراره صامتةً — وإلّا عاد المنع
        // إلى المراقبة ولا أحد يدري.
        $this->seed(\Database\Seeders\AmlDefaultRulesSeeder::class);

        $rule = AmlRule::where('code', 'VELOCITY_5MIN')->first();
        $this->assertNotNull($rule);

        $rule->shadow_mode = false;   // قرار المشغّل
        $rule->save();

        $this->seed(\Database\Seeders\AmlDefaultRulesSeeder::class);

        $this->assertFalse((bool) $rule->fresh()->shadow_mode,
            'أُلغي قرار المشغّل بإعادة البذر — عاد المنع إلى المراقبة صامتاً');
    }
}
