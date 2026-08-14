<?php

namespace Tests\Feature;

use App\Models\AmialNotification;
use App\Models\EMoney;
use App\Models\Ledger\LedgerJournalEntry;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\PaymentRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-REQUEST-DIRECT-001 — الطلب يصل صاحبه، ويوافق أو يرفض.
 *
 * **ما اشتكى منه المستخدم:** «طلب المال — ما هذه الطريقة؟ المفترض يصل الطلب
 * إلى المستخدم الآخر ثم يوافق أو يرفض.» وكانت الشاشة تعطيه رمزاً ورابطاً
 * يشاركهما بيده.
 *
 * **والسبب لم يكن غياب الميزة بل انقطاعها:**
 *   • `create()` يربط الطلب بالمستلم (`recipient_user_id`) ثمّ **لا يُشعره**.
 *     الإشعار الوحيد كان بعد الدفع — أي بعد فوات الأوان.
 *   • `listForUser(direction: 'incoming')` مبنيّة في الخلفية، و`incoming`
 *     مبنيّة في متحكّم التطبيق — **ولا شاشة واحدة تقرؤها**.
 *   • لا «رفض» إطلاقاً: من لا يريد الطلب يتجاهله حتى تنتهي صلاحيته.
 *   • الدفع لا يُمكن إلّا بالرمز القصير — وهو مسار من وصله رابط، لا من وصله
 *     الطلبُ في قائمته.
 *
 * فالبنية كانت قائمة والوصلات مقطوعة. وهذا نمطٌ تكرّر في هذا المشروع:
 * يُبنى الجديد ولا يُوصَّل، ويبقى القديم يعمل، ولا شيء يقول أيّهما المقصود.
 */
class PaymentRequestDirectFlowTest extends TestCase
{
    use RefreshDatabase;

    private PaymentRequestService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(PaymentRequestService::class);
    }

    private function user(string $phone, string $balance = '50000'): User
    {
        $u = User::factory()->create(['phone' => $phone, 'zone_code' => 'SOUTH', 'is_active' => 1, 'is_kyc_verified' => 1]);
        EMoney::updateOrCreate(['user_id' => $u->id],
            ['current_balance' => $balance, 'zone_code' => 'SOUTH']);

        return $u;
    }

    // ── الوصلة المقطوعة الأولى: الإشعار ────────────────────────────────

    public function test_the_recipient_is_notified_the_moment_the_request_is_created(): void
    {
        // هذا هو العطل بعينه: كان الطلب يُربط بالمستلم ولا يُشعره بشيء،
        // فيظنّ الطالب أنه وصل والمستلم لا يعلم.
        $requester = $this->user('770000001');
        $recipient = $this->user('770000002');

        $this->svc->create($requester, '10000', recipientPhone: $recipient->phone);

        $notif = AmialNotification::where('user_id', $recipient->id)
            ->where('type', 'payment_request_received')->first();

        $this->assertNotNull($notif,
            'لم يصل المستلم إشعارٌ بالطلب — فهو لا يعلم أن أحداً طلب منه شيئاً');
        $this->assertStringContainsString('يطلب منك', (string) $notif->body);
    }

    public function test_an_unregistered_number_gets_no_notification_and_no_link_break(): void
    {
        // رقمٌ ليس على أميال: لا مستلمَ يُشعَر، ويبقى الرمز والرابط سبيلاً.
        // فالرابط احتياطٌ لا بديل.
        $requester = $this->user('770000001');

        $req = $this->svc->create($requester, '5000', recipientPhone: '779999999');

        $this->assertNull($req->recipient_user_id);
        $this->assertNotEmpty($req->short_code, 'لا رمز — فلا سبيل إلى غير المسجَّل');
        $this->assertSame(0, AmialNotification::where('type', 'payment_request_received')->count());
    }

    // ── الوصلة المقطوعة الثانية: القائمة الواردة ───────────────────────

    public function test_the_request_appears_in_the_recipients_incoming_list(): void
    {
        $requester = $this->user('770000001');
        $recipient = $this->user('770000002');

        $this->svc->create($requester, '10000', recipientPhone: $recipient->phone);

        $incoming = $this->svc->listForUser($recipient, 'incoming');
        $outgoing = $this->svc->listForUser($requester, 'outgoing');

        $this->assertCount(1, $incoming, 'لا يرى المستلم الطلب في وارده');
        $this->assertCount(1, $outgoing, 'لا يرى الطالب طلبه في صادره');

        // والعكس: لا يرى أحدهما ما ليس له.
        $this->assertCount(0, $this->svc->listForUser($requester, 'incoming'));
    }

    // ── الوصلة المقطوعة الثالثة: الرفض ─────────────────────────────────

    public function test_the_recipient_can_decline_and_the_requester_is_told(): void
    {
        // بلا رفضٍ يتجاهل المستلمُ الطلبَ حتى تنتهي صلاحيته، والطالب ينتظر
        // أسبوعاً ثمّ يتّصل. وصمتُ الرفض أسوأ من الرفض.
        $requester = $this->user('770000001');
        $recipient = $this->user('770000002');

        $req = $this->svc->create($requester, '10000', recipientPhone: $recipient->phone);
        $req = $this->svc->decline($recipient, $req, 'ليس لديّ رصيد الآن');

        $this->assertSame('declined', $req->status);

        $notif = AmialNotification::where('user_id', $requester->id)
            ->where('type', 'payment_request_declined')->first();
        $this->assertNotNull($notif, 'لم يُبلَّغ الطالب بالرفض');
    }

    public function test_declining_is_distinct_from_the_requester_cancelling(): void
    {
        // الطالب يُلغي، والمستلم يرفض. وخلطُهما يُضيع على الطالب معرفةَ ما
        // وقع: المرفوض لا يُعاد إرساله، والملغى قد يُعاد.
        $requester = $this->user('770000001');
        $recipient = $this->user('770000002');

        $a = $this->svc->create($requester, '1000', recipientPhone: $recipient->phone);
        $b = $this->svc->create($requester, '2000', recipientPhone: $recipient->phone);

        $this->assertSame('declined', $this->svc->decline($recipient, $a)->status);
        $this->assertSame('cancelled', $this->svc->cancel($requester, $b)->status);
    }

    public function test_a_stranger_cannot_decline_someone_elses_request(): void
    {
        $requester = $this->user('770000001');
        $recipient = $this->user('770000002');
        $stranger = $this->user('770000003');

        $req = $this->svc->create($requester, '1000', recipientPhone: $recipient->phone);

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->decline($stranger, $req);
    }

    // ── الموافقة: الدفع ────────────────────────────────────────────────

    public function test_the_recipient_can_pay_and_money_moves_both_ways(): void
    {
        $requester = $this->user('770000001', '0');
        $recipient = $this->user('770000002', '50000');

        $req = $this->svc->create($requester, '10000', recipientPhone: $recipient->phone);
        $this->svc->pay($recipient, $req);

        $this->assertSame(0, bccomp(
            (string) EMoney::where('user_id', $requester->id)->value('current_balance'), '10000', 4));
        $this->assertSame(0, bccomp(
            (string) EMoney::where('user_id', $recipient->id)->value('current_balance'), '40000', 4));
        $this->assertSame('paid', $req->fresh()->status);
    }

    public function test_paying_a_request_writes_a_ledger_entry(): void
    {
        // AMIAL-LEDGER-REQUEST-001 — عطلٌ ثانٍ اكتُشف أثناء قراءة `pay()`:
        // كانت تُحرّك المال بـ`debit`/`credit` **ولا تُرحّل**، وسببُ إعفائها
        // في حارس التغطية مكتوبٌ خطأً: «التنفيذ يمرّ بمسار الدفع المُرحِّل».
        //
        // والحارس قبِل السبب المكتوب ولم يتحقّق منه — وهذا حدُّه الذي يجب
        // أن يُعرف: يمنع الصمت لا الكذب.
        $requester = $this->user('770000001', '0');
        $recipient = $this->user('770000002', '50000');

        $req = $this->svc->create($requester, '10000', recipientPhone: $recipient->phone);
        $this->svc->pay($recipient, $req);

        $this->assertSame(1,
            LedgerJournalEntry::where('source_type', 'payment_request')->count(),
            'دُفع الطلب بلا قيدٍ في الدفتر — مالٌ يتحرّك بلا أثر محاسبيّ');
    }

    public function test_someone_else_cannot_pay_a_targeted_request(): void
    {
        // طلبٌ موجَّه لشخصٍ بعينه لا يدفعه غيره ولو ملك الرمز.
        $requester = $this->user('770000001');
        $recipient = $this->user('770000002');
        $stranger = $this->user('770000003');

        $req = $this->svc->create($requester, '1000', recipientPhone: $recipient->phone);

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->pay($stranger, $req);
    }

    public function test_a_declined_request_cannot_then_be_paid(): void
    {
        // رفضٌ ثمّ دفع = تراجعٌ صامت. ومن رفض ثمّ ضغط «ادفع» بالخطأ يخرج ماله
        // على طلبٍ أعلن أنه لا يريده.
        $requester = $this->user('770000001');
        $recipient = $this->user('770000002');

        $req = $this->svc->create($requester, '1000', recipientPhone: $recipient->phone);
        $this->svc->decline($recipient, $req);

        $this->expectException(\RuntimeException::class);
        $this->svc->pay($recipient, $req->fresh());
    }
}
