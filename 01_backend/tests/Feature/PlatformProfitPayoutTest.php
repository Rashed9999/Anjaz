<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Ledger\LedgerAccount;
use App\Models\Ledger\LedgerEntryLine;
use App\Models\PlatformProfitPayout;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\LedgerService;
use App\Services\PlatformProfitPayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-PLATFORM-PROFIT-PAYOUT-001 — الأرباح ليست محفظةَ أدمن.
 *
 * تحرس هذه الاختبارات الفاصل بين: الإيراد، الاستحقاق المعتمد، وسيولة
 * الخزينة المركزية. كما تثبت أن احتياطي الوكلاء CASH_RESERVE لا يدخل هنا.
 */
class PlatformProfitPayoutTest extends TestCase
{
    use RefreshDatabase;

    private User $maker;
    private User $checker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->maker = User::factory()->create(['type' => ADMIN_TYPE, 'is_active' => 1]);
        $this->checker = User::factory()->create(['type' => ADMIN_TYPE, 'is_active' => 1]);
        $this->fundRevenueAndCash('1000.0000');
    }

    public function test_request_reserves_revenue_but_does_not_post_or_pay_before_second_approval(): void
    {
        $payout = $this->service()->request($this->maker, $this->requestData('400'));

        $this->assertSame(PlatformProfitPayout::STATUS_PENDING, $payout->status);
        $this->assertSame('pending', $payout->approval->status);
        $this->assertSame(0, $this->journalCount('platform_profit_payout_approval'));
        $this->assertSame(0, $this->journalCount('platform_profit_payout_payment'));
        $this->assertBalance('PLATFORM_FEE', '1000');
        $this->assertSame(0, bccomp('600', $this->service()->summary()['available_to_request'], 4));
    }

    public function test_another_admin_approval_moves_revenue_to_payable_then_cash_payment_closes_it(): void
    {
        $payout = $this->service()->request($this->maker, $this->requestData('400'));
        app(ApprovalService::class)->approve($this->checker, (int) $payout->approval_request_id, 'راجعت الاستحقاق');

        $payout->refresh();
        $this->assertSame(PlatformProfitPayout::STATUS_APPROVED, $payout->status);
        $this->assertBalance('PLATFORM_FEE', '600');
        $this->assertBalance('PLATFORM_PROFIT_PAYABLE', '400');
        $this->assertBalancedSource('platform_profit_payout_approval');

        $paid = $this->service()->markPaid($payout, $this->checker, 'CASH-RECEIPT-001', 'سند تسليم رقم 91', 'المكتب الرئيسي');
        $this->assertSame(PlatformProfitPayout::STATUS_PAID, $paid->status);
        $this->assertNotNull($paid->handover_ulid);
        $this->assertBalance('PLATFORM_PROFIT_PAYABLE', '0');
        $this->assertBalance('TREASURY_CASH_RESERVE', '600');
        $this->assertNull(LedgerAccount::where('account_code', 'CASH_RESERVE')->first(),
            'لا يجوز أن يلمس صرف أرباح المنصة احتياطي تسويات الوكلاء');
        $this->assertBalancedSource('platform_profit_payout_payment');

        $handover = $this->service()->confirmCashHandover($paid, $this->maker, 'استلمت النقد كاملاً');
        $this->assertSame('confirmed', $handover['status']);
    }

    public function test_maker_cannot_approve_their_own_platform_profit_payout(): void
    {
        $payout = $this->service()->request($this->maker, $this->requestData('100'));
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('SELF_APPROVAL_FORBIDDEN');
        app(ApprovalService::class)->approve($this->maker, (int) $payout->approval_request_id);
    }

    public function test_pending_requests_cannot_overcommit_the_same_revenue(): void
    {
        $this->service()->request($this->maker, $this->requestData('700'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('يتجاوز الإيراد المتاح');
        $this->service()->request($this->maker, $this->requestData('301'));
    }

    public function test_rejection_releases_the_reserved_revenue_without_posting_a_journal(): void
    {
        $payout = $this->service()->request($this->maker, $this->requestData('400'));
        app(ApprovalService::class)->reject($this->checker, (int) $payout->approval_request_id, 'المستند لا يطابق السجل');

        $payout->refresh();
        $this->assertSame(PlatformProfitPayout::STATUS_REJECTED, $payout->status);
        $this->assertSame(0, $this->journalCount('platform_profit_payout_approval'));
        $this->assertSame(0, bccomp('1000', $this->service()->summary()['available_to_request'], 4));
    }

    private function service(): PlatformProfitPayoutService
    {
        return app(PlatformProfitPayoutService::class);
    }

    /** @return array<string,string> */
    private function requestData(string $amount): array
    {
        return [
            'amount' => $amount,
            'payout_method' => PlatformProfitPayout::METHOD_CASH,
            'beneficiary_name' => 'مالك منصة أميال',
            'destination_reference' => 'المكتب الرئيسي',
            'request_note' => 'صرف أرباح دورة مالية موثقة',
        ];
    }

    private function fundRevenueAndCash(string $amount): void
    {
        $ledger = app(LedgerService::class);
        $cash = $ledger->getOrCreateSystemAccount('TREASURY_CASH_RESERVE', 'asset', 'احتياطي نقد الخزينة الموثق', 'debit');
        $revenue = $ledger->getOrCreateSystemAccount('PLATFORM_FEE', 'revenue', 'إيرادات رسوم المنصّة', 'credit');
        $ledger->post('profit_payout_test_seed', 'seed-1', 'تمويل اختبار إيراد وسيولة الخزينة', [
            ['account' => $cash->account_code, 'direction' => 'debit', 'amount' => $amount],
            ['account' => $revenue->account_code, 'direction' => 'credit', 'amount' => $amount],
        ]);
    }

    private function assertBalance(string $code, string $expected): void
    {
        $actual = (string) LedgerAccount::where('account_code', $code)->value('current_balance');
        $this->assertSame(0, bccomp($expected, $actual, 4), "رصيد {$code} غير صحيح");
    }

    private function journalCount(string $sourceType): int
    {
        return \App\Models\Ledger\LedgerJournalEntry::where('source_type', $sourceType)->count();
    }

    private function assertBalancedSource(string $sourceType): void
    {
        $entry = \App\Models\Ledger\LedgerJournalEntry::where('source_type', $sourceType)->firstOrFail();
        $lines = LedgerEntryLine::where('journal_entry_id', $entry->id)->get();
        $sum = fn (string $direction): string => array_reduce($lines->where('direction', $direction)->pluck('amount')->all(),
            fn (string $carry, $amount): string => bcadd($carry, (string) $amount, 4), '0.0000');
        $this->assertSame(0, bccomp($sum('debit'), $sum('credit'), 4));
    }
}
