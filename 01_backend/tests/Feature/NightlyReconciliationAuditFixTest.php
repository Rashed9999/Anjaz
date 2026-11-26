<?php

namespace Tests\Feature;

use App\CentralLogics\Helpers;
use App\Models\AgentProfile;
use App\Models\BillPaymentOrder;
use App\Models\BillProvider;
use App\Models\BillService;
use App\Models\BillServiceProduct;
use App\Models\BusinessSetting;
use App\Models\EMoney;
use App\Models\FeeScheme;
use App\Models\Ledger\LedgerAccount;
use App\Models\Ledger\LedgerEntryLine;
use App\Models\Ledger\LedgerJournalEntry;
use App\Models\MerchantProfile;
use App\Models\ReconciliationCase;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AgentNetworkService;
use App\Services\BillPay\BillProviderInterface;
use App\Services\BillPay\BillProviderResponse;
use App\Services\BillPayService;
use App\Services\LedgerReportService;
use App\Services\LedgerService;
use App\Services\MerchantPayoutService;
use App\Services\PendingTransferService;
use App\Services\Reconciliation\ReconciliationService;
use App\Services\WrongTransferRecoveryService;
use App\Support\Money\Currencies;
use App\Traits\TransactionTrait;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * اختبارات التدقيق المالي الشامل لإنذار المصالحة الليلية (recon.nightly_diverged):
 *   1. اختبارات الانحدار (Regression) لمسارات الكتابة والحجز ومنطق المطابقة.
 *   2. اختبارات التكرار (Idempotency) للترحيل والـ Backfill وقضايا المصالحة.
 *   3. اختبارات التزامن وترتيب الأقفال (Concurrency & Deterministic Locking).
 *   4. اختبارات العملات المتعددة (Multi-Currency Isolation).
 */
class NightlyReconciliationAuditFixTest extends TestCase
{
    use RefreshDatabase;
    use TransactionTrait;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function createFundedUser(
        string $balance = '100000.0000',
        int $type = CUSTOMER_TYPE,
        string $currency = Currencies::BASE,
        array $extra = [],
    ): User {
        $user = User::factory()->create(array_merge([
            'type' => $type,
            'zone_code' => 'SOUTH',
            'kyc_tier' => 3,
            'is_phone_verified' => 1,
            'is_kyc_verified' => 1,
            'verified_residence_governorate' => 'YE-AD',
            'residence_verified_at' => now(),
            'sanction_status' => 'clear',
            'sanction_checked' => true,
            'transaction_pin' => Hash::make('1234'),
            'pin_failed_attempts' => 0,
            'pin_locked_until' => null,
        ], $extra));

        EMoney::create([
            'user_id' => $user->id,
            'currency' => $currency,
            'current_balance' => $balance,
            'held_balance' => '0.0000',
            'pending_balance' => '0.0000',
            'charge_earned' => '0.0000',
            'zone_code' => 'SOUTH',
            'version' => 0,
        ]);

        return $user;
    }

    private function ledgerBalance(string $code): string
    {
        $acc = LedgerAccount::where('account_code', $code)->first();
        if (!$acc) {
            return '0.0000';
        }

        return app(LedgerService::class)->computeBalanceFromLines($acc->id);
    }

    /**
     * 1. اختبار انحدار: حسم النزاع عبر disputeTransaction يسجّل قيداً متوازناً ولا يسبّب انحرافاً في المصالحة الليلية.
     */
    public function test_dispute_transaction_posts_balanced_ledger_entry_and_reconciles_cleanly(): void
    {
        $claimant = $this->createFundedUser('50000.0000');
        $disputed = $this->createFundedUser('80000.0000');

        $this->disputeTransaction(
            ref_transaction_id: 'TX-DISPUTE-001',
            dispute_claimed_user_id: $claimant->id,
            disputed_user_id: $disputed->id,
            amount: '15000.5000',
        );

        $this->assertSame('65000.5000', (string) EMoney::where('user_id', $claimant->id)->value('current_balance'));
        $this->assertSame('64999.5000', (string) EMoney::where('user_id', $disputed->id)->value('current_balance'));

        $report = app(ReconciliationService::class)->run();
        $this->assertSame('clean', $report['status']);
        $this->assertSame(0, $report['wallets']['diverged']);
        $this->assertSame(0, bccomp('0', (string) $report['wallets']['gap'], 4));
        $this->assertSame('0.0000', $report['ledger']['net']);
    }

    /**
     * 2. اختبار انحدار: add_money_transaction تقيد المدين على محفظة الإدارة (USER_WALLET_{from}) لا على CASH_RESERVE،
     * فلا تنحرف محفظة الإدارة في المصالحة الليلية.
     */
    public function test_add_money_transaction_debits_admin_wallet_in_ledger_and_reconciles_cleanly(): void
    {
        $admin = $this->createFundedUser('500000.0000', ADMIN_TYPE);
        $customer = $this->createFundedUser('10000.0000', CUSTOMER_TYPE);

        $txId = self::add_money_transaction($admin->id, $customer->id, 25000.0);
        $this->assertNotNull($txId);

        $this->assertSame('475000.0000', (string) EMoney::where('user_id', $admin->id)->value('current_balance'));
        $this->assertSame('35000.0000', (string) EMoney::where('user_id', $customer->id)->value('current_balance'));

        $report = app(ReconciliationService::class)->run();
        $this->assertSame('clean', $report['status']);
        $this->assertSame(0, $report['wallets']['diverged']);
        $this->assertSame(0, bccomp('0', (string) $report['wallets']['gap'], 4));
    }

    /**
     * 3. اختبار انحدار: عمولة الإحالة في Helpers::add_refer_commission تسجّل قيداً متوازناً في الدفتر.
     */
    public function test_refer_commission_posts_balanced_ledger_entry_and_reconciles_cleanly(): void
    {
        $admin = $this->createFundedUser('100000.0000', ADMIN_TYPE);
        $referrer = $this->createFundedUser('5000.0000', CUSTOMER_TYPE, Currencies::BASE, [
            'unique_id' => 'REF-USER-001',
        ]);

        DB::table('business_settings')->updateOrInsert(
            ['key' => 'refer_commission'],
            ['value' => '500']
        );

        Helpers::add_refer_commission('REF-USER-001');

        $this->assertSame('99500.0000', (string) EMoney::where('user_id', $admin->id)->value('current_balance'));
        $this->assertSame('5500.0000', (string) EMoney::where('user_id', $referrer->id)->value('current_balance'));

        $report = app(ReconciliationService::class)->run();
        $this->assertSame('clean', $report['status']);
        $this->assertSame(0, $report['wallets']['diverged']);
        $this->assertSame(0, bccomp('0', (string) $report['wallets']['gap'], 4));
    }

    /**
     * 4. اختبار انحدار: الحجوزات النشطة في الخدمات الخمس (PendingTransfer، MerchantPayout، AgentPayout، WrongTransfer، BillPay)
     * تقيد في حسابات الحجز المعلّقة فوراً، فلا تظهر كانحراف محافظ أثناء فترة الحجز ولا بعد الإطلاق/الإلغاء.
     */
    public function test_active_holds_across_all_five_services_reconcile_cleanly_during_and_after_hold(): void
    {
        config()->set('amial.operational_governorates', ['YE-AD']);

        // 1) PendingTransferService
        $sender = $this->createFundedUser('100000.0000');
        $recipient = $this->createFundedUser('10000.0000');
        $pendingTransfer = app(PendingTransferService::class)->initiate(
            $sender, $recipient, '12000', '1234', fee: '100', cooldownSeconds: 60
        );
        $this->assertSame('12100.0000', $this->ledgerBalance('PENDING_TRANSFER_HOLD'));

        // 2) MerchantPayoutService
        $merchant = $this->createFundedUser('200000.0000', MERCHANT_TYPE);
        MerchantProfile::create([
            'user_id' => $merchant->id,
            'tier' => 'small',
            'verification_status' => 'verified',
            'business_type' => 'retail',
            'subscription_plan' => 'basic',
        ]);
        $payout = app(MerchantPayoutService::class)->request($merchant, '30000.0000', 'طلب سحب تاجر');
        $this->assertSame('30000.0000', $this->ledgerBalance('MERCHANT_PAYOUT_HOLD'));

        // 3) AgentNetworkService::requestPayout
        $agent = $this->createFundedUser('150000.0000', AGENT_TYPE);
        AgentProfile::create([
            'user_id' => $agent->id,
            'agent_level' => 'independent',
            'status' => 'active',
            'daily_cash_in_limit' => '500000',
            'single_transaction_limit' => '100000',
            'min_float_balance' => '5000',
            'commission_rate' => '0.50',
        ]);
        $agentSettlement = app(AgentNetworkService::class)->requestPayout($agent, '25000.0000');
        $this->assertSame('25000.0000', $this->ledgerBalance('AGENT_PAYOUT_HOLD'));

        // 4) WrongTransferRecoveryService
        $wrongSender = $this->createFundedUser('90000.0000', CUSTOMER_TYPE, Currencies::BASE, ['phone' => '+967771112233']);
        $wrongRecipient = $this->createFundedUser('0.0000', CUSTOMER_TYPE, Currencies::BASE, ['phone' => '+967771112234']);
        $txId = $this->customer_send_money_transaction(
            from_user_id: $wrongSender->id,
            to_user_id: $wrongRecipient->id,
            amount: '40000.0000',
            charge: '0.0000',
        );
        $claim = app(WrongTransferRecoveryService::class)->open($txId, '+967771112235');
        $this->assertSame('40000.0000', $this->ledgerBalance('WRONG_TRANSFER_HOLD'));

        // 5) BillPayService (pending order)
        $billUser = $this->createFundedUser('50000.0000');
        DB::table('residence_verifications')->insert([
            'user_id' => $billUser->id,
            'declared_governorate' => 'YE-AD',
            'evidence_type' => 'government_residence_document',
            'evidence_strength' => 'strong',
            'status' => 'verified',
            'submitted_at' => now()->subMinute(),
            'reviewed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        FeeScheme::create([
            'code' => 'BILL_PAY', 'label' => 'Bill pay', 'zone_code' => 'SOUTH',
            'applies_to' => 'customer', 'scope_key' => 'all', 'fee_type' => 'fixed',
            'fixed_amount' => '50.0000', 'percent_rate' => '0',
            'agent_commission_percent' => '0', 'agent_commission_fixed' => '0',
            'bearer' => 'sender', 'version' => 1, 'is_active' => true,
        ]);
        $provider = BillProvider::create([
            'code' => 'audit_provider', 'name' => 'Audit', 'display_name_ar' => 'مزود تدقيق',
            'integration_type' => 'stub', 'is_active' => true, 'zone_code' => 'SOUTH',
        ]);
        $billService = BillService::create([
            'provider_id' => $provider->id, 'code' => 'audit_srv', 'name' => 'Srv',
            'display_name_ar' => 'خدمة', 'service_type' => 'recharge',
            'is_active' => true, 'requires_account_number' => true,
        ]);
        $product = BillServiceProduct::create([
            'service_id' => $billService->id, 'product_code' => 'p1', 'name' => 'باقة',
            'amount_type' => 'fixed', 'fixed_amount' => '5000.0000',
            'fee_amount' => '0', 'fee_percent' => 0, 'is_active' => true,
        ]);
        $mockProvider = $this->createMock(BillProviderInterface::class);
        $mockProvider->method('pay')->willReturn(BillProviderResponse::pending('REF-P-1', 'Pending'));
        $mockProvider->method('name')->willReturn('mock');
        $billPayMock = $this->getMockBuilder(BillPayService::class)
            ->setConstructorArgs([
                app(\App\Services\FinancialGuardService::class),
                app(\App\Services\AuditService::class),
                app(\App\Services\ReceiptService::class),
                app(\App\Services\FeeService::class),
                app(\App\Services\KycTierService::class),
            ])
            ->onlyMethods(['resolveProvider'])
            ->getMock();
        $billPayMock->method('resolveProvider')->willReturn($mockProvider);
        $billOrder = $billPayMock->createAndExecute(
            $billUser, $provider, $billService, $product, '+967700000123', '5000.0000'
        );
        $this->assertSame('5050.0000', $this->ledgerBalance('BILL_PAY_HOLD'));

        // أثناء كون الحجوزات الخمس نشطة في نفس اللحظة: المصالحة الليلية يجب أن تكون نظيفة تماماً!
        $duringHoldReport = app(ReconciliationService::class)->run();
        $this->assertSame('clean', $duringHoldReport['status'], 'الحجوزات النشطة تسببت في انحراف كاذب أثناء المصالحة الليلية');
        $this->assertSame(0, $duringHoldReport['wallets']['diverged']);
        $this->assertSame(0, bccomp('0', (string) $duringHoldReport['wallets']['gap'], 4));
        $this->assertSame('0.0000', $duringHoldReport['ledger']['net']);

        // الآن ننهي/نلغي الحجوزات الخمس ونتحقق من تفريغ حسابات الحجز وبقاء المصالحة نظيفة:
        app(PendingTransferService::class)->cancel($pendingTransfer, $sender);
        $this->assertSame('0.0000', $this->ledgerBalance('PENDING_TRANSFER_HOLD'));

        $admin = $this->createFundedUser('0.0000', ADMIN_TYPE);
        app(MerchantPayoutService::class)->reject($payout, $admin, 'رفض لاختبار فك الحجز');
        $this->assertSame('0.0000', $this->ledgerBalance('MERCHANT_PAYOUT_HOLD'));

        app(AgentNetworkService::class)->rejectSettlement($agentSettlement, $admin, 'رفض لاختبار فك الحجز');
        $this->assertSame('0.0000', $this->ledgerBalance('AGENT_PAYOUT_HOLD'));

        app(WrongTransferRecoveryService::class)->resolve($claim, $admin->id, 'حسم الدعوى لصالح المدعي');
        $this->assertSame('0.0000', $this->ledgerBalance('WRONG_TRANSFER_HOLD'));

        $mockSuccess = $this->createMock(BillProviderInterface::class);
        $mockSuccess->method('checkStatus')->willReturn(BillProviderResponse::success('REF-OK-1', 'Done'));
        $mockSuccess->method('name')->willReturn('mock');
        $billPaySuccessMock = $this->getMockBuilder(BillPayService::class)
            ->setConstructorArgs([
                app(\App\Services\FinancialGuardService::class),
                app(\App\Services\AuditService::class),
                app(\App\Services\ReceiptService::class),
                app(\App\Services\FeeService::class),
                app(\App\Services\KycTierService::class),
            ])
            ->onlyMethods(['resolveProvider'])
            ->getMock();
        $billPaySuccessMock->method('resolveProvider')->willReturn($mockSuccess);
        $billPaySuccessMock->reconcilePendingOrder($billOrder->fresh());
        $this->assertSame('0.0000', $this->ledgerBalance('BILL_PAY_HOLD'));

        $afterHoldReport = app(ReconciliationService::class)->run();
        $this->assertSame('clean', $afterHoldReport['status']);
        $this->assertSame(0, $afterHoldReport['wallets']['diverged']);
        $this->assertSame(0, bccomp('0', (string) $afterHoldReport['wallets']['gap'], 4));
        $this->assertSame('0.0000', $afterHoldReport['ledger']['net']);
    }

    /**
     * 5. اختبار انحدار: LedgerReportService::walletTruth لا يضاعف خصم القيود المعكوسة (status = 'reversed')،
     * وحساب محفظة دفتري برصيد 0.0000 بلا صف e_money لا يُبلّغ كانحراف كاذب.
     */
    public function test_wallet_truth_handles_reversed_entries_and_zero_balance_missing_wallet_is_clean(): void
    {
        $user = $this->createFundedUser('0.0000');
        $ledger = app(LedgerService::class);
        $reserve = $ledger->getOrCreateSystemAccount('CASH_RESERVE', 'asset', 'احتياطي', 'debit');
        $walletAcc = $ledger->getOrCreateUserWallet($user->id);

        $entry = $ledger->post(
            sourceType: 'test_topup',
            sourceId: 'REV-1',
            description: 'شحن تجريبي يُعكس لاحقاً',
            lines: [
                ['account' => $reserve->account_code, 'direction' => 'debit', 'amount' => '10000.0000'],
                ['account' => $walletAcc->account_code, 'direction' => 'credit', 'amount' => '10000.0000'],
            ],
            idempotencyKey: 'test_rev_1',
        );

        $ledger->reverse($entry->id, 'عكس شحن تجريبي');

        $truth = app(LedgerReportService::class)->walletTruth($user->id);
        $this->assertSame('reconciled', $truth['state'], 'walletTruth استبعدت القيد الأصلي المعكوس وأبقت قيد العكس فنتج رصيد سالب كاذب');
        $this->assertSame('0.0000', $truth['ledger_balance']);
        $this->assertSame('0.0000', $truth['gap']);

        // حساب دفتري يتيم برصيد 0.0000 لا يجب أن يُشعل إنذار مصالحة:
        $orphanUser = User::factory()->create(['type' => CUSTOMER_TYPE, 'zone_code' => 'SOUTH']);
        $ledger->getOrCreateUserWallet($orphanUser->id);

        $recon = app(ReconciliationService::class)->run();
        $this->assertSame('clean', $recon['status']);
        $this->assertSame(0, $recon['wallets']['diverged']);
    }

    /**
     * 6. اختبار العملات المتعددة والتكرار:
     *   - محفظة USD سليمة لنفس المستخدم لا تطمس حالة محفظة YER المنحرفة إلى verifying.
     *   - amial:ledger-backfill يدعم جميع العملات ويكتسب خاصية عدم التكرار (Idempotency).
     */
    public function test_multi_currency_cases_do_not_clobber_each_other_and_backfill_is_idempotent(): void
    {
        $merchant = $this->createFundedUser('50000.0000', MERCHANT_TYPE, 'YER');
        EMoney::create([
            'user_id' => $merchant->id,
            'currency' => 'USD',
            'current_balance' => '200.0000',
            'held_balance' => '0.0000',
            'pending_balance' => '0.0000',
            'charge_earned' => '0.0000',
            'zone_code' => 'SOUTH',
            'version' => 0,
        ]);

        // نحدث انحرافاً على محفظة YER فقط بينما محفظة USD مطابقة للدفتر:
        DB::table('e_money')
            ->where('user_id', $merchant->id)
            ->where('currency', 'YER')
            ->update(['current_balance' => '65000.0000']);

        $report = app(ReconciliationService::class)->run();
        $this->assertSame('diverged', $report['status']);
        $this->assertSame(1, $report['wallets']['diverged']);
        $this->assertSame('15000.0000', $report['wallets']['gap']);

        $yerCase = ReconciliationCase::where('case_type', 'wallet')
            ->where('subject_user_id', $merchant->id)
            ->where('currency', 'YER')
            ->first();

        $this->assertNotNull($yerCase);
        $this->assertSame('detected', $yerCase->status, 'محفظة USD السليمة طمست قضية محفظة YER المنحرفة إلى verifying');

        // اختبار backfill لعملة أجنبية وُلدت بدون قيد افتتاحي (كأنها قديمة قبل المراقب):
        $legacyUser = User::factory()->create(['type' => MERCHANT_TYPE, 'zone_code' => 'SOUTH']);
        DB::table('e_money')->insert([
            'user_id' => $legacyUser->id,
            'currency' => 'USD',
            'current_balance' => '350.0000',
            'held_balance' => '0.0000',
            'pending_balance' => '0.0000',
            'charge_earned' => '0.0000',
            'zone_code' => 'SOUTH',
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('amial:ledger-backfill')->assertSuccessful();
        // إعادة التشغيل للتأكد من عدم التكرار (Idempotency):
        $this->artisan('amial:ledger-backfill')->assertSuccessful();

        $this->assertSame('350.0000', $this->ledgerBalance("USER_WALLET_{$legacyUser->id}_USD"));
    }

    /**
     * 7. اختبار انحدار: طلب السحب من بوابة التاجر (Merchant\WithdrawController::withdrawRequestStore)
     * يقيد المبلغ في WITHDRAW_PENDING فوراً ويحفظ تطابق الدفتر مع المحفظة.
     */
    public function test_merchant_web_withdraw_request_posts_withdraw_pending_and_reconciles_cleanly(): void
    {
        $merchant = $this->createFundedUser('80000.0000', MERCHANT_TYPE);
        $method = \App\Models\WithdrawalMethod::create([
            'method_name' => 'تحويل بنكي',
            'method_fields' => [
                ['input_name' => 'account_no', 'input_type' => 'text'],
            ],
        ]);

        $request = \Illuminate\Http\Request::create('/merchant/withdraw/request-store', 'POST', [
            'amount' => '20000',
            'withdrawal_method_id' => $method->id,
            'account_no' => 'IBAN-12345',
            'sender_note' => 'سحب تجريبي',
        ]);

        $this->actingAs($merchant);
        app(\App\Http\Controllers\Merchant\WithdrawController::class)->withdrawRequestStore($request);

        $this->assertSame('60000.0000', (string) EMoney::where('user_id', $merchant->id)->value('current_balance'));
        $this->assertSame('20000.0000', (string) EMoney::where('user_id', $merchant->id)->value('pending_balance'));
        $this->assertSame('20000.0000', $this->ledgerBalance('WITHDRAW_PENDING'));

        $report = app(ReconciliationService::class)->run();
        $this->assertSame('clean', $report['status']);
        $this->assertSame(0, $report['wallets']['diverged']);
        $this->assertSame(0, bccomp('0', (string) $report['wallets']['gap'], 4));
    }
}
