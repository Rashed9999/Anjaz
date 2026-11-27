<?php

namespace Tests\Feature;

use App\Models\AuditDecision;
use App\Models\EMoney;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\PaymentRequestService;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * اختبارات انحدار طلب المال (Payment Requests):
 * - إلغاء الطلب إدارياً داخل معاملة مقفلة مع تسجيل قرار في AuditDecision ومنع الدفع بعد الإلغاء
 * - منع تسوية طلب منتهي الصلاحية عبر الدالة العامة pay() حتى قبل تشغيل Cron وجدولة expireStale
 * - إتاحة الرابط العام /req/{code} دون 404 ودون تسريب اسم الطالب أو ملاحظته
 * - توحيد أمان PIN في pay() والسماح للطالب بمعاينة طلبه عبر showByCode()
 */
class PaymentRequestRegressionTest extends TestCase
{
    use RefreshDatabase;

    private PaymentRequestService $service;
    private User $requester;
    private User $payer;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PaymentRequestService::class);

        $this->requester = User::factory()->create([
            'f_name' => 'أحمد',
            'l_name' => 'السالم',
            'phone' => '+967703000001',
            'type' => CUSTOMER_TYPE,
            'role' => 'customer',
            'is_active' => 1,
            'is_kyc_verified' => 1,
            'zone_code' => 'SOUTH',
            'password' => Hash::make('1234'),
        ]);
        EMoney::create(['user_id' => $this->requester->id, 'current_balance' => '100.0000']);

        $this->payer = User::factory()->create([
            'f_name' => 'سعيد',
            'l_name' => 'المنصور',
            'phone' => '+967703000002',
            'type' => CUSTOMER_TYPE,
            'role' => 'customer',
            'is_active' => 1,
            'is_kyc_verified' => 1,
            'zone_code' => 'SOUTH',
            'password' => Hash::make('1234'),
        ]);
        EMoney::create(['user_id' => $this->payer->id, 'current_balance' => '1000.0000']);

        $this->admin = User::factory()->create([
            'type' => ADMIN_TYPE,
            'role' => 'admin',
            'is_active' => 1,
        ]);
        app(PlatformRoleService::class)->assign($this->admin, PlatformRoleService::ADMIN);
        $this->admin->refresh();
    }

    /** @test */
    public function admin_cancel_records_audit_decision_stops_payment_and_replay_leaves_balances_intact(): void
    {
        $req = $this->service->create(
            requester: $this->requester,
            amount: '250.0000',
            recipientPhone: $this->payer->phone,
            note: 'طلب اختبار إلغاء إداري',
        );

        // الإلغاء الإداري الأول ينجح ويسجّل قراراً في audit_decisions عبر AuditService::record()
        $this->actingAs($this->admin, 'user')
            ->post(route('admin.amial.surface.payment-requests.cancel', $req->id), [
                'reason' => 'طلب مكرّر بناءً على بلاغ العميل',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('cancelled', $req->fresh()->status);

        $audit = AuditDecision::where('subject_type', 'payment_request')
            ->where('subject_id', (string) $req->id)
            ->where('action', 'ADMIN_PAYMENT_REQUEST_CANCELLED')
            ->first();
        $this->assertNotNull($audit, 'يجب تسجيل قرار الإلغاء الإداري في سجل audit_decisions');
        $this->assertSame($this->admin->id, $audit->actor_user_id);
        $this->assertSame('ADMIN_CANCELLED', $audit->decision_code);

        // إعادة الإلغاء (Replay) تُرفض ولا تُغيّر الحالة
        $this->actingAs($this->admin, 'user')
            ->post(route('admin.amial.surface.payment-requests.cancel', $req->id), [
                'reason' => 'محاولة إلغاء مكررة',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        // محاولة الدفع عبر الدالة العامة pay() بعد الإلغاء تُرفض دون حركة مالية
        $blocked = false;
        try {
            $this->service->pay($this->payer, $req->fresh());
        } catch (\RuntimeException) {
            $blocked = true;
        }
        $this->assertTrue($blocked, 'يجب منع دفع طلب ملغى');

        $this->assertSame('1000.0000', (string) EMoney::where('user_id', $this->payer->id)->value('current_balance'));
        $this->assertSame('100.0000', (string) EMoney::where('user_id', $this->requester->id)->value('current_balance'));
    }

    /** @test */
    public function admin_cancel_fails_closed_and_rolls_back_when_audit_cannot_be_persisted(): void
    {
        $req = $this->service->create(
            requester: $this->requester,
            amount: '25.0000',
            recipientPhone: $this->payer->phone,
        );
        $audit = \Mockery::mock(\App\Services\AuditService::class);
        $audit->shouldReceive('record')->once()->andReturn(null);
        $this->app->instance(\App\Services\AuditService::class, $audit);

        $this->actingAs($this->admin, 'user')
            ->post(route('admin.amial.surface.payment-requests.cancel', $req->id), [
                'reason' => 'تجربة تعذّر تسجيل قرار الإلغاء المالي',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('pending', $req->fresh()->status);
    }

    /** @test */
    public function expired_pending_request_cannot_be_paid_via_public_pay_even_before_cron_runs(): void
    {
        $req = $this->service->create(
            requester: $this->requester,
            amount: '300.0000',
            recipientPhone: $this->payer->phone,
        );
        $req->update(['expires_at' => now()->subMinutes(5)]);

        $thrownMessage = '';
        try {
            $this->service->pay($this->payer, $req->fresh());
        } catch (\RuntimeException $e) {
            $thrownMessage = $e->getMessage();
        }

        $this->assertStringContainsString('انتهت صلاحية الطلب', $thrownMessage);
        $this->assertSame('1000.0000', (string) EMoney::where('user_id', $this->payer->id)->value('current_balance'));
        $this->assertSame('100.0000', (string) EMoney::where('user_id', $this->requester->id)->value('current_balance'));

        // expireStale تحوّل الطلب إلى expired بشكل تفرّدي (Idempotent)
        $firstRun = $this->service->expireStale();
        $secondRun = $this->service->expireStale();
        $this->assertSame(1, $firstRun);
        $this->assertSame(0, $secondRun);
        $this->assertSame('expired', $req->fresh()->status);
    }

    /** @test */
    public function public_short_code_web_route_renders_without_404_and_hides_pii(): void
    {
        $req = $this->service->create(
            requester: $this->requester,
            amount: '175.5000',
            note: 'ملاحظة خاصة لا يجوز كشفها',
        );

        $this->get("/req/{$req->short_code}")
            ->assertOk()
            ->assertSee($req->short_code)
            ->assertSee('175.50')
            ->assertDontSee('أحمد السالم')
            ->assertDontSee('ملاحظة خاصة لا يجوز كشفها');

        $this->get('/req/NOPE99')
            ->assertOk()
            ->assertSee('الطلب غير موجود');
    }

    /** @test */
    public function pay_by_code_rejects_non_numeric_pin_and_requester_can_view_own_code(): void
    {
        $req = $this->service->create(
            requester: $this->requester,
            amount: '50.0000',
            recipientPhone: $this->payer->phone,
        );

        // الطالب يمكنه استعراض طلبه عبر showByCode دون 403
        $this->actingAs($this->requester, 'api')
            ->getJson("/api/v1/amial/payment-requests/code/{$req->short_code}")
            ->assertOk()
            ->assertJsonPath('meta.is_self', true);

        // الدفع برمز PIN غير رقمي يُرفض بـ 422
        $this->actingAs($this->payer, 'api')
            ->postJson("/api/v1/amial/payment-requests/code/{$req->short_code}/pay", [
                'pin' => 'abcd',
            ])
            ->assertStatus(422);

        $this->assertSame('pending', $req->fresh()->status);
    }
}
