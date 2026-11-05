<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-SHIFT-GATE-002 — **فاتورةُ الجملة النقديّة نقدٌ في الدرج.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * أرسل صاحبُ المشروع: «حساب تاجر جملة أنشأ فاتورةً **دون أن يطلب فتحَ
 * ورديّة**، إذن النظامُ غائبٌ عنه».
 *
 * وقِيس فكان محقّاً: `wholesale/invoices` بلا `amial.shift`،
 * و`payment_type` يقبل `cash` — أي نقدٌ يُقبَض في اللحظة.
 *
 * **والوسيطُ لا يُركَّب على المسار، وهذا قرارٌ لا سهو:** الوسيطُ لا يقرأ
 * جسمَ الطلب، و`credit` دينٌ لا يمسّ الدرج. فتركيبُه يطلب ورديّةً لبيعٍ
 * آجلٍ لا ورديّةَ له — **وحاجزٌ يشلّ عملاً سليماً أسوأ من ثغرة**.
 *
 * **وثلاثُ حالاتٍ لا واحدة، والثانيةُ هي التي تحفظ العملَ السليم.**
 */
class WholesaleCashNeedsAShiftGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create([
            'type' => MERCHANT_TYPE, 'role' => \App\Support\Access\AccessConstants::ROLE_MERCHANT,
            'is_active' => 1, 'is_kyc_verified' => 1,
            'zone_code' => 'SOUTH', 'f_name' => 'تاجر', 'l_name' => 'جملة',
        ]);

        MerchantProfile::create([
            'user_id' => $this->owner->id, 'tier' => 'small',
            'verification_status' => 'verified', 'business_type' => 'wholesale',
            'subscription_plan' => \App\Support\Access\AccessConstants::PLAN_ENTERPRISE,
            'single_receive_limit' => '5000000',
            'daily_receive_limit' => '50000000',
        ]);
    }

    /** طلبُ إنشاء فاتورةٍ — يسقط قبل الحساب إن لزمت ورديّة. */
    private function invoice(string $paymentType)
    {
        return $this->actingAs($this->owner, 'api')
            ->postJson('/api/v1/amial/merchant/wholesale/invoices', [
                'customer_id' => 1,
                'payment_type' => $paymentType,
                'items' => [['product_id' => 1, 'quantity' => 1]],
            ]);
    }

    /** @test */
    public function a_cash_invoice_is_refused_without_an_open_shift(): void
    {
        $r = $this->invoice('cash');

        $r->assertStatus(409);
        $this->assertSame('SHIFT_REQUIRED', $r->json('code'),
            'رُفضت الفاتورةُ النقديّةُ برمزٍ آخر — والشاشةُ تفتح نافذةَ '
            .'الورديّة على هذا الرمز وحدَه، فتعرض عطلاً بدل الحلّ');

        // **والرفضُ يقول كيف يُصلَح** — رفضٌ لا يقول ذلك يُقرأ عطلاً.
        $this->assertTrue((bool) $r->json('meta.can_open'));
        $this->assertNotEmpty($r->json('meta.open_endpoint'));
    }

    /** @test */
    public function a_credit_invoice_is_never_blocked_by_a_shift(): void
    {
        // **وهذا هو الحدّ**: الآجلُ دينٌ لا يمسّ الدرج، وطلبُ ورديّةٍ له
        // حاجزٌ يشلّ عملاً سليماً — وهو أسوأ من ثغرة.
        $r = $this->invoice('credit');

        $this->assertNotSame(409, $r->status(),
            'مُنعت فاتورةٌ آجلةٌ لغياب ورديّة — والآجلُ لا نقدَ فيه، '
            .'فلا شيءَ يدخل الدرج ولا شيءَ يُجرَد');

        $this->assertNotSame('SHIFT_REQUIRED', $r->json('code'));
    }

    /** @test */
    public function the_refusal_is_the_same_one_the_middleware_gives(): void
    {
        // **رسالتان برمزين تجعلان الشاشةَ تعمل في بابٍ وتعطب في آخر.**
        // فالحكمُ من المصدر نفسِه لا مكتوبٌ ثانيةً في المتحكّم.
        $src = (string) file_get_contents(app_path(
            'Http/Controllers/Api/V1/Amial/WholesaleController.php'));

        $this->assertStringContainsString('refusalFor(', $src,
            'المتحكّمُ لا ينادي حكمَ الوسيط — فرسالةٌ ثانيةٌ تشيخ وحدَها');

        $this->assertStringNotContainsString("'SHIFT_REQUIRED'", $src,
            'أُعيدت كتابةُ الرمز في المتحكّم — ونسختان تتباعدان');
    }

    /** @test */
    public function turning_the_gate_off_from_the_panel_frees_the_cash_invoice(): void
    {
        // ⑨ القرارُ التجاريُّ ليس قرارَ شيفرة — والمنفذُ نفسُه لا منفذٌ ثانٍ.
        MerchantProfile::where('user_id', $this->owner->id)
            ->update(['require_shift_to_sell' => false]);

        $this->assertNotSame(409, $this->invoice('cash')->status(),
            'أُطفئ الإلزامُ من اللوحة وبقيت الفاتورةُ النقديّةُ محبوسة — '
            .'فالمنفذُ يعمل في بابٍ ولا يعمل في آخر');
    }

    /** @test */
    public function the_collection_door_stays_gated_by_the_middleware(): void
    {
        // **والجملةُ لها بابان لنقدها** — الفاتورةُ النقديّةُ والتحصيل.
        // وإصلاحُ أحدهما وتركُ الآخر يُبقي الثقبَ مفتوحاً بنصفه.
        $route = collect(app('router')->getRoutes())->first(
            fn ($r) => $r->uri() === 'api/v1/amial/merchant/wholesale/invoices/{id}/collect'
                && in_array('POST', $r->methods(), true));

        $this->assertNotNull($route, 'مسارُ التحصيل غيرُ مسجَّل — تغيّر اسمُه؟');
        $this->assertContains('amial.shift', $route->gatherMiddleware());

        // **ولا يُركَّب الوسيطُ على الإنشاء** — فيمنع الآجلَ معه.
        $create = collect(app('router')->getRoutes())->first(
            fn ($r) => $r->uri() === 'api/v1/amial/merchant/wholesale/invoices'
                && in_array('POST', $r->methods(), true));

        $this->assertNotNull($create);
        $this->assertNotContains('amial.shift', $create->gatherMiddleware(),
            'رُكّب الوسيطُ على إنشاء الفاتورة — فصار الآجلُ يطلب ورديّةً '
            .'لا معنى لها، والشرطُ في الجسم لا في المسار');
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }
}
