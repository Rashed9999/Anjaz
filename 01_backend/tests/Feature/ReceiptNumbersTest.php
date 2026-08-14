<?php

namespace Tests\Feature;

use App\Models\Receipt;
use App\Models\User;
use App\Support\ReadableCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AMIAL-RECEIPT-NUMBERS-001 — أرقام يقرؤها الإنسان.
 *
 * كان على الإشعار ثلاثة رموز:
 *   رقم الإشعار   AMY-20260725-HUW8YUD1  (21 خانة بحروف)
 *   رقم المرجع    01KYCSWXQDYM7R5M0AG46ZTMYH (ULID داخلي — بلا جمهور)
 *   كود التحقق    KNMX9A9UQPJM29D8 (16 حرفاً)
 *
 * ولا واحد منها يستطيع عميل إملاءه على الهاتف لموظّف الدعم، وهو الغرض
 * الأول من رقم الإشعار. صارت أرقاماً بحتة مجموعةً، وحُذف المرجع اليتيم.
 */
class ReceiptNumbersTest extends TestCase
{
    use RefreshDatabase;

    private function issue(array $overrides = []): Receipt
    {
        $user = User::factory()->create(['type' => 2]);

        return app(\App\Services\ReceiptService::class)->issueDebit(array_merge([
            'receipt_type' => 'send_money',
            'user_id' => $user->id,
            'reference_transaction_id' => (string) Str::ulid(),
            'amount' => '2000.0000',
            'fee' => '0.0000',
        ], $overrides));
    }

    public function test_receipt_number_is_digits_only(): void
    {
        $r = $this->issue();

        $this->assertMatchesRegularExpression('/^[0-9]{12}$/', $r->receipt_number);
        $this->assertStringNotContainsString('AMY', $r->receipt_number);
    }

    public function test_receipt_number_starts_with_the_date(): void
    {
        // التاريخ في الصدر يجعله مرتّباً زمنياً ومفيداً لموظّف الدعم.
        $this->assertStringStartsWith(now()->format('ymd'), $this->issue()->receipt_number);
    }

    public function test_verification_code_is_digits_only(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9]{16}$/', $this->issue()->verification_code);
    }

    public function test_receipt_numbers_do_not_collide(): void
    {
        $numbers = [];
        for ($i = 0; $i < 25; $i++) {
            $numbers[] = $this->issue()->receipt_number;
        }

        $this->assertCount(25, array_unique($numbers), 'كل إيصال رقم فريد');
    }

    // ===================== التجميع والتطبيع =====================

    public function test_numbers_are_grouped_for_reading(): void
    {
        $this->assertSame('260726 481037', ReadableCode::group('260726481037'));
        $this->assertSame('1234 5678 9012 3456', ReadableCode::group('1234567890123456', 4));
    }

    public function test_legacy_codes_are_left_untouched_by_grouping(): void
    {
        // أوراق مطبوعة بالفعل — تجميعها يشوّهها ولا يفيد.
        $this->assertSame('AMY-20260725-HUW8YUD1', ReadableCode::group('AMY-20260725-HUW8YUD1'));
    }

    public function test_normalize_strips_what_the_customer_types(): void
    {
        $this->assertSame('260726481037', ReadableCode::normalize('260726 481037'));
        $this->assertSame('260726481037', ReadableCode::normalize('260726-481037'));
        $this->assertSame('KNMX9A9UQPJM29D8', ReadableCode::normalize('knmx9a9u qpjm29d8'));
    }

    // ===================== التحقّق العام =====================

    public function test_new_numeric_code_verifies(): void
    {
        $r = $this->issue();

        $this->getJson("/api/v1/amial/v/{$r->verification_code}")
            ->assertOk()
            ->assertJsonPath('code', 'VERIFICATION_OK');
    }

    public function test_customer_may_type_the_code_with_spaces(): void
    {
        // يكتب ما يراه على الورقة. رفضه بسبب مسافة يجعل الميزة بلا فائدة.
        $r = $this->issue();
        $spaced = ReadableCode::group($r->verification_code, 4);

        $this->getJson('/api/v1/amial/v/' . rawurlencode($spaced))
            ->assertOk()
            ->assertJsonPath('code', 'VERIFICATION_OK');
    }

    public function test_legacy_base32_code_still_verifies(): void
    {
        // مستند مالي بيد عميل لا يُبطَل بتحديث برمجي.
        $r = $this->issue();
        $r->verification_code = 'KNMX9A9UQPJM29D8';
        $r->save();

        $this->getJson('/api/v1/amial/v/KNMX9A9UQPJM29D8')
            ->assertOk()
            ->assertJsonPath('code', 'VERIFICATION_OK');
    }

    public function test_unknown_code_is_not_found(): void
    {
        $this->getJson('/api/v1/amial/v/9999999999999999')->assertStatus(404);
    }

    public function test_public_verification_is_rate_limited(): void
    {
        // النقطة عامّة بلا مصادقة وكانت بلا أي حدّ — تُجرَّب الأكواد بالقوة
        // الغاشمة بلا مانع. الحدّ شرطُ أمانِ الكود الرقمي، لا تحسيناً.
        $hitLimit = false;

        for ($i = 0; $i < 40; $i++) {
            $code = str_pad((string) $i, 16, '0', STR_PAD_LEFT);
            if ($this->getJson("/api/v1/amial/v/{$code}")->status() === 429) {
                $hitLimit = true;
                break;
            }
        }

        $this->assertTrue($hitLimit, 'التجريب الآلي يجب أن يُوقَف');
    }

    // ===================== المرجع اليتيم =====================

    public function test_the_internal_ulid_is_not_printed_on_the_notice(): void
    {
        $ulid = (string) Str::ulid();
        $r = $this->issue(['reference_transaction_id' => $ulid]);

        $narrative = app(\App\Services\ReceiptNoticeService::class)->narrative($r);

        $this->assertStringNotContainsString($ulid, $narrative);
        $this->assertStringNotContainsString('رقم المرجع', $narrative);
    }

    // ===================== بحث الدعم =====================

    /**
     * **حسابُ إدارةٍ بدرجةٍ حقيقيّة** — AMIAL-DOCUMENTS-002.
     *
     * كان الاختباران يدخلان بحسابِ `ADMIAL_TYPE` بلا درجةٍ إطلاقاً، وكانا
     * يمرّان — **لأنّ مركز الدعم كان مفتوحاً بلا صلاحيّة**. ثمّ أُغلق
     * (`platform.customers.view`) فسقطا.
     *
     * فاختبارٌ يسقط عند إغلاق ثغرةٍ كان يُثبتها لا يُصلَح بفتحها ثانيةً:
     * يُعطى الحسابُ درجتَه. (وقع هذا نفسُه في `AgentNetworkApiTest`.)
     */
    private function supportOperator(): User
    {
        $u = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'admin']);
        app(\App\Services\PlatformRoleService::class)
            ->assign($u, \App\Services\PlatformRoleService::SUPPORT);

        return $u;
    }

    public function test_support_finds_a_receipt_by_its_printed_grouped_number(): void
    {
        $admin = $this->supportOperator();
        $r = $this->issue();
        $printed = ReadableCode::group($r->receipt_number);

        $found = $this->actingAs($admin, 'user')
            ->getJson('/admin/support-center/search?q=' . rawurlencode($printed))
            ->assertOk()
            ->json('meta.receipts');

        $this->assertNotEmpty($found, 'الرقم كما هو مطبوع يجب أن يُوجد');
    }

    public function test_support_finds_a_receipt_by_verification_code(): void
    {
        $admin = $this->supportOperator();
        $r = $this->issue();

        $found = $this->actingAs($admin, 'user')
            ->getJson('/admin/support-center/search?q=' . $r->verification_code)
            ->assertOk()
            ->json('meta.receipts');

        $this->assertNotEmpty($found);
    }
}
