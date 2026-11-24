<?php

namespace Tests\Feature;

use App\Models\CustomerCreditAccount;
use App\Models\EMoney;
use App\Models\FeeScheme;
use App\Models\FxRate;
use App\Models\Ledger\LedgerAccount;
use App\Models\Ledger\LedgerEntryLine;
use App\Models\Ledger\LedgerJournalEntry;
use App\Models\MerchantProfile;
use App\Models\ReconciliationCase;
use App\Models\SafePayment;
use App\Models\SettlementPartner;
use App\Models\User;
use App\Models\WithdrawalMethod;
use App\Services\CustomerCreditSettleService;
use App\Services\CustomerWithdrawService;
use App\Services\FxConversionService;
use App\Services\LedgerReportService;
use App\Services\LedgerService;
use App\Services\Reconciliation\ReconciliationService;
use App\Services\SafePaymentService;
use App\Services\UniversalSettlementService;
use App\Support\Money\Currencies;
use App\Traits\TransactionTrait;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * اختبارات انحدار لأعطال انحراف المحافظ عن دفتر الأستاذ (recon.nightly_diverged)
 * التي تُنتج فرقاً موجباً/سالباً في e_money مقابل ledger_entry_lines مع بقاء
 * صافي الدفتر ledger_net = 0.0000.
 */
class ReconciliationDivergenceRegressionTest extends TestCase
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
            'is_active' => 1,
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

    private function ledgerBalanceOf(int $userId, string $currency = Currencies::BASE): string
    {
        $cur = Currencies::normalize($currency);
        $code = Currencies::isBase($cur) ? "USER_WALLET_{$userId}" : "USER_WALLET_{$userId}_{$cur}";
        $acc = LedgerAccount::where('account_code', $code)->first();
        if (! $acc) {
            return '0.0000';
        }

        return app(LedgerService::class)->computeBalanceFromLines($acc->id);
    }

    /**
     * 1. دفع التاجر برسوم على المرسل (bearer = sender): يجب أن يخصم الدفتر
     *    $customerDebit (amount + fee) من العميل ويضيف $net (amount) للتاجر.
     */
    public function test_merchant_payment_with_sender_borne_fee_reconciles_cleanly(): void
    {
        FeeScheme::where('code', 'MERCHANT_QR')->update(['is_active' => false]);
        FeeScheme::create([
            'code' => 'MERCHANT_QR',
            'label' => 'دفع تاجر QR برسوم على الدافع',
            'zone_code' => 'SOUTH',
            'applies_to' => 'merchant',
            'fee_type' => 'percent',
            'percent_rate' => '2.5000',
            'fixed_amount' => '0',
            'agent_commission_percent' => '0',
            'agent_commission_fixed' => '0',
            'bearer' => 'sender',
            'version' => 9,
            'is_active' => true,
            'effective_from' => now()->subMinute(),
        ]);

        $customer = $this->createFundedUser('50000.0000', CUSTOMER_TYPE);
        $merchant = $this->createFundedUser('0.0000', MERCHANT_TYPE);
        MerchantProfile::create([
            'user_id' => $merchant->id,
            'tier' => 'small',
            'verification_status' => 'verified',
            'single_receive_limit' => '5000000',
            'daily_receive_limit' => '50000000',
        ]);

        $this->merchant_payment_transaction(
            customer_user_id: $customer->id,
            merchant_user_id: $merchant->id,
            amount: '10000.0000',
            channel: 'qr',
        );

        $this->assertSame('39750.0000', (string) EMoney::where('user_id', $customer->id)->value('current_balance'));
        $this->assertSame('10000.0000', (string) EMoney::where('user_id', $merchant->id)->value('current_balance'));
        $this->assertSame('39750.0000', $this->ledgerBalanceOf($customer->id));
        $this->assertSame('10000.0000', $this->ledgerBalanceOf($merchant->id));

        $report = app(ReconciliationService::class)->run();
        $this->assertSame('clean', $report['status']);
        $this->assertSame(0, $report['wallets']['diverged']);
        $this->assertSame('0.0000', $report['ledger']['net']);
    }

    /**
     * 2. إعادة محاولة صرف العملة بنفس idempotencyKey لا تحرّك e_money مرّتين بينما الدفتر يقيّد مرّة.
     */
    public function test_fx_conversion_replay_with_same_idempotency_key_does_not_double_move_wallets(): void
    {
        $user = $this->createFundedUser('0.0000', MERCHANT_TYPE, 'YER');
        EMoney::create([
            'user_id' => $user->id,
            'currency' => 'USD',
            'current_balance' => '1000.0000',
            'held_balance' => '0.0000',
            'pending_balance' => '0.0000',
            'charge_earned' => '0.0000',
            'zone_code' => 'SOUTH',
            'version' => 0,
        ]);

        FxRate::create([
            'currency' => 'USD',
            'base_currency' => 'YER',
            'rate_to_base' => '530.50000000',
            'source' => 'test',
            'effective_at' => now()->subMinute(),
        ]);

        $svc = app(FxConversionService::class);
        $first = $svc->convert($user->id, 'USD', 'YER', '100.0000', 'fx-replay-key-001');
        $second = $svc->convert($user->id, 'USD', 'YER', '100.0000', 'fx-replay-key-001');

        $this->assertSame($first['ref'], $second['ref']);
        $this->assertSame('900.0000', (string) EMoney::inCurrency('USD')->where('user_id', $user->id)->value('current_balance'));
        $this->assertSame('53050.0000', (string) EMoney::inCurrency('YER')->where('user_id', $user->id)->value('current_balance'));
        $this->assertSame('900.0000', $this->ledgerBalanceOf($user->id, 'USD'));
        $this->assertSame('53050.0000', $this->ledgerBalanceOf($user->id, 'YER'));

        $report = app(ReconciliationService::class)->run();
        $this->assertSame('clean', $report['status']);
        $this->assertSame(0, $report['wallets']['diverged']);
    }

    /**
     * 3. إعادة استدعاء سداد الدين الآجل بنفس Idempotency-Key لا تُضاعف حركة المحفظة دون قيد دفتري.
     */
    public function test_customer_credit_settle_replay_with_same_idempotency_key_does_not_diverge(): void
    {
        $customer = $this->createFundedUser('50000.0000', CUSTOMER_TYPE);
        $merchant = $this->createFundedUser('0.0000', MERCHANT_TYPE);
        MerchantProfile::create([
            'user_id' => $merchant->id,
            'tier' => 'small',
            'verification_status' => 'verified',
            'single_receive_limit' => '5000000',
            'daily_receive_limit' => '50000000',
        ]);

        $account = CustomerCreditAccount::create([
            'merchant_user_id' => $merchant->id,
            'customer_user_id' => $customer->id,
            'customer_name' => 'عميل آجل',
            'customer_phone' => $customer->phone,
            'credit_limit' => '100000.0000',
            'current_balance' => '30000.0000',
            'status' => 'active',
        ]);

        $req = Request::create('/api/v1/amial/my-debts/settle', 'POST');
        $req->headers->set('Idempotency-Key', 'credit-settle-replay-001');
        $this->app->instance('request', $req);

        $svc = app(CustomerCreditSettleService::class);
        $svc->settle($customer, $account, '10000.0000');

        try {
            $svc->settle($customer, $account->fresh(), '10000.0000');
            $this->fail('كان ينبغي رفض تكرار مفتاح Idempotency-Key قبل خصم المحفظة ثانيةً');
        } catch (\RuntimeException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $this->assertSame('40000.0000', (string) EMoney::where('user_id', $customer->id)->value('current_balance'));
        $this->assertSame('10000.0000', (string) EMoney::where('user_id', $merchant->id)->value('current_balance'));

        $report = app(ReconciliationService::class)->run();
        $this->assertSame('clean', $report['status']);
        $this->assertSame(0, $report['wallets']['diverged']);
    }

    /**
     * 4. إلغاء أو انتهاء طلب سحب نقدي مع releaseHoldUpTo جزئي لا يقيّد في الدفتر أكثر ممّا فُكّ فعلاً.
     */
    public function test_customer_withdraw_cancel_and_expire_only_post_actually_released_amount(): void
    {
        $customer = $this->createFundedUser('50000.0000', CUSTOMER_TYPE);
        FeeScheme::where('code', 'CASH_OUT')->update(['is_active' => false]);
        FeeScheme::create([
            'code' => 'CASH_OUT',
            'label' => 'سحب مجاني للاختبار',
            'zone_code' => 'SOUTH',
            'applies_to' => 'customer',
            'fee_type' => 'fixed',
            'fixed_amount' => '0',
            'percent_rate' => '0',
            'agent_commission_percent' => '0',
            'agent_commission_fixed' => '0',
            'bearer' => 'sender',
            'version' => 9,
            'is_active' => true,
            'effective_from' => now()->subMinute(),
        ]);

        $svc = app(CustomerWithdrawService::class);
        $req1 = $svc->request($customer, '10000.0000');

        // محاكاة نقص held_balance إلى 4000 فقط (الحالة التي وُجدت لها releaseHoldUpTo)
        DB::table('e_money')->where('user_id', $customer->id)->update(['held_balance' => '4000.0000']);
        $walletBefore = (string) EMoney::where('user_id', $customer->id)->value('current_balance');
        $ledgerBefore = $this->ledgerBalanceOf($customer->id);

        $svc->cancel($customer, $req1->id);

        $walletDelta = bcsub((string) EMoney::where('user_id', $customer->id)->value('current_balance'), $walletBefore, 4);
        $ledgerDelta = bcsub($this->ledgerBalanceOf($customer->id), $ledgerBefore, 4);

        $this->assertSame('4000.0000', $walletDelta);
        $this->assertSame('4000.0000', $ledgerDelta, 'cancel() قيّد total_debit كاملاً بدل المبلغ المفكوك فعلاً');

        // اختبار المسار الثاني: expireStale()
        $req2 = $svc->request($customer, '8000.0000');
        DB::table('withdrawal_requests')->where('id', $req2->id)->update(['expires_at' => now()->subMinutes(10)]);
        DB::table('e_money')->where('user_id', $customer->id)->update(['held_balance' => '3000.0000']);

        $walletBefore2 = (string) EMoney::where('user_id', $customer->id)->value('current_balance');
        $ledgerBefore2 = $this->ledgerBalanceOf($customer->id);

        $svc->expireStale();

        $walletDelta2 = bcsub((string) EMoney::where('user_id', $customer->id)->value('current_balance'), $walletBefore2, 4);
        $ledgerDelta2 = bcsub($this->ledgerBalanceOf($customer->id), $ledgerBefore2, 4);

        $this->assertSame('3000.0000', $walletDelta2);
        $this->assertSame('3000.0000', $ledgerDelta2, 'expireStale() قيّد total_debit كاملاً بدل المبلغ المفكوك فعلاً');
    }

    /**
     * 5. إفراج الدفع الآمن للبائع يقيّد grossAmount = held_amount المتبقي لا المبلغ الأصلي عند وجود استرداد جزئي مسبق.
     */
    public function test_safe_payment_release_uses_held_amount_not_original_amount(): void
    {
        FeeScheme::where('code', 'SAFE_PAYMENT')->update(['is_active' => false]);
        FeeScheme::create([
            'code' => 'SAFE_PAYMENT',
            'label' => 'دفع آمن بلا رسم للاختبار',
            'zone_code' => 'SOUTH',
            'applies_to' => 'customer',
            'fee_type' => 'fixed',
            'fixed_amount' => '0',
            'percent_rate' => '0',
            'agent_commission_percent' => '0',
            'agent_commission_fixed' => '0',
            'bearer' => 'sender',
            'version' => 9,
            'is_active' => true,
            'effective_from' => now()->subMinute(),
        ]);

        $buyer = $this->createFundedUser('50000.0000', CUSTOMER_TYPE);
        $seller = $this->createFundedUser('0.0000', CUSTOMER_TYPE);

        $svc = app(SafePaymentService::class);
        $payment = $svc->createAndFund($buyer, $seller, 'هاتف', 'وصف', '10000.0000');
        $svc->sellerAccept($payment, $seller);
        $svc->sellerMarkInDelivery($payment->fresh(), $seller);
        $svc->sellerMarkDelivered($payment->fresh(), $seller);

        // محاكاة استرداد جزئي سابق بقيمة 4000 من الضمان (متبقّي في الضمان = 6000)
        DB::transaction(function () use ($buyer, $payment) {
            $this->guard()->credit($buyer->id, '4000.0000', 'test_partial');
            $this->ledgerRefundEscrow($buyer->id, '4000.0000', $payment->payment_ulid.'_p1', 'استرداد جزئي مسبق');
            $payment->update(['held_amount' => '6000.0000', 'refunded_to_buyer_amount' => '4000.0000']);
        });

        $svc->buyerConfirm($payment->fresh(), $buyer);

        $this->assertSame('6000.0000', (string) EMoney::where('user_id', $seller->id)->value('current_balance'));
        $this->assertSame('6000.0000', $this->ledgerBalanceOf($seller->id));

        $report = app(ReconciliationService::class)->run();
        $this->assertSame('clean', $report['status']);
        $this->assertSame(0, $report['wallets']['diverged']);
    }

    /**
     * 6. محرك التسويات العام لا يسمح بخصم حساب USER_WALLET_* دفترياً دون تحريك e_money.
     */
    public function test_universal_settlement_rejects_user_wallet_as_source_account(): void
    {
        $admin = $this->createFundedUser('0.0000', ADMIN_TYPE);
        $customer = $this->createFundedUser('50000.0000', CUSTOMER_TYPE);
        $userWalletAccount = LedgerAccount::where('account_code', "USER_WALLET_{$customer->id}")->firstOrFail();

        $partner = SettlementPartner::create([
            'code' => 'TEST_PARTNER',
            'name_ar' => 'شريك اختبار',
            'name_en' => 'Test Partner',
            'type' => 'bank',
            'is_active' => true,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        app(UniversalSettlementService::class)->create(
            sourceAccountId: $userWalletAccount->id,
            partnerId: $partner->id,
            amount: '5000.0000',
            actor: $admin,
        );
    }

    /**
     * 7. amial:ledger-backfill يمرّ على كل الصفوف مع chunk صغيرة حتى لو اختلف ترتيب user_id عن id،
     *    وينفّذ --execute-approved-cases حتى عندما يكون للمحفظة رصيد دفتري قائم غير صفري.
     */
    public function test_ledger_backfill_paginates_all_rows_and_executes_approved_cases_with_history(): void
    {
        // (أ) إنشاء محافظ قديمة بترتيب user_id عكسي لترتيب e_money.id ثم تشغيل chunk=2
        $u1 = User::factory()->create(['type' => CUSTOMER_TYPE, 'zone_code' => 'SOUTH']);
        $u2 = User::factory()->create(['type' => CUSTOMER_TYPE, 'zone_code' => 'SOUTH']);
        $u3 = User::factory()->create(['type' => CUSTOMER_TYPE, 'zone_code' => 'SOUTH']);
        $u4 = User::factory()->create(['type' => CUSTOMER_TYPE, 'zone_code' => 'SOUTH']);

        foreach ([[$u4, '4000.0000'], [$u3, '3000.0000'], [$u2, '2000.0000'], [$u1, '1000.0000']] as [$u, $bal]) {
            DB::table('e_money')->insert([
                'user_id' => $u->id,
                'currency' => 'YER',
                'current_balance' => $bal,
                'charge_earned' => '0.0000',
                'pending_balance' => '0.0000',
                'held_balance' => '0.0000',
                'zone_code' => 'SOUTH',
                'version' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->artisan('amial:ledger-backfill', ['--chunk' => 2])->assertSuccessful();

        $this->assertSame('1000.0000', $this->ledgerBalanceOf($u1->id));
        $this->assertSame('2000.0000', $this->ledgerBalanceOf($u2->id));
        $this->assertSame('3000.0000', $this->ledgerBalanceOf($u3->id));
        $this->assertSame('4000.0000', $this->ledgerBalanceOf($u4->id));

        // (ب) محفظة ذات تاريخ دفتري (10000) وانحرفت إلى 15500.5000 مع قضية معتمدة بأربع عيون
        $drifted = $this->createFundedUser('10000.0000', MERCHANT_TYPE);
        $maker = User::factory()->create(['type' => ADMIN_TYPE]);
        $checker = User::factory()->create(['type' => ADMIN_TYPE]);

        DB::table('e_money')->where('user_id', $drifted->id)->update(['current_balance' => '15500.5000']);

        $case = ReconciliationCase::create([
            'case_ulid' => (string) Str::ulid(),
            'case_type' => 'wallet',
            'source' => 'nightly_wallet_reconciliation',
            'subject_user_id' => $drifted->id,
            'expected_amount' => '15500.5000',
            'actual_amount' => '10000.0000',
            'difference' => '5500.5000',
            'currency' => 'YER',
            'status' => 'pending_approval',
            'severity' => 'high',
            'first_detected_at' => now(),
            'last_detected_at' => now(),
            'detection_count' => 2,
            'maker_admin_id' => $maker->id,
            'checker_admin_id' => $checker->id,
            'action_taken' => 'اعتماد تسوية فرق تاريخي موثّق',
        ]);

        $this->artisan('amial:ledger-backfill', ['--execute-approved-cases' => true])->assertSuccessful();

        $this->assertSame('15500.5000', $this->ledgerBalanceOf($drifted->id));
        $this->assertSame('corrected', $case->fresh()->status);

        $report = app(ReconciliationService::class)->run();
        $this->assertSame('clean', $report['status']);
        $this->assertSame(0, $report['wallets']['diverged']);
    }

    /**
     * 8. مطابقة المحافظ تطبّع رمز العملة ولا تُصدر انحرافاً كاذباً بسبب حالة الأحرف،
     *    وطلب السحب من API العميل يحفظ التطابق الدقيق بالأعشار (.5000) داخل معاملة قافلة.
     */
    public function test_wallet_reconciliation_normalizes_currency_and_customer_withdraw_api_stays_clean(): void
    {
        $u = $this->createFundedUser('25000.5000', CUSTOMER_TYPE);
        DB::table('ledger_accounts')
            ->where('account_code', "USER_WALLET_{$u->id}")
            ->update(['currency' => 'yer']);

        $recon = app(LedgerReportService::class)->walletReconciliation();
        $this->assertSame(0, $recon['divergent'], 'اختلاف حالة أحرف العملة (yer/YER) أحدث انحرافاً كاذباً');

        // تفعيل طلب السحب واختبار مسار API العميل بدقة عشرية
        DB::table('business_settings')->updateOrInsert(['key' => 'withdraw_request_status'], ['value' => '1']);
        FeeScheme::where('code', 'WITHDRAW')->update(['is_active' => false]);
        FeeScheme::create([
            'code' => 'WITHDRAW',
            'label' => 'رسم سحب اختباري',
            'zone_code' => 'SOUTH',
            'applies_to' => 'customer',
            'fee_type' => 'fixed',
            'fixed_amount' => '100.5000',
            'percent_rate' => '0',
            'agent_commission_percent' => '0',
            'agent_commission_fixed' => '0',
            'bearer' => 'sender',
            'version' => 9,
            'is_active' => true,
            'effective_from' => now()->subMinute(),
        ]);

        $method = WithdrawalMethod::create([
            'method_name' => 'تحويل بنكي',
            'method_fields' => [
                ['input_name' => 'account_no', 'input_type' => 'text'],
            ],
        ]);

        $req = Request::create('/api/v1/customer/withdraw', 'POST', [
            'pin' => '1234',
            'amount' => '5000.0000',
            'withdrawal_method_id' => $method->id,
            'withdrawal_method_fields' => json_encode([['account_no' => 'ACC-999']]),
            'sender_note' => 'سحب API اختباري',
        ]);
        $req->setUserResolver(fn () => $u);

        $resp = app(\App\Http\Controllers\Api\V1\Customer\TransactionController::class)->withdraw($req);
        $this->assertSame(200, $resp->getStatusCode());

        $this->assertSame('19900.0000', (string) EMoney::where('user_id', $u->id)->value('current_balance'));
        $this->assertSame('5100.5000', (string) EMoney::where('user_id', $u->id)->value('pending_balance'));

        $report = app(ReconciliationService::class)->run();
        $this->assertSame('clean', $report['status']);
        $this->assertSame(0, $report['wallets']['diverged']);
    }
}
