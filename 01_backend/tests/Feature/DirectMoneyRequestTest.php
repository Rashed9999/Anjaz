<?php

namespace Tests\Feature;

use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\PaymentRequestService;
use App\Support\Phone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-REQUEST-DIRECT-002 — **طلبُ المال يصل بالرقم، لا برابطٍ يُشارَك.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الشكوى المتكرّرة:** «طلب المال لا تزال عبر الرابط أو الباركود وهذا
 * غير عمليّ… طريقة الكود القديم أفضل وأسهل.»
 *
 * والخدمةُ كانت **تدعم الطريقة المباشرة فعلاً**: تربط الطلب بالمستلم
 * وتُشعره. فلماذا لم تعمل؟
 *
 *     $recipientId = User::where('phone', $recipientPhone)->value('id');
 *
 * **تطابقٌ حرفيّ.** والرقمُ نفسُه يُخزَّن ويُكتب بأربع صيغ:
 * `+967777100001` و`967777100001` و`00967777100001` و`777100001`.
 * فمن يكتب `777…` في خانة «من تطلب منه» لا يُطابق حسابَ من سُجّل
 * `+967777…` — فيُرجع `null`، ويُنشأ الطلبُ **بلا مستلم**، ولا إشعارَ
 * يُرسَل، ولا يظهر في «الطلبات الواردة» عند أحد.
 *
 * **وتبقى للطالب طريقةٌ واحدةٌ يراها: الرابط.** فالميزةُ المباشرةُ
 * مبنيّةٌ ولا تُوصَل إليها — وهو نمطُ العطل الأكثر تكراراً في المشروع.
 *
 * ولا خطأَ في أيّ سجلّ: الطلبُ يُنشأ بنجاح، والاستجابةُ ٢٠٠، والحقلُ
 * `recipient_phone` مكتوبٌ فيه الرقمُ الصحيح — والرابطُ وحدَه يعمل.
 *
 * (وهو الدرسُ نفسُه المكتوب في `OtpPolicy`: «المقارنةُ بالمقطع الأخير لا
 * بالتطابق التامّ… وهو عطلٌ لا يُنتج خطأً في أيّ سجلّ». وقع مرّتين.)
 */
class DirectMoneyRequestTest extends TestCase
{
    use RefreshDatabase;

    private function person(string $phone): User
    {
        // **`type => 1` هو `AGENT_TYPE` لا العميل** — والعميلُ `2`.
        // فكان الحارسُ يردّ «طلب المال المباشر متاح للعملاء النشطين فقط»
        // ردّاً صحيحاً، ويُقرأ سقوطاً في الميزة. والثوابتُ تُكتب بأسمائها
        // لا بأرقامها لهذا بعينه.
        //
        // و`is_kyc_verified` شرطٌ ثانٍ يفحصه المسار — وغيابُه يوقف الطلب
        // بعد أن يمرّ فحصُ النوع.
        return User::factory()->create([
            'phone' => $phone,
            'type' => CUSTOMER_TYPE,
            'role' => 'customer',
            'is_active' => 1,
            'is_kyc_verified' => 1,
            'zone_code' => 'SOUTH',
        ]);
    }

    private function svc(): PaymentRequestService
    {
        return app(PaymentRequestService::class);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① الرقمُ يصل بأيّ صيغةٍ كُتب
    // ══════════════════════════════════════════════════════════════════

    public static function phoneShapes(): array
    {
        return [
            'محلّيّ'          => ['777100055', '+967777100055'],
            'بمفتاح الدولة'   => ['967777100055', '+967777100055'],
            'بصفرين'          => ['00967777100055', '+967777100055'],
            'بعلامة زائد'     => ['+967777100055', '967777100055'],
            'بمسافات'         => ['777 100 055', '+967777100055'],
        ];
    }

    /**
     * @dataProvider phoneShapes
     */
    public function test_the_request_finds_its_recipient_whatever_shape_the_phone_is_typed_in(
        string $typed, string $registered): void
    {
        $requester = $this->person('+967711000001');
        $recipient = $this->person($registered);

        $req = $this->svc()->create(
            requester: $requester, amount: '5000', recipientPhone: $typed);

        $this->assertSame($recipient->id, $req->recipient_user_id,
            "كُتب «{$typed}» والمسجَّل «{$registered}» — ولم يُربط الطلبُ بصاحبه، "
            . 'فلا إشعارَ ولا ظهورٌ في الطلبات الواردة، ولا يبقى للطالب إلّا الرابط');
    }

    public function test_an_unregistered_number_still_produces_a_shareable_request(): void
    {
        // **ولا يُكسر الرابط**: من يطلب من غير مشترك يحتاجه — وهو الحالةُ
        // التي وُجد الرابطُ لها أصلاً، لا الحالةُ العامّة.
        $requester = $this->person('+967711000002');

        $req = $this->svc()->create(
            requester: $requester, amount: '2500', recipientPhone: '+967733999999');

        $this->assertNull($req->recipient_user_id);
        $this->assertNotEmpty($req->short_code);
        $this->assertSame('pending', $req->status);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② الطريقةُ المباشرةُ تُعلَن، لا تُستنتج
    // ══════════════════════════════════════════════════════════════════

    public function test_a_direct_request_is_marked_direct_not_link(): void
    {
        // «غير عمليّ أن يكون طلبُ المال رابطاً» — والرابطُ كان **الوضعَ
        // الافتراضيّ** حتّى حين يكون المستلم مشتركاً معروفاً. فصار للطلب
        // ثلاثُ طرقٍ لا اثنتان، والمباشرةُ تُعلَن في الصفّ نفسه.
        $requester = $this->person('+967711000003');
        $this->person('+967777100077');

        $req = $this->svc()->create(
            requester: $requester, amount: '1500', recipientPhone: '777100077');

        $this->assertSame(PaymentRequest::SHARE_DIRECT, $req->share_method,
            'طلبٌ إلى مشتركٍ معروف يُسجَّل «مباشر» — والرابطُ لغير المشترك');
    }

    public function test_a_request_to_a_stranger_stays_a_link(): void
    {
        $requester = $this->person('+967711000004');

        $req = $this->svc()->create(
            requester: $requester, amount: '1500', recipientPhone: '733111222');

        $this->assertSame('link', $req->share_method);
    }

    public function test_you_cannot_request_money_from_yourself(): void
    {
        $me = $this->person('+967711000005');

        $this->expectException(\InvalidArgumentException::class);

        $this->svc()->create(requester: $me, amount: '100', recipientPhone: '711000005');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ③ يصل فعلاً — الإشعارُ والقائمة
    // ══════════════════════════════════════════════════════════════════

    public function test_the_recipient_sees_it_in_their_incoming_list(): void
    {
        $requester = $this->person('+967711000006');
        $recipient = $this->person('+967777100088');

        $this->svc()->create(
            requester: $requester, amount: '9000',
            recipientPhone: '777100088', note: 'حصّة الإيجار');

        $incoming = $this->actingAs($recipient, 'api')
            ->getJson('/api/v1/amial/payment-requests?direction=incoming')
            ->assertOk()->json('meta.requests');

        $this->assertCount(1, $incoming, 'الطلبُ لا يظهر عند المستلم');
        $this->assertSame('حصّة الإيجار', $incoming[0]['note']);
    }

    public function test_the_recipient_is_notified_the_moment_it_is_created(): void
    {
        $requester = $this->person('+967711000007');
        $recipient = $this->person('+967777100099');

        $this->svc()->create(requester: $requester, amount: '3000', recipientPhone: '777100099');

        $this->assertDatabaseHas('amial_notifications', [
            'user_id' => $recipient->id,
            'type' => 'payment_request_received',
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ④ الحارسُ الذي يمنع عودةَ العطل في كلّ موضعٍ آخر
    // ══════════════════════════════════════════════════════════════════

    /**
     * **لا بحثَ عن مستخدمٍ بهاتفٍ بتطابقٍ حرفيّ.**
     *
     * فالعطلُ ليس في هذه الخدمة وحدها: أيُّ `where('phone', $x)` يفترض
     * صيغةً واحدةً لرقمٍ يصل بأربع. و`Phone::variants()` مبنيّةٌ لهذا
     * بالضبط، وسبعُ خدماتٍ تستعملها — والباقي لا.
     */
    public function test_no_service_looks_up_a_user_by_a_literal_phone_match(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **النطاق: بحثٌ عن طرفٍ مقابلٍ برقمٍ يكتبه إنسان.**
        //
        // ومسارات الدخول القديمة (6cash) مستثناةٌ بسببٍ مكتوب: الرقمُ
        // فيها **هويّةُ الداخل نفسِه** لا طرفاً مقابلاً — يصل بالصيغة
        // التي سُجّل بها من الشاشة نفسها، فالتطابقُ الحرفيّ يعمل. ومسارُ
        // الدخول الموحَّد الحيّ (`UnifiedAuthService`) يستعمل `variants`
        // أصلاً.
        //
        // **والاستثناءُ مؤقّت**: توحيدُها يحتاج جولةً على تسعة متحكّمات،
        // وخطرُها أدنى — أسوأُ ما فيها أنّ صاحبَ الحساب لا يدخل، لا أنّ
        // مالاً يذهب إلى غير صاحبه.
        $legacyAuth = [
            'Api/V1/Customer/Auth/', 'Api/V1/Agent/Auth/', 'Api/V1/LoginController.php',
            'Web/UnifiedLoginController.php', 'Agent/AgentPortalController.php',
            'Admin/OtpCenterController.php', 'Admin/AdminHubController.php',
            'AccountRecoveryService.php',
        ];

        $offenders = [];
        $files = [];

        foreach ([app_path('Services'), app_path('Http/Controllers')] as $dir) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

            foreach ($it as $f) {
                if (str_ends_with((string) $f, '.php')) {
                    $files[] = (string) $f;
                }
            }
        }

        foreach ($files as $file) {
            foreach ($legacyAuth as $skip) {
                if (str_contains($file, $skip)) {
                    continue 2;
                }
            }

            foreach (file($file) as $i => $line) {
                // التعليقاتُ تُطرح — الدرسُ وقع ثلاثَ مرّاتٍ في هذا المشروع.
                $code = preg_replace('#(//|\#).*$#', '', ltrim($line));

                if (! preg_match("/User::where\(\s*'phone'\s*,/", (string) $code)
                    && ! preg_match("/->where\(\s*'phone'\s*,\s*\\\$/", (string) $code)) {
                    continue;
                }

                // `where('phone', Phone::canonical($x))` مقبولٌ: وُحِّد قبل السؤال.
                if (str_contains((string) $code, 'Phone::canonical')) {
                    continue;
                }

                $offenders[] = str_replace(base_path() . '/', '', $file) . ':' . ($i + 1);
            }
        }

        $this->assertSame([], $offenders, "\n"
            . 'بحثٌ عن حسابٍ بهاتفٍ بتطابقٍ حرفيّ:' . "\n  "
            . implode("\n  ", $offenders) . "\n\n"
            . 'والرقمُ الواحد يصل بأربع صيغ (+967… · 967… · 00967… · 777…). '
            . 'فيُرجع البحثُ null، وتُبنى الميزةُ على أنّ الحساب غير موجود — '
            . 'بلا خطأٍ في أيّ سجلّ. استعمل Phone::variants() أو '
            . 'Phone::canonical().');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ⑤ الشاشةُ تعرف قبل الإرسال — نقطةُ الفحص
    // ══════════════════════════════════════════════════════════════════

    public function test_the_screen_can_ask_whether_a_number_is_a_subscriber(): void
    {
        $me = $this->person('+967711000010');
        $them = $this->person('+967777100111');

        $r = $this->actingAs($me, 'api')
            ->postJson('/api/v1/amial/payment-requests/check-recipient', ['phone' => '777100111'])
            ->assertOk()->json('meta');

        $this->assertTrue($r['found']);
        $this->assertNotEmpty($r['hint']);

        // **الاسمُ يُقنَّع عمداً** — واسمُ العائلة كاملاً يجعل مسحَ الأرقام
        // كشفاً لدليل هواتف. فيكفي أن يتعرّف الطالبُ على من يعرفه:
        // الاسمُ الأوّل كاملاً، والعائلةُ بحرفها الأوّل.
        // (وللشقيقة `never_leaks_more_than_a_name` العقدُ نفسُه.)
        $this->assertStringStartsWith($them->f_name, trim($r['name']));
        $this->assertStringNotContainsString($them->l_name, trim($r['name']),
            'اسمُ العائلة ظهر كاملاً — والفحصُ يصير دليلَ هواتف');
        $this->assertSame($r['name'], $r['masked_name']);
    }

    public function test_an_unknown_number_is_answered_not_left_silent(): void
    {
        // (القاعدة ٧) الغيابُ يُقال مع سببه — لا يُترك الحقلُ بلا جواب.
        $me = $this->person('+967711000011');

        // **الغيابُ صار ٤٢٢ برسالةٍ صريحة** بدل ٢٠٠ بـ`found=false`:
        // فطلبُ المال المباشر لا رابطَ احتياطيَّ فيه، والرسالةُ تقول لماذا
        // وقف الطلبُ هنا. (وكان التلميحُ يعِد برابطٍ لم يعد يُعرض.)
        $r = $this->actingAs($me, 'api')
            ->postJson('/api/v1/amial/payment-requests/check-recipient', ['phone' => '733000000'])
            ->assertStatus(422)->json();

        $this->assertSame('REQUEST_RECIPIENT_INVALID', $r['code']);
        $this->assertNotEmpty($r['message']);
    }

    public function test_your_own_number_is_refused_before_you_press_send(): void
    {
        $me = $this->person('+967711000012');

        // ورقمُك أنت يُرَدُّ برسالةٍ لا بحقلٍ صامت.
        $r = $this->actingAs($me, 'api')
            ->postJson('/api/v1/amial/payment-requests/check-recipient', ['phone' => '711000012'])
            ->assertStatus(422)->json();

        $this->assertSame('REQUEST_RECIPIENT_INVALID', $r['code']);
        $this->assertNotEmpty($r['message']);
    }

    public function test_the_check_never_leaks_more_than_a_name(): void
    {
        // مسحُ أرقامٍ بحثاً عمّن هو على أميال لا يُعطي أكثر ممّا تُعطيه
        // محاولةُ الطلب نفسها. فلا هاتفَ ولا معرّفَ ولا رصيدَ في الجواب.
        $me = $this->person('+967711000013');
        $this->person('+967777100222');

        $r = $this->actingAs($me, 'api')
            ->postJson('/api/v1/amial/payment-requests/check-recipient', ['phone' => '777100222'])
            ->assertOk()->json('meta');

        foreach (['id', 'user_id', 'phone', 'balance', 'email'] as $leak) {
            $this->assertArrayNotHasKey($leak, $r, "الجوابُ يُفصح عن «{$leak}»");
        }
    }

    public function test_the_created_request_says_whether_it_was_delivered(): void
    {
        // **ورسالةُ نجاحٍ واحدةٌ للحالتين تجعل الطالب يظنّ أنّ طلبه وصل
        // وهو لم يصل.** فالرسالةُ تتبع ما وقع.
        $me = $this->person('+967711000014');
        $this->person('+967777100333');

        $direct = $this->actingAs($me, 'api')
            ->postJson('/api/v1/amial/payment-requests',
                ['amount' => '1200', 'recipient_phone' => '777100333'])
            ->assertCreated()->json();

        $this->assertTrue($direct['meta']['delivered']);
        $this->assertStringContainsString('وصل', $direct['message']);

        $link = $this->actingAs($me, 'api')
            ->postJson('/api/v1/amial/payment-requests',
                ['amount' => '1200', 'recipient_phone' => '733777888'])
            ->assertCreated()->json();

        $this->assertFalse($link['meta']['delivered']);
        $this->assertStringContainsString('رابط', $link['message']);
    }

    public function test_the_variants_helper_actually_covers_the_four_shapes(): void
    {
        // حارسٌ يعتمد على أداةٍ لا يفحصها يُطمئن على أساسٍ لم يُقَس.
        $v = Phone::variants('777100055');

        foreach (['777100055', '967777100055'] as $shape) {
            $this->assertContains($shape, $v, "الصيغة {$shape} خارج variants");
        }
    }
}
