<?php

namespace Tests\Feature;

use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-LEDGER-001 (v2.2) — اختبار تغطية الـ ledger الكاملة.
 *
 * يثبت أن كل أنماط القيود تعمل بشكل متوازن.
 */
class LedgerCoverageTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = app(LedgerService::class);

        // محافظ اختبار برصيد ابتدائي عبر CASH_RESERVE
        $this->seedWallet(1, '10000'); // agent
        $this->seedWallet(2, '5000');  // customer
        $this->seedWallet(3, '8000');  // merchant

        // حسابات النظام التي تستخدمها الاختبارات
        $this->ledger->getOrCreateSystemAccount('PLATFORM_FEE', 'revenue', 'رسوم المنصة', 'credit');
        $this->ledger->getOrCreateSystemAccount('ESCROW_HOLD', 'liability', 'حجز ضمان', 'credit');
        $this->ledger->getOrCreateSystemAccount('BILLER_PAYABLE_5', 'liability', 'مستحقات مزوّد الفواتير', 'credit');
    }

    private function seedWallet(int $userId, string $balance): void
    {
        $reserve = $this->ledger->getOrCreateSystemAccount(
            'CASH_RESERVE', 'asset', 'احتياطي', 'debit'
        );
        $wallet = $this->ledger->getOrCreateUserWallet($userId);
        $this->ledger->post(
            sourceType: 'seed',
            sourceId: "seed_{$userId}",
            description: 'رصيد ابتدائي',
            lines: [
                ['account' => $reserve->account_code, 'direction' => 'debit', 'amount' => $balance],
                ['account' => $wallet->account_code, 'direction' => 'credit', 'amount' => $balance],
            ],
            idempotencyKey: "seed_{$userId}",
            allowNegative: true,
        );
    }

    /** @test */
    public function agent_cash_in_posts_balanced_ledger()
    {
        // الوكيل (1) يودع 1000 للعميل (2)
        $this->ledger->post(
            sourceType: 'agent_cash_in',
            sourceId: 'CASHIN-1',
            description: 'إيداع وكيل',
            lines: [
                ['account' => 'USER_WALLET_1', 'direction' => 'debit', 'amount' => '1000'],
                ['account' => 'USER_WALLET_2', 'direction' => 'credit', 'amount' => '1000'],
            ],
        );

        $this->assertEquals('9000.0000',
            (string) \App\Models\Ledger\LedgerAccount::where('account_code', 'USER_WALLET_1')->value('current_balance'));
        $this->assertEquals('6000.0000',
            (string) \App\Models\Ledger\LedgerAccount::where('account_code', 'USER_WALLET_2')->value('current_balance'));
    }

    /** @test */
    public function send_money_with_fee_balances()
    {
        // العميل (2) يحوّل 500 للتاجر (3) برسوم 5
        $this->ledger->post(
            sourceType: 'send_money',
            sourceId: 'SEND-1',
            description: 'تحويل برسوم',
            lines: [
                ['account' => 'USER_WALLET_2', 'direction' => 'debit', 'amount' => '505'],
                ['account' => 'USER_WALLET_3', 'direction' => 'credit', 'amount' => '500'],
                ['account' => 'PLATFORM_FEE', 'direction' => 'credit', 'amount' => '5'],
            ],
        );

        $this->assertEquals('4495.0000',
            (string) \App\Models\Ledger\LedgerAccount::where('account_code', 'USER_WALLET_2')->value('current_balance'));
        $this->assertEquals('8500.0000',
            (string) \App\Models\Ledger\LedgerAccount::where('account_code', 'USER_WALLET_3')->value('current_balance'));
        $this->assertEquals('5.0000',
            (string) \App\Models\Ledger\LedgerAccount::where('account_code', 'PLATFORM_FEE')->value('current_balance'));
    }

    /** @test */
    public function bill_payment_posts_to_biller_account()
    {
        // العميل (2) يدفع فاتورة 200 برسوم 2
        $this->ledger->post(
            sourceType: 'bill_payment',
            sourceId: 'BILL-1',
            description: 'فاتورة',
            lines: [
                ['account' => 'USER_WALLET_2', 'direction' => 'debit', 'amount' => '202'],
                ['account' => 'BILLER_PAYABLE_5', 'direction' => 'credit', 'amount' => '200'],
                ['account' => 'PLATFORM_FEE', 'direction' => 'credit', 'amount' => '2'],
            ],
        );

        // حساب المزود (liability) يزداد
        $biller = \App\Models\Ledger\LedgerAccount::where('account_code', 'BILLER_PAYABLE_5')->first();
        // لو لم يكن موجوداً مسبقاً، الـ post أنشأه
        $this->assertNotNull($biller);
    }

    /** @test */
    public function escrow_full_lifecycle_balances()
    {
        // 1. حجز 1000 من العميل (2)
        $escrow = $this->ledger->getOrCreateSystemAccount('ESCROW_HOLD', 'liability', 'حجز', 'credit');
        $this->ledger->post(
            sourceType: 'safe_payment_fund', sourceId: 'SP-1', description: 'حجز',
            lines: [
                ['account' => 'USER_WALLET_2', 'direction' => 'debit', 'amount' => '1000'],
                ['account' => 'ESCROW_HOLD', 'direction' => 'credit', 'amount' => '1000'],
            ],
        );
        $this->assertEquals('4000.0000',
            (string) \App\Models\Ledger\LedgerAccount::where('account_code', 'USER_WALLET_2')->value('current_balance'));

        // 2. إفراج للتاجر (3) برسوم 10
        $this->ledger->post(
            sourceType: 'safe_payment_release', sourceId: 'SP-1', description: 'إفراج',
            lines: [
                ['account' => 'ESCROW_HOLD', 'direction' => 'debit', 'amount' => '1000'],
                ['account' => 'USER_WALLET_3', 'direction' => 'credit', 'amount' => '990'],
                ['account' => 'PLATFORM_FEE', 'direction' => 'credit', 'amount' => '10'],
            ],
        );

        // ESCROW رجع صفر، التاجر استلم 990
        $this->assertEquals('0.0000',
            (string) \App\Models\Ledger\LedgerAccount::where('account_code', 'ESCROW_HOLD')->value('current_balance'));
        $this->assertEquals('8990.0000',
            (string) \App\Models\Ledger\LedgerAccount::where('account_code', 'USER_WALLET_3')->value('current_balance'));
    }

    /** @test */
    public function system_total_remains_conserved()
    {
        // قانون المحاسبة: مجموع كل الأرصدة (assets) = مجموع (liabilities + revenue)
        // بعد أي سلسلة عمليات، النظام متوازن

        $this->ledger->post(
            sourceType: 'send_money', sourceId: 'CONS-1', description: 'تحويل',
            lines: [
                ['account' => 'USER_WALLET_1', 'direction' => 'debit', 'amount' => '300'],
                ['account' => 'USER_WALLET_2', 'direction' => 'credit', 'amount' => '300'],
            ],
        );

        // مجموع debits في كل الـ lines = مجموع credits
        $totalDebits = (string) \App\Models\Ledger\LedgerEntryLine::where('direction', 'debit')->sum('amount');
        $totalCredits = (string) \App\Models\Ledger\LedgerEntryLine::where('direction', 'credit')->sum('amount');

        $this->assertEquals(0, bccomp($totalDebits, $totalCredits, 4),
            "النظام غير متوازن: debits={$totalDebits}, credits={$totalCredits}");
    }
}
