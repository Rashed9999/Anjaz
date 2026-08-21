<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\Ledger\LedgerJournalEntry;
use App\Models\ReconciliationCase;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExternalAdjustmentControlTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function an_external_adjustment_is_rejected_without_an_approved_case(): void
    {
        $walletOwner = User::factory()->create();
        EMoney::create(['user_id' => $walletOwner->id, 'current_balance' => '125.0000']);

        try {
            app(LedgerService::class)->reconcileWalletBalance($walletOwner->id, 'فرق غير موثق');
            $this->fail('يجب رفض التسوية من دون قضية ومراجع مستقل.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('requires case', $e->getMessage());
        }

        $this->assertSame(0, LedgerJournalEntry::where('source_type', 'external_adjustment')->count());
    }

    /** @test */
    public function an_approved_case_creates_one_balanced_entry_and_links_it_back(): void
    {
        $walletOwner = User::factory()->create();
        $maker = User::factory()->create();
        $checker = User::factory()->create();
        EMoney::create(['user_id' => $walletOwner->id, 'current_balance' => '125.0000']);

        $case = ReconciliationCase::create([
            'case_ulid' => (string) \Illuminate\Support\Str::ulid(),
            'case_type' => 'wallet',
            'source' => 'test',
            'subject_user_id' => $walletOwner->id,
            'expected_amount' => '125.0000',
            'actual_amount' => '0.0000',
            'difference' => '125.0000',
            'currency' => 'YER',
            'status' => 'pending_approval',
            'severity' => 'high',
            'first_detected_at' => now(),
            'last_detected_at' => now(),
            'detection_count' => 1,
            'maker_admin_id' => $maker->id,
            'checker_admin_id' => $checker->id,
        ]);

        $entry = app(LedgerService::class)->reconcileWalletBalance(
            $walletOwner->id,
            'رصيد افتتاحي موثق',
            [
                'case_ulid' => $case->case_ulid,
                'maker_admin_id' => $maker->id,
                'checker_admin_id' => $checker->id,
                'approval_note' => 'تمت مراجعة مصدر الرصيد والموافقة على قيد افتتاحي.',
            ],
        );

        $this->assertNotNull($entry);
        $this->assertSame('external_adjustment', $entry->source_type);
        $this->assertSame($case->case_ulid, $entry->source_id);

        $case->refresh();
        $this->assertSame('corrected', $case->status);
        $this->assertSame($entry->id, $case->resolution_journal_entry_id);
        $this->assertSame(2, $entry->lines()->count());
        $this->assertSame('0.0000', (string) $entry->lines()
            ->selectRaw("SUM(CASE WHEN direction = 'debit' THEN amount ELSE -amount END) AS net")
            ->value('net'));
    }

    /** @test */
    public function a_maker_cannot_approve_their_own_external_adjustment(): void
    {
        $walletOwner = User::factory()->create();
        $operator = User::factory()->create();
        EMoney::create(['user_id' => $walletOwner->id, 'current_balance' => '25.0000']);

        $case = ReconciliationCase::create([
            'case_ulid' => (string) \Illuminate\Support\Str::ulid(),
            'case_type' => 'wallet',
            'source' => 'test',
            'subject_user_id' => $walletOwner->id,
            'expected_amount' => '25.0000',
            'actual_amount' => '0.0000',
            'difference' => '25.0000',
            'currency' => 'YER',
            'status' => 'pending_approval',
            'severity' => 'high',
            'first_detected_at' => now(),
            'last_detected_at' => now(),
            'detection_count' => 1,
            'maker_admin_id' => $operator->id,
            'checker_admin_id' => $operator->id,
        ]);

        $this->expectException(\RuntimeException::class);
        app(LedgerService::class)->reconcileWalletBalance($walletOwner->id, 'محاولة ذاتية', [
            'case_ulid' => $case->case_ulid,
            'maker_admin_id' => $operator->id,
            'checker_admin_id' => $operator->id,
            'approval_note' => 'لا يجب قبول هذه الموافقة.',
        ]);
    }
}
