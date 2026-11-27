<?php

namespace Tests\BankGrade;

use App\Exceptions\InsufficientBalanceException;
use App\Models\EMoney;
use App\Models\FeeScheme;
use App\Models\Ledger\LedgerAccount;
use App\Models\Ledger\LedgerEntryLine;
use App\Models\Ledger\LedgerJournalEntry;
use App\Models\MerchantProfile;
use App\Models\ReconciliationCase;
use App\Models\RequestMoney;
use App\Models\Settlement;
use App\Models\SettlementPartner;
use App\Models\User;
use App\Models\UserLogHistory;
use App\Models\WithdrawalMethod;
use App\Services\CashierService;
use App\Services\CustomerWithdrawService;
use App\Services\FeeService;
use App\Services\LedgerService;
use App\Services\MerchantSaleRefundService;
use App\Services\MoneyService;
use App\Services\PaymentRequestService;
use App\Services\TransactionPinService;
use App\Services\UniversalSettlementService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\EstablishesKycEvidence;
use Tests\TestCase;

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  AMIAL-BANK-GRADE-ANTITHEFT-001 — مصفوفة الاختبارات المصرفية (منع سرقة الأموال)
 * ══════════════════════════════════════════════════════════════════════════
 *
 * المعايير المرجعية المطبّقة:
 *   • Basel III Operational Risk — حفظ المال الصارم ومنع خلق الرصيد من العدم
 *   • SOX Section 404 — القيد المزدوج غير القابل للمحو، وفصل المهام الرباعي (Four-Eyes & Dual Approval)
 *   • ISO 4217 / Multi-Currency Ledger — منع خلق القيمة بخلط العملات في قيد واحد
 *   • FAPI 2.0 / OWASP API6 — منع الصرف المزدوج (Double-Spend)، الاسترداد الزائد، التلاعب بالفواصل (Salami Slicing)، وتجاوز رمز PIN
 */
class BankGradeAntiTheftSuiteTest extends TestCase
{
    use EstablishesKycEvidence;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    // ══════════════════════════════════════════════════════════════════════
    // 1. Strict Double-Entry Conservation & Zero Drift (Basel III / GAAP)
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function double_entry_ledger_preserves_exact_conservation_of_money_and_zero_cache_drift(): void
    {
        $ledger = app(LedgerService::class);

        $treasury = $ledger->getOrCreateSystemAccount('BANK_TREASURY_TEST', 'asset', 'خزينة الفحص المصرفي', 'debit');
        $feeAcc   = $ledger->getOrCreateSystemAccount('PLATFORM_FEE', 'revenue', 'إيراد العمولات', 'credit');
        $w1       = $ledger->getOrCreateUserWallet(5001);
        $w2       = $ledger->getOrCreateUserWallet(5002);
        $w3       = $ledger->getOrCreateUserWallet(5003);

        // حقن 1,000,000.0000 ريال فقط في النظام
        $ledger->post('treasury_mint', 'MINT-1', 'إيداع خزينة مقابل غطاء بنكي', [
            ['account' => $treasury->account_code, 'direction' => 'debit',  'amount' => '1000000.0000'],
            ['account' => $w1->account_code,       'direction' => 'credit', 'amount' => '600000.0000'],
            ['account' => $w2->account_code,       'direction' => 'credit', 'amount' => '400000.0000'],
        ]);

        // سلسلة تحويلات متعددة الأطراف مع اقتطاع عمولة للمنصة
        $ledger->post('p2p_fee', 'TX-101', 'تحويل مع عمولة', [
            ['account' => $w1->account_code,     'direction' => 'debit',  'amount' => '150750.2550'],
            ['account' => $w3->account_code,     'direction' => 'credit', 'amount' => '150000.0000'],
            ['account' => $feeAcc->account_code, 'direction' => 'credit', 'amount' => '750.2550'],
        ]);

        // محاولة سحب مكشوف تتجاوز الرصيد بـ 0.0001 ريال يجب أن تُرفض بالكامل دون أثر يتيم
        $overdraftBlocked = false;
        try {
            $ledger->post('overdraft_attack', 'TX-OVER', 'محاولة سحب مكشوف', [
                ['account' => $w2->account_code, 'direction' => 'debit',  'amount' => '400000.0001'],
                ['account' => $w3->account_code, 'direction' => 'credit', 'amount' => '400000.0001'],
            ]);
        } catch (RuntimeException) {
            $overdraftBlocked = true;
        }
        $this->assertTrue($overdraftBlocked, 'سمح الدفتر بسحب يتجاوز الرصيد بمقدار 0.0001 ريال!');

        // محاولة قيد غير متوازن بفارق 0.0001 ريال يجب أن تُرفض فوراً
        $unbalancedBlocked = false;
        try {
            $ledger->post('unbalanced_attack', 'TX-UNBAL', 'محاولة خلق فلس من العدم', [
                ['account' => $w2->account_code, 'direction' => 'debit',  'amount' => '100.0000'],
                ['account' => $w3->account_code, 'direction' => 'credit', 'amount' => '100.0001'],
            ]);
        } catch (RuntimeException) {
            $unbalancedBlocked = true;
        }
        $this->assertTrue($unbalancedBlocked, 'قَبِل الدفتر قيداً غير متوازن بفارق 0.0001 ريال!');

        // التحقق من الثوابت المصرفية الأربعة
        $totalDebits  = (string) LedgerEntryLine::where('direction', 'debit')->sum('amount');
        $totalCredits = (string) LedgerEntryLine::where('direction', 'credit')->sum('amount');
        $this->assertSame(0, bccomp($totalDebits, $totalCredits, 4), 'انكسر توازن القيد المزدوج العام!');

        foreach (LedgerAccount::all() as $acc) {
            $recomputed = $ledger->computeBalanceFromLines($acc->id);
            $this->assertSame(0, bccomp((string) $acc->current_balance, $recomputed, 4), "انحراف رصيد مخزّن في {$acc->account_code}");
        }

        // مجموع التزامات المحافظ + إيراد المنصة = الغطاء المودَع في الخزينة تماماً (1,000,000.0000)
        $liabilitiesAndRevenue = bcadd(
            bcadd(
                (string) $w1->fresh()->current_balance,
                (string) $w2->fresh()->current_balance,
                4
            ),
            bcadd(
                (string) $w3->fresh()->current_balance,
                (string) $feeAcc->fresh()->current_balance,
                4
            ),
            4
        );
        $this->assertSame('1000000.0000', $liabilitiesAndRevenue);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 2. Cross-Currency Minting Attack Prevention (ISO 4217)
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function ledger_blocks_cross_currency_value_minting_in_single_journal_entry(): void
    {
        $ledger = app(LedgerService::class);
        $yerTreasury = $ledger->getOrCreateSystemAccount('TREASURY_YER_BG', 'asset', 'خزينة ريال', 'debit', 'YER');
        $yerWallet   = $ledger->getOrCreateUserWallet(6001, 'SOUTH', 'YER');
        $usdWallet   = $ledger->getOrCreateUserWallet(6001, 'SOUTH', 'USD');

        $ledger->post('mint_yer', 'M-YER', 'تغذية بالريال', [
            ['account' => $yerTreasury->account_code, 'direction' => 'debit',  'amount' => '1000.0000'],
            ['account' => $yerWallet->account_code,   'direction' => 'credit', 'amount' => '1000.0000'],
        ]);

        // الهجوم: خصم 500 ريال يمني وإيداع 500 دولار أمريكي في قيد واحد «متوازن عددياً»!
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cross-currency journal entry');

        $ledger->post('fx_mint_attack', 'ATK-FX-1', 'محاولة تحويل 500 ريال إلى 500 دولار بلا حساب مركز صرف', [
            ['account' => $yerWallet->account_code, 'direction' => 'debit',  'amount' => '500.0000'],
            ['account' => $usdWallet->account_code, 'direction' => 'credit', 'amount' => '500.0000'],
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 3. Append-Only Ledger & Double-Reversal Prevention (SOX 404)
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function ledger_enforces_append_only_immutability_and_blocks_double_reversal(): void
    {
        $ledger = app(LedgerService::class);
        $cap = $ledger->getOrCreateSystemAccount('CAP_REV_TEST', 'equity', 'رأس مال', 'debit');
        $wA  = $ledger->getOrCreateUserWallet(6101);
        $wB  = $ledger->getOrCreateUserWallet(6102);

        $ledger->post('mint', 'M1', 'تغذية', [
            ['account' => $cap->account_code, 'direction' => 'debit',  'amount' => '10000.0000'],
            ['account' => $wA->account_code,  'direction' => 'credit', 'amount' => '10000.0000'],
        ]);

        $txEntry = $ledger->post('send_money', 'TX-REV-1', 'تحويل قابل للعكس', [
            ['account' => $wA->account_code, 'direction' => 'debit',  'amount' => '4000.0000'],
            ['account' => $wB->account_code, 'direction' => 'credit', 'amount' => '4000.0000'],
        ]);

        $revEntry = $ledger->reverse($txEntry->id, 'عكس خطأ تشغيلي موثق');
        $this->assertSame('10000.0000', MoneyService::normalize((string) $wA->fresh()->current_balance));
        $this->assertSame('0.0000', MoneyService::normalize((string) $wB->fresh()->current_balance));
        $this->assertSame(3, LedgerJournalEntry::count(), 'التصحيح المحاسبي يجب أن يضيف قيداً عكسياً لا أن يحذف التاريخ');

        // محاولة عكس القيد مرة ثانية لسرقة 4000 ريال إضافية من المستلم!
        $doubleRevBlocked = false;
        try {
            $ledger->reverse($txEntry->id, 'محاولة عكس مزدوج لخلق رصيد');
        } catch (RuntimeException) {
            $doubleRevBlocked = true;
        }
        $this->assertTrue($doubleRevBlocked, 'سمح الدفتر بعكس نفس القيد مرتين (سرقة بمضاعفة الاسترداد)!');

        // محاولة عكس قيد العكس نفسه
        $reverseOfReversalBlocked = false;
        try {
            $ledger->reverse($revEntry->id, 'عكس العكس');
        } catch (RuntimeException) {
            $reverseOfReversalBlocked = true;
        }
        $this->assertTrue($reverseOfReversalBlocked, 'سمح الدفتر بعكس قيد عكسي!');
    }

    // ══════════════════════════════════════════════════════════════════════
    // 4. Salami-Slicing & Fractional Rounding Theft Defense
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function money_and_fee_engines_defeat_salami_slicing_and_fractional_fils_rounding_theft(): void
    {
        // تقسيم مبالغ أولية فردية على أعداد لا تقبل القسمة لا يخلق ولا يفقد 0.0001 ريال
        foreach ([
            ['100.0000', 3],
            ['1000.0000', 7],
            ['0.0100', 3],
            ['99999.9999', 13],
        ] as [$pot, $participants]) {
            $shares = MoneyService::distribute($pot, $participants, 4);
            $sum = '0.0000';
            foreach ($shares as $share) {
                $sum = MoneyService::add($sum, $share);
            }
            $this->assertSame(0, bccomp($pot, $sum, 4), "انحراف تقسيم Salami في المبلغ {$pot}: المجموع {$sum}");
        }

        // في محرك العمولات: ربح المنصة + عمولة الوكيل === إجمالي الرسم دائماً بلا زيادة فلس واحد
        $feeSvc = app(FeeService::class);
        foreach (['0.01', '0.33', '1.00', '17.77', '333.33', '999999.99'] as $amt) {
            $sim = $feeSvc->simulate([
                'code' => 'CASH_OUT',
                'zone_code' => 'SOUTH',
                'applies_to' => 'customer',
                'fee_type' => 'percent_plus_fixed',
                'percent_rate' => '1.75',
                'fixed_amount' => '12.50',
                'agent_commission_percent' => '33.33',
                'agent_commission_fixed' => '0',
                'bearer' => 'sender',
            ], $amt);

            $splitSum = MoneyService::add($sim['platform_profit'], $sim['agent_commission']);
            $this->assertSame(
                0,
                bccomp($sim['fee'], $splitSum, 4),
                "انحراف توزيع عمولة عند المبلغ {$amt}: الرسم {$sim['fee']} != التوزيع {$splitSum}"
            );
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // 5. Hold / Escrow Double-Spend Prevention on Pending Cash-Out
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function escrow_hold_prevents_double_spending_pending_withdrawal_funds(): void
    {
        $admin    = User::factory()->admin()->create();
        $customer = User::factory()->create(['type' => 2, 'phone' => '+967700991100', 'zone_code' => 'SOUTH']);
        $agent    = User::factory()->create(['type' => 1, 'phone' => '+967700991101', 'zone_code' => 'SOUTH']);
        $this->establishKycEvidence($customer, 2, $admin);

        EMoney::create(['user_id' => $admin->id,    'current_balance' => '0.0000',     'zone_code' => 'SOUTH']);
        EMoney::create(['user_id' => $customer->id, 'current_balance' => '10500.0000', 'zone_code' => 'SOUTH']);
        EMoney::create(['user_id' => $agent->id,    'current_balance' => '0.0000',     'zone_code' => 'SOUTH']);

        FeeScheme::create([
            'code' => 'CASH_OUT', 'zone_code' => 'SOUTH', 'applies_to' => 'customer',
            'fee_type' => 'percent', 'percent_rate' => '5.0000', 'fixed_amount' => '0',
            'agent_commission_percent' => '40.0000', 'agent_commission_fixed' => '0',
            'bearer' => 'sender', 'version' => 1, 'is_active' => true, 'effective_from' => now(),
        ]);

        $withdrawSvc = app(CustomerWithdrawService::class);
        $req = $withdrawSvc->request($customer, '10000'); // يحجز 10000 + 500 = 10500 فوراً

        // الرصيد المتاح أصبح 0.0000 — أي محاولة طلب سحب ثانٍ قبل الصرف تفشل فوراً
        $this->expectException(InsufficientBalanceException::class);
        $withdrawSvc->request($customer, '100');
    }

    // ══════════════════════════════════════════════════════════════════════
    // 6. POS Over-Refund Theft Prevention
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function pos_partial_refunds_cannot_exceed_original_sale_total(): void
    {
        Bus::fake();

        $merchant = User::factory()->merchant()->create(['zone_code' => 'SOUTH']);
        MerchantProfile::create(['user_id' => $merchant->id, 'verification_status' => 'verified']);
        EMoney::create(['user_id' => $merchant->id, 'current_balance' => '100000.0000', 'zone_code' => 'SOUTH']);

        $customer = User::factory()->create(['type' => 2, 'phone' => '+967700445566', 'zone_code' => 'SOUTH']);
        EMoney::create(['user_id' => $customer->id, 'current_balance' => '0.0000', 'zone_code' => 'SOUTH']);

        $this->paidQrRequest($merchant, 'TX-BG-SALE-1', '8000');

        $sale = app(CashierService::class)->recordSale(
            merchant: $merchant,
            total: '8000',
            paymentMethod: 'amial_pay',
            items: [['name' => 'منتج فحص', 'qty' => 1, 'price' => '8000']],
            customer: ['name' => 'عميل', 'phone' => $customer->phone],
            paidTransactionId: 'TX-BG-SALE-1',
        );

        $refundSvc = app(MerchantSaleRefundService::class);

        // استردادان جزئيان (<= 5000 حد الاعتماد الفوري) مجموعهما = 8,000 ينجحان
        $refundSvc->refund($merchant, $sale->sale_ulid, '4500', 'wallet', [], 'استرداد جزئي 1');
        $refundSvc->refund($merchant, $sale->sale_ulid, '3500', 'wallet', [], 'استرداد جزئي 2');

        // محاولة استرداد ثالث ولو بريال واحد فوق قيمة البيع يجب أن تُرفض تماماً
        $overRefundBlocked = false;
        try {
            $refundSvc->refund($merchant, $sale->sale_ulid, '1', 'wallet', [], 'محاولة استرداد زائد');
        } catch (\Throwable) {
            $overRefundBlocked = true;
        }

        $this->assertTrue($overRefundBlocked, 'سمح النظام باسترداد يتجاوز إجمالي الفاتورة الأصلية!');
        $this->assertSame('8000.0000', MoneyService::normalize((string) EMoney::where('user_id', $customer->id)->value('current_balance')));
        $this->assertSame('92000.0000', MoneyService::normalize((string) EMoney::where('user_id', $merchant->id)->value('current_balance')));
    }

    // ══════════════════════════════════════════════════════════════════════
    // 7. Four-Eyes, Dual-Approval & Maker-Checker Insider Theft Controls
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function four_eyes_and_dual_approval_controls_prevent_insider_settlement_and_reconciliation_theft(): void
    {
        config(['amial.settlements.dual_approval_threshold' => '100000']);

        $source = LedgerAccount::create([
            'account_code' => UniversalSettlementService::REVENUE_WALLET_CODE,
            'account_type' => 'liability',
            'name_ar' => 'محفظة إيرادات المنصة',
            'owner_type' => 'platform',
            'normal_balance' => 'credit',
            'current_balance' => '5000000.0000',
        ]);
        $partner = SettlementPartner::create([
            'code' => 'BG_BANK_PARTNER',
            'name_ar' => 'بنك التسوية المركزي',
            'type' => SettlementPartner::TYPE_BANK,
            'currency' => 'YER',
            'is_active' => true,
        ]);

        $maker     = User::factory()->admin()->create(['f_name' => 'مُنشئ']);
        $submitter = User::factory()->admin()->create(['f_name' => 'مُقدّم']);
        $approver1 = User::factory()->admin()->create(['f_name' => 'معتمِد1']);
        $approver2 = User::factory()->admin()->create(['f_name' => 'معتمِد2']);
        $executor  = User::factory()->admin()->create(['f_name' => 'منفّذ']);

        $svc = app(UniversalSettlementService::class);
        $settlement = $svc->create($source->id, $partner->id, '500000', $maker); // فوق حد الموافقة المزدوجة
        $this->assertSame(2, (int) $settlement->approvals_required);

        $settlement = $svc->submit($settlement, $submitter);

        // الموافقة الأولى تبقي الحالة pending_approval ولا تفتح الصرف
        $settlement = $svc->approve($settlement, $approver1);
        $this->assertSame(Settlement::STATUS_PENDING_APPROVAL, $settlement->status);

        // نفس المدير المالي لا يستطيع منح الموافقة الثانية لنفسه!
        $sameApproverBlocked = false;
        try {
            $svc->approve($settlement, $approver1);
        } catch (DomainException) {
            $sameApproverBlocked = true;
        }
        $this->assertTrue($sameApproverBlocked, 'تمكّن نفس المدير المالي من التوقيع مرتين على تسوية كبرى!');

        // المدير الثاني يكمل الاعتماد، لكن لا يحق له بدء التنفيذ وإخراج المال بنفسه
        $settlement = $svc->approve($settlement, $approver2);
        $this->assertSame(Settlement::STATUS_APPROVED, $settlement->status);

        $approverExecBlocked = false;
        try {
            $svc->startProcessing($settlement, $approver2);
        } catch (DomainException) {
            $approverExecBlocked = true;
        }
        $this->assertTrue($approverExecBlocked, 'تمكّن المعتمِد من تنفيذ الصرف بنفسه دون فصل مهام!');

        // موظف خامس مستقل وحده يبدأ التنفيذ
        $settlement = $svc->startProcessing($settlement, $executor);
        $this->assertSame(Settlement::STATUS_PROCESSING, $settlement->status);

        // وكذلك تسوية انحراف المحفظة في الدفتر تمنع أن يكون Maker === Checker
        $sameMakerCheckerBlocked = false;
        try {
            app(LedgerService::class)->reconcileWalletBalance(9999, 'محاولة تسوية ذاتية', [
                'case_ulid' => (string) Str::ulid(),
                'maker_admin_id' => $maker->id,
                'checker_admin_id' => $maker->id,
                'approval_note' => 'موافقة لنفسي',
            ]);
        } catch (RuntimeException) {
            $sameMakerCheckerBlocked = true;
        }
        $this->assertTrue($sameMakerCheckerBlocked, 'قبلت reconcileWalletBalance أن يكون المُعِد والمُراجع شخصاً واحداً!');
    }

    // ══════════════════════════════════════════════════════════════════════
    // 8. Idempotency Replay & Payload-Tampering Conflict (FAPI 2.0)
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function idempotency_middleware_prevents_duplicate_debit_and_blocks_payload_amount_tampering(): void
    {
        $admin    = User::factory()->admin()->create();
        $customer = User::factory()->create(['type' => 2, 'phone' => '+967772000111', 'zone_code' => 'SOUTH']);
        $merchant = User::factory()->merchant()->create(['phone' => '+967772000222', 'zone_code' => 'SOUTH']);
        MerchantProfile::create(['user_id' => $merchant->id, 'verification_status' => 'verified']);
        $this->establishKycEvidence($customer, 2, $admin);

        app(TransactionPinService::class)->setPin($customer, '8524');

        EMoney::create(['user_id' => $admin->id,    'current_balance' => '0.0000',     'zone_code' => 'SOUTH']);
        EMoney::create(['user_id' => $customer->id, 'current_balance' => '20000.0000', 'zone_code' => 'SOUTH']);
        EMoney::create(['user_id' => $merchant->id, 'current_balance' => '0.0000',     'zone_code' => 'SOUTH']);

        FeeScheme::create([
            'code' => 'MERCHANT_QR', 'zone_code' => 'SOUTH', 'applies_to' => 'merchant',
            'fee_type' => 'percent', 'percent_rate' => '1.0000', 'fixed_amount' => '0',
            'agent_commission_percent' => '0', 'agent_commission_fixed' => '0',
            'bearer' => 'receiver', 'version' => 1, 'is_active' => true, 'effective_from' => now(),
        ]);

        Passport::actingAs($customer->fresh(), [], 'api');
        $idemKey = 'IDEM-BANK-GRADE-' . Str::ulid();

        // الطلب الأول: يخصم 5000 مرة واحدة فقط
        $first = $this->postJson('/api/v1/amial/merchant/pay', [
            'merchant_user_id' => $merchant->id,
            'amount' => '5000',
            'channel' => 'qr',
            'pin' => '8524',
        ], ['Idempotency-Key' => $idemKey]);
        $first->assertStatus(200);

        // الإعادة بنفس الجسم والمفتاح: تعيد نفس النتيجة دون خصم ثانٍ
        $replay = $this->postJson('/api/v1/amial/merchant/pay', [
            'merchant_user_id' => $merchant->id,
            'amount' => '5000',
            'channel' => 'qr',
            'pin' => '8524',
        ], ['Idempotency-Key' => $idemKey]);
        $replay->assertStatus(200)->assertHeader('X-Idempotent-Replay', '1');
        $this->assertSame('15000.0000', MoneyService::normalize((string) EMoney::where('user_id', $customer->id)->value('current_balance')));

        // التلاعب بالمبلغ تحت نفس مفتاح Idempotency-Key → رفض 409 CONFLICT بلا أي خصم إضافي!
        $tampered = $this->postJson('/api/v1/amial/merchant/pay', [
            'merchant_user_id' => $merchant->id,
            'amount' => '15000',
            'channel' => 'qr',
            'pin' => '8524',
        ], ['Idempotency-Key' => $idemKey]);
        $tampered->assertStatus(409);
        $this->assertSame('15000.0000', MoneyService::normalize((string) EMoney::where('user_id', $customer->id)->value('current_balance')));
    }

    // ══════════════════════════════════════════════════════════════════════
    // 9. Account Security Hold Freeze (ATO Post-Recovery Defense)
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function account_under_security_hold_cannot_transfer_or_settle_payment_requests(): void
    {
        $admin     = User::factory()->admin()->create();
        $frozenUsr = User::factory()->create([
            'type' => 2,
            'phone' => '+967773000111',
            'zone_code' => 'SOUTH',
            'security_hold_until' => now()->addHours(24),
            'security_hold_reason' => 'تغيير شريحة SIM واستعادة كلمة المرور',
        ]);
        $peer = User::factory()->create(['type' => 2, 'phone' => '+967773000222', 'zone_code' => 'SOUTH']);
        $this->establishKycEvidence($frozenUsr, 2, $admin);
        $this->establishKycEvidence($peer, 2, $admin);

        EMoney::create(['user_id' => $frozenUsr->id, 'current_balance' => '50000.0000', 'zone_code' => 'SOUTH']);
        EMoney::create(['user_id' => $peer->id,      'current_balance' => '0.0000',     'zone_code' => 'SOUTH']);

        $req = app(PaymentRequestService::class)->create($peer, '10000');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('security hold');

        app(PaymentRequestService::class)->pay($frozenUsr, $req);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 10. [BG-THEFT-01] PIN Bypass on External Withdrawal POST /customer/withdraw
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function external_withdrawal_endpoint_requires_valid_transaction_pin_before_moving_funds(): void
    {
        DB::table('business_settings')->updateOrInsert(['key' => 'withdraw_request_status'], ['value' => '1']);

        $admin  = User::factory()->admin()->create();
        $victim = User::factory()->create([
            'type' => 2,
            'phone' => '+967776111222',
            'is_kyc_verified' => 1,
            'zone_code' => 'SOUTH',
        ]);
        $this->establishKycEvidence($victim, 2, $admin);
        app(TransactionPinService::class)->setPin($victim, '9517');

        EMoney::create(['user_id' => $victim->id, 'current_balance' => '80000.0000', 'zone_code' => 'SOUTH']);

        FeeScheme::create([
            'code' => 'WITHDRAW', 'zone_code' => 'SOUTH', 'applies_to' => 'customer',
            'fee_type' => 'fixed', 'percent_rate' => '0', 'fixed_amount' => '100',
            'agent_commission_percent' => '0', 'agent_commission_fixed' => '0',
            'bearer' => 'sender', 'version' => 1, 'is_active' => true, 'effective_from' => now(),
        ]);

        $method = WithdrawalMethod::create([
            'method_name' => 'تحويل بنكي خارجي',
            'method_fields' => [['input_name' => 'iban', 'input_type' => 'string']],
        ]);

        UserLogHistory::create([
            'user_id' => $victim->id,
            'device_id' => 'victim-dev-1',
            'is_active' => true,
            'is_blocked' => false,
        ]);

        Passport::actingAs($victim->fresh(), [], 'api');

        // مهاجم استولى على الجلسة ولا يعرف رمز العمليات (9517) فيرسل رمزاً عشوائياً خاطئاً (0000)!
        $res = $this->postJson('/api/v1/customer/withdraw', [
            'pin' => '0000',
            'amount' => '50000',
            'withdrawal_method_id' => $method->id,
            'withdrawal_method_fields' => json_encode([['iban' => 'ATTACKER-IBAN-999']]),
        ], [
            'device-id' => 'victim-dev-1',
            'X-Forwarded-For' => '198.51.100.55',
            'Idempotency-Key' => (string) Str::ulid(),
        ]);

        $balanceAfter = MoneyService::normalize((string) EMoney::where('user_id', $victim->id)->value('current_balance'));

        $this->assertSame(
            403,
            $res->getStatusCode(),
            '[ثغرة سرقة أموال حرجة BG-THEFT-01] المسار POST /api/v1/customer/withdraw قبل طلب السحب برمز PIN خاطئ (0000) وخصم رصيد الضحية لأن TransactionController::withdraw نسي استدعاء فحص الـ PIN!'
        );
        $this->assertSame('80000.0000', $balanceAfter, 'تم خصم رصيد الضحية رغم إدخال رمز PIN خاطئ!');

        // والاتجاه الموجب: بالرمز الصحيح (9517) ينجح طلب السحب الشرعي ويحجز (50000 + 100 رسم) بدقة عشرية
        $validRes = $this->postJson('/api/v1/customer/withdraw', [
            'pin' => '9517',
            'amount' => '50000',
            'withdrawal_method_id' => $method->id,
            'withdrawal_method_fields' => json_encode([['iban' => 'LEGIT-IBAN-111']]),
        ], [
            'device-id' => 'victim-dev-1',
            'X-Forwarded-For' => '198.51.100.55',
            'Idempotency-Key' => (string) Str::ulid(),
        ]);
        $validRes->assertStatus(200);
        $walletAfterLegit = EMoney::where('user_id', $victim->id)->firstOrFail();
        $this->assertSame('29900.0000', MoneyService::normalize((string) $walletAfterLegit->current_balance));
        $this->assertSame('50100.0000', MoneyService::normalize((string) $walletAfterLegit->pending_balance));
    }

    // ══════════════════════════════════════════════════════════════════════
    // 11. [BG-THEFT-02] Multi-Drain Replay on Approved/Denied Money Request
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function money_request_approval_cannot_be_replayed_to_drain_victim_wallet_multiple_times(): void
    {
        DB::table('business_settings')->updateOrInsert(['key' => 'send_money_status'], ['value' => '1']);

        $admin     = User::factory()->admin()->create();
        $requester = User::factory()->create(['type' => 2, 'phone' => '+967778111001', 'zone_code' => 'SOUTH']);
        $payer     = User::factory()->create(['type' => 2, 'phone' => '+967778111002', 'zone_code' => 'SOUTH']);
        $this->establishKycEvidence($requester, 2, $admin);
        $this->establishKycEvidence($payer, 2, $admin);
        app(TransactionPinService::class)->setPin($payer, '6428');

        EMoney::create(['user_id' => $admin->id,     'current_balance' => '0.0000',     'zone_code' => 'SOUTH']);
        EMoney::create(['user_id' => $requester->id, 'current_balance' => '0.0000',     'zone_code' => 'SOUTH']);
        EMoney::create(['user_id' => $payer->id,     'current_balance' => '50000.0000', 'zone_code' => 'SOUTH']);

        FeeScheme::create([
            'code' => 'SEND_MONEY', 'zone_code' => 'SOUTH', 'applies_to' => 'customer',
            'fee_type' => 'fixed', 'percent_rate' => '0', 'fixed_amount' => '0',
            'agent_commission_percent' => '0', 'agent_commission_fixed' => '0',
            'bearer' => 'sender', 'version' => 1, 'is_active' => true, 'effective_from' => now(),
        ]);

        // الطلب مسجّل في قاعدة البيانات بحالة approved (مدفوع سلفاً في طلب HTTP سابق)
        $moneyReq = new RequestMoney();
        $moneyReq->from_user_id = $requester->id;
        $moneyReq->to_user_id   = $payer->id;
        $moneyReq->type         = 'approved';
        $moneyReq->amount       = '10000';
        $moneyReq->save();

        UserLogHistory::create([
            'user_id' => $payer->id,
            'device_id' => 'payer-dev-1',
            'is_active' => true,
            'is_blocked' => false,
        ]);

        Passport::actingAs($payer->fresh(), [], 'api');

        // محاولة صرف نفس الطلب المدفوع مسبقاً (type = approved) في طلب HTTP مستقل (كما في PHP-FPM الحقيقي):
        $secondApprove = $this->postJson('/api/v1/customer/request-money/approve', [
            'id' => $moneyReq->id,
            'pin' => '6428',
        ], [
            'device-id' => 'payer-dev-1',
            'X-Forwarded-For' => '198.51.100.77',
            'Idempotency-Key' => (string) Str::ulid(),
        ]);

        $payerBalance = MoneyService::normalize((string) EMoney::where('user_id', $payer->id)->value('current_balance'));
        $requesterBalance = MoneyService::normalize((string) EMoney::where('user_id', $requester->id)->value('current_balance'));

        $this->assertNotSame(
            200,
            $secondApprove->getStatusCode(),
            "[ثغرة سرقة أموال حرجة BG-THEFT-02] قبل المسار customer/request-money/approve صرف طلب مال حالته 'approved' مسبقاً فخصم من محفظة الدافع إلى {$payerBalance}!"
        );
        $this->assertSame('50000.0000', $payerBalance);
        $this->assertSame('0.0000', $requesterBalance);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 12. [BG-THEFT-03] Negative-Amount Validation & Self-Transfer Variant Guard
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function negative_amount_and_self_transfer_variant_are_strictly_rejected_without_server_crash_or_fee_drain(): void
    {
        DB::table('business_settings')->updateOrInsert(['key' => 'send_money_status'], ['value' => '1']);

        $admin  = User::factory()->admin()->create();
        $sender = User::factory()->create(['type' => 2, 'phone' => '+967779000111', 'zone_code' => 'SOUTH']);
        $peer   = User::factory()->create(['type' => 2, 'phone' => '+967779000222', 'zone_code' => 'SOUTH']);
        $this->establishKycEvidence($sender, 2, $admin);
        $this->establishKycEvidence($peer, 2, $admin);
        app(TransactionPinService::class)->setPin($sender, '5183');

        EMoney::create(['user_id' => $admin->id,  'current_balance' => '0.0000',     'zone_code' => 'SOUTH']);
        EMoney::create(['user_id' => $sender->id, 'current_balance' => '30000.0000', 'zone_code' => 'SOUTH']);
        EMoney::create(['user_id' => $peer->id,   'current_balance' => '0.0000',     'zone_code' => 'SOUTH']);

        FeeScheme::create([
            'code' => 'SEND_MONEY', 'zone_code' => 'SOUTH', 'applies_to' => 'customer',
            'fee_type' => 'fixed', 'percent_rate' => '0', 'fixed_amount' => '150',
            'agent_commission_percent' => '0', 'agent_commission_fixed' => '0',
            'bearer' => 'sender', 'version' => 1, 'is_active' => true, 'effective_from' => now(),
        ]);

        UserLogHistory::create([
            'user_id' => $sender->id,
            'device_id' => 'sender-dev-1',
            'is_active' => true,
            'is_blocked' => false,
        ]);

        Passport::actingAs($sender->fresh(), [], 'api');

        // (أ) إرسال مبلغ سالب "-500" يجب أن يُرفض في طبقة التحقق بـ 403/422 لا أن يمرّ من Validator وينهار بـ 500
        $negRes = $this->postJson('/api/v1/customer/send-money', [
            'phone' => $peer->phone,
            'amount' => '-500',
            'pin' => '5183',
        ], [
            'device-id' => 'sender-dev-1',
            'X-Forwarded-For' => '198.51.100.88',
            'Idempotency-Key' => (string) Str::ulid(),
        ]);

        $this->assertContains(
            $negRes->getStatusCode(),
            [400, 403, 422],
            "[ثغرة BG-THEFT-03] المبلغ السالب '-500' تجاوز قواعد Validator ('required|min:0|not_in:0' بلا numeric) وأعاد {$negRes->getStatusCode()}!"
        );

        // (ب) التحويل للنفس بصيغة محلية (779000111 مقابل +967779000111) يجب أن يُرفض بـ 400 بلا انهيار ولا استنزاف عمولة
        $selfVariantRes = $this->postJson('/api/v1/customer/send-money', [
            'phone' => '779000111',
            'amount' => '1000',
            'pin' => '5183',
        ], [
            'device-id' => 'sender-dev-1',
            'X-Forwarded-For' => '198.51.100.88',
            'Idempotency-Key' => (string) Str::ulid(),
        ]);

        $this->assertContains(
            $selfVariantRes->getStatusCode(),
            [400, 403, 422],
            "التحويل للنفس بالصيغة المحلية (779000111) لم يُرفض برمز خطأ عميل 4xx (أعاد {$selfVariantRes->getStatusCode()})!"
        );
        $this->assertSame('30000.0000', MoneyService::normalize((string) EMoney::where('user_id', $sender->id)->value('current_balance')));
    }

    // ══════════════════════════════════════════════════════════════════════
    // 13. [BG-THEFT-02] Denied Money Request Resurrection Attack
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function denied_money_request_cannot_be_subsequently_approved_to_move_funds(): void
    {
        DB::table('business_settings')->updateOrInsert(['key' => 'send_money_status'], ['value' => '1']);

        $admin     = User::factory()->admin()->create();
        $requester = User::factory()->create(['type' => 2, 'phone' => '+967778222001', 'zone_code' => 'SOUTH']);
        $payer     = User::factory()->create(['type' => 2, 'phone' => '+967778222002', 'zone_code' => 'SOUTH']);
        $this->establishKycEvidence($requester, 2, $admin);
        $this->establishKycEvidence($payer, 2, $admin);
        app(TransactionPinService::class)->setPin($payer, '6428');

        EMoney::create(['user_id' => $admin->id,     'current_balance' => '0.0000',     'zone_code' => 'SOUTH']);
        EMoney::create(['user_id' => $requester->id, 'current_balance' => '0.0000',     'zone_code' => 'SOUTH']);
        EMoney::create(['user_id' => $payer->id,     'current_balance' => '50000.0000', 'zone_code' => 'SOUTH']);

        FeeScheme::create([
            'code' => 'SEND_MONEY', 'zone_code' => 'SOUTH', 'applies_to' => 'customer',
            'fee_type' => 'fixed', 'percent_rate' => '0', 'fixed_amount' => '0',
            'agent_commission_percent' => '0', 'agent_commission_fixed' => '0',
            'bearer' => 'sender', 'version' => 1, 'is_active' => true, 'effective_from' => now(),
        ]);

        // الطلب مرفوض مسبقاً في قاعدة البيانات (type = denied)
        $moneyReq = new RequestMoney();
        $moneyReq->from_user_id = $requester->id;
        $moneyReq->to_user_id   = $payer->id;
        $moneyReq->type         = 'denied';
        $moneyReq->amount       = '15000';
        $moneyReq->save();

        UserLogHistory::create([
            'user_id' => $payer->id,
            'device_id' => 'payer-dev-2',
            'is_active' => true,
            'is_blocked' => false,
        ]);

        Passport::actingAs($payer->fresh(), [], 'api');

        // الهجوم: محاولة إحياء وصرف طلب مال مرفوض مسبقاً (type = denied)!
        $approveDenied = $this->postJson('/api/v1/customer/request-money/approve', [
            'id' => $moneyReq->id,
            'pin' => '6428',
        ], [
            'device-id' => 'payer-dev-2',
            'X-Forwarded-For' => '198.51.100.78',
            'Idempotency-Key' => (string) Str::ulid(),
        ]);

        $payerBalance = MoneyService::normalize((string) EMoney::where('user_id', $payer->id)->value('current_balance'));

        $this->assertNotSame(
            200,
            $approveDenied->getStatusCode(),
            "[ثغرة سرقة أموال BG-THEFT-02] قبل المسار customer/request-money/approve صرف طلب مال مرفوض مسبقاً (denied) وخصم 15,000 ريال من محفظة العميل!"
        );
        $this->assertSame('50000.0000', $payerBalance);
    }
}
