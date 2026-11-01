<?php

namespace Tests\Feature;

use App\Exceptions\CreditManagedInAmialBusinessException;
use App\Models\AmialNotification;
use App\Models\BusinessGateway\BusinessCustomerDebt;
use App\Models\BusinessGateway\BusinessLink;
use App\Models\BusinessGateway\BusinessOutboxEvent;
use App\Models\BusinessGateway\BusinessPaymentIntent;
use App\Models\CreditCollection;
use App\Models\CustomerCreditAccount;
use App\Models\EMoney;
use App\Models\Merchant;
use App\Models\MerchantProfile;
use App\Models\PaymentRequest;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WholesaleBusiness;
use App\Models\WholesaleCustomer;
use App\Services\BusinessGateway\BusinessCustomerDebtService;
use App\Services\BusinessGateway\BusinessGatewaySignature;
use App\Services\BusinessGateway\BusinessLinkService;
use App\Services\BusinessGateway\BusinessMigrationExport;
use App\Services\CustomerCreditService;
use App\Services\MoneyService;
use App\Services\PaymentRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AMIAL-BUSINESS-CREDIT-001 — الآجل بين أميال باي وأميال أعمال.
 *
 * ما يحرسه:
 *   - بعد التسليم لا يتحرّك أي رصيد دين في أميال باي (عميل · جملة · مورد) من أي مسار.
 *   - التسليم يُرفض إن تحرّك رصيد بعد قراءة الترحيل، أو بقي تحصيل غير محسوم.
 *   - دفتر أميال باي المجمَّد لا يظهر للعميل ولا يُسدَّد؛ تظهر مرآة أميال أعمال.
 *   - العميل يسدّد من تطبيقه مرة واحدة بالمبلغ الصحيح، ولا يتجاوز المتبقّي.
 *   - نسخة أقدم من المرآة لا تستبدل أحدث.
 */
class BusinessCreditLinkTest extends TestCase
{
    use RefreshDatabase;

    private const INBOUND_SECRET = 'test-inbound-secret-not-for-production';

    private User $merchant;
    private User $customer;
    private BusinessLink $link;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'amial_business.inbound_secret' => self::INBOUND_SECRET,
            'amial_business.webhook_secret' => 'test-webhook-secret-not-for-production',
            'amial_business.webhook_url' => 'https://business.test/api/amial-pay/webhooks',
        ]);

        $this->merchant = $this->makeMerchant('+967700200001', 'بقالة الأمانة');
        $this->customer = User::factory()->create([
            'type' => 2, 'phone' => '+967700200002', 'zone_code' => 'SOUTH',
            'f_name' => 'محمد', 'l_name' => 'عبدالله صالح',
            'is_active' => 1, 'is_kyc_verified' => 1,
            'transaction_pin' => Hash::make('1234'),
        ]);
        $this->fund($this->merchant, '0');
        $this->fund($this->customer, '50000');

        $this->link = app(BusinessLinkService::class)->link($this->merchant);
    }

    // ── التسليم والتجميد ───────────────────────────────────────────────

    #[Test]
    public function after_handover_no_credit_balance_moves_in_amial_pay(): void
    {
        $credit = app(CustomerCreditService::class);
        $account = $credit->findOrCreateAccount($this->merchant->id, '+967700200002', 'محمد');
        $credit->recordSale($account, '3000', createdBy: $this->merchant->id);
        $biz = WholesaleBusiness::create(['merchant_user_id' => $this->merchant->id, 'business_name' => 'جملة', 'is_active' => true]);
        $wc = WholesaleCustomer::create(['business_id' => $biz->id, 'full_name' => 'سوبرماركت', 'current_balance' => '8000', 'is_active' => true]);
        $supplier = Supplier::create(['merchant_user_id' => $this->merchant->id, 'name' => 'مصنع', 'current_debt' => '5000', 'current_credit' => '0', 'is_active' => true]);

        $this->handover('IMP-1')->assertOk()->assertJsonPath('code', 'CREDIT_HANDED_OVER');

        $this->assertThrows(fn () => $credit->recordSale($account->fresh(), '100', createdBy: $this->merchant->id), CreditManagedInAmialBusinessException::class);
        $this->assertThrows(fn () => $credit->recordPayment($account->fresh(), '100', createdBy: $this->merchant->id), CreditManagedInAmialBusinessException::class);
        $this->assertThrows(fn () => $wc->fresh()->update(['current_balance' => '7000']), CreditManagedInAmialBusinessException::class);
        $this->assertThrows(fn () => $supplier->fresh()->update(['current_debt' => '4000']), CreditManagedInAmialBusinessException::class);
        $this->assertThrows(fn () => Supplier::create(['merchant_user_id' => $this->merchant->id, 'name' => 'جديد', 'current_debt' => '10', 'is_active' => true]), CreditManagedInAmialBusinessException::class);
        $fleet = \App\Models\FuelCompanyAccount::withoutEvents(fn () => \App\Models\FuelCompanyAccount::create(['merchant_user_id' => $this->merchant->id, 'company_name' => 'أسطول', 'current_balance' => '0', 'is_active' => true]));
        $this->assertThrows(fn () => $fleet->fresh()->update(['current_balance' => '900']), CreditManagedInAmialBusinessException::class);

        $this->assertSame('3000.0000', (string) $account->fresh()->current_balance);
        $this->assertSame('8000.0000', (string) $wc->fresh()->current_balance);
        $this->assertSame('5000.0000', (string) $supplier->fresh()->current_debt);

        // ما لا يمسّ رصيداً يبقى مسموحاً: تعديل اسم، ومورد جديد بلا رصيد.
        $supplier->fresh()->update(['name' => 'مصنع الأمل', 'current_debt' => '5000.0000']);
        Supplier::create(['merchant_user_id' => $this->merchant->id, 'name' => 'بلا رصيد', 'current_debt' => '0', 'is_active' => true]);
        $this->assertSame('مصنع الأمل', $supplier->fresh()->name);
    }

    #[Test]
    public function another_merchant_is_not_frozen(): void
    {
        $other = $this->makeMerchant('+967700200009', 'متجر آخر');
        $this->handover('IMP-1')->assertOk();

        $credit = app(CustomerCreditService::class);
        $account = $credit->findOrCreateAccount($other->id, '+967700200002', 'محمد');
        $credit->recordSale($account, '700', createdBy: $other->id);

        $this->assertSame('700.0000', (string) $account->fresh()->current_balance);
    }

    #[Test]
    public function handover_is_refused_if_a_balance_moved_after_the_export_was_read(): void
    {
        $credit = app(CustomerCreditService::class);
        $account = $credit->findOrCreateAccount($this->merchant->id, '+967700200002', 'محمد');
        $credit->recordSale($account, '3000', createdBy: $this->merchant->id);
        $fingerprint = $this->summaryFingerprint();

        $credit->recordSale($account->fresh(), '250', createdBy: $this->merchant->id);

        $this->handover('IMP-1', $fingerprint)->assertStatus(409)->assertJsonPath('code', 'CREDIT_CHANGED_SINCE_EXPORT');
        $this->assertNull($this->link->fresh()->credit_handed_over_at);
        $credit->recordSale($account->fresh(), '50', createdBy: $this->merchant->id); // لم يُجمَّد
    }

    #[Test]
    public function handover_is_idempotent_and_cannot_be_replaced(): void
    {
        $first = $this->handover('IMP-1')->assertOk()->json('meta');
        $again = $this->handover('IMP-1')->assertOk()->json('meta');

        $this->assertFalse($first['replayed']);
        $this->assertTrue($again['replayed']);
        $this->assertSame($first['handed_over_at'], $again['handed_over_at']);
        $this->handover('IMP-2')->assertStatus(409)->assertJsonPath('code', 'CREDIT_ALREADY_HANDED_OVER');
    }

    #[Test]
    public function handover_waits_for_open_wallet_collections(): void
    {
        $account = app(CustomerCreditService::class)->findOrCreateAccount($this->merchant->id, '+967700200002', 'محمد');
        CreditCollection::create([
            'collection_ulid' => (string) Str::ulid(), 'merchant_user_id' => $this->merchant->id,
            'account_id' => $account->id, 'actor_user_id' => $this->merchant->id,
            'payment_method' => 'amial_pay', 'status' => 'pending', 'amount' => '100', 'idempotency_key' => 'k1',
        ]);

        $this->handover('IMP-1')->assertStatus(409)->assertJsonPath('code', 'CREDIT_COLLECTIONS_OUTSTANDING');
        $this->assertNull($this->link->fresh()->credit_handed_over_at);
    }

    #[Test]
    public function the_frozen_ledger_is_hidden_from_the_customer_and_cannot_be_settled(): void
    {
        $credit = app(CustomerCreditService::class);
        $account = $credit->findOrCreateAccount($this->merchant->id, '+967700200002', 'محمد');
        $credit->recordSale($account, '3000', createdBy: $this->merchant->id);
        $this->handover('IMP-1')->assertOk();
        $this->mirror('C-1', '3000', version: 1);

        Passport::actingAs($this->customer->fresh(), [], 'api');
        $meta = $this->getJson('/api/v1/amial/customer/credits')->assertOk()->json('meta');

        $this->assertSame([], $meta['accounts']);
        $this->assertCount(1, $meta['business_accounts']);
        $this->assertSame('3000.0000', $meta['total_owed']); // لا يُحسب الدين مرتين

        $this->postJson("/api/v1/amial/customer/credits/{$account->id}/settle", ['amount' => '1000', 'pin' => '1234'])
            ->assertStatus(422);
        $this->assertSame('50000.0000', $this->balance($this->customer));
        $this->assertSame('3000.0000', (string) $account->fresh()->current_balance);
    }

    // ── المرآة ───────────────────────────────────────────────────────

    #[Test]
    public function an_older_mirror_version_never_replaces_a_newer_one(): void
    {
        $this->mirror('C-1', '5000', version: 7)->assertJsonPath('code', 'DEBT_MIRRORED');
        $this->mirror('C-1', '9000', version: 6)->assertJsonPath('code', 'DEBT_VERSION_STALE');
        $this->mirror('C-1', '9000', version: 7)->assertJsonPath('code', 'DEBT_VERSION_STALE');

        $this->assertSame('5000.0000', (string) BusinessCustomerDebt::firstOrFail()->balance);
        $this->mirror('C-1', '4000', version: 8)->assertJsonPath('code', 'DEBT_MIRRORED');
        $this->assertSame('4000.0000', (string) BusinessCustomerDebt::firstOrFail()->balance);
    }

    #[Test]
    public function the_mirror_links_the_customer_by_any_phone_format(): void
    {
        $this->mirror('C-1', '5000', version: 1, phone: '700200002')->assertJsonPath('meta.customer_linked', true);
        $this->assertSame($this->customer->id, BusinessCustomerDebt::firstOrFail()->customer_user_id);
    }

    // ── سداد العميل من التطبيق ─────────────────────────────────────────

    #[Test]
    public function the_customer_settles_from_the_app_once_and_amial_business_is_told(): void
    {
        $this->mirror('C-1', '5000', version: 1);
        $debt = BusinessCustomerDebt::firstOrFail();

        Passport::actingAs($this->customer->fresh(), [], 'api');
        $this->postJson("/api/v1/amial/customer/credits/business/{$debt->id}/settle", ['amount' => '2000', 'pin' => '1234'])
            ->assertOk()
            ->assertJsonPath('meta.paid', '2000.0000')
            ->assertJsonPath('meta.new_balance', '3000.0000');

        $this->assertSame('48000.0000', $this->balance($this->customer));
        $this->assertSame('2000.0000', $this->balance($this->merchant));

        $intent = BusinessPaymentIntent::firstOrFail();
        $this->assertSame('succeeded', $intent->status);
        $this->assertSame('debt_collection', $intent->purpose);
        $this->assertSame('customer_app', $intent->origin);
        $this->assertSame('C-1', $intent->contact_ref);

        $event = BusinessOutboxEvent::where('event_type', 'payment_intent.succeeded')->firstOrFail();
        $this->assertSame('debt_collection', $event->payload['data']['purpose']);
        $this->assertSame('C-1', $event->payload['data']['contact_ref']);

        // قبل وصول نسخة أميال أعمال الجديدة: المتبقّي 3000 لا 5000، فلا يُدفع مرتين.
        $this->postJson("/api/v1/amial/customer/credits/business/{$debt->id}/settle", ['amount' => '3500', 'pin' => '1234'])
            ->assertStatus(422)->assertJsonPath('code', 'AMOUNT_EXCEEDS_DEBT');
        $this->assertSame('48000.0000', $this->balance($this->customer));

        // وصلت النسخة التي تشمل السداد: المتبقّي يبقى 3000.
        $this->mirror('C-1', '3000', version: 2, asOf: now()->addSecond()->toIso8601String());
        $this->assertSame('3000.0000', app(BusinessCustomerDebtService::class)->outstanding($debt->fresh()));
    }

    #[Test]
    public function a_customer_cannot_settle_someone_elses_debt(): void
    {
        $this->mirror('C-1', '5000', version: 1);
        $stranger = User::factory()->create(['type' => 2, 'phone' => '+967700200077', 'is_active' => 1, 'is_kyc_verified' => 1, 'transaction_pin' => Hash::make('1234')]);
        $this->fund($stranger, '9000');

        Passport::actingAs($stranger, [], 'api');
        $this->postJson('/api/v1/amial/customer/credits/business/'.BusinessCustomerDebt::firstOrFail()->id.'/settle', ['amount' => '100', 'pin' => '1234'])
            ->assertStatus(404);
        $this->assertSame('9000.0000', $this->balance($stranger));
    }

    #[Test]
    public function a_wrong_pin_moves_nothing(): void
    {
        $this->mirror('C-1', '5000', version: 1);
        Passport::actingAs($this->customer->fresh(), [], 'api');
        $this->postJson('/api/v1/amial/customer/credits/business/'.BusinessCustomerDebt::firstOrFail()->id.'/settle', ['amount' => '100', 'pin' => '9999'])
            ->assertStatus(403);
        $this->assertSame(0, BusinessPaymentIntent::count());
    }

    // ── تحصيل الكاشير الموجّه ─────────────────────────────────────────

    #[Test]
    public function a_directed_debt_request_reaches_only_its_customer(): void
    {
        $meta = $this->signed('POST', '/api/v1/business/payment-intents', [
            'business_reference' => 'DC-1', 'amount' => '1500', 'currency' => 'YER',
            'purpose' => 'debt_collection', 'contact_ref' => 'C-1', 'payer_phone' => '700200002',
        ])->assertStatus(201)->json('meta');

        $this->assertSame('محمد ع*** ص***', $meta['payer_masked_name']);
        $this->assertSame('debt_collection', $meta['purpose']);
        $request = PaymentRequest::where('short_code', $meta['short_code'])->firstOrFail();
        $this->assertSame($this->customer->id, (int) $request->recipient_user_id);

        $stranger = User::factory()->create(['type' => 2, 'phone' => '+967700200078', 'is_active' => 1, 'is_kyc_verified' => 1]);
        $this->fund($stranger, '9000');
        $this->assertThrows(fn () => app(PaymentRequestService::class)->pay($stranger, $request), \InvalidArgumentException::class);

        app(PaymentRequestService::class)->pay($this->customer, $request->fresh());
        $this->assertSame('1500.0000', $this->balance($this->merchant));
    }

    #[Test]
    public function a_debt_collection_needs_a_contact_and_app_references_are_reserved(): void
    {
        $this->signed('POST', '/api/v1/business/payment-intents', ['business_reference' => 'DC-2', 'amount' => '10', 'currency' => 'YER', 'purpose' => 'debt_collection'])
            ->assertStatus(422);
        $this->signed('POST', '/api/v1/business/payment-intents', ['business_reference' => 'APP-X', 'amount' => '10', 'currency' => 'YER'])
            ->assertStatus(422);
        $this->assertSame(0, BusinessPaymentIntent::count());
    }

    #[Test]
    public function resolving_a_phone_reveals_only_a_masked_name(): void
    {
        $found = $this->signed('POST', '/api/v1/business/customers/resolve', ['phone' => '+967700200002'])->assertOk()->json('meta');
        $this->assertSame(['found' => true, 'masked_name' => 'محمد ع*** ص***'], $found);

        $missing = $this->signed('POST', '/api/v1/business/customers/resolve', ['phone' => '+967700299999'])->assertOk()->json('meta');
        $this->assertSame(['found' => false, 'masked_name' => null], $missing);
    }

    // ── التذكير ───────────────────────────────────────────────────────

    #[Test]
    public function overdue_debts_are_reminded_at_most_once_per_interval_and_only_if_enabled(): void
    {
        $this->mirror('C-1', '5000', version: 1, overdue: '2000');
        $this->mirror('C-2', '900', version: 1, overdue: '900', reminders: false, phone: '+967700200002');

        $svc = app(BusinessCustomerDebtService::class);
        $this->assertSame(1, $svc->sendDueReminders());
        $this->assertSame(0, $svc->sendDueReminders());
        $this->assertSame(1, AmialNotification::where('user_id', $this->customer->id)->where('type', 'business_debt_reminder')->count());

        $this->travel(4)->days();
        $this->assertSame(1, $svc->sendDueReminders());
    }

    // ── أدوات ──────────────────────────────────────────────────────────

    private function handover(string $reference, ?string $fingerprint = null): TestResponse
    {
        return $this->signed('POST', '/api/v1/business/credit/handover', [
            'reference' => $reference,
            'credit_fingerprint' => $fingerprint ?? $this->summaryFingerprint(),
        ]);
    }

    private function summaryFingerprint(): string
    {
        return app(BusinessMigrationExport::class)->creditFingerprint($this->link->fresh());
    }

    private function mirror(
        string $contactRef, string $balance, int $version, string $overdue = '0',
        bool $reminders = true, string $phone = '+967700200002', ?string $asOf = null,
    ): TestResponse {
        return $this->signed('PUT', "/api/v1/business/customer-debts/{$contactRef}", [
            'customer_name' => 'محمد', 'customer_phone' => $phone,
            'balance' => $balance, 'overdue_amount' => $overdue, 'next_due_date' => null,
            'reminders_enabled' => $reminders, 'version' => $version,
            'as_of' => $asOf ?? now()->toIso8601String(),
        ])->assertOk();
    }

    private function signed(string $method, string $path, ?array $body): TestResponse
    {
        $timestamp = (string) time();
        $nonce = Str::random(32);
        $content = $body === null ? '' : json_encode($body);
        $canonical = BusinessGatewaySignature::inboundCanonical($timestamp, $nonce, $method, $path, '', $content);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_AMIAL_BUSINESS_TIMESTAMP' => $timestamp, 'HTTP_X_AMIAL_BUSINESS_NONCE' => $nonce,
            'HTTP_X_AMIAL_BUSINESS_SIGNATURE' => BusinessGatewaySignature::sign($canonical, self::INBOUND_SECRET),
            'HTTP_X_AMIAL_BUSINESS_LINK' => $this->link->link_ulid];

        return $this->call($method, $path, [], [], [], $server, $content);
    }

    private function makeMerchant(string $phone, string $store): User
    {
        $merchant = User::factory()->create([
            'type' => 3, 'phone' => $phone, 'zone_code' => 'SOUTH',
            'is_active' => 1, 'is_kyc_verified' => 1,
        ]);
        MerchantProfile::create(['user_id' => $merchant->id, 'verification_status' => 'verified']);
        $m = new Merchant();
        $m->user_id = $merchant->id;
        $m->store_name = $store;
        $m->merchant_number = 'M-'.substr($phone, -5);
        $m->address = '—';
        $m->save();

        return $merchant;
    }

    private function fund(User $user, string $amount): void
    {
        EMoney::create([
            'user_id' => $user->id, 'current_balance' => MoneyService::normalize($amount),
            'held_balance' => '0.0000', 'pending_balance' => '0.0000',
            'charge_earned' => '0.0000', 'zone_code' => 'SOUTH',
        ]);
    }

    private function balance(User $user): string
    {
        return (string) EMoney::where('user_id', $user->id)->value('current_balance');
    }
}
