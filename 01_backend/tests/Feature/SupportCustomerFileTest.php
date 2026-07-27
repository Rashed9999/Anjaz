<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AMIAL-SUPPORT-DOCS-002 — ملفّ العميل كما يحتاجه موظّف الدعم.
 *
 * **السؤال الذي كان بلا جواب:** «أين إيصالي؟». الإيصال يظهر في قائمة
 * العميل بينما ملفّه غير مولَّد، فيفشل تنزيله ولا يعرف الدعم لماذا —
 * والجواب كان في عمود `pdf_storage_path` لا يقرؤه أحد في اللوحة.
 *
 * وثلاثة ضوابط تُفحص معه: أن يُحرس فتح الملفّ بصلاحية، وأن يُسجَّل الوصول
 * إلى البيانات الشخصية، وأن تُقال الحقيقة عن الملفّ لا الحقل.
 */
class SupportCustomerFileTest extends TestCase
{
    use RefreshDatabase;

    private function operator(string $roleCode): User
    {
        $user = User::factory()->create(['type' => 0, 'zone_code' => 'SOUTH']);
        $roleId = DB::table('roles')->whereNull('merchant_user_id')
            ->where('code', $roleCode)->value('id');
        DB::table('admin_user_roles')->insert([
            'user_id' => $user->id, 'role_id' => $roleId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user->fresh();
    }

    private function customer(): User
    {
        $u = User::factory()->create(['type' => 2, 'zone_code' => 'SOUTH']);
        EMoney::create([
            'user_id' => $u->id, 'current_balance' => '5000.0000',
            'held_balance' => '0.0000', 'pending_balance' => '0.0000',
            'charge_earned' => '0.0000', 'zone_code' => 'SOUTH',
        ]);

        return $u;
    }

    private function receipt(User $u, array $overrides = []): Receipt
    {
        return Receipt::create(array_merge([
            'receipt_number' => '2607' . random_int(10000000, 99999999),
            'verification_code' => (string) random_int(10000000, 99999999),
            'receipt_type' => 'send_money',
            'user_id' => $u->id,
            'reference_transaction_id' => 'TX-' . random_int(1, 9999),
            'reference_type' => 'transaction',
            'reference_id' => random_int(1, 9999),
            'amount' => '1000.0000', 'fee' => '0.0000', 'net_amount' => '1000.0000',
            'direction' => 'debit', 'status' => 'pending_pdf',
            'zone_code' => 'SOUTH', 'issued_at' => now(),
        ], $overrides));
    }

    // ── ما يحتاجه الدعم ────────────────────────────────────────────────

    public function test_the_file_shows_receipts_and_which_have_no_document_yet(): void
    {
        Storage::fake('local');
        $customer = $this->customer();
        $this->receipt($customer);
        $this->receipt($customer);

        $docs = $this->actingAs($this->operator('platform_support'), 'user')
            ->getJson("/admin/support-center/customers/{$customer->id}")
            ->assertOk()->json('meta.documents');

        $this->assertSame(2, $docs['receipts_without_file'],
            'الدعم لا يرى أن ملفّات الإيصالات غير مولَّدة — وهو سبب فشل التنزيل');
        $this->assertCount(2, $docs['recent']);
        $this->assertFalse($docs['recent'][0]['file_ready']);
    }

    /**
     * الحقيقة لا الحقل: المسار محفوظ والملفّ ذهب مع نشرة على تخزين مؤقّت.
     *
     * والفرق بينهما هو الفرق بين «سيُفتح فوراً» و«سيُصيَّر الآن وقد يُقطع» —
     * وهو ما يفسّر للدعم لماذا نجح التنزيل أمس وفشل اليوم.
     */
    public function test_a_stored_path_whose_file_vanished_is_reported_as_not_ready(): void
    {
        Storage::fake('local');
        $customer = $this->customer();
        $this->receipt($customer, [
            'status' => 'pdf_generated',
            'pdf_storage_path' => 'receipts/2026/07/ghost.pdf',
        ]);

        $docs = $this->actingAs($this->operator('platform_support'), 'user')
            ->getJson("/admin/support-center/customers/{$customer->id}")
            ->assertOk()->json('meta.documents');

        $this->assertFalse($docs['recent'][0]['file_ready'],
            'قيل «جاهز» والملفّ غير موجود — يُوعد العميل بتنزيل سيفشل');
        $this->assertSame(0, $docs['receipts_without_file'],
            'العدّاد يقيس المسار المحفوظ، والجاهزية تقيس الملفّ — وهما سؤالان');
    }

    public function test_a_generated_receipt_is_reported_ready(): void
    {
        Storage::fake('local');
        $customer = $this->customer();
        Storage::disk('local')->put('receipts/2026/07/real.pdf', '%PDF-1.4 x');
        $this->receipt($customer, [
            'status' => 'pdf_generated',
            'pdf_storage_path' => 'receipts/2026/07/real.pdf',
        ]);

        $docs = $this->actingAs($this->operator('platform_support'), 'user')
            ->getJson("/admin/support-center/customers/{$customer->id}")
            ->assertOk()->json('meta.documents');

        $this->assertTrue($docs['recent'][0]['file_ready']);
    }

    /** عميل بلا إيصالات لا يُسقط الصفحة — أوّل يوم لكل حساب. */
    public function test_a_customer_with_no_receipts_is_fine(): void
    {
        Storage::fake('local');
        $customer = $this->customer();

        $docs = $this->actingAs($this->operator('platform_support'), 'user')
            ->getJson("/admin/support-center/customers/{$customer->id}")
            ->assertOk()->json('meta.documents');

        $this->assertSame(0, $docs['receipts_without_file']);
        $this->assertSame([], $docs['recent']);
    }

    // ── الضوابط ────────────────────────────────────────────────────────

    /** الصيانة لا تقرأ ملفّات العملاء: أقلّ امتياز يعني أقلّ ما يكفي. */
    public function test_maintenance_cannot_open_a_customer_file(): void
    {
        $this->actingAs($this->operator('platform_maintenance'), 'user')
            ->getJson("/admin/support-center/customers/{$this->customer()->id}")
            ->assertStatus(403);
    }

    /**
     * فتح الملفّ يُسجَّل في سجلّ الوصول إلى البيانات الشخصية.
     *
     * الصلاحية تقول «يجوز له» والسجلّ يقول «فعلها». وبينهما فرقٌ يظهر عند
     * أوّل شكوى تسريب: من فتح ملفّ فلان ومتى وكم مرّة.
     */
    public function test_opening_a_customer_file_is_logged_as_pii_access(): void
    {
        Storage::fake('local');
        $support = $this->operator('platform_support');
        $customer = $this->customer();

        $this->actingAs($support, 'user')
            ->getJson("/admin/support-center/customers/{$customer->id}")
            ->assertOk();

        $this->assertDatabaseHas('pii_access_logs', [
            'actor_user_id' => $support->id,
            'subject_type' => 'user',
            'subject_id' => $customer->id,
            'field_name' => 'support_customer_file',
        ]);
    }

    /** ولا يُسجَّل وصولٌ لم يقع: عميل غير موجود لا يترك أثراً كاذباً. */
    public function test_a_missing_customer_leaves_no_access_record(): void
    {
        $this->actingAs($this->operator('platform_support'), 'user')
            ->getJson('/admin/support-center/customers/999999')
            ->assertStatus(404);

        $this->assertDatabaseCount('pii_access_logs', 0);
    }
}
