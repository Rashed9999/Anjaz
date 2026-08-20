<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\Ledger\LedgerEntryLine;
use App\Models\Ledger\LedgerJournalEntry;
use App\Models\User;
use App\Services\PlatformTreasuryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A treasury top-up is one financial event, not a wallet mutation plus hope. */
class PlatformTreasuryIssuanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['type' => ADMIN_TYPE, 'zone_code' => 'SOUTH']);
        EMoney::create(['user_id' => $this->admin->id, 'current_balance' => '0', 'zone_code' => 'SOUTH']);
    }

    public function test_issuance_posts_wallet_reserve_and_a_balanced_ledger_entry_together(): void
    {
        $issued = app(PlatformTreasuryService::class)->issueAdminFloat(
            '1250.5000', $this->admin, 'TREASURY-TEST-001', 'توريد نقد موثق للاختبار',
        );

        $this->assertFalse($issued['duplicate']);
        $this->assertSame('treasury_issuance', $issued['entry']->source_type);
        $this->assertSame('TREASURY-TEST-001', $issued['entry']->metadata['reference']);
        $this->assertSame('1250.5000', (string) EMoney::where('user_id', $this->admin->id)
            ->value('current_balance'));

        $lines = LedgerEntryLine::where('journal_entry_id', $issued['entry']->id)->get();
        $this->assertCount(2, $lines);
        $this->assertSame('1250.5000', (string) $lines->where('direction', 'debit')->sum('amount'));
        $this->assertSame('1250.5000', (string) $lines->where('direction', 'credit')->sum('amount'));
        $this->assertTrue($lines->contains(fn ($l) => $l->account->account_code === 'TREASURY_CASH_RESERVE'));
        $this->assertTrue($lines->contains(fn ($l) => $l->account->account_code === "USER_WALLET_{$this->admin->id}"));
    }

    public function test_a_retried_reference_cannot_issue_the_same_float_twice(): void
    {
        $service = app(PlatformTreasuryService::class);
        $first = $service->issueAdminFloat('700', $this->admin, 'TREASURY-TEST-RETRY', 'توريد أول');
        $second = $service->issueAdminFloat('700', $this->admin, 'TREASURY-TEST-RETRY', 'إعادة طلب');

        $this->assertFalse($first['duplicate']);
        $this->assertTrue($second['duplicate']);
        $this->assertSame($first['entry']->id, $second['entry']->id);
        $this->assertSame(1, LedgerJournalEntry::where('source_type', 'treasury_issuance')->count());
        $this->assertSame('700.0000', (string) EMoney::where('user_id', $this->admin->id)
            ->value('current_balance'));
    }
}
