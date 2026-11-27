<?php

namespace Tests\Feature;

use App\Models\Agent\AgentBranch;
use App\Models\AmialNotification;
use App\Models\CharityCampaign;
use App\Models\CharityCategory;
use App\Models\CharityOrganization;
use App\Models\EMoney;
use App\Models\FeatureFlag;
use App\Models\FeeScheme;
use App\Models\Ledger\LedgerEntryLine;
use App\Models\PlatformFeeEntry;
use App\Models\Receipt;
use App\Models\User;
use App\Services\AgentBranchService;
use App\Services\AgentCounterService;
use App\Services\AgentNetworkService;
use App\Services\MoneyService;
use App\Services\Otp\PasswordResetGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\StartsWithSafePaymentIntakeOpen;
use Tests\TestCase;

/**
 * AMIAL-CUSTOMER-JOURNEY-P2-001
 *
 * محاكاة واقعية متكاملة لرحلة «العميل الأول — المرحلة الثانية» من رصيد صفري:
 *   1) نسيان كلمة المرور (مع منع تطابقها مع رمز PIN وإبطال الجلسات السابقة)
 *   2) نسيان رمز PIN (مع منع الرموز السهلة واعتماد رمز من 6 أرقام)
 *   3) إيداع نقدي حقيقي عبر شبّاك فرع وكيل مموَّل من الخزانة + قراءة رقم الحساب
 *   4) تحويل لعميل آخر (تسعيرة الخادم + منع تزوير حقل fee + إلغاء داخل المهلة + تسليم كامل وإشعارات وإيصالان)
 *   5) صندوق العائلة (إنشاء + دعوة وقبول + فرض رمز PIN خادمياً في المساهمة والاعتماد)
 *   6) الدفع الآمن (تحقق البائع + حجز برمز PIN + قبول البائع + رمز التسليم السري للمشتري + إفراج برمز PIN)
 *   7) التبرعات (تصفّح الحملات الموثّقة + تبرّع برمز PIN + ظهور التبرع في «تبرعاتي» والإيصالات)
 *   8) السحب النقدي (فرض رمز PIN خادمياً عند الطلب + إلغاء وفكّ حجز + طلب جديد وصرفه عند الوكيل)
 *   9) التحقق الختامي من توازن القيد المحاسبي المزدوج (`sum(debits) === sum(credits)`).
 */
class CustomerPhase2FinancialJourneyTest extends TestCase
{
    use RefreshDatabase;
    use StartsWithSafePaymentIntakeOpen;

    /** @var array<int, mixed> */
    private array $emails = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->startWithSafePaymentIntakeOpen();

        config([
            'amial.encryption.pii_key' => base64_encode(random_bytes(32)),
            'amial.encryption.blind_index_key' => base64_encode(random_bytes(32)),
            'amial.operational_governorates' => ['YE-AD'],
            'amial_otp.resend.api_key' => 'test-resend-key',
            'amial_otp.password_reset_channel' => 'email',
            'amial_otp.pin_recovery_channel' => 'email',
            'amial_otp.max_attempts' => 5,
            'amial_otp.resend_seconds' => 60,
            'amial_otp.ttl_minutes' => 5,
            'amial.donations.fee_percent' => '1.0',
            'amial.donations.min_amount' => '1.0000',
            'amial.donations.max_amount' => '50000.0000',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'api.resend.com/emails' => function ($request) {
                $this->emails[] = $request;
                return Http::response(['id' => 'email-' . count($this->emails)], 200);
            },
        ]);

        FeatureFlag::updateOrCreate(
            ['key' => 'safe_payment'],
            ['name_ar' => 'الدفع الآمن', 'category' => 'financial', 'enabled' => true],
        );
        FeatureFlag::updateOrCreate(
            ['key' => 'donations'],
            ['name_ar' => 'التبرعات', 'category' => 'financial', 'enabled' => true],
        );

        $this->seedFeeSchemes();
    }

    private function seedFeeSchemes(): void
    {
        // إيداع عبر وكيل مجاني للعميل (نسخة صريحة بصفر كما يفرض AMIAL-FEE-TRUTH-006)
        FeeScheme::create([
            'code' => 'AGENT_DEPOSIT',
            'zone_code' => 'SOUTH',
            'applies_to' => 'customer',
            'fee_type' => 'fixed',
            'percent_rate' => '0.0000',
            'fixed_amount' => '0.0000',
            'agent_commission_percent' => '0.0000',
            'agent_commission_fixed' => '0.0000',
            'bearer' => 'sender',
            'version' => 1,
            'is_active' => true,
            'effective_from' => now()->subHour(),
        ]);

        // تحويل بين الأفراد برسم 2% يتحمّله المرسل
        FeeScheme::create([
            'code' => 'SEND_MONEY',
            'zone_code' => 'SOUTH',
            'applies_to' => 'customer',
            'fee_type' => 'percent',
            'percent_rate' => '2.0000',
            'fixed_amount' => '0.0000',
            'agent_commission_percent' => '0.0000',
            'agent_commission_fixed' => '0.0000',
            'bearer' => 'sender',
            'version' => 1,
            'is_active' => true,
            'effective_from' => now()->subHour(),
        ]);

        // سحب نقدي عبر وكيل برسم 5% (40% عمولة وكيل، 60% إيراد منصّة)
        FeeScheme::create([
            'code' => 'CASH_OUT',
            'zone_code' => 'SOUTH',
            'applies_to' => 'customer',
            'fee_type' => 'percent',
            'percent_rate' => '5.0000',
            'fixed_amount' => '0.0000',
            'agent_commission_percent' => '40.0000',
            'agent_commission_fixed' => '0.0000',
            'bearer' => 'sender',
            'version' => 1,
            'is_active' => true,
            'effective_from' => now()->subHour(),
        ]);
    }

    private function lastEmailOtp(): string
    {
        $last = $this->emails[array_key_last($this->emails)];
        $this->assertSame(1, preg_match('/(?<!\d)\d{6}(?!\d)/u', $last['text'], $m));
        return $m[0];
    }

    private function makeVerifiedCustomer(array $attrs): User
    {
        $user = User::factory()->create(array_merge([
            'type' => CUSTOMER_TYPE,
            'role' => 'customer',
            'zone_code' => 'SOUTH',
            'kyc_tier' => 2,
            'is_active' => 1,
            'is_phone_verified' => 1,
            'is_email_verified' => 1,
            'email_verified_at' => now(),
            'is_kyc_verified' => 1,
            'verified_residence_governorate' => 'YE-AD',
            'residence_verified_at' => now(),
            'sanction_status' => 'clear',
            'sanction_checked' => true,
            'pin_failed_attempts' => 0,
            'pin_locked_until' => null,
        ], $attrs));

        EMoney::create([
            'user_id' => $user->id,
            'current_balance' => '0.0000',
            'held_balance' => '0.0000',
            'pending_balance' => '0.0000',
            'charge_earned' => '0.0000',
            'zone_code' => 'SOUTH',
        ]);

        return $user;
    }

    private function walletBalance(User $user): string
    {
        return (string) EMoney::where('user_id', $user->id)->value('current_balance');
    }

    private function walletHeld(User $user): string
    {
        return (string) EMoney::where('user_id', $user->id)->value('held_balance');
    }

    #[Test]
    public function customer_1_completes_full_phase_2_journey_with_balanced_double_entry_ledger(): void
    {
        $admin = User::factory()->create([
            'type' => ADMIN_TYPE,
            'role' => 'super_admin',
            'phone' => '+967770009900',
            'is_active' => 1,
            'zone_code' => 'SOUTH',
        ]);
        EMoney::create([
            'user_id' => $admin->id,
            'current_balance' => '0.0000',
            'held_balance' => '0.0000',
            'pending_balance' => '0.0000',
            'charge_earned' => '0.0000',
            'zone_code' => 'SOUTH',
        ]);

        $customer1 = $this->makeVerifiedCustomer([
            'f_name' => 'سالم',
            'l_name' => 'الحضرمي',
            'phone' => '+967771112233',
            'email' => 'customer1.journey@example.com',
            'account_number' => null,
            'password' => Hash::make('Initial#Pass1'),
            'transaction_pin' => Hash::make('5829'),
            'transaction_pin_set_at' => now(),
        ]);
        $customer1AccountNumber = app(\App\Services\AccountNumberService::class)->assign($customer1);

        $customer2 = $this->makeVerifiedCustomer([
            'f_name' => 'أحمد',
            'l_name' => 'باعبيد',
            'phone' => '+967772223344',
            'email' => 'customer2.journey@example.com',
            'account_number' => null,
            'password' => Hash::make('Peer#Pass2'),
            'transaction_pin' => Hash::make('4927'),
            'transaction_pin_set_at' => now(),
        ]);
        $customer2AccountNumber = app(\App\Services\AccountNumberService::class)->assign($customer2);

        // =====================================================================
        // ١) نسيان كلمة المرور (Forgot Password via Email OTP)
        // =====================================================================
        $pwReq = $this->postJson('/api/v1/auth/email-otp/request', [
            'email' => $customer1->email,
            'purpose' => 'password_reset',
        ])->assertOk();
        $pwChallengeId = (string) $pwReq->json('meta.challenge_id');

        $pwVerify = $this->postJson('/api/v1/auth/email-otp/verify', [
            'challenge_id' => $pwChallengeId,
            'email' => $customer1->email,
            'purpose' => 'password_reset',
            'otp' => $this->lastEmailOtp(),
        ])->assertOk();
        $pwToken = (string) $pwVerify->json('meta.verification_token');

        // فصل الأسرار: رفض كلمة مرور تطابق رمز PIN الحالي دون إتلاف إثبات التحقق
        $this->postJson('/api/v1/auth/password-reset/email', [
            'challenge_id' => $pwChallengeId,
            'email' => $customer1->email,
            'verification_token' => $pwToken,
            'password' => '5829',
            'password_confirmation' => '5829',
        ])->assertStatus(422)
          ->assertJsonPath('code', 'SECRET_SEPARATION_REQUIRED');

        // نجاح تعيين كلمة مرور قوية بنفس إثبات التحقق
        $this->postJson('/api/v1/auth/password-reset/email', [
            'challenge_id' => $pwChallengeId,
            'email' => $customer1->email,
            'verification_token' => $pwToken,
            'password' => 'Recovered#2026',
            'password_confirmation' => 'Recovered#2026',
        ])->assertOk()
          ->assertJsonPath('code', 'PASSWORD_RESET');

        $this->assertTrue(Hash::check('Recovered#2026', $customer1->fresh()->password));

        // =====================================================================
        // ٢) نسيان رمز المعاملات (Forgot PIN → رمز جديد من 6 أرقام)
        // =====================================================================
        $this->travel(61)->seconds();
        $pinReq = $this->postJson('/api/v1/auth/email-otp/request', [
            'email' => $customer1->email,
            'purpose' => 'pin_recovery',
        ])->assertOk();
        $pinChallengeId = (string) $pinReq->json('meta.challenge_id');

        $pinVerify = $this->postJson('/api/v1/auth/email-otp/verify', [
            'challenge_id' => $pinChallengeId,
            'email' => $customer1->email,
            'purpose' => 'pin_recovery',
            'otp' => $this->lastEmailOtp(),
        ])->assertOk();
        $pinToken = (string) $pinVerify->json('meta.verification_token');

        // رفض الرمز السهل مع بقاء التحدّي صالحاً
        $this->postJson('/api/v1/auth/pin-recovery/email', [
            'challenge_id' => $pinChallengeId,
            'email' => $customer1->email,
            'verification_token' => $pinToken,
            'new_pin' => '1234',
            'new_pin_confirmation' => '1234',
        ])->assertStatus(422)
          ->assertJsonPath('code', 'WEAK_PIN');

        // اعتماد رمز من 6 أرقام (739582)
        $this->postJson('/api/v1/auth/pin-recovery/email', [
            'challenge_id' => $pinChallengeId,
            'email' => $customer1->email,
            'verification_token' => $pinToken,
            'new_pin' => '739582',
            'new_pin_confirmation' => '739582',
        ])->assertOk()
          ->assertJsonPath('code', 'PIN_RESET');

        Passport::actingAs($customer1->fresh(), [], 'api');
        $this->postJson('/api/v1/amial/verify-pin', ['pin' => '5829'])->assertStatus(403);
        $this->postJson('/api/v1/amial/verify-pin', ['pin' => '739582'])->assertOk();

        // =====================================================================
        // ٣) إيداع نقدي لدى وكيل (الخزانة ← الشركة الأم ← فرع الوكيل ← العميل ١)
        // =====================================================================
        $this->getJson('/api/v1/amial/me/account-number')
            ->assertOk()
            ->assertJsonPath('meta.account_number', $customer1AccountNumber);

        $agentCompany = User::factory()->create([
            'type' => AGENT_TYPE,
            'role' => 'agent',
            'phone' => '+967773334455',
            'is_active' => 1,
            'zone_code' => 'SOUTH',
        ]);
        $branchUser = User::factory()->create([
            'type' => AGENT_TYPE,
            'role' => 'agent',
            'phone' => '+967774445566',
            'is_active' => 1,
            'zone_code' => 'SOUTH',
        ]);
        foreach ([$agentCompany, $branchUser] as $ag) {
            EMoney::create([
                'user_id' => $ag->id,
                'current_balance' => '0.0000',
                'held_balance' => '0.0000',
                'pending_balance' => '0.0000',
                'charge_earned' => '0.0000',
                'zone_code' => 'SOUTH',
            ]);
        }

        $branch = AgentBranch::create([
            'agent_user_id' => $agentCompany->id,
            'branch_user_id' => $branchUser->id,
            'name' => 'فرع المنصورة الرئيسي',
            'code' => 'BR-ADEN-01',
            'city' => 'عدن',
            'is_active' => true,
        ]);

        // شحن الوكيل من الخزانة ثم تمويل الفرع ثم إيداع 100,000 ر.ي للعميل ١
        app(AgentNetworkService::class)->adminCreditAgent($agentCompany, '250000.0000', $admin);
        app(AgentBranchService::class)->fundBranch($branch, $agentCompany, '150000.0000', 'تمويل تشغيل الفرع');
        $depositResult = app(AgentCounterService::class)->deposit(
            $branch->fresh(),
            $customer1->fresh(),
            '100000.0000',
            $branchUser,
            'إيداع نقدي أول للعميل ١',
        );

        $this->assertNotEmpty($depositResult['reference']);
        $this->assertSame('100000.0000', $this->walletBalance($customer1));
        $this->assertTrue(
            Receipt::where('user_id', $customer1->id)
                ->where('receipt_type', 'cash_in')
                ->exists(),
            'إيصال الإيداع النقدي لدى الوكيل يجب أن يصدر للعميل ١',
        );

        // =====================================================================
        // ٤) تحويل لعميل آخر (تحقق + تسعيرة الخادم + منع تزوير fee + إلغاء + تسليم)
        // =====================================================================
        Passport::actingAs($customer1->fresh(), [], 'api');

        $verifyRes = $this->postJson('/api/v1/amial/transfer/verify-recipient', [
            'phone' => '772223344',
            'amount' => '10000',
        ])->assertOk();

        $this->assertSame($customer2->id, $verifyRes->json('meta.recipient_id'));
        $this->assertSame('200.0000', $verifyRes->json('meta.fee_quote.fee'));
        $this->assertSame('10200.0000', $verifyRes->json('meta.fee_quote.total_debit'));

        // محاولة تزوير حقل fee=0 من العميل: الخادم يتجاهله ويحتسب 200.0000 من FeeService
        $init1 = $this->postJson('/api/v1/amial/transfer/initiate', [
            'recipient_id' => $customer2->id,
            'verification_token' => $verifyRes->json('meta.verification_token'),
            'amount' => '10000',
            'pin' => '739582',
            'fee' => '0',
            'note' => 'تجربة تراجع',
        ], ['Idempotency-Key' => (string) Str::ulid()])->assertOk();

        $ulid1 = (string) $init1->json('meta.transfer_ulid');
        $this->assertSame('200.0000', $init1->json('meta.fee'), 'يجب ألّا يثق الخادم بـ fee المرسل من العميل');
        $this->assertSame('10200.0000', $init1->json('meta.total_debited'));
        $this->assertSame('89800.0000', $this->walletBalance($customer1));

        // إلغاء التحويل خلال نافذة التراجع يسترد أصل المبلغ + الرسم كاملاً (10,200)
        $this->postJson("/api/v1/amial/transfer/{$ulid1}/cancel", [], ['Idempotency-Key' => (string) Str::ulid()])
            ->assertOk()
            ->assertJsonPath('meta.refunded', '10200.0000');
        $this->assertSame('100000.0000', $this->walletBalance($customer1));

        // تنفيذ تحويل فعلي وتسليمه بعد انتهاء نافذة التراجع
        $verifyRes2 = $this->postJson('/api/v1/amial/transfer/verify-recipient', [
            'phone' => $customer2AccountNumber,
            'amount' => '10000',
        ])->assertOk();

        $init2 = $this->postJson('/api/v1/amial/transfer/initiate', [
            'recipient_id' => $customer2->id,
            'verification_token' => $verifyRes2->json('meta.verification_token'),
            'amount' => '10000',
            'pin' => '739582',
            'note' => 'دفعة إيجار',
        ], ['Idempotency-Key' => (string) Str::ulid()])->assertOk();

        $ulid2 = (string) $init2->json('meta.transfer_ulid');
        $seconds = (int) $init2->json('meta.seconds_remaining');
        $this->travel($seconds + 2)->seconds();

        // استعلام الشاشة عند انتهاء العدّاد يطلق التسليم الفوري (Lazy Release)
        $this->getJson("/api/v1/amial/transfer/{$ulid2}/status")
            ->assertOk()
            ->assertJsonPath('meta.status', 'completed');

        $this->assertSame('89800.0000', $this->walletBalance($customer1));
        $this->assertSame('10000.0000', $this->walletBalance($customer2));
        $this->assertTrue(
            PlatformFeeEntry::where('source_type', SEND_MONEY)
                ->where('from_user_id', $customer1->id)
                ->where('amount', '200.0000')
                ->exists(),
            'يجب تسجيل إيراد رسم التحويل مع source_type=send_money',
        );
        $this->assertTrue(
            AmialNotification::where('user_id', $customer2->id)->where('type', 'money_received')->exists(),
            'يجب إشعار المستلم بوصول الحوالة',
        );
        $this->assertTrue(
            AmialNotification::where('user_id', $customer1->id)->where('type', 'transfer_completed')->exists(),
            'يجب إشعار المرسل باكتمال التسليم',
        );

        // =====================================================================
        // ٥) صندوق العائلة (إنشاء + دعوة + قبول + مساهمة بـ PIN + اقتراح واعتماد بـ PIN)
        // =====================================================================
        $fundRes = $this->postJson('/api/v1/amial/funds', [
            'name' => 'صندوق عائلة الحضرمي',
            'description' => 'تكافل الأسرة الشهري',
            'require_owner_approval_for_disbursement' => true,
            'target_amount' => '50000',
        ], ['Idempotency-Key' => (string) Str::ulid()])->assertStatus(201);
        $fundUlid = (string) $fundRes->json('meta.fund.fund_ulid');

        $inviteRes = $this->postJson("/api/v1/amial/funds/{$fundUlid}/invite", [
            'phone' => '772223344',
            'role' => 'member',
        ], ['Idempotency-Key' => (string) Str::ulid()])->assertStatus(201);
        $membershipId = (int) $inviteRes->json('meta.membership.id');

        // العميل ٢ يقبل الدعوة
        Passport::actingAs($customer2->fresh(), [], 'api');
        $this->postJson("/api/v1/amial/funds/memberships/{$membershipId}/accept", [], [
            'Idempotency-Key' => (string) Str::ulid(),
        ])->assertOk();

        // العميل ١ يساهم بـ 15,000 ر.ي: يُرفض بلا PIN أو برمز خاطئ، وينجح برمز PIN الصحيح
        Passport::actingAs($customer1->fresh(), [], 'api');
        $this->postJson("/api/v1/amial/funds/{$fundUlid}/contribute", [
            'amount' => '15000',
        ], ['Idempotency-Key' => (string) Str::ulid()])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PIN_REQUIRED');

        $this->postJson("/api/v1/amial/funds/{$fundUlid}/contribute", [
            'amount' => '15000',
            'pin' => '111111',
        ], ['Idempotency-Key' => (string) Str::ulid()])
            ->assertStatus(403)
            ->assertJsonPath('code', 'PIN_INVALID');

        $this->postJson("/api/v1/amial/funds/{$fundUlid}/contribute", [
            'amount' => '15000',
            'pin' => '739582',
            'note' => 'مساهمة الشهر',
        ], ['Idempotency-Key' => (string) Str::ulid()])
            ->assertStatus(201)
            ->assertJsonPath('code', 'CONTRIBUTE_OK');

        // الرصيد الآن: 89,800 - 15,000 = 74,800
        $this->assertSame('74800.0000', $this->walletBalance($customer1));

        // العميل ٢ يطلب صرف 5,000 ر.ي برمزه هو (4927) → بانتظار موافقة المالك
        Passport::actingAs($customer2->fresh(), [], 'api');
        $disbRes = $this->postJson("/api/v1/amial/funds/{$fundUlid}/propose-disbursement", [
            'beneficiary_user_id' => $customer2->id,
            'amount' => '5000',
            'pin' => '4927',
            'note' => 'مستلزمات دراسية',
        ], ['Idempotency-Key' => (string) Str::ulid()])
            ->assertStatus(201)
            ->assertJsonPath('code', 'DISBURSEMENT_PENDING');
        $txUlid = (string) $disbRes->json('meta.transaction.tx_ulid');

        // العميل ١ (المالك) لا يمكنه الاعتماد بلا PIN، وينجح برمز PIN (739582)
        Passport::actingAs($customer1->fresh(), [], 'api');
        $this->postJson("/api/v1/amial/funds/disbursements/{$txUlid}/approve", [], [
            'Idempotency-Key' => (string) Str::ulid(),
        ])->assertStatus(422)
          ->assertJsonPath('code', 'PIN_REQUIRED');

        $this->postJson("/api/v1/amial/funds/disbursements/{$txUlid}/approve", [
            'pin' => '739582',
        ], ['Idempotency-Key' => (string) Str::ulid()])
            ->assertOk()
            ->assertJsonPath('code', 'DISBURSEMENT_OK');

        $this->assertSame('15000.0000', $this->walletBalance($customer2)); // 10,000 + 5,000

        // =====================================================================
        // ٦) الدفع الآمن (Escrow: حجز 20,000 ر.ي ← قبول ← رمز تسليم ← إفراج)
        // =====================================================================
        $sellerVerify = $this->postJson('/api/v1/amial/safe-payments/verify-seller', [
            'seller_phone' => '772223344',
        ])->assertOk();

        $spCreate = $this->postJson('/api/v1/amial/safe-payments', [
            'seller_phone' => '772223344',
            'seller_verification_token' => $sellerVerify->json('meta.verification_token'),
            'pin' => '739582',
            'title' => 'حاسوب محمول مستعمل',
            'description' => 'حاسوب بحالة ممتازة مع الشاحن الأصلي والحقيبة',
            'amount' => '20000',
        ], ['Idempotency-Key' => (string) Str::ulid()])
            ->assertStatus(201)
            ->assertJsonPath('code', 'SAFE_PAYMENT_CREATED');

        $spUlid = (string) $spCreate->json('meta.payment.payment_ulid');
        // الرصيد الآن: 74,800 - 20,000 = 54,800
        $this->assertSame('54800.0000', $this->walletBalance($customer1));

        // البائع (العميل ٢) يقبل الصفقة ولا يرى رمز التسليم
        Passport::actingAs($customer2->fresh(), [], 'api');
        $this->postJson("/api/v1/amial/safe-payments/{$spUlid}/seller-accept", [], [
            'Idempotency-Key' => (string) Str::ulid(),
        ])->assertOk();
        $this->assertNull(
            $this->getJson("/api/v1/amial/safe-payments/{$spUlid}")->json('meta.delivery_code'),
            'لا يجوز كشف رمز التسليم للبائع',
        );

        // المشتري (العميل ١) يقرأ رمز التسليم ويسلّمه للبائع عند الاستلام
        Passport::actingAs($customer1->fresh(), [], 'api');
        $deliveryCode = (string) $this->getJson("/api/v1/amial/safe-payments/{$spUlid}")
            ->assertOk()
            ->json('meta.delivery_code');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $deliveryCode);

        Passport::actingAs($customer2->fresh(), [], 'api');
        $this->postJson("/api/v1/amial/safe-payments/{$spUlid}/verify-delivery", [
            'code' => $deliveryCode,
        ], ['Idempotency-Key' => (string) Str::ulid()])
            ->assertOk()
            ->assertJsonPath('code', 'DELIVERY_CONFIRMED');

        // المشتري يؤكد الاستلام ويفرج عن المبلغ برمز PIN من 6 أرقام
        Passport::actingAs($customer1->fresh(), [], 'api');
        $this->postJson("/api/v1/amial/safe-payments/{$spUlid}/buyer-confirm", [
            'pin' => '739582',
        ], ['Idempotency-Key' => (string) Str::ulid()])
            ->assertOk()
            ->assertJsonPath('code', 'SAFE_PAYMENT_RELEASED');

        // رسم الدفع الآمن الافتراضي 1% (200 ر.ي) → يصل البائع 19,800 ر.ي (15,000 + 19,800 = 34,800)
        $this->assertSame('34800.0000', $this->walletBalance($customer2));

        // =====================================================================
        // ٧) التبرعات (تصفّح الحملات ← تبرّع بـ 3,000 ر.ي برمز PIN ← تبرعاتي)
        // =====================================================================
        $cat = CharityCategory::create([
            'code' => 'water_relief',
            'name_ar' => 'سقيا الماء',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $org = CharityOrganization::create([
            'org_ulid' => (string) Str::ulid(),
            'name_ar' => 'مؤسسة نماء الخيرية',
            'license_number' => 'LIC-P2-001',
            'description_ar' => 'مؤسسة خيرية موثقة',
            'contact_phone' => '+9672234567',
            'verification_status' => 'verified',
            'verified_at' => now(),
            'is_active' => true,
            'zone_code' => 'SOUTH',
        ]);
        $campaign = CharityCampaign::create([
            'campaign_ulid' => (string) Str::ulid(),
            'org_id' => $org->id,
            'category_id' => $cat->id,
            'title_ar' => 'بئر مياه قرية ساحلية',
            'description_ar' => 'حفر وتجهيز بئر مياه بالطاقة الشمسية',
            'target_amount' => '100000.0000',
            'current_amount' => '0.0000',
            'platform_fee_collected' => '0.0000',
            'donor_count' => 0,
            'status' => 'active',
            'start_at' => now()->subDay(),
            'deadline_at' => now()->addMonth(),
            'zone_code' => 'SOUTH',
        ]);

        $this->getJson('/api/v1/amial/donations/campaigns')->assertOk();

        $this->postJson('/api/v1/amial/donations/donate', [
            'campaign_ulid' => $campaign->campaign_ulid,
            'amount' => '3000',
            'pin' => '739582',
            'is_anonymous' => false,
            'message' => 'تقبّل الله',
        ], ['Idempotency-Key' => (string) Str::ulid()])
            ->assertStatus(201)
            ->assertJsonPath('code', 'DONATION_OK');

        // الرصيد الآن: 54,800 - 3,000 = 51,800
        $this->assertSame('51800.0000', $this->walletBalance($customer1));
        $this->getJson('/api/v1/amial/donations/my-donations')
            ->assertOk()
            ->assertJsonCount(1, 'meta.items');

        // =====================================================================
        // ٨) السحب النقدي (رفض بلا PIN ← طلب بـ PIN ← إلغاء ← طلب جديد ← صرف الوكيل)
        // =====================================================================
        $this->postJson('/api/v1/amial/withdraw/request', [
            'amount' => '8000',
        ], ['Idempotency-Key' => (string) Str::ulid()])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PIN_REQUIRED');

        $this->postJson('/api/v1/amial/withdraw/request', [
            'amount' => '8000',
            'pin' => '999999',
        ], ['Idempotency-Key' => (string) Str::ulid()])
            ->assertStatus(403)
            ->assertJsonPath('code', 'PIN_INVALID');

        $this->assertSame('51800.0000', $this->walletBalance($customer1));
        $this->assertSame('0.0000', $this->walletHeld($customer1));

        // طلب سحب أول (8,000 + 5% رسم 400 = حجز 8,400) ثم إلغاؤه وفكّ حجزه
        $wd1 = $this->postJson('/api/v1/amial/withdraw/request', [
            'amount' => '8000',
            'pin' => '739582',
        ], ['Idempotency-Key' => (string) Str::ulid()])
            ->assertOk()
            ->assertJsonPath('code', 'WITHDRAW_PENDING');

        $wd1Id = (int) $wd1->json('meta.request_id');
        $this->assertSame('43400.0000', $this->walletBalance($customer1));
        $this->assertSame('8400.0000', $this->walletHeld($customer1));

        $this->postJson("/api/v1/amial/withdraw/{$wd1Id}/cancel", [], [
            'Idempotency-Key' => (string) Str::ulid(),
        ])->assertOk()
          ->assertJsonPath('code', 'WITHDRAW_CANCELLED');

        $this->assertSame('51800.0000', $this->walletBalance($customer1));
        $this->assertSame('0.0000', $this->walletHeld($customer1));

        // طلب سحب ثانٍ وتنفيذه فعلياً لدى الوكيل
        $wd2 = $this->postJson('/api/v1/amial/withdraw/request', [
            'amount' => '8000',
            'pin' => '739582',
        ], ['Idempotency-Key' => (string) Str::ulid()])->assertOk();
        $opCode = (string) $wd2->json('meta.op_code');

        Passport::actingAs($branchUser->fresh(), [], 'api');
        $this->postJson('/api/v1/amial/agent/withdraw/lookup', [
            'op_code' => $opCode,
        ])->assertOk()
          ->assertJsonPath('meta.amount', '8000.0000');

        $execRes = app(\App\Services\CustomerWithdrawService::class)
            ->execute($branchUser->fresh(), $opCode, '771112233');
        $this->assertNotEmpty($execRes['transaction_id']);

        // رصيد العميل ١ النهائي: 51,800 - 8,400 = 43,400 ر.ي، والمحجوز = 0
        $this->assertSame('43400.0000', $this->walletBalance($customer1));
        $this->assertSame('0.0000', $this->walletHeld($customer1));
        $this->assertTrue(
            Receipt::where('user_id', $customer1->id)
                ->where('receipt_type', 'cash_out')
                ->exists(),
            'إيصال السحب النقدي يجب أن يصدر للعميل ١ بعد الصرف',
        );

        // =====================================================================
        // ٩) قانون حفظ المال: توازن جميع قيود الدفتر المحاسبي المزدوج في الرحلة كلها
        // =====================================================================
        $totalDebits = MoneyService::normalize((string) LedgerEntryLine::where('direction', 'debit')->sum('amount'));
        $totalCredits = MoneyService::normalize((string) LedgerEntryLine::where('direction', 'credit')->sum('amount'));
        $this->assertTrue(
            MoneyService::gt($totalDebits, '0'),
            'يجب وجود قيود دفتر فعلية عبر الرحلة',
        );
        $this->assertSame(
            $totalDebits,
            $totalCredits,
            "اختلّ توازن القيد المزدوج في رحلة العميل ١: مدين={$totalDebits} مقابل دائن={$totalCredits}",
        );
    }

    #[Test]
    public function legacy_phone_password_reset_accepts_local_phone_variants_and_blocks_pin_collision(): void
    {
        $user = User::factory()->create([
            'type' => CUSTOMER_TYPE,
            'phone' => '+967775556677',
            'password' => bcrypt('OldPass#1'),
            'transaction_pin' => Hash::make('4829'),
        ]);

        DB::table('password_resets')->insert([
            'phone' => '+967775556677',
            'token' => PasswordResetGuard::digest('654321'),
            'otp_hit_count' => 0,
            'is_temp_blocked' => 0,
            'created_at' => now(),
        ]);

        // محاولة تعيين كلمة مرور تطابق رمز PIN تُرفض دون استهلاك الرمز
        $this->withHeaders(['device-id' => 'dev-reset-p2'])
            ->putJson('/api/v1/customer/auth/reset-password', [
                'phone' => '775556677',
                'otp' => '654321',
                'password' => '4829',
                'confirm_password' => '4829',
            ])
            ->assertStatus(422);

        // الرقم المحلي (775556677) يطابق الحساب المخزّن (+967775556677) ويغيّر كلمة المرور بنجاح
        $this->withHeaders(['device-id' => 'dev-reset-p2'])
            ->putJson('/api/v1/customer/auth/reset-password', [
                'phone' => '775556677',
                'otp' => '654321',
                'password' => 'Fresh#Pass99',
                'confirm_password' => 'Fresh#Pass99',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('Fresh#Pass99', $user->fresh()->password));
    }
}
