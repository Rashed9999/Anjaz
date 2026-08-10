<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\User;
use App\Models\WithdrawRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-WITHDRAW-DOUBLE-001 — **طلبُ سحبٍ يُبتّ فيه مرّتين.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * لم يكن في `WithdrawController::status_update` فحصٌ واحدٌ لحالة الطلب.
 * فضغطتان على «رفض» — أو تحديثُ الصفحة بعد الإرسال، أو موظّفان يفتحان
 * القائمة نفسها — تُنفّذان المسار مرّتين:
 *
 *     pending_balance -= total   ← مرّتين، فيصير سالباً
 *     current_balance += total   ← مرّتين، **فيُعاد المبلغ مرّتين**
 *
 * **والمعاملةُ لا تحمي من هذا**: كلٌّ من التنفيذين معاملةٌ صحيحةٌ بذاتها.
 * الذي يمنعه فحصُ الحالة **داخل** المعاملة مع قفل الصفّ — ومن ظفر
 * بالقفل ملك الحقّ، والثاني يجد الحالة مبتوتةً فينسحب.
 *
 * ولا يظهر في أيّ سجلّ: عمليّتا رفضٍ ناجحتان، والدفترُ يستقبل قيدين،
 * والرصيدُ زاد ضعفَ ما يجب.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وكيف يُقاس هنا:** التوازي الحقيقيّ لا يجري في مجموعةٍ متتابعة
 * (القاعدة الأولى). لكنّ **إعادةَ الإرسال** تُنتج العطل نفسَه بلا
 * توازٍ — وهي الصورةُ الأكثر وقوعاً في الواقع: ضغطتان أو تحديثُ صفحة.
 * فيُقاس بها.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وقياسٌ مهمٌّ خرج من تجربة الحارس بالعكس:** نُزع الفحصان فمرّ الاختبارُ
 * السلوكيّ. والسببُ أنّ مفتاحَ تفرّد الدفتر (`withdraw_denied_{id}`) كان
 * يُسقط الترحيلَ الثاني فتُردّ المعاملةُ كلُّها — أي أنّ **المالَ كان
 * محميّاً بالصدفة**، والمستعملُ يرى خطأ ٥٠٠ بلا تفسير.
 *
 * فالفحصان يحوّلان حمايةً عرَضيّةً تُخرج انهياراً إلى حمايةٍ مقصودةٍ تُخرج
 * رسالة. **ولذلك يبقى الحارسُ النصّيُّ أدناه**: هو وحده الذي يسقط حين
 * يُنزع الفحص.
 */
class WithdrawDoubleDecisionGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'admin']);
        app(\App\Services\PlatformRoleService::class)
            ->assign($this->admin, \App\Services\PlatformRoleService::ADMIN);

        $this->customer = User::factory()->create([
            'type' => CUSTOMER_TYPE, 'role' => 'customer',
            'is_active' => 1, 'zone_code' => 'SOUTH',
        ]);
    }

    /** محفظةٌ فيها مبلغٌ محجوزٌ لطلبِ سحبٍ معلَّق. */
    private function pendingWithdraw(string $amount = '5000', string $charge = '100'): WithdrawRequest
    {
        $total = bcadd($amount, $charge, 4);

        // **تُبنى الحالةُ كما تُبنى في الإنتاج، لا كما تبدو في النهاية.**
        //
        // أوّلُ نسخةٍ كتبت الأرقامَ النهائيّة مباشرة (متاح ١٠٠٠ · محجوز
        // ٥١٠٠) — فسقط الترحيلُ مرّتين: أوّلاً لأنّ `WITHDRAW_PENDING`
        // فارغ، ثمّ لأنّ محفظة الدفتر فيها ١٠٠٠ لا تكفي خصمَ ٥١٠٠.
        //
        // والسببُ أنّ الحالةَ الموصوفة **لا تقع في الإنتاج**: هناك يبدأ
        // العميلُ بـ٦١٠٠ متاحاً، ثمّ يُحجز منها. فتهيئةٌ تصف نهايةً بلا
        // طريقٍ إليها تختبر نظاماً غير الذي يعمل.
        EMoney::create([
            'user_id' => $this->customer->id,
            'current_balance' => bcadd('1000', $total, 4),
            'pending_balance' => '0',
            'held_balance' => '0',
            'charge_earned' => '0',
        ]);

        $req = WithdrawRequest::create([
            'user_id' => $this->customer->id,
            'amount' => $amount,
            'admin_charge' => $charge,
            'request_status' => 'pending',
            'is_paid' => 0,
        ]);

        // **والحجزُ يُرحَّل إلى الدفتر كما في الإنتاج.**
        //
        // أوّلُ نسخةٍ من هذه التهيئة حجزت المبلغ في `pending_balance`
        // وحده — فسقط الرفضُ عند ترحيل القيد المقابل («رصيد غير كافٍ في
        // WITHDRAW_PENDING»). وهي تهيئةٌ تصف حالةً **لا تقع في الإنتاج**:
        // كلُّ حجزٍ هناك يمرّ بـ`ledgerWithdrawRequested`.
        //
        // (ولولا تضييقُ الالتقاط لظهر ذلك رسالةَ «بُتَّ فيه» — سببٌ حقيقيٌّ
        // مُبتلَعٌ خلف رسالةٍ تكذب.)
        $poster = new class { use \App\Traits\PostsToLedger; public function hold(int $u, string $t, string $s): void { $this->ledgerWithdrawRequested($u, $t, $s); } };
        $poster->hold($this->customer->id, $total, (string) $req->id);

        // ثمّ يعكس عمودا المحفظة ما عكسه الدفتر.
        EMoney::where('user_id', $this->customer->id)->update([
            'current_balance' => '1000.0000',
            'pending_balance' => $total,
        ]);

        return $req;
    }

    private function decide(WithdrawRequest $r, string $status)
    {
        return $this->actingAs($this->admin, 'user')
            ->post(route('admin.withdraw.status-update'), [
                'request_id' => $r->id,
                'request_status' => $status,
            ]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① الرفضُ مرّتين لا يُعيد المال مرّتين
    // ══════════════════════════════════════════════════════════════════

    public function test_denying_twice_refunds_the_money_only_once(): void
    {
        $req = $this->pendingWithdraw('5000', '100');

        $this->decide($req, 'deny');

        $afterFirst = (string) EMoney::where('user_id', $this->customer->id)->value('current_balance');
        $this->assertSame('6100.0000', $afterFirst, 'الرفضُ الأوّل لم يُعِد المبلغ');

        // الضغطةُ الثانية — أو تحديثُ الصفحة.
        $this->decide($req, 'deny');

        $afterSecond = (string) EMoney::where('user_id', $this->customer->id)->value('current_balance');

        $this->assertSame($afterFirst, $afterSecond,
            'الرفضُ الثاني أعاد المبلغ مرّةً أخرى — مالٌ خُلق من ضغطةِ زرّ');
    }

    public function test_the_held_amount_never_goes_negative(): void
    {
        $req = $this->pendingWithdraw('5000', '100');

        $this->decide($req, 'deny');
        $this->decide($req, 'deny');
        $this->decide($req, 'deny');

        $pending = (string) EMoney::where('user_id', $this->customer->id)->value('pending_balance');

        $this->assertSame(1, bccomp($pending, '-0.0001', 4),
            "الرصيدُ المحجوز صار سالباً ({$pending}) — وهو مستحيلٌ محاسبيّاً");
    }

    public function test_the_second_attempt_is_told_why_not_left_silent(): void
    {
        // (القاعدة السابعة والدرسُ المكتوب: «رفضٌ سليمٌ يخرج بـ٥٠٠ إنجليزيّ
        // في وجه الموظّف».) فالثانيةُ تُردّ برسالةٍ لا بانهيار.
        $req = $this->pendingWithdraw();

        $this->decide($req, 'deny');
        $second = $this->decide($req, 'deny');

        $second->assertRedirect();
        $this->assertLessThan(500, $second->status(), 'المحاولةُ الثانية تنهار بدل أن تُردّ');
    }

    public function test_the_request_keeps_its_first_decision(): void
    {
        $req = $this->pendingWithdraw();

        $this->decide($req, 'deny');
        $this->decide($req, 'approve');   // محاولةُ قلبِ القرار

        $this->assertSame('denied', (string) $req->fresh()->request_status,
            'قرارٌ مبتوتٌ انقلب بضغطةٍ ثانية — والمالُ تحرّك مرّتين باتّجاهين');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② الاعتمادُ مرّتين لا يصرف مرّتين
    // ══════════════════════════════════════════════════════════════════

    public function test_approving_twice_does_not_pay_out_twice(): void
    {
        // خزنةُ المنصّة تُموَّل ليمرّ الاعتماد الأوّل.
        EMoney::create([
            'user_id' => $this->admin->id,
            'current_balance' => '1000000.0000',
            'pending_balance' => '0', 'held_balance' => '0', 'charge_earned' => '0',
        ]);

        $req = $this->pendingWithdraw('5000', '100');

        $this->decide($req, 'approve');
        $afterFirst = (string) EMoney::where('user_id', $this->customer->id)->value('pending_balance');

        $this->decide($req, 'approve');
        $afterSecond = (string) EMoney::where('user_id', $this->customer->id)->value('pending_balance');

        $this->assertSame($afterFirst, $afterSecond,
            'الاعتمادُ الثاني حرّك المال مرّةً أخرى');
        $this->assertSame('approved', (string) $req->fresh()->request_status);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ③ الحارسُ النصّيّ: الفحصُ داخل القفل لا قبله
    // ══════════════════════════════════════════════════════════════════

    /**
     * **ولماذا نصّاً أيضاً:** الاختبارُ أعلاه يمرّ لو نُقل الفحصُ إلى
     * أوّل الدالّة بلا قفل — لأنّ التتابع يُخفي السباق. وهو بالضبط ما
     * جعل العطل يعيش. فيُفحص الشرطُ الذي يجعله مستحيلاً بالتوازي أيضاً.
     */
    public function test_the_state_check_sits_inside_the_lock(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/Admin/WithdrawController.php'));

        $start = strpos($src, 'function status_update');
        $this->assertNotFalse($start, 'status_update اختفت — حدِّث هذا الحارس');

        $body = substr($src, $start);

        // ثلاثةُ أقفال: صفُّ الطلب في الرفض، ومحفظةُ العميل، وصفُّ الطلب
        // في الاعتماد. وأقلُّ من ذلك يعني مساراً بلا قفل.
        $this->assertGreaterThanOrEqual(3, substr_count($body, 'lockForUpdate()'),
            'ينقص قفلٌ في أحد المسارين (الرفض والاعتماد) — '
            . 'وفحصٌ خارج القفل لا يظهر إلّا بالتوازي');

        // ثلاثة: فحصٌ مبكّرٌ يُخبر الموظّف، وفحصان داخل القفلين هما
        // الحارسُ الحقيقيّ. والمبكّرُ وحدَه لا يكفي — يقع خارج القفل.
        $this->assertGreaterThanOrEqual(3, substr_count($body, "!== 'pending'"),
            'ينقص فحصُ حالةٍ في أحد المسارين');
    }
}
