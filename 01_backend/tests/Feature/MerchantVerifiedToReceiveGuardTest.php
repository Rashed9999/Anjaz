<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\MerchantProfile;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\MerchantVerificationService;
use App\Services\PaymentRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-MERCHANT-VERIFY-RECEIVE-001 — **«دخولٌ محدود فوراً»: القفلُ ماليٌّ
 * خادميّ، لا حبسٌ للواجهة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما كان، وقِيس:** التاجرُ الجديد يُحبَس في التطبيق كلِّه بشاشة «قيد
 * المراجعة» (‏AMIAL-VERIFY-GATE)، فلا يبيع ولا يجرد ولا يطبع — والمالكُ
 * هو من أنشأ الحساب. وقرارُ صاحب المشروع: **يدخل ويعمل فوراً، ويبقى
 * استلامُ المال الحقيقيّ عبر المنصّة مقفلاً حتّى تعتمده الإدارة.**
 *
 * **ورفعُ حبس الواجهة وحدَه ثغرة:** «إخفاءُ الواجهة ليس أماناً»
 * (amial-rbac). فلو رُفع الحبسُ بلا حارسٍ خادميّ، لقبض تاجرٌ غيرُ موثّقٍ
 * مالاً حقيقيّاً — تخطٍّ كاملٌ لبوّابة الاعتماد. فالقفلُ الحقيقيُّ يقع في
 * **الخادم**، عند البابِ الوحيد الذي يستلم منه التاجرُ مالاً:
 * `MerchantRiskService::assertReceiveAllowed`.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والبابان يُختبَران معاً — القاعدةُ الرابعة:**
 *
 *     QR / POS        →  merchant_payment_transaction
 *     رابطٌ / فاتورة  →  PaymentRequestService::settle
 *
 * فحارسٌ على أحدهما يُقرأ «مطبَّق» ويُلتفّ عليه من الآخر.
 *
 * **وعُطلٌ ثالثٌ يُمسَك هنا:** الاعتمادُ من اللوحة كان يضبط
 * `verification_status='verified'` على الـprofile وحدَه، و
 * `users.is_kyc_verified` يبقى 0 — فالتاجرُ المعتمَدُ يبقى محبوساً كأنّه
 * لم يُعتمَد. الاختبارُ الأخيرُ يثبت أنّ الاعتمادَ يرفع القفلَ فعلاً.
 */
class MerchantVerifiedToReceiveGuardTest extends TestCase
{
    use RefreshDatabase;

    /** تاجرٌ بحالة توثيقٍ محدّدة وحدودٍ سخيّة (فالمقياسُ هو التوثيق لا الحدّ). */
    private function merchant(string $status): User
    {
        $m = User::factory()->create(['type' => 3, 'is_active' => 1, 'is_kyc_verified' => 0]);
        MerchantProfile::create([
            'user_id' => $m->id,
            'tier' => 'small',
            'verification_status' => $status,
            'single_receive_limit' => '500000',
            'daily_receive_limit' => '5000000',
        ]);

        return $m;
    }

    private function customer(): User
    {
        return User::factory()->create([
            'type' => 2, 'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ]);
    }

    private function fund(User $u, string $amount): void
    {
        EMoney::updateOrCreate(['user_id' => $u->id], ['current_balance' => $amount]);
    }

    private function balance(User $u): string
    {
        return (string) (EMoney::where('user_id', $u->id)->value('current_balance') ?? '0');
    }

    // ══════════════════════════════════════════════════════════════════
    // البابُ الأوّل — QR / POS
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function an_unverified_merchant_cannot_receive_at_the_qr_door(): void
    {
        $customer = $this->customer();
        $merchant = $this->merchant('pending_review');
        $this->fund($customer, '10000');

        $before = $this->balance($merchant);

        try {
            $svc = new class { use \App\Traits\TransactionTrait; };
            $svc->merchant_payment_transaction($customer->id, $merchant->id, '1000', 'qr');
            $this->fail('قبض تاجرٌ قيد المراجعة مالاً عبر QR — القفلُ الماليُّ غائب');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('قيد المراجعة', $e->getMessage());
        }

        // الرفضُ يُقاس بالأثر لا بالرسالة: لا مالَ تحرّك.
        $this->assertSame($before, $this->balance($merchant),
            'رُفض القبضُ ومع ذلك تحرّك رصيدُ التاجر');
    }

    /** @test */
    public function a_verified_merchant_receives_at_the_qr_door(): void
    {
        $customer = $this->customer();
        $merchant = $this->merchant('verified');
        $this->fund($customer, '10000');

        $svc = new class { use \App\Traits\TransactionTrait; };
        $txId = $svc->merchant_payment_transaction($customer->id, $merchant->id, '1000', 'qr');

        $this->assertNotNull($txId, 'تاجرٌ موثّقٌ ومع ذلك رُفض قبضُه — القفلُ زائدُ الإحكام');
        $this->assertTrue(bccomp($this->balance($merchant), '0', 4) > 0,
            'تاجرٌ موثّقٌ لم يصله مال');
    }

    // ══════════════════════════════════════════════════════════════════
    // البابُ الثاني — رابطُ الدفع / الفاتورة
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function an_unverified_merchant_cannot_receive_via_a_payment_request(): void
    {
        $customer = $this->customer();
        $merchant = $this->merchant('pending_review');
        $this->fund($customer, '10000');

        // فاتورةٌ عامّةٌ من تاجرٍ قيد المراجعة، يدفعها عميلٌ موثّق.
        $req = PaymentRequest::create([
            'request_ulid' => (string) \Illuminate\Support\Str::ulid(),
            'short_code' => strtoupper(\Illuminate\Support\Str::random(8)),
            'requester_user_id' => $merchant->id,
            'recipient_user_id' => null,
            'amount' => '1000',
            'status' => 'pending',
            'share_method' => 'link',
            'expires_at' => now()->addDay(),
        ]);

        $before = $this->balance($merchant);

        try {
            app(PaymentRequestService::class)->pay($customer, $req);
            $this->fail('قبض تاجرٌ قيد المراجعة مالاً عبر فاتورة — البابُ الثاني بلا حارس');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('قيد المراجعة', $e->getMessage());
        }

        $this->assertSame($before, $this->balance($merchant),
            'رُفضت الفاتورةُ ومع ذلك تحرّك رصيدُ التاجر');
    }

    // ══════════════════════════════════════════════════════════════════
    // الاعتماد يرفع القفلَ فعلاً — لا اسماً
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function admin_approval_flips_kyc_and_unlocks_receiving(): void
    {
        $admin = User::factory()->create(['type' => 1]);
        $merchant = $this->merchant('pending_review');

        // اجعل الطلبَ قيدَ المراجعة (كما يصنعه submit).
        $req = \App\Models\MerchantVerificationRequest::create([
            'request_ulid' => (string) \Illuminate\Support\Str::ulid(),
            'merchant_user_id' => $merchant->id,
            'business_name' => 'متجر الاختبار',
            'status' => 'pending_review',
        ]);

        $this->assertSame(0, (int) $merchant->fresh()->is_kyc_verified,
            'قبل الاعتماد: التاجرُ غيرُ موثّق');

        app(MerchantVerificationService::class)->approve($req, $admin->id);

        // ① الاعتمادُ رفع القفلَ في الحقلين معاً.
        $this->assertSame(1, (int) $merchant->fresh()->is_kyc_verified,
            'الاعتمادُ لم يضبط is_kyc_verified — فالتطبيقُ يبقى حابساً للتاجر المعتمَد');
        $this->assertSame('verified',
            MerchantProfile::where('user_id', $merchant->id)->value('verification_status'));

        // ② وبعد الاعتماد يقبض فعلاً عبر البابِ الماليّ.
        $customer = $this->customer();
        $this->fund($customer, '10000');
        $svc = new class { use \App\Traits\TransactionTrait; };
        $txId = $svc->merchant_payment_transaction($customer->id, $merchant->id, '1000', 'qr');

        $this->assertNotNull($txId, 'اعتُمد التاجرُ ومع ذلك رُفض قبضُه — الاعتمادُ اسمٌ بلا أثر');
    }
}
