<?php

namespace Tests\Feature;

use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentStaff;
use App\Models\EMoney;
use App\Models\FeeScheme;
use App\Models\Receipt;
use App\Models\User;
use App\Services\AgentCounterService;
use App\Services\AgentDailySettlementService;
use App\Services\AgentReportService;
use App\Services\AgentReportsService;
use App\Services\AgentSettlementEngine;
use App\Services\AgentShiftService;
use App\Services\AgentShiftStatementService;
use App\Services\AgentStaffService;
use App\Services\CustomerWithdrawService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AGT-GAP-001 / 002 / 003 (BUG-01..03) — السحبُ الحيّ برمز العميل وإيداع حصة المنصة.
 *
 * كانت الاختباراتُ القديمةُ تستدعي `AgentCounterService::withdraw()` الميّتة مباشرةً،
 * فمرّت دون أن ترى أنّ المسار الحيّ (`POST /agent/counter/withdrawal`) يُرحِّل
 * `cash_out_executed` بلا فرعٍ ولا رسمٍ ولا عمولة، وأنّ الإيصال يُرفَض بـ403.
 * وهذا الاختبارُ يمرّ من الباب الحيّ نفسِه ويتحقّق من المحرّكات الخمسة والإيصالات.
 */
class AgentLiveWithdrawalLedgerSplitFixTest extends TestCase
{
    use RefreshDatabase;

    private User $company;
    private AgentStaff $hq;
    private AgentBranch $branch;
    private AgentStaff $teller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->agentUser('967773000001');
        $this->hq = app(AgentStaffService::class)->ensureHeadOfficeAccount($this->company, 'hqpass123');
    }

    private function agentUser(string $phone): User
    {
        $u = new User();
        $u->email = 'fixture-' . bin2hex(random_bytes(8)) . '@example.test';
        $u->forceFill([
            'f_name' => 'شركة', 'l_name' => 'صرافة', 'phone' => $phone, 'type' => AGENT_TYPE,
            'password' => Hash::make('secret123'), 'is_kyc_verified' => 1, 'is_active' => 1,
            'zone_code' => 'SOUTH',
        ])->save();
        EMoney::create(['user_id' => $u->id, 'current_balance' => '0']);

        return $u;
    }

    private function makeBranch(string $floatBalance, string $code = 'MKL', string $phone = '967773000099'): array
    {
        $bu = new User();
        $bu->email = 'fixture-' . bin2hex(random_bytes(8)) . '@example.test';
        $bu->forceFill([
            'f_name' => 'فرع', 'l_name' => $code, 'type' => AGENT_TYPE,
            'phone' => $phone, 'password' => Hash::make('secret123'),
            'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ])->save();
        EMoney::create(['user_id' => $bu->id, 'current_balance' => $floatBalance]);

        DB::table('agent_profiles')->insert([
            'user_id' => $bu->id, 'parent_agent_id' => $this->company->id, 'agent_level' => 2,
            'business_name' => 'فرع ' . $code, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        config()->set('amial.branch_working_hours.timezone', 'Asia/Riyadh');

        $branch = AgentBranch::create([
            'agent_user_id' => $this->company->id, 'branch_user_id' => $bu->id,
            'name' => 'فرع ' . $code, 'code' => $code, 'city' => 'حضرموت',
            'phone' => $bu->phone, 'is_active' => true,
            'working_hours' => array_fill_keys(['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'], ['open' => '00:00', 'close' => '23:59']),
        ]);
        $branch->till()->create([
            'cash_on_hand' => '3000000', 'max_cash_on_hand' => '9000000', 'min_cash_alert' => '100000',
        ]);
        $branch = $branch->fresh('till');

        $teller = app(AgentStaffService::class)->hire($this->hq, [
            'name' => 'صراف ' . $code, 'role' => AgentStaff::ROLE_TELLER,
            'branch_id' => $branch->id, 'password' => 'teller12',
        ]);

        return [$branch, $teller];
    }

    private function customer(string $balance): User
    {
        $c = User::factory()->create([
            'type' => CUSTOMER_TYPE, 'phone' => '96777300' . random_int(1000, 9999),
            'is_active' => 1, 'is_kyc_verified' => 1,
        ]);
        EMoney::updateOrCreate(['user_id' => $c->id], ['current_balance' => $balance]);

        return $c;
    }

    private function seedFee(string $code, string $appliesTo): void
    {
        FeeScheme::create([
            'code' => $code, 'label' => 'رسم ' . $code, 'fee_type' => 'percent',
            'percent_rate' => '1.00', 'fixed_amount' => '0',
            'agent_commission_percent' => '60', 'agent_commission_fixed' => '0',
            'bearer' => 'sender', 'zone_code' => 'SOUTH', 'applies_to' => $appliesTo,
            'version' => 1, 'is_active' => true,
        ]);
    }

    /** @test */
    public function a_live_code_withdrawal_is_visible_to_receipts_reports_and_the_settlement_engine(): void
    {
        [$this->branch, $this->teller] = $this->makeBranch('0');
        [$otherBranch, $otherTeller] = $this->makeBranch('0', 'SHT', '967773000088');
        $this->seedFee('CASH_OUT', 'customer');
        $shift = app(AgentShiftService::class)->open($this->teller, '500000');

        $customer = $this->customer('400000');
        $req = app(CustomerWithdrawService::class)->request($customer, '100000');

        $this->actingAs($this->teller, 'agent_staff');
        $res = $this->postJson(route('agent.counter.withdrawal.execute'), ['op_code' => $req->op_code]);
        $this->assertSame(200, $res->status(), (string) $res->json('message'));
        $res = $res->json();

        // BUG-02: الإيصال يُفتح للصراف (200) ويُرفض لفرع آخر (403) ويحمل سياق الفرع.
        $number = $res['result']['receipt_number'];
        $this->assertNotEmpty($number);
        $receipt = Receipt::where('receipt_number', $number)->first();
        $this->assertSame('agent_branch', $receipt->reference_type);
        $this->assertSame((int) $this->branch->id, (int) $receipt->reference_id);
        $meta = (array) $receipt->metadata;
        $this->assertSame((int) $this->branch->id, (int) $meta['branch_id']);
        $this->assertSame('MKL', $meta['branch_code']);

        $html = $this->get('/agent/receipt/' . $number)->assertOk()->getContent();
        $this->assertStringContainsString('فرع MKL', $html);
        $this->assertStringContainsString('صراف MKL', $html);

        $this->actingAs($otherTeller, 'agent_staff')
            ->get('/agent/receipt/' . $number)
            ->assertStatus(403);

        // BUG-01: القيد يحمل الفرع والرسم والعمولة ورمز العملية.
        $entry = DB::table('ledger_journal_entries')->where('source_type', 'cash_out_executed')->first();
        $this->assertNotNull($entry);
        $em = json_decode((string) $entry->metadata, true);
        $this->assertSame((int) $this->branch->id, (int) $em['branch_id']);
        $this->assertSame($req->op_code, $em['op_code']);
        $this->assertSame(1, bccomp($em['fee'], '0', 4));
        $this->assertSame(1, bccomp($em['commission'], '0', 4));

        // 1) محرّك التسوية: لا فائض إلكتروني وهمي (difference = 0.0000).
        $pos = app(AgentSettlementEngine::class)->position($this->company);
        $this->assertSame(
            bcadd('100000', $em['commission'], 4),
            $pos['float']['breakdown']['from_withdrawals'],
        );
        $this->assertSame('0.0000', $pos['float']['difference']);

        // 2) تقرير عمولات الفرع.
        $rep = app(AgentReportService::class)->commissions($this->branch);
        $this->assertSame(1, $rep['operations']);
        $this->assertSame(0, bccomp($rep['total_commission'], $em['commission'], 4));
        $this->assertSame(0, bccomp($rep['total_fee'], $em['fee'], 4));

        // 3) التسوية اليومية.
        $today = now()->toDateString();
        $day = app(AgentDailySettlementService::class)->computeDay($this->company, $today);
        $this->assertSame(0, bccomp($day['fees_collected'], $em['fee'], 4));
        $this->assertSame(0, bccomp($day['agent_commission'], $em['commission'], 4));

        // 4) لوحة التقارير التحليلية.
        $reports = app(AgentReportsService::class)->full($this->company, $today, $today);
        $this->assertSame(0, bccomp($reports['summary']['fees']['value'], $em['fee'], 4));
        $this->assertSame(0, bccomp($reports['summary']['commission']['value'], $em['commission'], 4));

        // 5) كشف تسوية الوردية.
        $stmt = app(AgentShiftStatementService::class)->build($shift->fresh());
        $this->assertTrue($stmt['fees']['known']);
        $this->assertSame(0, bccomp($stmt['fees']['collected'], $em['fee'], 4));
        $this->assertSame(0, bccomp($stmt['fees']['agent_commission'], $em['commission'], 4));
        $this->assertSame(
            bcadd('100000', $em['commission'], 4),
            $stmt['emoney']['in_on_withdrawals'],
        );
    }

    /** @test */
    public function a_deposit_is_refused_when_the_branch_float_covers_the_net_but_not_the_platform_share(): void
    {
        // إيداع 10000 برسم 1% = 100 ⇒ صافي 9900، عمولة 60، حصّة منصّة 40 ⇒ المطلوب 9940.
        [$this->branch, $this->teller] = $this->makeBranch('9920');
        $this->seedFee('AGENT_DEPOSIT', 'agent');
        $shift = app(AgentShiftService::class)->open($this->teller, '0');
        $customer = $this->customer('0');

        try {
            app(AgentCounterService::class)->deposit(
                $this->branch, $customer, '10000', $this->branch->account, null, $shift->fresh(),
            );
            $this->fail('الإيداع مرّ رغم أنّ رصيد الفرع لا يغطّي حصّة المنصّة');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('9940.0000', $e->getMessage());
        }

        $this->assertSame('9920.0000', bcadd((string) EMoney::where('user_id', $this->branch->branch_user_id)->value('current_balance'), '0', 4));
    }
}
