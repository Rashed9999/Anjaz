<?php

namespace Tests\Feature;

use App\Models\Ledger\LedgerAccount;
use App\Models\Ledger\LedgerEntryLine;
use App\Models\Ledger\LedgerJournalEntry;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-LEDGER-001 (v1.7) — اختبارات القيد المزدوج.
 */
class LedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = app(LedgerService::class);

        // حسابات اختبار
        LedgerAccount::create([
            'account_code' => 'USER_WALLET_1', 'account_type' => 'liability',
            'name_ar' => 'محفظة 1', 'owner_user_id' => 1, 'owner_type' => 'user',
            'normal_balance' => 'credit', 'current_balance' => '1000',
        ]);
        LedgerAccount::create([
            'account_code' => 'USER_WALLET_2', 'account_type' => 'liability',
            'name_ar' => 'محفظة 2', 'owner_user_id' => 2, 'owner_type' => 'user',
            'normal_balance' => 'credit', 'current_balance' => '500',
        ]);
        LedgerAccount::create([
            'account_code' => 'PLATFORM_FEE', 'account_type' => 'revenue',
            'name_ar' => 'رسوم المنصة', 'owner_type' => 'platform',
            'normal_balance' => 'credit', 'current_balance' => '0',
        ]);
    }

    /** @test */
    public function it_posts_balanced_double_entry()
    {
        $entry = $this->ledger->post(
            sourceType: 'send_money',
            sourceId: 'TX-001',
            description: 'تحويل من 1 إلى 2',
            lines: [
                ['account' => 'USER_WALLET_1', 'direction' => 'debit', 'amount' => '100'],
                ['account' => 'USER_WALLET_2', 'direction' => 'credit', 'amount' => '100'],
            ],
        );

        $this->assertEquals('posted', $entry->status);
        $this->assertEquals('100.0000', (string)$entry->total_amount);
        $this->assertEquals(2, $entry->lines()->count());

        // أرصدة محدّثة
        $this->assertEquals('900.0000',
            (string) LedgerAccount::where('account_code', 'USER_WALLET_1')->value('current_balance'));
        $this->assertEquals('600.0000',
            (string) LedgerAccount::where('account_code', 'USER_WALLET_2')->value('current_balance'));
    }

    /** @test */
    public function it_rejects_unbalanced_entry()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unbalanced');

        $this->ledger->post(
            sourceType: 'send_money',
            sourceId: 'TX-002',
            description: 'غير متوازن',
            lines: [
                ['account' => 'USER_WALLET_1', 'direction' => 'debit', 'amount' => '100'],
                ['account' => 'USER_WALLET_2', 'direction' => 'credit', 'amount' => '90'], // ≠ 100
            ],
        );
    }

    /** @test */
    public function it_handles_three_way_split_with_fee()
    {
        // العميل يدفع 100، التاجر يستلم 99، المنصة 1
        $entry = $this->ledger->post(
            sourceType: 'pay_merchant',
            sourceId: 'TX-003',
            description: 'دفعة مع رسوم',
            lines: [
                ['account' => 'USER_WALLET_1', 'direction' => 'debit', 'amount' => '100'],
                ['account' => 'USER_WALLET_2', 'direction' => 'credit', 'amount' => '99'],
                ['account' => 'PLATFORM_FEE', 'direction' => 'credit', 'amount' => '1'],
            ],
        );

        $this->assertEquals('posted', $entry->status);
        $this->assertEquals('900.0000',
            (string) LedgerAccount::where('account_code', 'USER_WALLET_1')->value('current_balance'));
        $this->assertEquals('599.0000',
            (string) LedgerAccount::where('account_code', 'USER_WALLET_2')->value('current_balance'));
        $this->assertEquals('1.0000',
            (string) LedgerAccount::where('account_code', 'PLATFORM_FEE')->value('current_balance'));
    }

    /** @test */
    public function it_prevents_insufficient_balance_on_asset_account()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Insufficient balance');

        $this->ledger->post(
            sourceType: 'send_money',
            sourceId: 'TX-004',
            description: 'رصيد غير كاف',
            lines: [
                ['account' => 'USER_WALLET_1', 'direction' => 'debit', 'amount' => '5000'], // > 1000
                ['account' => 'USER_WALLET_2', 'direction' => 'credit', 'amount' => '5000'],
            ],
        );
    }

    /** @test */
    public function it_is_idempotent()
    {
        $first = $this->ledger->post(
            sourceType: 'send_money', sourceId: 'TX-005', description: 'تحويل',
            lines: [
                ['account' => 'USER_WALLET_1', 'direction' => 'debit', 'amount' => '50'],
                ['account' => 'USER_WALLET_2', 'direction' => 'credit', 'amount' => '50'],
            ],
            idempotencyKey: 'unique-key-123',
        );

        $second = $this->ledger->post(
            sourceType: 'send_money', sourceId: 'TX-005', description: 'تحويل',
            lines: [
                ['account' => 'USER_WALLET_1', 'direction' => 'debit', 'amount' => '50'],
                ['account' => 'USER_WALLET_2', 'direction' => 'credit', 'amount' => '50'],
            ],
            idempotencyKey: 'unique-key-123',
        );

        $this->assertEquals($first->id, $second->id); // نفس القيد
        $this->assertEquals(1, LedgerJournalEntry::count());
        // الرصيد خُصم مرة واحدة فقط
        $this->assertEquals('950.0000',
            (string) LedgerAccount::where('account_code', 'USER_WALLET_1')->value('current_balance'));
    }

    /** @test */
    public function it_records_balance_snapshots()
    {
        $this->ledger->post(
            sourceType: 'send_money', sourceId: 'TX-006', description: 'تحويل',
            lines: [
                ['account' => 'USER_WALLET_1', 'direction' => 'debit', 'amount' => '200'],
                ['account' => 'USER_WALLET_2', 'direction' => 'credit', 'amount' => '200'],
            ],
        );

        $line = LedgerEntryLine::whereHas('account',
            fn($q) => $q->where('account_code', 'USER_WALLET_1'))->first();

        $this->assertEquals('1000.0000', (string)$line->balance_before);
        $this->assertEquals('800.0000', (string)$line->balance_after);
    }

    /** @test */
    public function reverse_creates_opposite_entry_without_deleting_original()
    {
        $original = $this->ledger->post(
            sourceType: 'send_money', sourceId: 'TX-007', description: 'تحويل',
            lines: [
                ['account' => 'USER_WALLET_1', 'direction' => 'debit', 'amount' => '100'],
                ['account' => 'USER_WALLET_2', 'direction' => 'credit', 'amount' => '100'],
            ],
        );

        // بعد التحويل: W1=900, W2=600
        $reversal = $this->ledger->reverse($original->id, 'خطأ في التحويل');

        // الأصلي لا يزال موجوداً، لكن مُعلَّم reversed
        $original->refresh();
        $this->assertEquals('reversed', $original->status);
        $this->assertEquals($reversal->id, $original->reversed_by_entry_id);

        // القيد العكسي
        $this->assertTrue($reversal->is_reversal);
        $this->assertEquals($original->id, $reversal->reverses_entry_id);

        // الأرصدة رجعت لأصلها
        $this->assertEquals('1000.0000',
            (string) LedgerAccount::where('account_code', 'USER_WALLET_1')->value('current_balance'));
        $this->assertEquals('500.0000',
            (string) LedgerAccount::where('account_code', 'USER_WALLET_2')->value('current_balance'));

        // كلا القيدين موجودان (لا حذف)
        $this->assertEquals(2, LedgerJournalEntry::count());
    }

    /** @test */
    public function cannot_reverse_already_reversed_entry()
    {
        $original = $this->ledger->post(
            sourceType: 'send_money', sourceId: 'TX-008', description: 'تحويل',
            lines: [
                ['account' => 'USER_WALLET_1', 'direction' => 'debit', 'amount' => '50'],
                ['account' => 'USER_WALLET_2', 'direction' => 'credit', 'amount' => '50'],
            ],
        );
        $this->ledger->reverse($original->id, 'first reversal');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already reversed');
        $this->ledger->reverse($original->id, 'second attempt');
    }

    /** @test */
    public function journal_entry_cannot_be_deleted()
    {
        $entry = $this->ledger->post(
            sourceType: 'send_money', sourceId: 'TX-009', description: 'تحويل',
            lines: [
                ['account' => 'USER_WALLET_1', 'direction' => 'debit', 'amount' => '10'],
                ['account' => 'USER_WALLET_2', 'direction' => 'credit', 'amount' => '10'],
            ],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot be deleted');
        $entry->delete();
    }

    /** @test */
    public function entry_line_cannot_be_modified()
    {
        $this->ledger->post(
            sourceType: 'send_money', sourceId: 'TX-010', description: 'تحويل',
            lines: [
                ['account' => 'USER_WALLET_1', 'direction' => 'debit', 'amount' => '10'],
                ['account' => 'USER_WALLET_2', 'direction' => 'credit', 'amount' => '10'],
            ],
        );

        $line = LedgerEntryLine::first();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('immutable');
        $line->update(['amount' => '999']);
    }

    /** @test */
    public function computed_balance_matches_cached_balance()
    {
        $this->ledger->post(
            sourceType: 'send_money', sourceId: 'TX-011', description: 'تحويل 1',
            lines: [
                ['account' => 'USER_WALLET_1', 'direction' => 'debit', 'amount' => '100'],
                ['account' => 'USER_WALLET_2', 'direction' => 'credit', 'amount' => '100'],
            ],
        );
        $this->ledger->post(
            sourceType: 'send_money', sourceId: 'TX-012', description: 'تحويل 2',
            lines: [
                ['account' => 'USER_WALLET_1', 'direction' => 'debit', 'amount' => '50'],
                ['account' => 'USER_WALLET_2', 'direction' => 'credit', 'amount' => '50'],
            ],
        );

        // حساب مخصّص مُهيّأ بالكامل عبر قيود (حتى يعكس computed كامل التاريخ)
        $this->ledger->getOrCreateSystemAccount('CASH_RESERVE', 'asset', 'احتياطي', 'debit');
        $fresh = $this->ledger->getOrCreateUserWallet(900);
        $this->ledger->post(
            sourceType: 'opening', sourceId: 'OPEN-900', description: 'افتتاحي',
            lines: [
                ['account' => 'CASH_RESERVE', 'direction' => 'debit', 'amount' => '1000'],
                ['account' => $fresh->account_code, 'direction' => 'credit', 'amount' => '1000'],
            ],
            allowNegative: true,
        );
        $this->ledger->post(
            sourceType: 'send_money', sourceId: 'TX-900', description: 'خصم 150',
            lines: [
                ['account' => $fresh->account_code, 'direction' => 'debit', 'amount' => '150'],
                ['account' => 'USER_WALLET_2', 'direction' => 'credit', 'amount' => '150'],
            ],
        );

        $account = LedgerAccount::where('account_code', $fresh->account_code)->first();
        $computed = $this->ledger->computeBalanceFromLines($account->id);

        // cached = 1000 - 150 = 850، computed يطابق (كله عبر قيود)
        $this->assertEquals('850.0000', (string)$account->current_balance);
        $this->assertEquals('850.0000', $computed);
    }

    /** @test */
    public function get_or_create_user_wallet_works()
    {
        $wallet = $this->ledger->getOrCreateUserWallet(99);
        $this->assertEquals('USER_WALLET_99', $wallet->account_code);
        $this->assertEquals('liability', $wallet->account_type);
        $this->assertEquals('credit', $wallet->normal_balance);

        // idempotent
        $wallet2 = $this->ledger->getOrCreateUserWallet(99);
        $this->assertEquals($wallet->id, $wallet2->id);
    }
}
