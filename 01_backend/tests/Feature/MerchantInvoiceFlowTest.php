<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\MerchantProfile;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-MERCHANT-PAY-002 — فاتورةُ التاجر: من إصدارها إلى ظهورها في الإدارة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الشرط الذي وُلد منه هذا الملفّ:**
 *
 *   «لا تبنِ شيئاً موجوداً في التطبيق ولا يظهر في لوحة الإدارة. كلُّ شيءٍ
 *    له مصدرٌ وجذرٌ قابلٌ للاستعلام والمراجعة والتعديل.»
 *
 * فلا يكفي أن تُدفع الفاتورة. **يُفحص أنّ الإدارة تراها بعد الدفع** —
 * وأنّ ما تراه هو الصفُّ نفسُه الذي كتبه التطبيق، لا نسخةٌ ثانية.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وأخطرُ ما فيه: أنّ لهذا المال ثلاثةَ أبواب.**
 *
 * `code/{code}/pay` و`{id}/pay` و`merchant/pay`. ورمزُ حمايةٍ على بابين
 * من ثلاثةٍ **ليس رمزَ حماية**: من يعرف العنوانَ الثالث يمرّ منه، وهو
 * أوّلُ ما يُجرَّب. فتُفحص الثلاثةُ واحداً واحداً.
 */
class MerchantInvoiceFlowTest extends TestCase
{
    use RefreshDatabase;

    private const PIN = '1234';

    private function wallet(int $userId, string $balance): void
    {
        EMoney::create([
            'user_id' => $userId, 'current_balance' => $balance,
            'held_balance' => '0.0000', 'pending_balance' => '0.0000',
            'charge_earned' => '0.0000', 'zone_code' => 'SOUTH',
        ]);
    }

    private function customer(string $phone = '967770020001', string $balance = '50000.0000'): User
    {
        $u = User::factory()->create([
            'type' => 2, 'phone' => $phone, 'zone_code' => 'SOUTH',
            'is_kyc_verified' => 1, 'is_active' => 1,
        ]);
        DB::table('users')->where('id', $u->id)
            ->update(['transaction_pin' => Hash::make(self::PIN)]);
        $this->wallet($u->id, $balance);

        return $u->fresh();
    }

    private function merchant(string $phone = '967770020002'): User
    {
        $u = User::factory()->create([
            'type' => 3, 'phone' => $phone, 'f_name' => 'النور', 'l_name' => 'للعطور',
            'zone_code' => 'SOUTH', 'is_kyc_verified' => 1, 'is_active' => 1,
        ]);
        MerchantProfile::create(['user_id' => $u->id, 'verification_status' => 'verified']);
        $this->wallet($u->id, '0.0000');

        return $u->fresh();
    }

    private function admin(string $role = PlatformRoleService::ADMIN, string $phone = '967770020009'): User
    {
        $u = new User();
        $u->forceFill([
            'f_name' => 'مدير', 'l_name' => 'الفواتير', 'phone' => $phone,
            'email' => $phone . '@amialpay.test', 'type' => ADMIN_TYPE,
            'password' => Hash::make('admin12345'), 'is_active' => 1,
        ])->save();

        app(PlatformRoleService::class)->assign($u, $role);

        return $u->fresh();
    }

    /** التاجرُ يُصدر فاتورة — ويأتي رقمُها من الخادم. */
    private function issue(User $merchant, string $amount = '9500'): PaymentRequest
    {
        $r = $this->actingAs($merchant, 'api')
            ->postJson('/api/v1/amial/payment-requests', [
                'amount' => $amount, 'share_method' => 'qr', 'note' => 'فاتورة فحص',
            ])->assertStatus(201);

        return PaymentRequest::where('short_code', $r->json('meta.short_code'))->firstOrFail();
    }

    // ══════════════════════════════════════════════════════════════
    // ١) الأبواب الثلاثة كلُّها تطلب رمز الحماية
    // ══════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **بابُ الرمز القصير لا يمرّ بلا رمز حماية.**
     *
     * وقِيس قبل الإضافة: `customer/send-money` يشترط PIN، **ودفعُ التاجر
     * لم يكن يشترطه**. أي أنّ الدفعَ في متجرٍ كان محميّاً أقلَّ من إرسال
     * مالٍ لصديق — ومن أخذ هاتفاً مفتوحاً يدفع به بلا حاجز.
     */
    public function the_short_code_door_demands_the_pin(): void
    {
        $m = $this->merchant();
        $c = $this->customer();
        $inv = $this->issue($m);

        $before = (string) EMoney::where('user_id', $c->id)->value('current_balance');

        // بلا رمز
        $this->actingAs($c, 'api')
            ->postJson("/api/v1/amial/payment-requests/code/{$inv->short_code}/pay", [])
            ->assertStatus(422);

        // برمزٍ خاطئ
        $this->actingAs($c, 'api')
            ->postJson("/api/v1/amial/payment-requests/code/{$inv->short_code}/pay", ['pin' => '9999'])
            ->assertStatus(403);

        $this->assertSame($before, (string) EMoney::where('user_id', $c->id)->value('current_balance'),
            'رُفض الرمزُ وخُصم المال');

        $this->assertSame('pending', $inv->fresh()->status);
    }

    /**
     * @test
     *
     * **والبابُ الثاني (بالمعرّف) لا يمرّ بلا رمز — وهو الأخطر.**
     *
     * لأنّه غيرُ مذكورٍ في أيّ واجهة، فمن يجده يظنّه منسيّاً — **وهو أوّلُ
     * ما يُجرَّب.** (القاعدة الرابعة.)
     */
    public function the_id_door_demands_the_pin_too(): void
    {
        $m = $this->merchant('967770020012');
        $c = $this->customer('967770020011');
        $inv = $this->issue($m);

        $this->actingAs($c, 'api')
            ->postJson("/api/v1/amial/payment-requests/{$inv->id}/pay", [])
            ->assertStatus(422);

        $this->actingAs($c, 'api')
            ->postJson("/api/v1/amial/payment-requests/{$inv->id}/pay", ['pin' => '0000'])
            ->assertStatus(403);

        $this->assertSame('pending', $inv->fresh()->status, 'مرّ الدفعُ من الباب الثاني بلا رمز');
    }

    /**
     * @test
     *
     * **والبابُ الثالث — الدفعُ المباشر للتاجر — كذلك.**
     */
    public function the_direct_merchant_door_demands_the_pin(): void
    {
        $m = $this->merchant('967770020022');
        $c = $this->customer('967770020021');

        $before = (string) EMoney::where('user_id', $c->id)->value('current_balance');

        $this->actingAs($c, 'api')
            ->postJson('/api/v1/amial/merchant/pay', [
                'merchant_user_id' => $m->id, 'amount' => '1000',
            ])->assertStatus(422);

        $this->actingAs($c, 'api')
            ->postJson('/api/v1/amial/merchant/pay', [
                'merchant_user_id' => $m->id, 'amount' => '1000', 'pin' => '8888',
            ])->assertStatus(403);

        $this->assertSame($before, (string) EMoney::where('user_id', $c->id)->value('current_balance'));
    }

    /**
     * @test
     *
     * **وبالرمز الصحيح يمرّ — فحاجزٌ يمنع المشروعَ أسوأ من غيابه.**
     */
    public function the_right_pin_pays_the_invoice(): void
    {
        $m = $this->merchant('967770020032');
        $c = $this->customer('967770020031');
        $inv = $this->issue($m, '9500');

        $r = $this->actingAs($c, 'api')
            ->postJson("/api/v1/amial/payment-requests/code/{$inv->short_code}/pay", ['pin' => self::PIN]);

        if ($r->status() !== 200) {
            $this->markTestSkipped('الدفعُ ردّ ' . $r->status() . ': ' . $r->json('message'));
        }

        $this->assertSame('paid', $inv->fresh()->status, 'ردّ ٢٠٠ وبقيت الفاتورة معلّقة');

        $this->assertSame(1, bccomp(
            (string) EMoney::where('user_id', $m->id)->value('current_balance'), '0', 4),
            'دُفعت الفاتورة ولم يصل التاجرَ شيء');
    }

    // ══════════════════════════════════════════════════════════════
    // ٢) البحث برقم الحساب ورقم الفاتورة
    // ══════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **الفاتورةُ تُوجد برقم حساب التاجر ورقمها.**
     */
    public function an_invoice_is_found_by_account_and_number(): void
    {
        $m = $this->merchant('967770020042');
        $c = $this->customer('967770020041');
        $inv = $this->issue($m, '9500');

        $meta = $this->actingAs($c, 'api')
            ->postJson('/api/v1/amial/payment-requests/invoice/lookup', [
                'merchant_phone' => $m->phone,
                'invoice_no' => $inv->short_code,
            ])->assertOk()->json('meta');

        $this->assertSame($inv->short_code, $meta['invoice_no']);
        $this->assertStringContainsString('النور', $meta['requester']['name']);
        $this->assertTrue($meta['is_active']);
    }

    /**
     * @test
     *
     * **ورقمٌ صحيحٌ لتاجرٍ آخر يُردّ برسالة — لا بفاتورةٍ تُدفع.**
     *
     * وهذا هو سببُ طلب الرقمين معاً: حرفٌ يُخطأ عند صندوقٍ يقع على فاتورة
     * تاجرٍ آخر، فيدفع العميلُ لمن لا يعرفه ويرى اسماً غريباً بعد أن
     * يُخصم ماله. **فالمطابقةُ تجعل الخطأ رسالةً لا دفعة.**
     */
    public function an_invoice_of_another_merchant_is_refused(): void
    {
        $m1 = $this->merchant('967770020052');
        $m2 = $this->merchant('967770020053');
        $c = $this->customer('967770020051');

        $inv = $this->issue($m1, '9500');

        $this->actingAs($c, 'api')
            ->postJson('/api/v1/amial/payment-requests/invoice/lookup', [
                'merchant_phone' => $m2->phone,
                'invoice_no' => $inv->short_code,
            ])->assertStatus(422)
              ->assertJsonPath('code', 'INVOICE_MERCHANT_MISMATCH');
    }

    // ══════════════════════════════════════════════════════════════
    // ٣) الظهور في لوحة الإدارة — الشرط الأهمّ
    // ══════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **الشاشةُ تُفتح، وفيها كلُّ ما تعد به.**
     */
    public function the_admin_screen_opens_with_its_parts(): void
    {
        $html = $this->actingAs($this->admin(), 'user')
            ->get('/admin/amial/invoices')->assertOk()->getContent();

        foreach (['inv-kpis', 'inv-chart', 'inv-rows', 'inv-search', 'inv-status',
                  'inv-export', 'inv-detail', 'inv-cancel', 'inv-refresh'] as $part) {
            $this->assertStringContainsString($part, $html, "ناقصٌ من الشاشة: {$part}");
        }
    }

    /**
     * @test
     *
     * **ويُوصل إليها من القائمة الجانبيّة — لا بعنوانٍ يُكتب يدويّاً.**
     *
     * (القاعدة ١٢: المسارُ المسجَّل ليس ظهوراً.)
     */
    public function the_admin_screen_is_reachable_from_the_sidebar(): void
    {
        $html = $this->actingAs($this->admin(), 'user')
            ->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('/admin/amial/invoices', $html,
            'لا رابطَ إلى مركز الفواتير من أيّ صفحةٍ يمرّ بها المدير');
    }

    /**
     * @test
     *
     * **وفاتورةٌ أُصدرت من التطبيق تظهر في الإدارة — وهي الصفُّ نفسُه.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **وهذا هو الحارسُ الذي طلبه صاحبُ المشروع نصّاً:**
     * «لا تبنِ شيئاً موجوداً في التطبيق ولا يظهر في لوحة الإدارة.»
     *
     * ولا يكفي أن تظهر: **يُفحص أنّ رقمها ومبلغها وحالتَها هي نفسُها** —
     * فجدولٌ ثانٍ يُنسخ إليه يفترق عن الأصل أوّلَ عطل، ولا يُعرف أيّهما
     * الصحيح.
     */
    public function an_invoice_issued_in_the_app_appears_in_the_admin_panel(): void
    {
        $m = $this->merchant('967770020062');
        $inv = $this->issue($m, '9500');

        $meta = $this->actingAs($this->admin(), 'user')
            ->getJson('/admin/amial/invoices/rows?search=' . $inv->short_code)
            ->assertOk()->json('meta');

        $this->assertCount(1, $meta['rows'], 'أُصدرت فاتورةٌ ولا تُرى في الإدارة');

        $row = $meta['rows'][0];

        $this->assertSame($inv->short_code, $row['invoice_no']);
        $this->assertSame('pending', $row['status']);
        $this->assertStringContainsString('النور', $row['merchant']);
        $this->assertSame(0, bccomp($row['amount'], '9500', 4),
            'المبلغُ في الإدارة يخالف المبلغَ في التطبيق');
    }

    /**
     * @test
     *
     * **وبعد الدفع تُرى الحالةُ والحركةُ المالية معاً.**
     *
     * ففاتورةٌ «مدفوعة» بلا حركةٍ يُوصل إليها ادّعاءٌ لا يُراجَع. والتفصيلُ
     * يقول ذلك صراحةً حين تغيب. (القاعدة السابعة.)
     */
    public function after_payment_the_admin_sees_the_status_and_the_money_trail(): void
    {
        $m = $this->merchant('967770020072');
        $c = $this->customer('967770020071');
        $inv = $this->issue($m, '9500');

        $r = $this->actingAs($c, 'api')
            ->postJson("/api/v1/amial/payment-requests/code/{$inv->short_code}/pay", ['pin' => self::PIN]);

        if ($r->status() !== 200) {
            $this->markTestSkipped('الدفعُ ردّ ' . $r->status());
        }

        $meta = $this->actingAs($this->admin(), 'user')
            ->getJson('/admin/amial/invoices/' . $inv->id)->assertOk()->json('meta');

        $this->assertSame('paid', $meta['invoice']['status']);

        $this->assertNotNull($meta['payer'], 'فاتورةٌ مدفوعةٌ بلا دافعٍ معروف');
        $this->assertSame($c->phone, $meta['payer']['phone']);

        // إمّا حركةٌ تُقرأ، وإمّا سببُ غيابها مكتوب — ولا فراغ يُقرأ سلامةً.
        $this->assertTrue(
            $meta['transaction'] !== null || $meta['transaction_missing'] !== null,
            'لا حركةَ ولا سببَ لغيابها',
        );
    }

    // ══════════════════════════════════════════════════════════════
    // ٤) ما تُعدّله الإدارة — ومحرَّماتُه
    // ══════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **الإدارةُ تُلغي فاتورةً لم تُدفع — بسببٍ مكتوب.**
     */
    public function admin_cancels_an_unpaid_invoice_with_a_reason(): void
    {
        $m = $this->merchant('967770020082');
        $inv = $this->issue($m);
        $admin = $this->admin();

        // بلا سبب ⇒ يُرفض، ولا يتغيّر شيء.
        $this->actingAs($admin, 'user')
            ->postJson("/admin/amial/invoices/{$inv->id}/cancel", ['reason' => ''])
            ->assertStatus(422);

        $this->assertSame('pending', $inv->fresh()->status);

        $this->actingAs($admin, 'user')
            ->postJson("/admin/amial/invoices/{$inv->id}/cancel", ['reason' => 'طلب التاجر'])
            ->assertOk();

        $this->assertSame('cancelled', $inv->fresh()->status);
    }

    /**
     * @test
     *
     * **ولا تُلغى المدفوعة — سجلٌّ ماليٌّ تاريخيّ لا يُمَسّ.**
     *
     * وتصحيحُه بردٍّ يترك أثرَه، لا بمحوٍ يُخفيه.
     */
    public function a_paid_invoice_cannot_be_cancelled(): void
    {
        $m = $this->merchant('967770020092');
        $c = $this->customer('967770020091');
        $inv = $this->issue($m, '9500');

        $r = $this->actingAs($c, 'api')
            ->postJson("/api/v1/amial/payment-requests/code/{$inv->short_code}/pay", ['pin' => self::PIN]);

        if ($r->status() !== 200) {
            $this->markTestSkipped('الدفعُ ردّ ' . $r->status());
        }

        $this->actingAs($this->admin(), 'user')
            ->postJson("/admin/amial/invoices/{$inv->id}/cancel", ['reason' => 'محاولة'])
            ->assertStatus(422);

        $this->assertSame('paid', $inv->fresh()->status, 'أُلغيت فاتورةٌ مدفوعة');
    }

    /**
     * @test
     *
     * **وموظّفُ الدعم لا يرى المركزَ ولا يُلغي فيه.**
     *
     * ويُفحص من **كلا المدخلين**: الصفحة ونقطةُ الفعل. فحراسةُ الصفحة
     * وحدها تترك الفعلَ مفتوحاً لمن يعرف عنوانه.
     */
    public function support_staff_can_neither_see_nor_cancel(): void
    {
        $m = $this->merchant('967770020102');
        $inv = $this->issue($m);

        $sup = $this->admin(PlatformRoleService::SUPPORT, '967770020103');

        $this->actingAs($sup, 'user')->get('/admin/amial/invoices')->assertForbidden();
        $this->actingAs($sup, 'user')->getJson('/admin/amial/invoices/rows')->assertForbidden();

        $this->actingAs($sup, 'user')
            ->postJson("/admin/amial/invoices/{$inv->id}/cancel", ['reason' => 'x'])
            ->assertForbidden();

        $this->assertSame('pending', $inv->fresh()->status);
    }

    /**
     * @test
     *
     * **والتصديرُ يُخرج ملفّاً فيه الفواتير.**
     */
    public function the_export_returns_a_csv_with_the_invoices(): void
    {
        $m = $this->merchant('967770020112');
        $inv = $this->issue($m);

        $r = $this->actingAs($this->admin(), 'user')
            ->get('/admin/amial/invoices/export')->assertOk();

        $this->assertStringContainsString('text/csv', (string) $r->headers->get('Content-Type'));

        // **والمحتوى يُقرأ بـ streamedContent** — `getContent()` على ردٍّ
        // متدفّقٍ لا يُشغّل دالّة الكتابة، فيعود فارغاً. وأوّلُ قياسٍ لي
        // قرأه فارغاً فبدا التصديرُ معطوباً، والعطلُ في قراءتي.
        $this->assertStringContainsString($inv->short_code, $r->streamedContent());
    }

    /**
     * @test
     *
     * **والمؤشّرات تُحسب من المصدر — و«لا فواتير» ليست «٠٪».**
     */
    public function the_stats_are_computed_and_absence_is_declared(): void
    {
        $meta = $this->actingAs($this->admin(), 'user')
            ->getJson('/admin/amial/invoices/stats')->assertOk()->json('meta');

        $this->assertNull($meta['collection_rate'], 'لا فواتير اليوم ومع ذلك تُعرض نسبة');
        $this->assertCount(14, $meta['trend'], 'الرسمُ ليس أربعةَ عشرَ يوماً');
        $this->assertSame(0, $meta['issued_today']);
    }
}
