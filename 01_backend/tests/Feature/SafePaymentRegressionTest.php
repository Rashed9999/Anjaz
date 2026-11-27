<?php

namespace Tests\Feature;

use App\Models\AuditDecision;
use App\Models\EMoney;
use App\Models\SafePayment;
use App\Models\SafePaymentEvent;
use App\Models\SafePaymentEvidence;
use App\Models\User;
use App\Services\PlatformRoleService;
use App\Services\SafePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\StartsWithSafePaymentIntakeOpen;
use Tests\TestCase;

/**
 * اختبارات انحدار الدفع الآمن (Safe Payment):
 * - تسجيل مسار verify-seller وعمله بنجاح
 * - إزالة الحظر الذاتي في FourEyesService عند فتح النزاع والأدلة ثم الحسم من نفس الموظف
 * - منع الحسم المزدوج (Replay) وضمان عدم مضاعفة الإيداع للبائع أو المشتري
 * - نظافة سجل الأحداث وتسجيل الصافي والرسوم في adminResolveRelease
 * - إظهار ملاحظة الدليل note وإرجاع المحاولات المتبقية عند خطأ رمز التسليم
 */
class SafePaymentRegressionTest extends TestCase
{
    use RefreshDatabase;
    use StartsWithSafePaymentIntakeOpen;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startWithSafePaymentIntakeOpen();
    }

    private function party(string $phone, string $balance = '50000.0000'): User
    {
        $u = User::factory()->create([
            'phone' => $phone,
            'type' => CUSTOMER_TYPE,
            'role' => 'customer',
            'is_active' => 1,
            'is_kyc_verified' => 1,
            'zone_code' => 'SOUTH',
        ]);
        EMoney::create([
            'user_id' => $u->id,
            'current_balance' => $balance,
            'held_balance' => '0.0000',
            'pending_balance' => '0.0000',
            'charge_earned' => '0.0000',
            'zone_code' => 'SOUTH',
        ]);

        return $u;
    }

    private function adminStaff(): User
    {
        $u = User::factory()->create([
            'type' => ADMIN_TYPE,
            'role' => 'admin',
            'is_active' => 1,
        ]);
        app(PlatformRoleService::class)->assign($u, PlatformRoleService::ADMIN);

        return $u->refresh();
    }

    /** @test */
    public function verify_seller_route_is_registered_and_returns_token(): void
    {
        $buyer = $this->party('+967704000001');
        $seller = $this->party('+967704000002', '0.0000');

        $this->actingAs($buyer, 'api')
            ->postJson('/api/v1/amial/safe-payments/verify-seller', [
                'seller_phone' => $seller->phone,
            ])
            ->assertOk()
            ->assertJsonStructure(['meta' => ['verification_token']]);
    }

    /** @test */
    public function same_admin_can_view_dispute_and_evidence_then_resolve_without_self_lockout_and_replay_is_blocked(): void
    {
        $buyer = $this->party('+967704000003', '50000.0000');
        $seller = $this->party('+967704000004', '0.0000');
        $staff = $this->adminStaff();
        $svc = app(SafePaymentService::class);

        $payment = $svc->createAndFund($buyer, $seller, 'شاشة حاسوب', 'شاشة جديدة بالكرتون', '10000.0000');
        $svc->sellerAccept($payment->fresh(), $seller);
        $svc->sellerMarkInDelivery($payment->fresh(), $seller);
        $svc->sellerMarkDelivered($payment->fresh(), $seller);

        // رفع دليل مع ملاحظة والتحقق من ظهورها في واجهة العميل
        $this->actingAs($buyer, 'api')
            ->post("/api/v1/amial/safe-payments/{$payment->payment_ulid}/evidence", [
                'stage' => 'dispute',
                'note' => 'صورة الكسر في الزاوية اليمنى',
                'files' => [UploadedFile::fake()->image('crack.jpg', 400, 300)],
            ])
            ->assertSuccessful();

        $evidenceList = $this->actingAs($buyer, 'api')
            ->getJson("/api/v1/amial/safe-payments/{$payment->payment_ulid}/evidence")
            ->assertOk()
            ->json('meta.evidence');
        $this->assertSame('صورة الكسر في الزاوية اليمنى', $evidenceList['dispute'][0]['note'] ?? null);

        $svc->buyerDispute($payment->fresh(), $buyer, 'الشاشة وصلت مكسورة', null, 'damaged');
        $evId = SafePaymentEvidence::where('safe_payment_id', $payment->id)->value('id');

        // الموظف يفتح تفاصيل النزاع ويطّلع على الدليل (يكتب سجلات قراءة في audit_decisions)
        $this->actingAs($staff, 'user')
            ->getJson("/admin/amial/safe-payments/{$payment->payment_ulid}")
            ->assertOk()
            ->assertJsonPath('meta.dispute_reason', 'الشاشة وصلت مكسورة')
            ->assertJsonPath('meta.can_decide', true);

        $this->actingAs($staff, 'user')
            ->get("/admin/amial/safe-payments/evidence/{$evId}/file")
            ->assertOk();

        // نفس الموظف يحسم النزاع بالإفراج — يجب أن ينجح ولا يُحظر ذاتياً بـ FOUR_EYES_VIOLATION
        $this->actingAs($staff, 'user')
            ->postJson("/admin/amial/safe-payments/{$payment->payment_ulid}/release", [
                'reason' => 'بعد فحص الأدلة تبيّن سلامة التسليم للبائع',
            ])
            ->assertOk();

        $payment->refresh();
        $this->assertSame('released_to_seller', $payment->status);
        $sellerBalanceAfterFirst = (string) EMoney::where('user_id', $seller->id)->value('current_balance');
        $this->assertGreaterThan(0, (float) $sellerBalanceAfterFirst);

        // التأكد من عدم وجود حدث زائف من buyer_confirmed عند الحسم الإداري
        $badEvents = SafePaymentEvent::where('safe_payment_id', $payment->id)
            ->where('from_status', 'buyer_confirmed')
            ->count();
        $this->assertSame(0, $badEvents);

        // التأكد من تسجيل صافي البائع والرسوم في سجل التدقيق
        $audit = AuditDecision::where('subject_type', 'safe_payment')
            ->where('subject_id', (string) $payment->id)
            ->where('action', 'SAFE_PAYMENT_ADMIN_RELEASE')
            ->first();
        $this->assertNotNull($audit);
        $ctx = json_decode((string) $audit->context, true);
        $this->assertArrayHasKey('to_seller_net', $ctx);
        $this->assertArrayHasKey('platform_fee', $ctx);

        // إعادة محاولة الحسم (Replay) يجب أن تُرفض ولا تضاعف رصيد البائع
        $this->actingAs($staff, 'user')
            ->postJson("/admin/amial/safe-payments/{$payment->payment_ulid}/release", [
                'reason' => 'محاولة إفراج مكررة لنفس النزاع',
            ])
            ->assertStatus(422);

        $this->assertSame(
            $sellerBalanceAfterFirst,
            (string) EMoney::where('user_id', $seller->id)->value('current_balance')
        );
    }

    /** @test */
    public function wrong_delivery_code_reports_remaining_attempts(): void
    {
        $buyer = $this->party('+967704000005', '50000.0000');
        $seller = $this->party('+967704000006', '0.0000');
        $svc = app(SafePaymentService::class);

        $payment = $svc->createAndFund($buyer, $seller, 'طرد اختباري', 'وصف الطرد', '5000.0000');
        $svc->sellerAccept($payment->fresh(), $seller);
        $svc->sellerMarkInDelivery($payment->fresh(), $seller);

        $message = '';
        try {
            $svc->verifyDeliveryCode($payment->fresh(), $seller, '000000');
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
        }

        $this->assertStringContainsString('المتبقية', $message);
    }
}
