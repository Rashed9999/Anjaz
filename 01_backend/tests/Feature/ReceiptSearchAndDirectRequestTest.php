<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * AMIAL-RECEIPTS-FILTER-001 · AMIAL-REQ-DIRECT-001
 *
 * ══════════════════════════════════════════════════════════════════════
 * **عطلان أبلغ عنهما صاحبُ المشروع من الشاشة:**
 *
 * ① شاشةُ الإيصالات بلا بحثٍ ولا فلتر. ومن له مئةُ عمليّةٍ في الشهر يبحث
 *   عن تحويلٍ لشخصٍ بعينه **بالتمرير** — ولا يجده.
 *
 * ② «طلب المال» يقود إلى إنشاء رابطٍ ورمزِ QR، وهو مسارُ تاجر. والمسارُ
 *   الأبسط — هاتفٌ ومبلغٌ فيصل إشعار — **مبنيٌّ في الخادم منذ البداية
 *   ولا ينادي عليه أحد**.
 */
class ReceiptSearchAndDirectRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $me;
    private User $ahmed;
    private User $sami;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = $this->customer('777100001', 'أنا', 'المستخدم');
        $this->ahmed = $this->customer('777200002', 'أحمد', 'العولقي');
        $this->sami = $this->customer('777300003', 'سامي', 'الحضرمي');
    }

    private function customer(string $phone, string $f, string $l): User
    {
        $u = User::factory()->create([
            'f_name' => $f, 'l_name' => $l,
            'phone' => '967' . $phone,
            'type' => 2, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ]);

        EMoney::create([
            'user_id' => $u->id, 'current_balance' => '500000',
            'pending_balance' => '0', 'held_balance' => '0',
            'charge_earned' => '0', 'zone_code' => 'SOUTH', 'version' => 0,
        ]);

        // **وجهازٌ مسجَّل** — `CheckDeviceId` تردّ ٤٠٠ لمن لا يُرسل
        // `device-id` و٤٠٣ لمن يُرسل جهازاً غير مسجَّل. وحارسٌ يتخطّى هذه
        // البوّابة يقيس مساراً لا يسلكه أحد.
        \Illuminate\Support\Facades\DB::table('user_log_histories')->insert([
            'user_id' => $u->id, 'device_id' => 'test-device-' . $u->id,
            'ip_address' => '10.0.0.1', 'is_active' => 1, 'is_blocked' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $u;
    }

    /** الخدمةُ تُفحص من الإعدادات — تُفعَّل صراحةً وإلّا قِيس رفضٌ لا سلوك. */
    private function enableRequestMoney(): void
    {
        // `forceFill` لا `updateOrCreate`: النموذجُ يحصر `$fillable`،
        // و`create` تُسقط بصمتٍ ما ليس فيه — فتُنشأ صفٌّ بلا قيمة.
        \Illuminate\Support\Facades\DB::table('business_settings')->updateOrInsert(
            ['key' => 'send_money_request_status'],
            ['value' => 1, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    /** @return array<string,string> */
    private function deviceHeaders(): array
    {
        return [
            'Idempotency-Key' => (string) \Illuminate\Support\Str::ulid(),
            'device-id' => 'test-device-' . $this->me->id,
        ];
    }

    private function receipt(User $counterparty, string $amount, string $type, string $dir): Receipt
    {
        return Receipt::create([
            'receipt_number' => 'RCP-' . strtoupper(bin2hex(random_bytes(4))),
            'verification_code' => strtoupper(bin2hex(random_bytes(3))),
            'receipt_type' => $type,
            'user_id' => $this->me->id,
            'counterparty_user_id' => $counterparty->id,
            'reference_transaction_id' => 'TX' . random_int(100000, 999999),
            'amount' => $amount,
            'fee' => '0',
            'net_amount' => $amount,
            'direction' => $dir,
            'status' => 'pdf_generated',
            'zone_code' => 'SOUTH',
            'issued_at' => now(),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① البحث والفلاتر
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **البحثُ بالهاتف يجد إيصالاتِ ذلك الشخص وحدَه.**
     *
     * وهو أوّلُ ما يُبحث به: الناسُ يحفظون الهاتف لا رقمَ الإيصال.
     */
    public function searching_by_phone_finds_that_persons_receipts_only(): void
    {
        $this->receipt($this->ahmed, '2000', 'send_money', 'debit');
        $this->receipt($this->ahmed, '1000', 'send_money', 'debit');
        $this->receipt($this->sami, '5000', 'send_money', 'debit');

        Passport::actingAs($this->me, [], 'api');

        $items = $this->getJson('/api/v1/amial/receipts?q=777200002')
            ->assertOk()->json('meta.items');

        $this->assertCount(2, $items, sprintf(
            'البحث بالهاتف أعاد %d بدل ٢ — والشاشةُ بلا بحثٍ تُجبر على التمرير',
            count($items),
        ));

        foreach ($items as $it) {
            $this->assertSame($this->ahmed->id, (int) $it['counterparty_user_id'],
                'ظهر إيصالٌ لشخصٍ آخر في نتيجة البحث');
        }
    }

    /**
     * @test
     *
     * **والهاتفُ يُطابَق بصيغه كلِّها.**
     *
     * ══════════════════════════════════════════════════════════════════
     * من ينسخ الرقم من جهات الاتصال يحصل على `+967777200002`، ومن يكتبه
     * بيده يكتب `777200002`، ومن يقرأه من رسالةٍ يكتب `0777200002`.
     *
     * **ومطابقةٌ حرفيّةٌ واحدة تُخرج «لا نتائج» على رقمٍ موجود** — وهو أسوأ
     * من غياب البحث: يُقنع الباحثَ أنّ العمليّة لم تقع.
     */
    public function every_phone_spelling_finds_the_same_receipts(): void
    {
        $this->receipt($this->ahmed, '2000', 'send_money', 'debit');

        Passport::actingAs($this->me, [], 'api');

        foreach ([
            '777200002',
            '0777200002',
            '967777200002',
            '+967777200002',
            '200002',
        ] as $spelling) {
            $items = $this->getJson('/api/v1/amial/receipts?q=' . urlencode($spelling))
                ->assertOk()->json('meta.items');

            $this->assertCount(1, $items, sprintf(
                'الصيغة «%s» لم تجد الإيصال — والباحثُ يظنّ العمليّة لم تقع',
                $spelling,
            ));
        }
    }

    /**
     * @test
     *
     * **والبحثُ بالاسم يعمل كذلك** — فمن لا يحفظ الرقم يحفظ الاسم.
     */
    public function searching_by_name_works(): void
    {
        $this->receipt($this->ahmed, '2000', 'send_money', 'debit');
        $this->receipt($this->sami, '5000', 'send_money', 'debit');

        Passport::actingAs($this->me, [], 'api');

        $items = $this->getJson('/api/v1/amial/receipts?q=' . urlencode('أحمد'))
            ->assertOk()->json('meta.items');

        $this->assertCount(1, $items, 'البحث بالاسم لا يعمل');
    }

    /**
     * @test
     *
     * **والفلاتر تُطبَّق فعلاً — لا تُعرض وتُتجاهل.**
     */
    public function direction_amount_and_type_filters_actually_filter(): void
    {
        $this->receipt($this->ahmed, '2000', 'send_money', 'debit');
        $this->receipt($this->ahmed, '9000', 'send_money', 'credit');
        $this->receipt($this->sami, '500', 'cash_out', 'debit');

        Passport::actingAs($this->me, [], 'api');

        $cases = [
            'direction=credit' => 1,
            'direction=debit' => 2,
            'type=cash_out' => 1,
            'min_amount=1000' => 2,
            'max_amount=1000' => 1,
            'direction=debit&min_amount=1000' => 1,
        ];

        foreach ($cases as $qs => $expected) {
            $items = $this->getJson("/api/v1/amial/receipts?{$qs}")
                ->assertOk()->json('meta.items');

            $this->assertCount($expected, $items,
                "الفلتر «{$qs}» أعاد " . count($items) . " بدل {$expected} — يُعرض ولا يُطبَّق");
        }
    }

    /**
     * @test
     *
     * **وبحثٌ لا يطابق أحداً يُرجع فراغاً — لا كلَّ شيء.**
     *
     * فقائمةٌ فارغةٌ من المطابقين لو أُهملت لألغت الشرطَ كلَّه، **فيظهر
     * كلُّ السجلّ على أنّه نتيجةُ البحث** — وهو أسوأ من لا نتائج.
     */
    public function a_search_that_matches_nobody_returns_nothing(): void
    {
        $this->receipt($this->ahmed, '2000', 'send_money', 'debit');
        $this->receipt($this->sami, '5000', 'send_money', 'debit');

        Passport::actingAs($this->me, [], 'api');

        $items = $this->getJson('/api/v1/amial/receipts?q=' . urlencode('لا أحد بهذا الاسم'))
            ->assertOk()->json('meta.items');

        $this->assertCount(0, $items,
            'بحثٌ بلا مطابق أعاد ' . count($items) . ' — الشرطُ أُلغي وعُرض كلُّ السجلّ');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② الطلبُ المباشر — مسار 6cash
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **طلبُ مالٍ من شخصٍ بهاتفه يصل إليه — بلا رابطٍ ولا QR.**
     *
     * ══════════════════════════════════════════════════════════════════
     * نقطةُ النهاية هذه **حيّةٌ منذ البداية ولم ينادِ عليها التطبيق قطّ**:
     * الثابتُ `customerRequestMoney` مكتوبٌ في `app_constants.dart`، ولا
     * سطرَ واحدٌ يستعمله. (مبنيٌّ ولا يُوصَل إليه.)
     */
    public function requesting_money_from_a_person_by_phone_reaches_them(): void
    {
        $this->enableRequestMoney();

        Passport::actingAs($this->me, [], 'api');

        // **الترويسات كما يرسلها الجهاز، لا مخفَّفة.**
        //
        // `Idempotency-Key` يولّده `ApiClient.postData` تلقائيّاً، و`device-id`
        // يُرسَل مع كلّ نداء. وحارسٌ يُسقطهما يقيس مساراً لا يسلكه أحد.
        $r = $this->withHeaders($this->deviceHeaders())
            ->postJson('/api/v1/customer/request-money', [
                'phone' => '777200002',
                'amount' => '20000',
                'note' => 'حصتي من الغداء',
            ]);

        $this->assertSame(200, $r->status(), sprintf(
            "طلبُ المال المباشر رُفض: %s\nوهذا هو المسار الأبسط الذي طُلب إحياؤه.",
            (string) ($r->json('message') ?? $r->getContent()),
        ));

        // **ويظهر عند المطلوب منه** — وإلّا فهو صفٌّ في جدولٍ لا طلب.
        $this->assertDatabaseHas('request_money', [
            'from_user_id' => $this->me->id,
            'to_user_id' => $this->ahmed->id,
            'type' => 'pending',
        ]);
    }

    /**
     * @test
     *
     * **والرفضُ يقول سببَه — لا «فشل».**
     *
     * فمن يكتب رقماً غيرَ مسجَّل يجب أن يعرف أنّ الرقم غيرُ مسجَّل، لا أن
     * يجرّب ثلاث مرّاتٍ ثمّ يتّصل بالدعم.
     */
    public function a_refused_request_says_why(): void
    {
        $this->enableRequestMoney();

        Passport::actingAs($this->me, [], 'api');

        $r = $this->withHeaders($this->deviceHeaders())
            ->postJson('/api/v1/customer/request-money', [
                'phone' => '777999999',   // غير مسجَّل
                'amount' => '5000',
            ]);

        $this->assertNotSame(200, $r->status());

        $msg = (string) ($r->json('message') ?? '');

        $this->assertNotEmpty($msg, 'رفضٌ بلا رسالة — والمستعمل يرى فراغاً');

        $this->assertStringContainsString('غير موجود', $msg,
            "الرسالة لا تدلّ على السبب: «{$msg}»");
    }
}
