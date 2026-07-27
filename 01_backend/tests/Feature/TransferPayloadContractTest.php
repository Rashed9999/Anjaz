<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\PendingTransfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-TRANSFER-CONTRACT-001 — العقد بين ردود التحويل وما يقرؤه التطبيق.
 *
 * PendingTransferServiceTest يفحص الخدمة: هل حُجز المبلغ، هل استُرد عند
 * الإلغاء، هل يُقفل الحساب بعد ثلاث محاولات. وهو فحص المنطق المالي وحده.
 *
 * وهذا الملفّ يفحص ما لا يفحصه: **شكل الاستجابة**. فالتطبيق لا ينادي الخدمة
 * بل ينادي المسار ويقرأ مفاتيح بعينها من meta. ومفتاحٌ يُعاد تسميته أو
 * يُنسى لا يُسقط أي اختبار خدمة — يُنتج null صامتاً في يد المستخدم: مبلغ
 * لا يظهر، أو عدّاد إلغاء لا يبدأ، أو زرّ تراجع لا يعمل.
 *
 * وقد وقع هذا الصنف مرّتين في يوم واحد (unique_id ثم zone_code في استجابة
 * الدخول). ولأن التحويل يحرّك مالاً، فوقوعه هنا أغلى.
 *
 * المفاتيح المفحوصة مستخرَجة من الشاشات نفسها، لا من الخيال:
 * amial_send_money_screen.dart و amial_transfer_holding_screen.dart.
 */
class TransferPayloadContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('amial.encryption.pii_key', base64_encode(random_bytes(32)));
        config()->set('amial.encryption.blind_index_key', base64_encode(random_bytes(32)));
    }

    private function makeUser(string $balance, array $extra = []): User
    {
        $user = User::factory()->create(array_merge([
            'zone_code' => 'SOUTH',
            'kyc_tier' => 3,
            'sanction_status' => 'clear',
            'sanction_checked' => true,
            'transaction_pin' => Hash::make('1234'),
            'pin_failed_attempts' => 0,
            'pin_locked_until' => null,
        ], $extra));
        EMoney::create([
            'user_id' => $user->id, 'current_balance' => $balance, 'zone_code' => 'SOUTH',
        ]);
        return $user;
    }

    /** يُنشئ تحويلاً معلّقاً حقيقياً عبر الخدمة — أسرع من المرور بالمسار. */
    private function holding(User $sender, User $recipient, string $amount = '1000'): PendingTransfer
    {
        return app(\App\Services\PendingTransferService::class)
            ->initiate($sender, $recipient, $amount, '1234');
    }

    // ── التحقّق من المستلِم ────────────────────────────────────────────

    public function test_verify_recipient_returns_every_key_the_app_reads(): void
    {
        $sender = $this->makeUser('10000');
        $recipient = $this->makeUser('0', ['phone' => '967771230321', 'f_name' => 'محمد', 'l_name' => 'الأهدل']);

        $meta = $this->actingAs($sender, 'api')
            ->postJson('/api/v1/amial/transfer/verify-recipient', ['phone' => '967771230321'])
            ->assertOk()->json('meta');

        foreach (['verification_token', 'recipient_id', 'masked_name', 'masked_phone'] as $key) {
            $this->assertArrayHasKey($key, $meta,
                "التطبيق يقرأ meta.$key والخادم لا يرسله — يقرأ null صامتاً");
        }
    }

    /**
     * التقنيع ليس تجميلاً: الشاشة تعرض هذا النصّ لمن أدخل رقماً قد يكون
     * خاطئاً. فلو ظهر الاسم كاملاً صار البحث برقم عشوائي وسيلةً لكشف أسماء
     * أصحاب الأرقام.
     */
    public function test_the_recipient_name_and_phone_are_actually_masked(): void
    {
        $sender = $this->makeUser('10000');
        $this->makeUser('0', ['phone' => '967771230321', 'f_name' => 'محمد', 'l_name' => 'الأهدل']);

        $meta = $this->actingAs($sender, 'api')
            ->postJson('/api/v1/amial/transfer/verify-recipient', ['phone' => '967771230321'])
            ->assertOk()->json('meta');

        $this->assertStringContainsString('*', $meta['masked_name'],
            'الاسم غير مقنَّع — البحث برقم عشوائي يكشف صاحبه');
        $this->assertStringContainsString('*', $meta['masked_phone']);
        $this->assertNotSame('967771230321', $meta['masked_phone']);
    }

    // ── بدء التحويل ────────────────────────────────────────────────────

    public function test_the_holding_screen_gets_what_it_needs_to_count_down(): void
    {
        $sender = $this->makeUser('10000');
        $recipient = $this->makeUser('0');
        $pending = $this->holding($sender, $recipient, '1500');

        $meta = $this->actingAs($sender, 'api')
            ->getJson("/api/v1/amial/transfer/{$pending->transfer_ulid}/status")
            ->assertOk()->json('meta');

        foreach (['transfer_ulid', 'status', 'amount', 'seconds_remaining'] as $key) {
            $this->assertArrayHasKey($key, $meta, "ينقص meta.$key");
        }

        // العدّاد هو ما يمنح المستخدم فرصة التراجع. صفرٌ أو null يعني شاشة
        // انتظار لا تنتهي أو زرّ إلغاء يختفي فوراً.
        $this->assertIsInt($meta['seconds_remaining']);
        $this->assertGreaterThan(0, $meta['seconds_remaining']);
        $this->assertSame('holding', $meta['status']);
    }

    /** المبلغ نصّ لا رقم عائم — كسور العملة لا تُترك لدقّة الفاصلة. */
    public function test_the_amount_is_returned_as_a_string(): void
    {
        $sender = $this->makeUser('10000');
        $pending = $this->holding($sender, $this->makeUser('0'), '1500');

        $amount = $this->actingAs($sender, 'api')
            ->getJson("/api/v1/amial/transfer/{$pending->transfer_ulid}/status")
            ->json('meta.amount');

        $this->assertIsString($amount);
        $this->assertSame('1500.0000', $amount);
    }

    // ── الإلغاء ────────────────────────────────────────────────────────

    public function test_cancel_returns_the_refunded_amount_the_app_shows(): void
    {
        $sender = $this->makeUser('10000');
        $pending = $this->holding($sender, $this->makeUser('0'), '1500');

        $meta = $this->actingAs($sender, 'api')
            ->postJson("/api/v1/amial/transfer/{$pending->transfer_ulid}/cancel")
            ->assertOk()->json('meta');

        foreach (['transfer_ulid', 'status', 'refunded'] as $key) {
            $this->assertArrayHasKey($key, $meta, "ينقص meta.$key");
        }
        $this->assertSame('cancelled', $meta['status']);
        // التطبيق يعرض «استُرد لك كذا». صفرٌ هنا يُفزع المستخدم بلا سبب.
        $this->assertSame('1500.0000', $meta['refunded']);
    }

    /**
     * تحويل غيرك لا يُلغى — ولا يُعترف بوجوده.
     *
     * المنع نفسه كان قائماً في الخدمة، لكن المسار كان يبحث بالمعرّف وحده
     * فيردّ 422 «غير مصرّح» على تحويل قائم و404 على غير القائم. والفرق بين
     * الردّين يُخبر من يجرّب معرّفات أن هذا المعرّف حقيقي — وهو ما لا داعي
     * لقوله. صار البحث مقيَّداً بالمرسل كما في status.
     */
    public function test_another_users_transfer_cannot_be_cancelled_or_probed(): void
    {
        $sender = $this->makeUser('10000');
        $pending = $this->holding($sender, $this->makeUser('0'), '1500');

        $this->actingAs($this->makeUser('500'), 'api')
            ->postJson("/api/v1/amial/transfer/{$pending->transfer_ulid}/cancel")
            ->assertStatus(404);

        // ويبقى معلّقاً — المحاولة الفاشلة لا تمسّ المال.
        $this->assertSame('holding', $pending->fresh()->status);
    }

    public function test_an_unknown_ulid_answers_exactly_like_someone_elses(): void
    {
        $this->actingAs($this->makeUser('500'), 'api')
            ->postJson('/api/v1/amial/transfer/01JZZZZZZZZZZZZZZZZZZZZZZZ/cancel')
            ->assertStatus(404);
    }

    /** حالة تحويل غيرك لا تُقرأ — المبلغ والمستلِم بيانات طرفين. */
    public function test_another_users_transfer_status_is_not_readable(): void
    {
        $sender = $this->makeUser('10000');
        $pending = $this->holding($sender, $this->makeUser('0'), '1500');

        $this->actingAs($this->makeUser('500'), 'api')
            ->getJson("/api/v1/amial/transfer/{$pending->transfer_ulid}/status")
            ->assertStatus(404);
    }

    // ── الغلاف الموحّد ─────────────────────────────────────────────────

    /**
     * التطبيق يفحص success قبل قراءة meta في كل شاشة. غيابه يجعل الفشل
     * يُقرأ نجاحاً — وفي التحويل يعني إظهار «تمّ» على عملية لم تقع.
     */
    public function test_every_transfer_response_carries_the_success_flag(): void
    {
        $sender = $this->makeUser('10000');
        $recipient = $this->makeUser('0', ['phone' => '967771230321']);
        $pending = $this->holding($sender, $recipient, '1000');

        $calls = [
            $this->actingAs($sender, 'api')->postJson(
                '/api/v1/amial/transfer/verify-recipient', ['phone' => '967771230321']),
            $this->actingAs($sender, 'api')->getJson(
                "/api/v1/amial/transfer/{$pending->transfer_ulid}/status"),
            $this->actingAs($sender, 'api')->postJson(
                "/api/v1/amial/transfer/{$pending->transfer_ulid}/cancel"),
        ];

        foreach ($calls as $i => $response) {
            $body = $response->json();
            $this->assertArrayHasKey('success', $body, "الاستدعاء #$i بلا success");
            $this->assertTrue($body['success']);
            $this->assertArrayHasKey('meta', $body);
            $this->assertArrayHasKey('message', $body);
        }
    }
}
