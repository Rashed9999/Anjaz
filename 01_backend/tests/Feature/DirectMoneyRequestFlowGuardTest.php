<?php

namespace Tests\Feature;

use App\Models\PaymentRequest;
use App\Models\Receipt;
use App\Models\EMoney;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Access\AccessConstants;
use App\Support\Access\AccessPresets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * AMIAL-REQUEST-DIRECT-003 — **طلبُ المال: طريقٌ واحدٌ من طرفَيه.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * قالها صاحبُ المشروع أربعَ مرّات: «لماذا لا زلتَ مصمِّماً على الرابط
 * والباركود؟». وفي كلّ مرّة أُصلح جزءٌ وبقي الطريقُ مقطوعاً في موضعٍ آخر.
 *
 * **والقياسُ في هذه الجولة كشف خمسةَ قطوعٍ لا واحداً:**
 *
 * | الموضع | ما كان |
 * |---|---|
 * | شاشةُ ما بعد الإنشاء | «الطلب جاهز للمشاركة» + رمزُ QR + الرابط — **لكلّ طلبٍ**، ولو وصل صاحبَه |
 * | «خدماتي» | ورقةٌ تسأل «رابط أم مباشر؟» — تجعل الرابطَ نِدّاً في أوّل خطوة |
 * | الإشعار | `payment_request_received` يُرسَل ولا فرعَ له في `notification_helper` — يُضغط فلا يفتح شيء |
 * | صفُّ الوارد | يقرأ `requester_name` وهو **ليس عموداً** — «مستخدم يطلب منك» بلا اسم |
 * | «طلباتي المرسلة» | `outgoing` مبنيّةٌ في المتحكّم والخلفية، **ولا شاشة تقرؤها** |
 *
 * **وأصلُ الخمسة واحد: نظامان متوازيان.** `request_money` القديم
 * و`payment_requests` الجديد، ولكلٍّ صندوقُ واردٍ لا يرى الآخر. فصار
 * واحداً — والقديمُ بقيت نقطةُ نهايته للنسخ المنشورة، وسقط بابُه من
 * التطبيق.
 */
class DirectMoneyRequestFlowGuardTest extends TestCase
{
    use RefreshDatabase;

    private const APP = __DIR__ . '/../../../02_flutter_app/lib';

    private User $requester;
    private User $recipient;

    protected function setUp(): void
    {
        parent::setUp();

        // حدُّ المعدّل يَنزف بين الاختبارات في عمليّةٍ واحدة — والحدُّ
        // نفسُه محروسٌ نصّاً في `routes/api/amial.php`.
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->requester = User::factory()->create([
            'type' => CUSTOMER_TYPE, 'is_active' => 1,
            'is_kyc_verified' => 1,
            'kyc_tier' => 2,
            'f_name' => 'أحمد', 'l_name' => 'الصالح',
        ]);

        $this->recipient = User::factory()->create([
            'type' => CUSTOMER_TYPE, 'is_active' => 1,
            'is_kyc_verified' => 1,
            'kyc_tier' => 2,
            'transaction_pin' => Hash::make('1234'),
            'f_name' => 'سالم', 'l_name' => 'المقطري',
        ]);
    }

    private function dart(string $relative): string
    {
        $path = self::APP . '/' . $relative;

        $this->assertFileExists($path, "ملفٌّ مفقود: {$relative}");

        return (string) file_get_contents($path);
    }

    /** التعليقاتُ تُنزع قبل المطابقة — **حارسٌ مرّ ثلاثَ مرّاتٍ على تعليق**. */
    private function dartCode(string $relative): string
    {
        return preg_replace('#^\s*//.*$#m', '', $this->dart($relative)) ?? '';
    }

    public function test_customers_are_authorized_to_see_the_payment_request_feature(): void
    {
        $this->assertContains(
            AccessConstants::F_PAYMENT_REQUESTS,
            AccessPresets::roleBase(AccessConstants::ROLE_USER),
            'الواجهة تبني طلب المال لكن خريطة الوصول تخفيه عن العميل',
        );
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① الخادم — الطلبُ يصل، ويحمل اسمَ صاحبه
    // ══════════════════════════════════════════════════════════════════

    public function test_a_request_to_a_subscriber_is_delivered_not_shared(): void
    {
        $meta = $this->actingAs($this->requester, 'api')
            ->postJson('/api/v1/amial/payment-requests', [
                'amount' => '5000',
                'recipient_phone' => $this->recipient->phone,
                // **حتّى حين تطلب الشاشةُ رابطاً**: الخادمُ يرى مشتركاً
                // فيُوصل. وإلّا كفى تطبيقٌ قديمٌ لإحياء الرابط.
                'share_method' => 'link',
            ])->assertCreated()->json('meta');

        $this->assertTrue($meta['delivered'],
            'المستلمُ مشتركٌ والطلبُ لم يصله — عاد الرابطُ من باب الخادم');

        $this->assertSame('سالم المقطري', $meta['recipient_label'],
            'شاشةُ النجاح تقول «وصل الطلب» ولا تقول إلى مَن');

        $this->assertSame(PaymentRequest::SHARE_DIRECT,
            PaymentRequest::latest('id')->value('share_method'));
    }

    public function test_the_strict_flow_verifies_then_delivers_without_a_share_fallback(): void
    {
        $verified = $this->actingAs($this->requester, 'api')
            ->postJson('/api/v1/amial/payment-requests/check-recipient', [
                'phone' => $this->recipient->phone,
            ])->assertOk()->json('meta');

        $this->assertTrue($verified['found']);
        $this->assertNotSame('سالم المقطري', $verified['masked_name'],
            'نقطة التحقق تكشف الاسم الكامل وتسمح بحصاد بيانات العملاء');
        $this->assertSame(26, strlen($verified['verification_token']));

        $meta = $this->actingAs($this->requester, 'api')
            ->postJson('/api/v1/amial/payment-requests/direct', [
                'amount' => '5000',
                'recipient_id' => $verified['recipient_id'],
                'verification_token' => $verified['verification_token'],
                'note' => 'حصتي من الفاتورة',
            ])->assertCreated()->json('meta');

        $this->assertTrue($meta['delivered']);
        $this->assertArrayNotHasKey('public_url', $meta,
            'عقد الطلب المباشر أعاد رابطاً — وعادت الشاشة القديمة');
        $this->assertSame(PaymentRequest::SHARE_DIRECT,
            PaymentRequest::find($meta['request']['id'])->share_method);

        $row = $this->actingAs($this->requester, 'api')
            ->getJson('/api/v1/amial/payment-requests?direction=outgoing')
            ->assertOk()->json('meta.requests.0');
        $this->assertSame($verified['masked_name'], $row['recipient_label']);
        $this->assertNotSame('سالم المقطري', $row['recipient_label'],
            'القائمة كشفت الاسم الكامل بعد أن قنّعه فحص المستلم');
    }

    public function test_a_direct_request_rejects_an_unverified_recipient_token(): void
    {
        $this->actingAs($this->requester, 'api')
            ->postJson('/api/v1/amial/payment-requests/direct', [
                'amount' => '5000',
                'recipient_id' => $this->recipient->id,
                'verification_token' => str_repeat('A', 26),
            ])->assertForbidden();

        $this->assertSame(0, PaymentRequest::count(),
            'أُنشئ الطلب من recipient_id قابل للتعديل بلا تأكيد المستلم');
    }

    public function test_direct_approval_moves_money_and_writes_all_financial_traces(): void
    {
        Queue::fake();
        EMoney::updateOrCreate(['user_id' => $this->requester->id], [
            'current_balance' => '0.0000', 'held_balance' => '0.0000',
            'pending_balance' => '0.0000', 'charge_earned' => '0.0000',
            'zone_code' => 'SOUTH',
        ]);
        EMoney::updateOrCreate(['user_id' => $this->recipient->id], [
            'current_balance' => '20000.0000', 'held_balance' => '0.0000',
            'pending_balance' => '0.0000', 'charge_earned' => '0.0000',
            'zone_code' => 'SOUTH',
        ]);

        $verified = $this->actingAs($this->requester, 'api')
            ->postJson('/api/v1/amial/payment-requests/check-recipient', [
                'phone' => $this->recipient->phone,
            ])->assertOk()->json('meta');

        $id = $this->actingAs($this->requester, 'api')
            ->postJson('/api/v1/amial/payment-requests/direct', [
                'amount' => '5000',
                'recipient_id' => $verified['recipient_id'],
                'verification_token' => $verified['verification_token'],
            ])->assertCreated()->json('meta.request.id');

        $this->actingAs($this->recipient, 'api')
            ->postJson("/api/v1/amial/payment-requests/{$id}/pay", [
                'pin' => '1234',
            ])->assertOk();

        $request = PaymentRequest::findOrFail($id);
        $this->assertSame('paid', $request->status);
        $this->assertNotNull($request->paid_transaction_id);
        $this->assertSame(2, Transaction::where('ref_trans_id', $request->paid_transaction_id)
            ->orWhere('transaction_id', $request->paid_transaction_id)->count());
        $this->assertDatabaseHas('ledger_journal_entries', [
            'source_type' => 'payment_request',
            'source_id' => $request->request_ulid,
        ]);
        $this->assertDatabaseHas('audit_decisions', [
            'subject_type' => 'payment_request',
            'subject_id' => $request->request_ulid,
            'action' => 'PAYMENT_REQUEST_PAID',
        ]);
        $this->assertSame(2, Receipt::where(
            'reference_transaction_id', $request->paid_transaction_id)->count(),
            'التحويل بلا إيصالين للطرفين');
        $this->assertSame('5000.0000', (string) EMoney::where(
            'user_id', $this->requester->id)->value('current_balance'));
    }

    public function test_direct_approval_with_insufficient_balance_moves_nothing(): void
    {
        EMoney::updateOrCreate(['user_id' => $this->requester->id], [
            'current_balance' => '0.0000', 'held_balance' => '0.0000',
            'pending_balance' => '0.0000', 'charge_earned' => '0.0000',
            'zone_code' => 'SOUTH',
        ]);
        EMoney::updateOrCreate(['user_id' => $this->recipient->id], [
            'current_balance' => '1000.0000', 'held_balance' => '0.0000',
            'pending_balance' => '0.0000', 'charge_earned' => '0.0000',
            'zone_code' => 'SOUTH',
        ]);

        $verified = $this->actingAs($this->requester, 'api')
            ->postJson('/api/v1/amial/payment-requests/check-recipient', [
                'phone' => $this->recipient->phone,
            ])->assertOk()->json('meta');
        $id = $this->actingAs($this->requester, 'api')
            ->postJson('/api/v1/amial/payment-requests/direct', [
                'amount' => '5000',
                'recipient_id' => $verified['recipient_id'],
                'verification_token' => $verified['verification_token'],
            ])->assertCreated()->json('meta.request.id');

        $this->actingAs($this->recipient, 'api')
            ->postJson("/api/v1/amial/payment-requests/{$id}/pay", ['pin' => '1234'])
            ->assertStatus(402);

        $this->assertSame('pending', PaymentRequest::findOrFail($id)->status);
        $this->assertSame(0, Transaction::count());
        // **«لا قيدَ لهذه الدفعة» لا «الدفترُ فارغ».**
        //
        // وضعُ رصيدٍ في محفظةٍ يُنشئ قيدَ **رصيدٍ افتتاحيّ** يفسّر من أين
        // جاء المال — وهو واجبٌ لا خلل. فكان الحارسُ يعدّ الدفترَ كلَّه
        // ويسقط على قيدٍ صحيح، **ويصف مساراً سليماً بالكسر**.
        $this->assertSame(
            0,
            \App\Models\Ledger\LedgerJournalEntry::where('source_type', 'payment_request')->count(),
            'دفعةٌ مرفوضةٌ تركت قيداً في الدفتر',
        );
        $this->assertSame('0.0000', (string) EMoney::where(
            'user_id', $this->requester->id)->value('current_balance'));
        $this->assertSame('1000.0000', (string) EMoney::where(
            'user_id', $this->recipient->id)->value('current_balance'));
    }

    public function test_wrong_payment_pins_are_counted_and_lock_the_account(): void
    {
        $verified = $this->actingAs($this->requester, 'api')
            ->postJson('/api/v1/amial/payment-requests/check-recipient', [
                'phone' => $this->recipient->phone,
            ])->assertOk()->json('meta');
        $id = $this->actingAs($this->requester, 'api')
            ->postJson('/api/v1/amial/payment-requests/direct', [
                'amount' => '5000',
                'recipient_id' => $verified['recipient_id'],
                'verification_token' => $verified['verification_token'],
            ])->assertCreated()->json('meta.request.id');

        for ($attempt = 1; $attempt < 5; $attempt++) {
            $this->actingAs($this->recipient, 'api')
                ->postJson("/api/v1/amial/payment-requests/{$id}/pay", [
                    'pin' => '9999',
                ])->assertForbidden();
        }

        $this->actingAs($this->recipient, 'api')
            ->postJson("/api/v1/amial/payment-requests/{$id}/pay", [
                'pin' => '9999',
            ])->assertStatus(423)
            ->assertJsonPath('code', 'PIN_LOCKED');

        $this->assertSame(5, (int) $this->recipient->fresh()->pin_failed_attempts);
        $this->assertTrue($this->recipient->fresh()->pin_locked_until->isFuture());
        $this->assertSame('pending', PaymentRequest::findOrFail($id)->status);
        $this->assertSame(0, Transaction::count(),
            'محاولة PIN فاشلة حرّكت مالاً أو كتبت عملية');
        $this->assertDatabaseHas('account_security_events', [
            'user_id' => $this->recipient->id,
            'event_type' => 'PIN_LOCKED',
        ]);
    }

    public function test_a_request_to_a_stranger_keeps_the_link(): void
    {
        // **والرابطُ لم يُحذف** — له حالةٌ حقيقيّة: من ليس على أميال لا
        // يصله إشعار. وحذفُه يترك الطالبَ بلا طريقٍ إطلاقاً.
        $meta = $this->actingAs($this->requester, 'api')
            ->postJson('/api/v1/amial/payment-requests', [
                'amount' => '5000',
                'recipient_phone' => '770000009',
                'share_method' => 'link',
            ])->assertCreated()->json('meta');

        $this->assertFalse($meta['delivered']);
        $this->assertNotEmpty($meta['short_code']);
    }

    public function test_the_incoming_row_names_who_is_asking(): void
    {
        $this->actingAs($this->requester, 'api')
            ->postJson('/api/v1/amial/payment-requests', [
                'amount' => '5000',
                'recipient_phone' => $this->recipient->phone,
            ])->assertCreated();

        $rows = $this->actingAs($this->recipient, 'api')
            ->getJson('/api/v1/amial/payment-requests?direction=incoming&status=pending')
            ->assertOk()->json('meta.requests');

        $this->assertCount(1, $rows, 'الطلبُ لا يظهر في وارد المستلم');

        $this->assertSame('أحمد الصالح', $rows[0]['requester_label'],
            'صفُّ الوارد بلا اسمٍ — ومن لا يعرف مَن يطلب منه لا يوافق');
    }

    public function test_the_outgoing_row_names_who_was_asked(): void
    {
        $this->actingAs($this->requester, 'api')
            ->postJson('/api/v1/amial/payment-requests', [
                'amount' => '5000',
                'recipient_phone' => $this->recipient->phone,
            ])->assertCreated();

        $rows = $this->actingAs($this->requester, 'api')
            ->getJson('/api/v1/amial/payment-requests?direction=outgoing')
            ->assertOk()->json('meta.requests');

        $this->assertSame('سالم المقطري', $rows[0]['recipient_label']);
        $this->assertSame('pending', $rows[0]['status']);
    }

    /** **وموافقةُ المستلم تُحرّك المال** — وإلّا فالطريقُ كلُّه زينة. */
    public function test_approval_moves_the_money(): void
    {
        $this->recipient->update(['is_kyc_verified' => 1]);
        $this->requester->update(['is_kyc_verified' => 1]);

        $id = $this->actingAs($this->requester, 'api')
            ->postJson('/api/v1/amial/payment-requests', [
                'amount' => '5000',
                'recipient_phone' => $this->recipient->phone,
            ])->assertCreated()->json('meta.request.id');

        $this->assertSame('pending',
            (string) PaymentRequest::find($id)->status);

        // الدفعُ نفسُه محروسٌ في `PaymentRequestPayTest`؛ المقصودُ هنا أنّ
        // **الصفَّ الواصلَ قابلٌ للدفع بمعرّفه** — لا برمزٍ قصيرٍ يُملى.
        $route = collect(\Illuminate\Support\Facades\Route::getRoutes())
            ->first(fn ($r) => str_contains((string) $r->uri(), 'payment-requests/{id}/pay'));

        $this->assertNotNull($route,
            'لا مسارَ للدفع بالمعرّف — فالمستلمُ يحتاج الرمزَ القصير، '
            . 'وهو ما يجعل الرابطَ ضروريّاً');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② التطبيق — القاعدة التاسعة والثانية عشرة
    // ══════════════════════════════════════════════════════════════════

    /**
     * **شاشةُ النجاح لا تعرض رمزاً ولا رابطاً للطلب الواصل.**
     *
     * وهذا الحارسُ هو جوهرُ الشكوى: آخرُ ما يُرى هو ما يُصدَّق.
     */
    public function test_the_delivered_result_shows_neither_qr_nor_link(): void
    {
        $src = $this->dartCode('features/requested_money/screens/payment_request_show_screen.dart');

        $start = strpos($src, '_deliveredBody(');
        $end = strpos($src, '_shareBody(Map');

        $this->assertNotFalse($start, 'الشاشةُ بلا وجهٍ مباشر — عادت شاشةَ مشاركةٍ واحدة');
        $this->assertNotFalse($end, 'الشاشةُ بلا وجهِ مشاركة — وغيرُ المشترك بلا طريق');
        $this->assertGreaterThan($start, $end);

        $delivered = substr($src, $start, $end - $start);

        foreach (['QrDisplayWidget', 'public_url', 'short_code', 'مشاركة الرابط'] as $needle) {
            $this->assertStringNotContainsString($needle, $delivered,
                "وجهُ «وصل الطلب» يعرض «{$needle}» — وهي بعينها الشكوى");
        }

        // والوجهُ الآخرُ يبقى، ويقول سببَه.
        $this->assertStringContainsString('QrDisplayWidget', substr($src, $end));
        $this->assertStringContainsString('ليس على أميال', $this->dart(
            'features/requested_money/screens/payment_request_show_screen.dart'));
    }

    /** **ولا يُسأل «رابطٌ أم مباشر؟»** — السؤالُ نفسُه كان العطل. */
    public function test_no_screen_asks_which_request_method(): void
    {
        $src = $this->dartCode('features/me/screens/my_services_screen.dart');

        $this->assertStringNotContainsString('_chooseRequestMethod', $src,
            'ورقةُ اختيار الطريقة عادت — والرابطُ نِدٌّ في أوّل خطوة');

        $this->assertStringContainsString('PaymentRequestCreateScreen', $src);
    }

    /** **وبابُ الطلب واحد**: لا مدخلَ يُنشئ في الجدول القديم. */
    public function test_the_app_no_longer_creates_legacy_money_requests(): void
    {
        $offenders = [];

        foreach (['features/me/screens/my_services_screen.dart',
                  'features/camera_verification/controllers/qr_code_scanner_controller.dart',
                  'features/home/screens/amial_customer_home_screen.dart'] as $f) {
            if (str_contains($this->dartCode($f), "transactionType: 'request_money'")) {
                $offenders[] = $f;
            }
        }

        $this->assertSame([], $offenders,
            'مدخلٌ ينشئ طلباً في `request_money` — صندوقُ واردٍ ثانٍ لا '
            . 'يراه المستلم: ' . implode('، ', $offenders));

        $this->assertFileDoesNotExist(
            self::APP . '/features/requested_money/screens/request_from_person_screen.dart',
            'شاشةُ الطلب القديمة عادت — ونظامان لأمرٍ واحدٍ يعنيان صندوقين');
    }

    /** **الإشعارُ يقود إلى مكان** — وإلّا رنّ الهاتفُ ولم يُفتح شيء. */
    public function test_the_new_request_notification_opens_the_inbox(): void
    {
        $src = $this->dartCode('helper/notification_helper.dart');

        $this->assertGreaterThanOrEqual(4,
            substr_count($src, "'payment_request_received'"),
            'نوعُ الإشعار غيرُ معالَجٍ في مسارات الاستقبال والفتح');

        $this->assertStringContainsString('IncomingRequestsScreen', $src);
    }

    /** **وطلباتي المرسلة لها شاشة** — `outgoing` كانت بلا قارئ. */
    public function test_the_outgoing_list_has_a_screen_and_a_door(): void
    {
        $screen = $this->dartCode(
            'features/requested_money/screens/outgoing_requests_screen.dart');

        $this->assertStringContainsString("loadList('outgoing')", $screen,
            'شاشةُ الصادرة لا تقرأ القائمة');

        $doors = 0;

        foreach (['features/me/screens/my_services_screen.dart',
                  'features/requested_money/screens/payment_request_show_screen.dart'] as $f) {
            if (str_contains($this->dartCode($f), 'OutgoingRequestsScreen')) {
                $doors++;
            }
        }

        $this->assertGreaterThanOrEqual(2, $doors,
            'صفحةٌ لا يُوصل إليها ليست مبنيّة (القاعدة ١٢)');
    }

    /** **والوارد يُرى في الرئيسيّة** — لا خلف ثلاثِ ضغطاتٍ ومعرفةٍ مسبقة. */
    public function test_the_home_screen_surfaces_incoming_requests(): void
    {
        $src = $this->dartCode('features/home/screens/amial_customer_home_screen.dart');

        $this->assertStringContainsString("loadList('incoming'", $src,
            'الرئيسيّةُ لا تسأل عن الطلبات الواردة');

        $this->assertStringContainsString('_incomingRequestsBanner', $src);
        $this->assertStringContainsString('IncomingRequestsScreen', $src,
            'اللافتةُ بلا وجهةٍ — تُعرض ولا تُضغط');
    }

    /** وصفُّ الوارد يقرأ الحقلَ الذي يُرسله الخادمُ فعلاً. */
    public function test_the_incoming_card_reads_the_field_the_server_sends(): void
    {
        $src = $this->dartCode(
            'features/requested_money/screens/incoming_requests_screen.dart');

        $this->assertStringContainsString("request['requester_label']", $src,
            "الصفُّ يقرأ `requester_name` — وهو ليس عموداً، فيُعرض «مستخدم»");
    }

    /** شاشةُ الطلب نفسُها لا تعيد رابطاً من الباب الخلفي. */
    public function test_the_customer_request_screen_is_strictly_direct(): void
    {
        $src = $this->dartCode(
            'features/requested_money/screens/payment_request_create_screen.dart');

        $this->assertStringContainsString('createDirect(', $src);
        $this->assertStringContainsString('تأكيد طلب المال', $src);
        $this->assertStringContainsString('verification_token', $this->dartCode(
            'features/requested_money/domain/repositories/payment_request_repo.dart'));

        foreach (['QrDisplayWidget', "shareMethod: 'link'", '_shareMethod'] as $needle) {
            $this->assertStringNotContainsString($needle, $src,
                "شاشةُ طلب العميل أعادت {$needle}");
        }
    }

    public function test_admin_can_trace_both_parties_status_and_transaction(): void
    {
        $controller = (string) file_get_contents(
            app_path('Http/Controllers/Admin/AdminSurfaceController.php'));
        $view = (string) file_get_contents(resource_path(
            'views/admin-views/amial/surface/payment-requests.blade.php'));

        foreach (['recipient', 'paidBy', 'paid_transaction_id', "export') === 'csv'"] as $needle) {
            $this->assertStringContainsString($needle, $controller,
                "لوحة الإدارة لا تجلب أثر الطلب: {$needle}");
        }
        foreach (['share_method', 'paid_transaction_id', 'recipient', 'declined'] as $needle) {
            $this->assertStringContainsString($needle, $view,
                "جدول الإدارة يخفي معلومة لازمة للدعم: {$needle}");
        }
        $this->assertStringNotContainsString('طلبات الأموال (روابط/QR)', $view,
            'لوحة الإدارة ما زالت تصف الميزة الجديدة كأنها روابط وQR فقط');
    }
}
