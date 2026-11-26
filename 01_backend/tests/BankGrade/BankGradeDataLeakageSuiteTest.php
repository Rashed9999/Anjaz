<?php

namespace Tests\BankGrade;

use App\Models\AuditDecision;
use App\Models\EMoney;
use App\Models\PaymentRequest;
use App\Models\Receipt;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserLogHistory;
use App\Services\AuditService;
use App\Services\EncryptionService;
use App\Services\PaymentRequestService;
use App\Services\TwoFactorAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  AMIAL-BANK-GRADE-DLP-001 — مصفوفة الاختبارات المصرفية العالمية (منع تسريب البيانات)
 * ══════════════════════════════════════════════════════════════════════════
 *
 * المعايير المرجعية المطبّقة:
 *   • PCI-DSS v4.0 — المتطلّبان 3 (حماية البيانات المخزّنة والمعمّاة) و10 (سلامة وسرية سجلات التدقيق)
 *   • ISO/IEC 27001:2022 — ضوابط السرية وتقليل البيانات (Data Minimization) وعدم الإنكار
 *   • OWASP API Security Top 10 (2023) — API1:BOLA (عزل ملكية الكائنات) و API3:BOPLA (الإفصاح الزائد)
 *   • NIST SP 800-38D — التشفير المصادَق AES-256-GCM والفهرسة العمياء HMAC-SHA256
 */
class BankGradeDataLeakageSuiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    // ══════════════════════════════════════════════════════════════════════
    // 1. AES-256-GCM Confidentiality, Tamper Detection & Blind Index Safety
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function aes_256_gcm_pii_encryption_enforces_semantic_security_tamper_rejection_and_blind_indexing(): void
    {
        $enc = app(EncryptionService::class);
        $secretNationalId = '01019988776';

        $cipherA = $enc->encrypt($secretNationalId);
        $cipherB = $enc->encrypt($secretNationalId);

        // أمان دلالي (Semantic Security): تشفير القيمة نفسها مرتين ينتج نصين مختلفين تماماً
        $this->assertNotSame($cipherA, $cipherB);
        $this->assertStringNotContainsString($secretNationalId, (string) $cipherA);
        $this->assertSame($secretNationalId, $enc->decrypt($cipherA));

        // كشف التلاعب (Authenticated Tag Integrity): قلب بايت واحد يرفض فك التشفير فوراً
        $corrupted = $cipherA;
        $pos = intdiv(strlen($corrupted), 2);
        $corrupted[$pos] = $corrupted[$pos] === 'X' ? 'Y' : 'X';

        $tamperCaught = false;
        try {
            $enc->decrypt($corrupted);
        } catch (RuntimeException) {
            $tamperCaught = true;
        }
        $this->assertTrue($tamperCaught, 'فشل AES-256-GCM في اكتشاف التلاعب بالنص المشفر!');
        $this->assertNull($enc->tryDecrypt($corrupted));

        // الفهرس الأعمى لا يكشف أجزاء الرقم الأصلي ويوحّد الصيغ
        $idx1 = $enc->blindIndex('01-019-988-776', 'national_id');
        $idx2 = $enc->blindIndex('01019988776', 'national_id');
        $this->assertSame($idx1, $idx2);
        $this->assertStringNotContainsString('9988776', $idx1);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 2. BOLA / IDOR Isolation on Receipts & PDF Downloads (OWASP API1:2023)
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function bola_isolation_prevents_cross_customer_access_to_receipts_and_pdf_exports(): void
    {
        $victim   = User::factory()->create(['f_name' => 'ضحية', 'phone' => '+967770001111']);
        $attacker = User::factory()->create(['f_name' => 'مهاجم', 'phone' => '+967770002222']);

        $victimReceipt = Receipt::create([
            'receipt_number' => 'RCP-SECRET-0001',
            'verification_code' => '2345678923456789',
            'receipt_type' => 'send_money',
            'user_id' => $victim->id,
            'counterparty_user_id' => $victim->id,
            'reference_transaction_id' => (string) Str::ulid(),
            'amount' => '75000.0000',
            'fee' => '250.0000',
            'net_amount' => '75250.0000',
            'direction' => 'debit',
            'status' => 'ready',
            'zone_code' => 'SOUTH',
            'issued_at' => now(),
        ]);

        Passport::actingAs($attacker, [], 'api');

        foreach ([
            "/api/v1/amial/receipts/{$victimReceipt->id}",
            "/api/v1/amial/receipts/{$victimReceipt->id}/download",
            "/api/v1/amial/receipts/{$victimReceipt->id}/thermal?size=80",
            "/api/v1/amial/receipts/{$victimReceipt->id}/invoice",
        ] as $endpoint) {
            $res = $this->getJson($endpoint);
            $this->assertSame(
                404,
                $res->getStatusCode(),
                "تسريب BOLA/IDOR: تمكّن مستخدم آخر من الوصول إلى إيصال الضحية عبر [{$endpoint}]!"
            );
            $this->assertStringNotContainsString('75000', $res->getContent());
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // 3. BOLA Isolation on Account Statements & Payment Requests
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function bola_isolation_prevents_cross_customer_statement_and_payment_request_manipulation(): void
    {
        $victim   = User::factory()->create(['phone' => '+967770003333']);
        $attacker = User::factory()->create(['phone' => '+967770004444']);

        Transaction::create([
            'user_id' => $victim->id,
            'transaction_id' => (string) Str::ulid(),
            'transaction_no' => '120999888777666',
            'transaction_type' => SEND_MONEY,
            'debit' => '98765.0000',
            'credit' => '0.0000',
            'amount' => '98765.0000',
            'charge' => '0.0000',
            'balance' => '12345.0000',
            'from_user_id' => $victim->id,
            'to_user_id' => $victim->id,
            'note' => 'حوالة سرية خاصة بالضحية',
            'zone_code' => 'SOUTH',
        ]);

        $victimRequest = app(PaymentRequestService::class)->create(
            requester: $victim,
            amount: '44000',
            note: 'فاتورة خاصة بالضحية',
        );

        Passport::actingAs($attacker, [], 'api');

        // محاولة حقن user_id الخاص بالضحية في استعلام كشف الحساب
        $stmt = $this->getJson("/api/v1/amial/statement?user_id={$victim->id}");
        $stmt->assertStatus(200);
        $this->assertSame(0, $stmt->json('meta.count'));
        $this->assertStringNotContainsString('98765', $stmt->getContent());
        $this->assertStringNotContainsString('حوالة سرية خاصة بالضحية', $stmt->getContent());

        // محاولة إلغاء طلب دفع الضحية من حساب المهاجم
        $cancel = $this->postJson("/api/v1/amial/payment-requests/{$victimRequest->id}/cancel");
        $this->assertNotSame(200, $cancel->getStatusCode());
        $this->assertSame('pending', $victimRequest->fresh()->status);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 4. Recursive Secret & Card/PIN Scrubbing in Audit Logs (PCI-DSS 3.3/10.2)
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function audit_log_recursively_redacts_all_credentials_pins_tokens_and_card_data(): void
    {
        $audit = app(AuditService::class);
        $decisionId = $audit->record([
            'actor_type' => 'user',
            'actor_user_id' => 1,
            'subject_type' => 'user',
            'subject_id' => '1',
            'action' => 'SENSITIVE_PAYLOAD_TEST',
            'decision_code' => 'TEST_REDACTION',
            'severity' => 'warning',
            'context' => [
                'safe_note' => 'عملية مراجعة',
                'password' => 'PlainSuperSecretPass!',
                'transaction_pin' => '9517',
                'nested_headers' => [
                    'authorization' => 'Bearer eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9',
                    'access_token' => 'tok_live_secret_999',
                    'payment_card' => [
                        'card_number' => '4532015112830366',
                        'cvv' => '789',
                        'iban' => 'YE00AMIAL0000123456789',
                        'otp' => '654321',
                    ],
                ],
            ],
        ]);

        $this->assertNotNull($decisionId);
        $rawRow = (string) DB::table('audit_decisions')->where('decision_id', $decisionId)->value('context');

        foreach ([
            'PlainSuperSecretPass!',
            '9517',
            'eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9',
            'tok_live_secret_999',
            '4532015112830366',
            '789',
            'YE00AMIAL0000123456789',
            '654321',
        ] as $forbiddenSecret) {
            $this->assertStringNotContainsString(
                $forbiddenSecret,
                $rawRow,
                "تسرّبت قيمة سرية [{$forbiddenSecret}] إلى جدول التدقيق audit_decisions!"
            );
        }
        $this->assertStringContainsString('[REDACTED]', $rawRow);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 5. Cryptographic Audit Hash-Chain Insider Tamper Detection (PCI-DSS 10.3)
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function cryptographic_audit_hash_chain_detects_post_hoc_database_tampering(): void
    {
        $audit = app(AuditService::class);

        $id1 = $audit->record([
            'actor_type' => 'admin', 'actor_user_id' => 10,
            'subject_type' => 'wallet', 'subject_id' => '55',
            'action' => 'LIMIT_OVERRIDE', 'decision_code' => 'APPROVED',
            'reason' => 'موافقة رسمية موثقة', 'severity' => 'critical',
        ]);
        $id2 = $audit->record([
            'actor_type' => 'admin', 'actor_user_id' => 10,
            'subject_type' => 'settlement', 'subject_id' => '99',
            'action' => 'SETTLEMENT_APPROVED', 'decision_code' => 'APPROVED',
            'reason' => 'تسوية مليون ريال', 'severity' => 'critical',
        ]);

        $row1 = AuditDecision::where('decision_id', $id1)->firstOrFail();
        $row2 = AuditDecision::where('decision_id', $id2)->firstOrFail();

        // قبل التلاعب: السلسلة متصلة والبصمتان سليمتان
        $this->assertSame($row1->entry_hash, $row2->prev_hash);
        $this->assertTrue(AuditService::hashMatches($row1->prev_hash, $row1->toArray(), $row1->entry_hash));
        $this->assertTrue(AuditService::hashMatches($row2->prev_hash, $row2->toArray(), $row2->entry_hash));

        // محاكاة تلاعب داخلي في قاعدة البيانات (تزوير سبب الموافقة أو هوية الفاعل)
        DB::table('audit_decisions')->where('id', $row1->id)->update([
            'actor_user_id' => 999,
            'reason' => 'سبب مزوّر بعد الحادثة',
        ]);

        $tamperedRow1 = AuditDecision::findOrFail($row1->id);
        $this->assertFalse(
            AuditService::hashMatches($tamperedRow1->prev_hash, $tamperedRow1->toArray(), $tamperedRow1->entry_hash),
            'فشلت سلسلة التجزئة الجنائية في كشف التعديل المباشر على جدول audit_decisions!'
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // 6. SQL Injection & Stack-Trace Non-Leakage (OWASP ASVS V5.3 & V7.4)
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function sql_injection_and_malformed_inputs_never_leak_database_schema_or_stack_traces(): void
    {
        User::factory()->create(['phone' => '+967771234567']);

        $payloads = [
            "967771234567' OR '1'='1",
            "967771234567\" UNION SELECT 1,password,3 FROM users--",
            "'; DROP TABLE users; --",
        ];

        foreach ($payloads as $sqli) {
            $res = $this->postJson('/api/v1/auth/login', [
                'role' => 'customer',
                'national_id' => $sqli,
                'phone' => $sqli,
                'password' => "x' OR '1'='1",
            ]);

            $body = $res->getContent();
            $this->assertNotSame(200, $res->getStatusCode());
            $this->assertNotSame(500, $res->getStatusCode(), "تسبّبت حمولة SQLi في خطأ 500 على الدخول!");
            $this->assertStringNotContainsStringIgnoringCase('SQLSTATE', $body);
            $this->assertStringNotContainsStringIgnoringCase('syntax error', $body);
            $this->assertStringNotContainsStringIgnoringCase('Stack trace', $body);
            $this->assertStringNotContainsString('access_token', $body);
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // 7. [BG-LEAK-01] Excessive Data Exposure in POST /api/v1/check-customer
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function customer_lookup_endpoint_never_leaks_victim_national_id_2fa_secrets_or_encrypted_columns(): void
    {
        $victim = User::factory()->create([
            'type' => 2,
            'role' => 'customer',
            'f_name' => 'سالم',
            'l_name' => 'الحضرمي',
            'phone' => '+967775551111',
            'email' => 'salem.private@bank-client.ye',
            'identification_number' => '01099887766',
            'security_hold_reason' => 'مراجعة امتثال داخلية سرية',
        ]);
        app(TwoFactorAuthService::class)->setup($victim);

        $curiousPeer = User::factory()->create(['type' => 2, 'role' => 'customer', 'phone' => '+967775552222']);
        Passport::actingAs($curiousPeer, [], 'api');

        $res = $this->postJson('/api/v1/check-customer', [
            'phone' => '775551111',
        ]);

        $res->assertStatus(200);
        $rawBody = $res->getContent();

        $this->assertArrayNotHasKey(
            'user_info',
            $res->json(),
            '[ثغرة حرجة BG-LEAK-01] المسار POST /api/v1/check-customer يُسرّب كائن User الكامل (user_info) لأي مستخدم يعرف رقم هاتف الضحية!'
        );
        $this->assertStringNotContainsString('01099887766', $rawBody, 'تسرّب رقم الهوية الوطنية للضحية!');
        $this->assertStringNotContainsString('salem.private@bank-client.ye', $rawBody, 'تسرّب البريد الشخصي للضحية!');
        $this->assertStringNotContainsString('two_factor_secret', $rawBody, 'تسرّب حقل سر التحقق الثنائي 2FA!');
        $this->assertStringNotContainsString('national_id_blind_index', $rawBody, 'تسرّب الفهرس الأعمى للهوية الوطنية!');
        $this->assertStringNotContainsString('مراجعة امتثال داخلية سرية', $rawBody, 'تسرّب سبب التجميد الأمني الداخلي!');
    }

    // ══════════════════════════════════════════════════════════════════════
    // 8. [BG-LEAK-02] User Model JSON Serialization Guard (PCI-DSS Req 3.4)
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function user_model_serialization_hides_two_factor_secrets_ciphertexts_and_blind_indexes(): void
    {
        $admin = User::factory()->admin()->create([
            'phone' => '+967779998887',
            'email' => 'ciso@amialpay.com',
            'identification_number' => '01055443322',
        ]);
        app(TwoFactorAuthService::class)->setup($admin);

        $serialized = $admin->fresh()->toArray();

        $mustBeHidden = [
            'password',
            'transaction_pin',
            'fcm_token',
            'remember_token',
            'two_factor_secret',
            'two_factor_recovery_codes',
            'phone_encrypted',
            'phone_blind_index',
            'email_encrypted',
            'email_blind_index',
            'national_id_encrypted',
            'national_id_blind_index',
        ];

        foreach ($mustBeHidden as $sensitiveColumn) {
            $this->assertArrayNotHasKey(
                $sensitiveColumn,
                $serialized,
                "[ثغرة BG-LEAK-02] الحقل السري [{$sensitiveColumn}] غير مُدرج في User::\$hidden ويظهر في أي toArray()/JSON!"
            );
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // 9. [BG-LEAK-03] Unauthenticated Phone Enumeration Guard (OWASP ASVS V2.2)
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function unauthenticated_phone_probe_does_not_leak_full_customer_name_or_account_role(): void
    {
        $agent = User::factory()->create([
            'type' => 1,
            'role' => 'agent',
            'f_name' => 'شركة الصرافة',
            'l_name' => 'المركزية',
            'phone' => '+967774443332',
        ]);

        // مستعلم مجهول بلا أي توكن يجسّ أرقام الهواتف عبر مسار عام
        $res = $this->postJson('/api/v1/customer/auth/check-phone', [
            'phone' => '774443332',
        ]);

        $raw = $res->getContent();
        $this->assertStringNotContainsString(
            'شركة الصرافة المركزية',
            $raw,
            '[ثغرة BG-LEAK-03] المسار العام غير المصادَق customer/auth/check-phone يُفصح عن الاسم الكامل لصاحب الرقم ونوع حسابه (عميل/وكيل)!'
        );
        $this->assertArrayNotHasKey('user_name', $res->json() ?? []);
        $this->assertArrayNotHasKey('user_type', $res->json() ?? []);
    }
}
