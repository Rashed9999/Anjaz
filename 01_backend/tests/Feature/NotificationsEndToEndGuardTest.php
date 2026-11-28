<?php

namespace Tests\Feature;

use App\Jobs\SendAmialNotificationPushJob;
use App\Jobs\SendTransactionNotificationJob;
use App\Models\AmialNotification;
use App\Models\CharityCampaign;
use App\Models\CharityOrganization;
use App\Models\EMoney;
use App\Models\FamilyFund;
use App\Models\FamilyFundMember;
use App\Models\User;
use App\Services\DonationsService;
use App\Services\FamilyFundService;
use App\Services\FirebaseTokenService;
use App\Services\LedgerService;
use App\Services\NotificationDeliveryLogService;
use App\Services\NotificationService;
use App\Services\SafePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AMIAL-NOTIFICATIONS-E2E-GUARD-001
 *
 * يحرس الطبقات الثلاث لمنظومة الإشعارات من الطرف إلى الطرف:
 *   1. تسجيل رمز FCM وعزله ومسحه عبر /api/v1/amial/me/fcm-token لكل الأدوار.
 *   2. إنشاء الإشعارات داخل التطبيق (amial_notifications) في المسارات المالية
 *      حتى عند غياب رمز FCM وبلا تكرار عند إعادة المحاولة.
 *   3. إرسال إشعارات الخلفية عبر FCM v1 وتنظيف الرموز الملغاة (UNREGISTERED).
 */
class NotificationsEndToEndGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_unified_fcm_endpoint_supports_all_roles_isolates_device_and_clears_on_logout(): void
    {
        $customerA = $this->makeVerifiedUser(type: 2, phone: '967771000101');
        $merchantB = $this->makeVerifiedUser(type: 1, phone: '967771000102');
        $agentC = $this->makeVerifiedUser(type: 4, phone: '967771000103');

        $sharedDeviceToken = 'fcm_device_token_' . Str::random(24);

        // 1) العميل أ يسجّل رمز الهاتف
        $this->actingAs($customerA, 'api')
            ->postJson('/api/v1/amial/me/fcm-token', ['token' => $sharedDeviceToken])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('code', 'FCM_UPDATED')
            ->assertJsonPath('meta.fcm_registered', true);

        $this->assertSame($sharedDeviceToken, $customerA->fresh()->fcm_token);

        // 2) التاجر ب يسجّل الدخول على نفس الهاتف — يجب أن يُقبل (لا 403) ويُفكّ الرمز عن العميل أ
        $this->actingAs($merchantB, 'api')
            ->postJson('/api/v1/amial/me/fcm-token', ['token' => $sharedDeviceToken])
            ->assertOk()
            ->assertJsonPath('code', 'FCM_UPDATED');

        $this->assertNull(
            $customerA->fresh()->fcm_token,
            'بقي رمز الجهاز مربوطاً بحساب العميل السابق بعد دخول مستخدم آخر على نفس الجهاز!'
        );
        $this->assertSame($sharedDeviceToken, $merchantB->fresh()->fcm_token);

        // 3) الوكيل ج يعمل أيضاً عبر المسار الموحّد
        $agentToken = 'fcm_agent_token_' . Str::random(24);
        $this->actingAs($agentC, 'api')
            ->putJson('/api/v1/amial/me/fcm-token', ['token' => $agentToken])
            ->assertOk()
            ->assertJsonPath('meta.fcm_registered', true);
        $this->assertSame($agentToken, $agentC->fresh()->fcm_token);

        // 4) تسجيل الخروج بـ '@' أو DELETE يمسح الرمز إلى NULL فعلياً لا نص '@'
        $this->actingAs($merchantB, 'api')
            ->postJson('/api/v1/amial/me/fcm-token', ['token' => '@'])
            ->assertOk()
            ->assertJsonPath('code', 'FCM_CLEARED')
            ->assertJsonPath('meta.fcm_registered', false);
        $this->assertNull($merchantB->fresh()->fcm_token);

        $this->actingAs($agentC, 'api')
            ->deleteJson('/api/v1/amial/me/fcm-token')
            ->assertOk()
            ->assertJsonPath('code', 'FCM_CLEARED');
        $this->assertNull($agentC->fresh()->fcm_token);

        // 5) المسار القديم للعميل ينظّف '@' إلى NULL أيضاً عند وجود جلسة جهاز نشطة
        \App\Models\UserLogHistory::create([
            'user_id' => $customerA->id,
            'device_id' => 'dev-guard-customer-a',
            'ip_address' => '127.0.0.1',
            'is_active' => 1,
        ]);
        $customerA->forceFill(['fcm_token' => 'temp_tok_before_clear'])->save();
        $this->actingAs($customerA, 'api')
            ->withHeaders(['device-id' => 'dev-guard-customer-a'])
            ->putJson('/api/v1/customer/update-fcm-token', ['token' => '@'])
            ->assertOk();
        $this->assertNull($customerA->fresh()->fcm_token);
    }

    public function test_transaction_notification_job_creates_idempotent_in_app_notification_even_without_fcm_token(): void
    {
        $customer = $this->makeVerifiedUser(type: 2, phone: '967771000201');
        $this->assertNull($customer->fcm_token);

        $job = new SendTransactionNotificationJob(
            userId: $customer->id,
            amount: '7500.0000',
            transactionType: 'received_money',
            notificationType: null,
            transactionId: 'TX-GUARD-INAPP-001',
        );

        $job->handle(
            app(FirebaseTokenService::class),
            app(NotificationDeliveryLogService::class),
        );

        // ظهر الإشعار داخل التطبيق رغم غياب رمز FCM
        $this->assertSame(1, AmialNotification::where('user_id', $customer->id)->count());
        $notif = AmialNotification::where('user_id', $customer->id)->firstOrFail();
        $this->assertSame('money_received', $notif->type);
        $this->assertSame('TX-GUARD-INAPP-001', $notif->data['transaction_id'] ?? null);

        // إعادة تشغيل المهمة نفسها لا تكرّر الإشعار الداخلي
        $job->handle(
            app(FirebaseTokenService::class),
            app(NotificationDeliveryLogService::class),
        );
        $this->assertSame(1, AmialNotification::where('user_id', $customer->id)->count());

        $this->assertDatabaseHas('notification_delivery_logs', [
            'user_id' => $customer->id,
            'transaction_id' => 'TX-GUARD-INAPP-001',
            'status' => 'skipped',
            'error_code' => 'FCM_TOKEN_MISSING',
        ]);
    }

    public function test_push_job_delivers_with_sound_channel_and_purges_unregistered_fcm_tokens(): void
    {
        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $privateKeyPem = '';
        openssl_pkey_export($res, $privateKeyPem);

        config([
            'services.firebase.credentials' => json_encode([
                'project_id' => 'amial-test-proj',
                'client_email' => 'firebase-adminsdk@amial-test-proj.iam.gserviceaccount.com',
                'private_key' => $privateKeyPem,
            ]),
        ]);

        $customer = $this->makeVerifiedUser(type: 2, phone: '967771000301');
        $customer->forceFill(['fcm_token' => 'valid_fcm_token_abc'])->save();

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'ya29.fake_oauth_token',
                'expires_in' => 3600,
            ], 200),
            'https://fcm.googleapis.com/v1/projects/amial-test-proj/messages:send' => Http::sequence()
                ->push(['name' => 'projects/amial-test-proj/messages/msg_ok_1'], 200)
                ->push([
                    'error' => [
                        'code' => 404,
                        'status' => 'NOT_FOUND',
                        'details' => [['errorCode' => 'UNREGISTERED']],
                    ],
                ], 404),
        ]);

        // 1) الإرسال الأول ينجح على قناة amial_pay_default مع الصوت notification
        (new SendAmialNotificationPushJob(
            userId: $customer->id,
            notificationId: 501,
            type: 'money_received',
            title: 'استلمت حوالة مالية',
            body: 'وصلك مبلغ 5,000 ر.ي',
            actionUrl: '/receipts',
        ))->handle(
            app(FirebaseTokenService::class),
            app(NotificationDeliveryLogService::class),
        );

        Http::assertSent(function (Request $req): bool {
            if (!str_contains($req->url(), 'fcm.googleapis.com')) {
                return false;
            }
            $msg = $req->data()['message'] ?? [];
            return ($msg['token'] ?? null) === 'valid_fcm_token_abc'
                && ($msg['android']['notification']['channel_id'] ?? null) === 'amial_pay_default'
                && ($msg['android']['notification']['sound'] ?? null) === 'notification'
                && ($msg['data']['notification_id'] ?? null) === '501';
        });

        $this->assertDatabaseHas('notification_delivery_logs', [
            'user_id' => $customer->id,
            'notification_id' => 501,
            'status' => 'provider_accepted',
        ]);

        // 2) الإرسال الثاني يردّ UNREGISTERED (404) فيُمسح الرمز الميت دون رمي استثناء
        (new SendAmialNotificationPushJob(
            userId: $customer->id,
            notificationId: 502,
            type: 'money_received',
            title: 'إشعار ثانٍ',
            body: 'نصّ',
        ))->handle(
            app(FirebaseTokenService::class),
            app(NotificationDeliveryLogService::class),
        );

        $this->assertNull(
            $customer->fresh()->fcm_token,
            'لم يُمسح رمز FCM المنتهي (UNREGISTERED) من سجل المستخدم!'
        );
        $this->assertDatabaseHas('notification_delivery_logs', [
            'user_id' => $customer->id,
            'notification_id' => 502,
            'status' => 'skipped',
            'error_code' => 'FCM_TOKEN_UNREGISTERED',
        ]);
    }

    public function test_safe_payment_family_fund_and_donation_services_emit_in_app_and_push_notifications(): void
    {
        Queue::fake();

        if (\Illuminate\Support\Facades\Schema::hasTable('control_center_policies')) {
            DB::table('control_center_policies')->updateOrInsert(
                ['feature_key' => 'safe_payment'],
                ['mode' => 'RUNNING', 'updated_at' => now(), 'created_at' => now()]
            );
        }

        $buyer = $this->makeFundedCustomer('967771000401', '50000.0000');
        $seller = $this->makeFundedCustomer('967771000402', '5000.0000');

        // 1) الدفع الآمن: الإنشاء يشعر البائع والمشتري، والقبول يشعر المشتري
        $safeSvc = app(SafePaymentService::class);
        $payment = $safeSvc->createAndFund($buyer, $seller, 'شراء جهاز', 'وصف الصفقة', '4000.0000');

        $this->assertDatabaseHas('amial_notifications', [
            'user_id' => $seller->id,
            'type' => 'safe_payment_created',
        ]);
        $this->assertDatabaseHas('amial_notifications', [
            'user_id' => $buyer->id,
            'type' => 'safe_payment_created',
        ]);

        $payment = $safeSvc->sellerAccept($payment, $seller, 'تم القبول');
        $this->assertDatabaseHas('amial_notifications', [
            'user_id' => $buyer->id,
            'type' => 'safe_payment_accepted',
        ]);

        // 2) صندوق العائلة: الدعوة والمساهمة تشعر الأعضاء
        $fundSvc = app(FamilyFundService::class);
        $fund = FamilyFund::create([
            'fund_ulid' => (string) Str::ulid(),
            'owner_user_id' => $buyer->id,
            'name' => 'صندوق الأسرة',
            'balance' => '0.0000',
            'status' => 'active',
            'zone_code' => 'SOUTH',
        ]);
        FamilyFundMember::create([
            'fund_id' => $fund->id,
            'user_id' => $buyer->id,
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $fundSvc->inviteMember($fund, $buyer, (string) $seller->phone, 'member');
        $this->assertDatabaseHas('amial_notifications', [
            'user_id' => $seller->id,
            'type' => 'family_fund_invited',
        ]);

        $fundSvc->contribute($fund, $buyer, '2000.0000', 'مساهمة شهرية');
        $this->assertDatabaseHas('amial_notifications', [
            'user_id' => $buyer->id,
            'type' => 'family_fund_contributed',
        ]);

        // 3) التبرعات: التبرع يشعر المتبرع
        $cat = \App\Models\CharityCategory::create([
            'code' => 'water-notif-guard',
            'name_ar' => 'سقيا الماء',
            'is_active' => true,
        ]);
        $org = CharityOrganization::create([
            'org_ulid' => (string) Str::ulid(),
            'name_ar' => 'جمعية الإحسان',
            'license_number' => 'LIC-NOTIF-001',
            'description_ar' => 'جمعية خيرية موثقة',
            'contact_phone' => '+9672234999',
            'verification_status' => 'verified',
            'verified_at' => now(),
            'is_active' => true,
            'zone_code' => 'SOUTH',
        ]);
        $campaign = CharityCampaign::create([
            'campaign_ulid' => (string) Str::ulid(),
            'org_id' => $org->id,
            'category_id' => $cat->id,
            'title_ar' => 'سقيا الماء',
            'description_ar' => 'حملة خيرية',
            'target_amount' => '100000.0000',
            'current_amount' => '0.0000',
            'platform_fee_collected' => '0.0000',
            'donor_count' => 0,
            'status' => 'active',
            'start_at' => now()->subDay(),
            'deadline_at' => now()->addMonth(),
            'zone_code' => 'SOUTH',
        ]);

        app(DonationsService::class)->donate($buyer, $campaign, '1000.0000');
        $this->assertDatabaseHas('amial_notifications', [
            'user_id' => $buyer->id,
            'type' => 'donation_completed',
        ]);

        Queue::assertPushed(SendAmialNotificationPushJob::class);
    }

    private function makeVerifiedUser(int $type, string $phone): User
    {
        return User::factory()->create([
            'type' => $type,
            'phone' => $phone,
            'is_active' => 1,
            'is_phone_verified' => 1,
            'is_kyc_verified' => 1,
            'kyc_tier' => 2,
            'zone_code' => 'SOUTH',
        ]);
    }

    private function makeFundedCustomer(string $phone, string $balance): User
    {
        $user = $this->makeVerifiedUser(2, $phone);
        EMoney::updateOrCreate(
            ['user_id' => $user->id],
            ['current_balance' => $balance, 'pending_balance' => '0.0000', 'zone_code' => 'SOUTH']
        );

        $ledger = app(LedgerService::class);
        $wallet = $ledger->getOrCreateUserWallet($user->id);
        $capital = $ledger->getOrCreateSystemAccount('OPENING_CAPITAL', 'equity', 'رأس المال الافتتاحي', 'credit');
        $ledger->post(
            sourceType: 'opening_balance',
            sourceId: 'open_' . $user->id,
            description: 'رصيد افتتاحي للاختبار',
            lines: [
                ['account' => $wallet->account_code, 'direction' => 'credit', 'amount' => $balance],
                ['account' => $capital->account_code, 'direction' => 'debit', 'amount' => $balance],
            ],
            idempotencyKey: 'test_opening_' . $user->id,
        );

        return $user;
    }
}
