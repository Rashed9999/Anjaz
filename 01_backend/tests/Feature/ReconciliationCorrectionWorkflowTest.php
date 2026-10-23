<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\ReconciliationCase;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\Reconciliation\ReconciliationCorrectionWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReconciliationCorrectionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function walletCase(User $owner): ReconciliationCase
    {
        return ReconciliationCase::create([
            'case_ulid' => (string) Str::ulid(),
            'case_type' => 'wallet',
            'source' => 'test',
            'subject_user_id' => $owner->id,
            'expected_amount' => '125.0000',
            'actual_amount' => '0.0000',
            'difference' => '125.0000',
            'currency' => 'YER',
            'status' => 'detected',
            'severity' => 'critical',
            'first_detected_at' => now(),
            'last_detected_at' => now(),
            'detection_count' => 3,
        ]);
    }

    /** @test */
    public function proposal_records_evidence_but_cannot_move_money(): void
    {
        $owner = User::factory()->create();
        $maker = User::factory()->create();
        EMoney::create(['user_id' => $owner->id, 'current_balance' => '0.0000']);
        DB::table('e_money')->where('user_id', $owner->id)->update(['current_balance' => '125.0000']);
        $case = $this->walletCase($owner);

        app(ReconciliationCorrectionWorkflow::class)->propose(
            $case->case_ulid,
            $maker->id,
            'حركات تاريخية سبقت تفعيل ترحيل الدفتر الإلزامي.',
            'راجع تقرير المصالحة والتسوية المصدرية للمعاملات التاريخية.'
        );

        $case->refresh();
        $this->assertSame('pending_approval', $case->status);
        $this->assertSame($maker->id, $case->maker_admin_id);
        $this->assertNull($case->checker_admin_id);
        $this->assertSame(0, DB::table('ledger_journal_entries')
            ->where('source_type', 'external_adjustment')->count(),
            'مجرد الاقتراح يجب ألا ينشئ حركة مالية.');
    }

    /** @test */
    public function a_different_checker_posts_one_balanced_adjustment(): void
    {
        $owner = User::factory()->create();
        $maker = User::factory()->create();
        $checker = User::factory()->create();
        EMoney::create(['user_id' => $owner->id, 'current_balance' => '0.0000']);
        DB::table('e_money')->where('user_id', $owner->id)->update(['current_balance' => '125.0000']);
        $case = $this->walletCase($owner);
        $workflow = app(ReconciliationCorrectionWorkflow::class);

        $workflow->propose(
            $case->case_ulid,
            $maker->id,
            'حركات تاريخية سبقت تفعيل ترحيل الدفتر الإلزامي.',
            'راجع تقرير المصالحة والتسوية المصدرية للمعاملات التاريخية.'
        );
        $entry = $workflow->approve(
            $case->case_ulid,
            $checker->id,
            'راجعت السبب والأدلة، ولست مقدم الاقتراح، وأعتمد القيد المتوازن.',
            app(LedgerService::class),
        );

        $case->refresh();
        $this->assertNotNull($entry);
        $this->assertSame('corrected', $case->status);
        $this->assertSame($checker->id, $case->checker_admin_id);
        $this->assertNotNull($case->resolved_at);
        $this->assertSame(2, $entry->lines()->count());
    }

    /** @test */
    public function maker_cannot_approve_their_own_proposal(): void
    {
        $owner = User::factory()->create();
        $maker = User::factory()->create();
        EMoney::create(['user_id' => $owner->id, 'current_balance' => '0.0000']);
        $case = $this->walletCase($owner);
        $workflow = app(ReconciliationCorrectionWorkflow::class);

        $workflow->propose(
            $case->case_ulid,
            $maker->id,
            'حركات تاريخية سبقت تفعيل ترحيل الدفتر الإلزامي.',
            'راجع تقرير المصالحة والتسوية المصدرية للمعاملات التاريخية.'
        );

        $this->expectException(ValidationException::class);
        $workflow->approve(
            $case->case_ulid,
            $maker->id,
            'لا يجوز أن يعتمد مقدم الاقتراح قراره بنفسه.',
            app(LedgerService::class),
        );
    }
}
