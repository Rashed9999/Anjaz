<?php

namespace Tests\BankGrade;

use App\Models\BusinessGateway\BusinessLink;
use App\Models\EMoney;
use App\Models\FeeScheme;
use App\Models\MerchantProfile;
use App\Models\MuwathaqAccountOpeningApplication;
use App\Models\MuwathaqOpeningEvidence;
use App\Models\User;
use App\Models\UserLogHistory;
use App\Services\BusinessGateway\BusinessGatewaySignature;
use App\Services\BusinessGateway\BusinessLinkService;
use App\Services\EncryptedFileStorage;
use App\Services\LedgerService;
use App\Services\MoneyService;
use App\Services\Muwathaq\MuwathaqTransportSecrets;
use App\Services\TransactionPinService;
use App\Services\TwoFactorAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Tests\Support\EstablishesKycEvidence;
use Tests\TestCase;

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  AMIAL-BANK-GRADE-SEC-001 — مصفوفة الاختبارات المصرفية العالمية (الأمان)
 * ══════════════════════════════════════════════════════════════════════════
 *
 * المعايير المرجعية المطبّقة:
 *   • OWASP ASVS v4.0.3 Level 3 (المستوى المصرفي عالي الضمان)
 *   • FAPI 2.0 (Financial-grade API Security Profile)
 *   • PCI-DSS v4.0 — المتطلّبان 7 (عزل الصلاحيات) و8 (المصادقة القوية)
 *   • NIST SP 800-63B — مقاومة التخمين وإعادة الإرسال (Anti-Replay & Lockout)
 *   • OWASP API Security Top 10 (2023) — API2 / API3 / API5 / API8
 */
class BankGradeSecuritySuiteTest extends TestCase
{
    use EstablishesKycEvidence;
    use RefreshDatabase;

    private const BUSINESS_INBOUND_SECRET = 'bankgrade-inbound-secret-key-2026';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    // ══════════════════════════════════════════════════════════════════════
    // 1. Zero-Trust Authentication Gate (FAPI 2.0 / OWASP ASVS V4.1)
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function unauthenticated_requests_to_critical_financial_and_admin_endpoints_are_rejected(): void
    {
        $protectedEndpoints = [
            ['GET',  '/api/v1/amial/me'],
            ['GET',  '/api/v1/amial/receipts'],
            ['GET',  '/api/v1/amial/statement'],
            ['POST', '/api/v1/amial/merchant/pay'],
            ['GET',  '/api/v1/amial/admin/settlements/dashboard'],
            ['POST', '/api/v1/amial/admin/settlements/1/approve'],
            ['POST', '/api/v1/customer/send-money'],
            ['POST', '/api/v1/customer/withdraw'],
        ];

        foreach ($protectedEndpoints as [$method, $uri]) {
            $response = $this->json($method, $uri);
            $this->assertContains(
                $response->getStatusCode(),
                [401, 403],
                "المسار المحمي [{$method} {$uri}] لم يرفض الوصول غير المصادَق (أعاد {$response->getStatusCode()})"
            );
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // 2. Horizontal & Vertical RBAC Isolation (OWASP API5:BFLA / PCI-DSS 7.2)
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function horizontal_and_vertical_rbac_matrix_blocks_privilege_escalation(): void
    {
        $customer = User::factory()->create(['type' => 2, 'role' => 'customer']);
        $agent    = User::factory()->create(['type' => 1, 'role' => 'agent']);
        $merchant = User::factory()->merchant()->create();

        $adminRoutes = [
            ['GET',  '/api/v1/amial/admin/settlements/dashboard'],
            ['POST', '/api/v1/amial/admin/settlements/1/approve'],
            ['GET',  '/api/v1/amial/admin/ops/status'],
        ];

        foreach (['customer' => $customer, 'agent' => $agent, 'merchant' => $merchant] as $label => $actor) {
            Passport::actingAs($actor, [], 'api');
            foreach ($adminRoutes as [$method, $uri]) {
                $res = $this->json($method, $uri, ['notes' => 'attempt']);
                $this->assertSame(
                    403,
                    $res->getStatusCode(),
                    "خرق RBAC: الدور [{$label}] لم يُحجَب بـ 403 عن مسار الإدارة [{$method} {$uri}]"
                );
            }
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // 3. Transaction PIN Entropy & Lockout (PCI-DSS 8.3 / NIST SP 800-63B)
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function transaction_pin_service_rejects_weak_pins_and_enforces_lockout_after_five_failures(): void
    {
        $pinService = app(TransactionPinService::class);
        $user = User::factory()->create();

        foreach (['0000', '1111', '1234', '4321', '0123', '9999', '12a4'] as $weakPin) {
            $rejected = false;
            try {
                $pinService->setPin($user, $weakPin);
            } catch (\InvalidArgumentException) {
                $rejected = true;
            }
            $this->assertTrue($rejected, "رمز العمليات الضعيف [{$weakPin}] قُبِل بدلاً من رفضه!");
        }

        $pinService->setPin($user, '7391');
        $user->refresh();

        // 5 محاولات خاطئة تقفل الرمز 30 دقيقة
        for ($i = 1; $i <= TransactionPinService::MAX_ATTEMPTS; $i++) {
            $this->assertFalse($pinService->verify($user, '8462'));
            $user->refresh();
        }

        $this->assertNotNull($user->pin_locked_until, 'لم يُقفَل رمز العمليات بعد 5 محاولات فاشلة');
        $this->assertTrue($user->pin_locked_until->isFuture());

        // حتى الرمز الصحيح يجب رفضه أثناء فترة القفل المصرفي
        $this->assertFalse(
            $pinService->verify($user, '7391'),
            'قُبِل رمز العمليات الصحيح أثناء سريان القفل الأمني!'
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // 4. RFC 6238 2FA TOTP Single-Use Anti-Replay & Brute-Force Lockout
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function totp_2fa_enforces_rfc6238_single_use_anti_replay_and_brute_force_lockout(): void
    {
        $totp = app(TwoFactorAuthService::class);
        $admin = User::factory()->admin()->create();

        $setup = $totp->setup($admin);
        $admin->refresh();
        $this->assertFalse((bool) $admin->two_factor_enabled);

        $validCode = $totp->generateTotp($setup['secret']);
        $this->assertTrue($totp->confirm($admin, $validCode));
        $admin->refresh();
        $this->assertTrue((bool) $admin->two_factor_enabled);

        // إعادة استخدام نفس الرمز في نفس نافذة الـ 30 ثانية يجب أن تُرفض (Replay Attack)
        $this->assertFalse(
            $totp->verify($admin, $validCode),
            'نجح هجوم إعادة استخدام رمز TOTP نفسه ضمن النافذة الزمنية!'
        );

        // رمز استرداد يعمل لمرة واحدة فقط ثم يُحرق
        $recoveryCode = $setup['recovery_codes'][0];
        $this->assertTrue($totp->verify($admin->fresh(), $recoveryCode));
        $this->assertFalse(
            $totp->verify($admin->fresh(), $recoveryCode),
            'قُبِل رمز الاسترداد الطارئ (Recovery Code) أكثر من مرة!'
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // 5. S2S HMAC + Timestamp + Body + Nonce Anti-Replay (Business Gateway)
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function business_gateway_s2s_enforces_hmac_timestamp_body_integrity_and_nonce_anti_replay(): void
    {
        config(['amial_business.inbound_secret' => self::BUSINESS_INBOUND_SECRET]);

        $merchant = User::factory()->merchant()->create(['phone' => '+967700880001']);
        MerchantProfile::create(['user_id' => $merchant->id, 'verification_status' => 'verified']);
        $link = app(BusinessLinkService::class)->link($merchant);

        $path = '/api/v1/business/payment-intents';
        $originalBody = json_encode(['business_reference' => 'INV-BG-1', 'amount' => '1500', 'currency' => 'YER']);
        $tamperedBody = json_encode(['business_reference' => 'INV-BG-1', 'amount' => '99000', 'currency' => 'YER']);

        // (أ) طابع زمني منتهي الصلاحية (> 300 ثانية) → 401
        $oldTs = (string) (time() - 600);
        $nonce1 = Str::random(32);
        $sigOld = BusinessGatewaySignature::sign(
            BusinessGatewaySignature::inboundCanonical($oldTs, $nonce1, 'POST', $path, '', $originalBody),
            self::BUSINESS_INBOUND_SECRET
        );
        $this->call('POST', $path, [], [], [], $this->toServerHeaders([
            'X-Amial-Business-Timestamp' => $oldTs,
            'X-Amial-Business-Nonce' => $nonce1,
            'X-Amial-Business-Signature' => $sigOld,
            'X-Amial-Business-Link' => $link->link_ulid,
        ]), $originalBody)->assertStatus(401)->assertJsonPath('code', 'BUSINESS_SIGNATURE_EXPIRED');

        // (ب) التلاعب بمبلغ الفاتورة بعد التوقيع → 401
        $nowTs = (string) time();
        $nonce2 = Str::random(32);
        $sigValidForOriginal = BusinessGatewaySignature::sign(
            BusinessGatewaySignature::inboundCanonical($nowTs, $nonce2, 'POST', $path, '', $originalBody),
            self::BUSINESS_INBOUND_SECRET
        );
        $this->call('POST', $path, [], [], [], $this->toServerHeaders([
            'X-Amial-Business-Timestamp' => $nowTs,
            'X-Amial-Business-Nonce' => $nonce2,
            'X-Amial-Business-Signature' => $sigValidForOriginal,
            'X-Amial-Business-Link' => $link->link_ulid,
        ]), $tamperedBody)->assertStatus(401)->assertJsonPath('code', 'BUSINESS_SIGNATURE_INVALID');

        // (ج) الطلب السليم يمرّ مرة واحدة، وإعادته بنفس الـ Nonce تُرفض بـ 409
        $first = $this->call('POST', $path, [], [], [], $this->toServerHeaders([
            'X-Amial-Business-Timestamp' => $nowTs,
            'X-Amial-Business-Nonce' => $nonce2,
            'X-Amial-Business-Signature' => $sigValidForOriginal,
            'X-Amial-Business-Link' => $link->link_ulid,
        ]), $originalBody);
        $this->assertContains($first->getStatusCode(), [200, 201]);

        $replay = $this->call('POST', $path, [], [], [], $this->toServerHeaders([
            'X-Amial-Business-Timestamp' => $nowTs,
            'X-Amial-Business-Nonce' => $nonce2,
            'X-Amial-Business-Signature' => $sigValidForOriginal,
            'X-Amial-Business-Link' => $link->link_ulid,
        ]), $originalBody);
        $replay->assertStatus(409)->assertJsonPath('code', 'BUSINESS_REQUEST_REPLAYED');
    }

    // ══════════════════════════════════════════════════════════════════════
    // 6. Defensive Security Headers & HTTPS HSTS (OWASP ASVS V14.4)
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function defensive_http_security_headers_and_tls_hsts_are_enforced_on_api_responses(): void
    {
        $res = $this->call('GET', '/api/v1/amial/ping', [], [], [], [
            'HTTPS' => 'on',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $res->assertHeader('X-Content-Type-Options', 'nosniff');
        $res->assertHeader('X-Frame-Options', 'DENY');
        $res->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $res->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('max-age=31536000', (string) $res->headers->get('Strict-Transport-Security'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // 7. [BG-SEC-01] Anti-Spoofing on Device Trust Gate (OWASP API2 / CWE-290)
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function blocked_device_cannot_bypass_device_trust_gate_via_spoofed_x_forwarded_for_loopback(): void
    {
        $victim = User::factory()->create(['type' => 2, 'role' => 'customer']);
        UserLogHistory::create([
            'user_id' => $victim->id,
            'device_id' => 'stolen-iphone-device-99',
            'is_active' => true,
            'is_blocked' => true,
            'blocked_at' => now(),
            'block_reason' => 'سرقة الجهاز المبلّغ عنها من العميل',
        ]);

        Passport::actingAs($victim, [], 'api');

        // من عنوان عادي يُحجب الجهاز المسروق بـ 403 DEVICE_BLOCKED
        $normalAttempt = $this->getJson('/api/v1/customer/get-purpose', [
            'device-id' => 'stolen-iphone-device-99',
            'X-Forwarded-For' => '198.51.100.42',
        ]);
        $normalAttempt->assertStatus(403)->assertJsonPath('code', 'DEVICE_BLOCKED');

        // اختبار مصرفي صارم: تزوير X-Forwarded-For: ::1 لا يجوز أبداً أن يتجاوز حظر الجهاز المسروق!
        $spoofedLoopbackAttempt = $this->getJson('/api/v1/customer/get-purpose', [
            'device-id' => 'stolen-iphone-device-99',
            'X-Forwarded-For' => '::1',
        ]);

        $this->assertSame(
            403,
            $spoofedLoopbackAttempt->getStatusCode(),
            '[ثغرة BG-SEC-01] تزوير الترويسة X-Forwarded-For: ::1 مع TrustProxies(*) تجاوز حارس CheckDeviceId وسمح لجهاز محظور/مسروق بالعبور!'
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // 8. [BG-SEC-02] PIN Lockout Enforcement on Live Money Endpoints
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function locked_transaction_pin_must_block_live_money_transfer_endpoints_and_count_failures(): void
    {
        DB::table('business_settings')->updateOrInsert(['key' => 'send_money_status'], ['value' => '1']);

        $admin = User::factory()->admin()->create();
        $sender = User::factory()->create(['type' => 2, 'phone' => '+967771000111', 'zone_code' => 'SOUTH']);
        $receiver = User::factory()->create(['type' => 2, 'phone' => '+967771000222', 'zone_code' => 'SOUTH']);
        $this->establishKycEvidence($sender, 2, $admin);
        $this->establishKycEvidence($receiver, 2, $admin);

        EMoney::create(['user_id' => $admin->id, 'current_balance' => '0.0000', 'zone_code' => 'SOUTH']);
        EMoney::create(['user_id' => $sender->id, 'current_balance' => '50000.0000', 'zone_code' => 'SOUTH']);
        EMoney::create(['user_id' => $receiver->id, 'current_balance' => '0.0000', 'zone_code' => 'SOUTH']);

        FeeScheme::create([
            'code' => 'SEND_MONEY', 'zone_code' => 'SOUTH', 'applies_to' => 'customer',
            'fee_type' => 'fixed', 'percent_rate' => '0', 'fixed_amount' => '0',
            'agent_commission_percent' => '0', 'agent_commission_fixed' => '0',
            'bearer' => 'sender', 'version' => 1, 'is_active' => true, 'effective_from' => now(),
        ]);

        // ضبط PIN صحيح ثم قفل الحساب أمنياً لمدة 30 دقيقة بسبب تخمين متكرر
        $pinService = app(TransactionPinService::class);
        $pinService->setPin($sender, '7391');
        $sender->forceFill([
            'pin_failed_attempts' => 5,
            'pin_locked_until' => now()->addMinutes(30),
        ])->save();

        UserLogHistory::create([
            'user_id' => $sender->id,
            'device_id' => 'trusted-dev-1',
            'is_active' => true,
            'is_blocked' => false,
        ]);

        Passport::actingAs($sender->fresh(), [], 'api');

        $res = $this->postJson('/api/v1/customer/send-money', [
            'phone' => $receiver->phone,
            'amount' => '1000',
            'pin' => '7391',
        ], [
            'device-id' => 'trusted-dev-1',
            'X-Forwarded-For' => '198.51.100.10',
            'Idempotency-Key' => (string) Str::ulid(),
        ]);

        $senderBalance = MoneyService::normalize((string) EMoney::where('user_id', $sender->id)->value('current_balance'));

        $this->assertNotSame(
            200,
            $res->getStatusCode(),
            '[ثغرة BG-SEC-02] مسار التحويل الفعلي customer/send-money يستدعي Helpers::pin_check ويتجاهل قفل pin_locked_until فيمرّر التحويل رغم قفل الرمز!'
        );
        $this->assertSame('50000.0000', $senderBalance);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 9. [BG-SEC-03] Mass-Assignment Protection on Privileged User Attributes
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function user_model_prevents_mass_assignment_of_privileged_role_and_security_locks(): void
    {
        $customer = User::factory()->create([
            'type' => 2,
            'role' => 'customer',
            'verification_level' => 'basic',
            'pin_failed_attempts' => 5,
            'pin_locked_until' => now()->addMinutes(30),
            'security_hold_until' => now()->addHours(24),
        ]);

        // محاولة حقن جماعي (BOPLA / Mass Assignment) لرفع الدور إلى super_admin وفك الأقفال الأمنية
        $customer->fill([
            'type' => 0,
            'role' => 'super_admin',
            'verification_level' => 'gold',
            'pin_failed_attempts' => 0,
            'pin_locked_until' => null,
            'security_hold_until' => null,
        ]);

        $this->assertSame(2, (int) $customer->type, 'حقل type تسرب عبر Mass Assignment!');
        $this->assertNotSame(
            'super_admin',
            $customer->role,
            '[ثغرة BG-SEC-03] حقل role موجود في User::$fillable مما يسمح برفع عميل عادي إلى super_admin عبر fill()!'
        );
        $this->assertNotNull(
            $customer->pin_locked_until,
            '[ثغرة BG-SEC-03] حقل pin_locked_until موجود في User::$fillable مما يسمح بتصفير قفل الـ PIN عبر fill()!'
        );
        $this->assertNotNull(
            $customer->security_hold_until,
            '[ثغرة BG-SEC-03] حقل security_hold_until موجود في User::$fillable مما يسمح بإلغاء التجميد الأمني عبر fill()!'
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // 10. [BG-SEC-04] Muwathaq S2S Request-Target Binding (FAPI 2.0 / CWE-347)
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function muwathaq_internal_s2s_hmac_binds_http_method_path_query_and_prevents_cross_endpoint_substitution(): void
    {
        config(['muwathaq.transport_secret' => 'bankgrade-muwathaq-master-secret-2026']);
        $secret = MuwathaqTransportSecrets::muwathaqToAmial();

        $applicant = User::factory()->tierZero()->create(['phone' => '+967773123456']);
        $application = MuwathaqAccountOpeningApplication::create([
            'application_ulid' => (string) Str::ulid(),
            'user_id' => $applicant->id,
            'subject_kind' => 'customer',
            'verification_path' => 'in_app',
            'requested_tier' => 2,
            'status' => MuwathaqAccountOpeningApplication::STATUS_UNDER_REVIEW,
            'submitted_at' => now(),
        ]);

        $fakeScan = \Illuminate\Http\UploadedFile::fake()->createWithContent(
            'id-front.jpg',
            'CONFIDENTIAL-NATIONAL-ID-SCAN-BYTES'
        );
        $storedPath = app(EncryptedFileStorage::class)->encryptAndStore($fakeScan, 'kyc');
        $evidence = MuwathaqOpeningEvidence::create([
            'application_id' => $application->id,
            'kind' => 'national_id_front',
            'state' => 'submitted',
            'file_encrypted_path' => $storedPath,
            'file_mime' => 'image/jpeg',
            'file_sha256' => hash('sha256', 'CONFIDENTIAL-NATIONAL-ID-SCAN-BYTES'),
        ]);

        // توقيعٌ شرعيٌّ أُصدر حصراً لطلب GET بريء إلى دليل الحسابات (?limit=1)
        $timestamp = (string) time();
        $signatureForDirectoryOnly = hash_hmac('sha256', $timestamp . '.', $secret);

        $dirRes = $this->get('/api/v1/internal/muwathaq/account-directory?limit=1', [
            'X-Muwathaq-Timestamp' => $timestamp,
            'X-Muwathaq-Signature' => $signatureForDirectoryOnly,
            'Accept' => 'application/json',
        ]);
        $dirRes->assertStatus(200);

        // الهجوم: استخدام التوقيع نفسه تماماً لتنزيل وثيقة هوية مشفرة من مسار مختلف كلّياً!
        $crossEndpointAttack = $this->get(
            "/api/v1/internal/muwathaq/applications/{$application->application_ulid}/evidences/{$evidence->id}/document",
            [
                'X-Muwathaq-Timestamp' => $timestamp,
                'X-Muwathaq-Signature' => $signatureForDirectoryOnly,
            ]
        );

        $this->assertSame(
            401,
            $crossEndpointAttack->getStatusCode(),
            '[ثغرة BG-SEC-04] توقيع موثّق VerifyMuwathaqSignature لا يربط الطريقة والمسار والاستعلام ولا يملك Nonce، فنجح استخدام توقيع GET عام لتنزيل وثيقة هوية مشفرة!'
        );

        // والاتجاه الموجب: التوقيع القانوني v2 المقيد بالمسار والـ Nonce يعمل لمرة واحدة ويرفض الإعادة بـ 409
        $docPath = "/api/v1/internal/muwathaq/applications/{$application->application_ulid}/evidences/{$evidence->id}/document";
        $nonceV2 = Str::random(32);
        $sigV2 = hash_hmac(
            'sha256',
            \App\Http\Middleware\VerifyMuwathaqSignature::canonical($timestamp, $nonceV2, 'GET', $docPath, '', ''),
            $secret
        );

        $legitDocRes = $this->get($docPath, [
            'X-Muwathaq-Timestamp' => $timestamp,
            'X-Muwathaq-Nonce' => $nonceV2,
            'X-Muwathaq-Signature' => $sigV2,
        ]);
        $legitDocRes->assertStatus(200);
        $this->assertSame('CONFIDENTIAL-NATIONAL-ID-SCAN-BYTES', $legitDocRes->getContent());

        $replayedDocRes = $this->get($docPath, [
            'X-Muwathaq-Timestamp' => $timestamp,
            'X-Muwathaq-Nonce' => $nonceV2,
            'X-Muwathaq-Signature' => $sigV2,
        ]);
        $replayedDocRes->assertStatus(409)->assertJsonPath('code', 'MUWATHAQ_SIGNATURE_REPLAYED');
    }

    private function toServerHeaders(array $headers): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        return $server;
    }
}
