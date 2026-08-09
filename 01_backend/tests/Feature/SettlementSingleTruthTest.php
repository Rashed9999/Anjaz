<?php

namespace Tests\Feature;

use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentShift;
use App\Models\Agent\AgentStaff;
use App\Models\User;
use App\Services\AgentDailySettlementService;
use App\Services\AgentSettlementEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-TRUTH-001 — رقمُ العجز والفائض: مصدرٌ واحد لا اثنان.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما كُشف بتدقيق `amial-financial-truth`:**
 *
 * خدمتان تحسبان عجزَ يومِ الوكيل وفائضَه، **بالكتلة نفسِها حرفاً بحرف**:
 *
 *   AgentSettlementEngine::dailySettlement()
 *   AgentDailySettlementService::computeDay()
 *
 * وكلتاهما تُقرأ في لوحةٍ إداريّةٍ واحدة (`AgentSupervisionController`):
 * الأولى في «ملفّ الوكيل»، والثانية في «لوحة اليوم».
 *
 * **والمهارةُ تمنع هذا صراحةً**: «Do not duplicate settlement
 * calculations». والسببُ ليس جمالَ الشيفرة — هو أنّ نسختين تفترقان عند
 * أوّل تعديل، **فيرى المشرفُ عجزاً في شاشةٍ وعجزاً آخرَ في شاشة**، ولا
 * يعرف أيّهما الحقّ.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وهذا الاختبارُ لا يقرأ الشيفرة — يُشغّل الاثنين على البيانات نفسِها
 * ويقارن.** فتطابقُ النصّ لا يعني تطابقَ النتيجة، واختلافُه لا يعني
 * افتراقَها. الرقمُ يُقاس.
 *
 * وهو **عقدٌ حيّ**: يبقى ساقطاً إن تغيّرت إحداهما وحدَها غداً.
 */
class SettlementSingleTruthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * وكيلٌ بفرعٍ وثلاثِ ورديّاتٍ مغلقة: عجزان وفائض.
     *
     * @return array{0:User,1:string}
     */
    private function agentWithShifts(): array
    {
        $agent = User::factory()->create(['role' => 'agent']);

        $branch = AgentBranch::create([
            'agent_user_id' => $agent->id,
            'branch_user_id' => $agent->id,
            'name' => 'فرع المكلا',
            'code' => 'BR-' . $agent->id,
            'is_active' => true,
        ]);

        $staff = AgentStaff::create([
            'agent_user_id' => $agent->id,
            'branch_id' => $branch->id,
            'username' => 'teller' . $agent->id,
            'name' => 'صرّاف',
            'phone' => '7770' . str_pad((string) $agent->id, 5, '0', STR_PAD_LEFT),
            'password' => bcrypt('x'),
            'role' => 'teller',
            'is_active' => true,
        ]);

        $date = now()->toDateString();

        foreach (['-250.5000', '-1000.0000', '75.2500'] as $i => $variance) {
            AgentShift::create([
                'branch_id' => $branch->id,
                'staff_id' => $staff->id,
                'opened_at' => $date . sprintf(' %02d:00:00', $i + 8),
                'closed_at' => $date . sprintf(' %02d:00:00', $i + 12),
                'opening_float' => '0',
                'cash_on_hand' => '0',
                'counted_cash' => '0',
                'variance' => $variance,
                'status' => AgentShift::STATUS_CLOSED,
            ]);
        }

        return [$agent, $date];
    }

    /**
     * @test
     *
     * **الرقمان متطابقان — أو أحدُهما يكذب على مشرفٍ ما.**
     *
     * ══════════════════════════════════════════════════════════════════
     * ولا يُقاس التطابقُ بالصدفة: البياناتُ فيها عجزان مختلفان وفائضٌ
     * واحد، فلو أخطأت إحداهما في الإشارة أو في الجمع المطلق لظهر الفرق.
     */
    public function both_engines_report_the_same_shortage_and_overage(): void
    {
        [$agent, $date] = $this->agentWithShifts();

        $a = app(AgentSettlementEngine::class)->dailySettlement($agent, $date);
        $b = app(AgentDailySettlementService::class)->computeDay($agent, $date);

        foreach (['shortage_count', 'shortage_total',
                  'overage_count', 'overage_total'] as $k) {
            $this->assertSame(
                (string) $a[$k],
                (string) $b[$k],
                sprintf(
                    "«%s» يختلف بين محرّكين يُقرآن في لوحةٍ واحدة:\n"
                    . "  AgentSettlementEngine::dailySettlement  = %s\n"
                    . "  AgentDailySettlementService::computeDay = %s\n"
                    . "فيرى المشرفُ رقماً في «ملفّ الوكيل» وآخرَ في «لوحة اليوم»،\n"
                    . 'ولا يعرف أيّهما الحقّ.',
                    $k, (string) $a[$k], (string) $b[$k],
                ),
            );
        }

        // **ويُتأكَّد أنّ الرقمَ ليس صفراً في الاثنين.**
        // فاختبارٌ يقارن صفراً بصفرٍ يمرّ أبداً ولا يحرس شيئاً.
        $this->assertSame(2, (int) $a['shortage_count'],
            'البياناتُ لم تُبنَ كما يُفترض — الفحصُ يقارن فراغاً بفراغ');

        $this->assertSame('1250.5000', $a['shortage_total'],
            'مجموعُ العجز خاطئ — القيمةُ المطلقة لا تُجمَع كما يجب');
    }

    /**
     * @test
     *
     * **وحدُّ اليوم واحد — فورديّةٌ تُحسب هنا وتسقط هناك فرقٌ صامت.**
     *
     * ══════════════════════════════════════════════════════════════════
     * الأولى تكتب `'23:59:59'` نصّاً، والثانية `endOfDay()` — أي
     * `23:59:59.999999`. **فورديّةٌ فُتحت في تلك الجزئيّة من الثانية
     * تُحسب في واحدةٍ ولا تُحسب في الأخرى.**
     *
     * وهو فرقٌ لا يقع كلَّ يوم — **وهذا ما يجعله خطيراً**: يظهر مرّةً في
     * الشهر فيُظنّ خطأَ إدخالٍ لا خطأَ نظام.
     */
    public function the_day_boundary_is_identical_in_both(): void
    {
        [$agent, $date] = $this->agentWithShifts();

        $branchId = AgentBranch::where('agent_user_id', $agent->id)->value('id');
        $staffId = AgentStaff::where('agent_user_id', $agent->id)->value('id');

        // ورديّةٌ على حافّة اليوم بالضبط.
        AgentShift::create([
            'branch_id' => $branchId,
            'staff_id' => $staffId,
            'opened_at' => $date . ' 23:59:59',
            'closed_at' => now()->addDay()->toDateString() . ' 02:00:00',
            'opening_float' => '0',
            'cash_on_hand' => '0',
            'counted_cash' => '0',
            'variance' => '-500.0000',
            'status' => AgentShift::STATUS_CLOSED,
        ]);

        $a = app(AgentSettlementEngine::class)->dailySettlement($agent, $date);
        $b = app(AgentDailySettlementService::class)->computeDay($agent, $date);

        $this->assertSame(
            (string) $a['shortage_total'],
            (string) $b['shortage_total'],
            "ورديّةُ حافّةِ اليوم تُحسب في محرّكٍ وتسقط من الآخر:\n"
            . "  dailySettlement = {$a['shortage_total']}\n"
            . "  computeDay      = {$b['shortage_total']}\n"
            . 'فرقٌ يظهر مرّةً في الشهر فيُظنّ خطأَ إدخالٍ لا خطأَ نظام.',
        );
    }
}
