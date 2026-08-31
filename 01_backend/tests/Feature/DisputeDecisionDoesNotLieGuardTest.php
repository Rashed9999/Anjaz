<?php

namespace Tests\Feature;

use App\Models\Dispute;
use App\Models\EMoney;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * AMIAL-WRONG-TRANSFER-001 — **سجلٌّ ماليٌّ كان يكذب، وبابٌ لم يكن موجوداً.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **عطلان في مسارٍ واحد، وُجدا وأنا أجيب عن سؤال «حوّل إلى الرقم الخطأ»:**
 *
 * ① **`$dispute->save()` كان يسبق حركةَ المال بلا `try/catch`.** فإن سقطت
 *    الحركة — ورصيدُ الطرف الآخر صفرٌ لأنّه أنفقه، وهي الحالةُ الأشيع —
 *    خرج الاستثناءُ صفحةَ ٥٠٠ **والملفُّ محفوظٌ «disputed»**. فالسجلُّ
 *    يقول «حُلّ» ولا ريالَ تحرّك، ويُغلَق الملفُّ على المشتكي إلى الأبد.
 *
 * ② **ولا مسارَ للشاشة أصلاً.** `list()` و`changeStatus()` مبنيّتان،
 *    و`admin-views.disputes.index` غيرُ موجودٍ على القرص، ولا سطرَ لهما
 *    في `routes/admin.php`. **والعميلُ يرفع بلاغَه من التطبيق فيدخل
 *    جدولاً لا يقرؤه أحد.**
 *
 * والثاني أخطرُ من الأوّل: كنتُ أُصلح كذبةً **في شاشةٍ لا تُفتَح**.
 * ══════════════════════════════════════════════════════════════════════
 */
class DisputeDecisionDoesNotLieGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $ahmed;

    private User $salem;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->admin = User::factory()->create([
            'type' => ADMIN_TYPE, 'role' => 'super_admin', 'phone' => '967770009977',
        ]);

        $roleId = DB::table('roles')->whereNull('merchant_user_id')
            ->where('code', 'platform_admin')->value('id');

        DB::table('admin_user_roles')->updateOrInsert(
            ['user_id' => $this->admin->id, 'role_id' => $roleId],
            ['created_at' => now(), 'updated_at' => now()],
        );

        $this->ahmed = User::factory()->create(['zone_code' => 'SOUTH', 'phone' => '+967700222111', 'type' => 2]);
        $this->salem = User::factory()->create(['zone_code' => 'SOUTH', 'phone' => '+967700222112', 'type' => 2]);

        foreach ([$this->ahmed, $this->salem] as $u) {
            EMoney::create(['user_id' => $u->id, 'current_balance' => '0.0000',
                'held_balance' => '0.0000', 'pending_balance' => '0.0000',
                'charge_earned' => '0.0000', 'zone_code' => 'SOUTH', 'version' => 0]);
        }
    }

    /**
     * **① القرارُ الذي تعذّر تنفيذُه لا يُحفَظ.**
     *
     * ══════════════════════════════════════════════════════════════════
     * وهذه الحالةُ **جُرّبت بالعكس فسقطت**: أُعيد `save()` خارج المعاملة
     * فمرّت الحالةُ الأولى (السجلُّ صار `disputed`) وسقط هذا التأكيد.
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function a_decision_that_could_not_move_money_is_not_saved_as_resolved(): void
    {
        $dispute = $this->openDispute('100000');

        // رصيدُ سالمٍ صفرٌ — أنفقه عند التجّار.
        $this->assertSame('0.0000', (string) EMoney::where('user_id', $this->salem->id)->value('current_balance'));

        $response = $this->actingAs($this->admin, 'user')
            ->post(route('admin.disputes.change-status'), [
                'dispute_id' => $dispute->id,
                'status' => 'disputed',
            ]);

        $response->assertRedirect();

        $this->assertSame('pending', $dispute->fresh()->status,
            "**الملفُّ يقول «حُلّ» ولا ريالَ تحرّك.**\n"
            .'فالحفظُ وقع خارج معاملة حركة المال، فبقي القرارُ محفوظاً بعد '
            ."سقوطها — ويُغلَق الملفُّ على المشتكي إلى الأبد.\n"
            .'**وسجلٌّ ماليٌّ يكذب أسوأ من سجلٍّ لا يوجد.**');

        $this->assertSame('0.0000', (string) EMoney::where('user_id', $this->ahmed->id)->value('current_balance'),
            'دخل أحمدَ مالٌ لم يخرج من أحد.');

        $this->assertSame(0, Transaction::where('transaction_type', ADDED_DISPUTE_MONEY)->count(),
            'كُتب سطرُ عمليّةٍ لحركةٍ لم تقع.');
    }

    /**
     * **② والرسالةُ تقول ما العمل — لا «خطأ ما» ولا صفحةُ ٥٠٠.**
     *
     * فموظّفُ الدعم أمام عميلٍ على الهاتف. ورسالةٌ لا تدلّ على الخطوة
     * التالية تجعله يضغط الزرَّ مرّةً بعد مرّة.
     */
    /** @test */
    public function the_refusal_names_the_next_step(): void
    {
        $dispute = $this->openDispute('100000');

        $this->actingAs($this->admin, 'user')
            ->post(route('admin.disputes.change-status'), [
                'dispute_id' => $dispute->id,
                'status' => 'disputed',
            ])
            ->assertRedirect();

        // **المفتاحُ `toastr::messages` لا `notifications`** — وقد سقط
        // هذا التأكيدُ أوّلَ تشغيلٍ على فراغٍ لأنّي خمّنتُ الاسم بدل أن
        // أقرأه من المكتبة. (القاعدة الثالثة: يُقاس ثمّ يُقال.)
        $shown = collect((array) session('toastr::messages'))
            ->pluck('message')->implode(' | ');

        $this->assertStringContainsString('لا يكفي', $shown,
            '**الرفضُ صامتٌ أو غامض** — فلا يعرف الموظّف لماذا لم يقع شيء.');

        $this->assertStringContainsString('رقمٍ خاطئ', $shown,
            '**قيل «لا يمكن» ولم يُقل «افعل هذا»** — والحلُّ مبنيٌّ في '
            .'منصّة الدعم ولا أحدَ يُرشد إليه. (مبنيٌّ ولا يُوصَل إليه.)');
    }

    /** **③ وما لا يمسّ المالَ يُحفَظ كما هو.** */
    /** @test */
    public function a_decision_that_moves_no_money_still_saves(): void
    {
        $dispute = $this->openDispute('100000');

        $this->actingAs($this->admin, 'user')
            ->post(route('admin.disputes.change-status'), [
                'dispute_id' => $dispute->id,
                'status' => 'denied',
                'denied_note' => 'الرقمُ محفوظٌ لديه',
            ])
            ->assertRedirect();

        $this->assertSame('denied', $dispute->fresh()->status,
            '**المعاملةُ الجديدةُ منعت قراراً سليماً** — فالعلاجُ صار عطلاً.');
    }

    /**
     * **④ والشاشةُ تُفتَح فعلاً — لا مسارٌ مسجَّلٌ وحده.**
     *
     * فالقالبُ `admin-views.disputes.index` لم يكن موجوداً على القرص
     * إطلاقاً، ومسارٌ يشير إلى قالبٍ مفقودٍ يردّ ٥٠٠ لا صفحة.
     */
    /** @test */
    public function the_customer_disputes_screen_actually_opens(): void
    {
        $this->assertTrue(Route::has('admin.disputes.index'),
            '**لا مسارَ لشاشة بلاغات العملاء** — فالبلاغُ يدخل جدولاً لا يقرؤه أحد.');

        $this->openDispute('50000');

        $this->actingAs($this->admin, 'user')
            ->get(route('admin.disputes.index'))
            ->assertOk()
            ->assertSee('بلاغات العملاء', false)
            ->assertSee('نقلُ المبلغ', false);
    }

    /**
     * **⑤ ولها رابطٌ في القائمة الجانبيّة.**
     *
     * (القاعدة الثانيةَ عشرة: صفحةٌ لا يُوصل إليها ليست مبنيّة. ومسارٌ
     * مسجَّلٌ ليس ظهوراً.)
     */
    /** @test */
    public function the_screen_is_linked_from_the_sidebar(): void
    {
        $sidebar = (string) file_get_contents(
            resource_path('views/admin-views/amial/partials/_sidebar.blade.php'));

        $this->assertStringContainsString("route('admin.disputes.index')", $sidebar,
            '**الشاشةُ مبنيّةٌ ولا رابطَ يقود إليها** — وهو نمطُ العطل '
            .'الأكثرُ تكراراً في أميال باي.');
    }

    // ═════════════════════════════════════════════════════════════════

    /** بلاغٌ على تحويلٍ وقع فعلاً — والمالُ أُنفق كلُّه. */
    private function openDispute(string $amount): Dispute
    {
        $ref = 'TX'.strtoupper(uniqid());

        $tx = Transaction::create([
            'user_id' => $this->ahmed->id, 'transaction_id' => $ref,
            'transaction_type' => SEND_MONEY, 'debit' => $amount, 'credit' => '0',
            'amount' => $amount, 'balance' => '0',
            'from_user_id' => $this->ahmed->id, 'to_user_id' => $this->salem->id,
            'zone_code' => 'SOUTH',
            'transaction_no' => '120'.random_int(100000000000, 999999999999),
        ]);

        return Dispute::create([
            'sender_id' => $this->ahmed->id,
            'sender_type' => 'customer',
            'transaction_id' => $tx->id,
            'trx_id' => $ref,
            'amount' => $amount,
            'disputed_user_id' => $this->salem->id,
            'status' => 'pending',
            'report_reason' => 'أخطأتُ في رقم الهاتف',
            'comment' => 'حوّلتُ إلى رقمٍ لا أعرفه',
        ]);
    }
}
