<?php

namespace Tests\Feature;

use App\Jobs\GeneratePdfReceiptJob;
use App\Models\Receipt;
use App\Models\User;
use App\Services\ReceiptService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** AMIAL-DOCUMENTS-001 - يختبر أثر ما بعد commit خارج معاملة الاختبار. */
class ReceiptDocumentAfterCommitTest extends TestCase
{
    // ══════════════════════════════════════════════════════════════════
    //  **لماذا `DatabaseTruncation` لا `DatabaseMigrations`** — AMIAL-DOCUMENTS-002.
    //
    //  هذا الاختبار يقيس أثراً يقع **بعد** الـcommit، فلا يصلح معه
    //  `RefreshDatabase` (يلفّ كلّ حالةٍ في معاملةٍ لا تُغلَق أبداً).
    //  فاختير `DatabaseMigrations` — وهو **يتراجع** عن كلّ الهجرات بعد
    //  كلّ حالة.
    //
    //  **والتراجعُ لا يمرّ**: في سجلّ الهجرات دوالُّ `down()` لا تعمل على
    //  قاعدةٍ فيها بيانات — تعدادٌ يُضيَّق وفيه قيمةٌ خارجُه، وفهرسٌ
    //  يُحذف ولم يُنشأ. فسقط الاختبار **بعد نجاح تأكيداته السبع**،
    //  ورسالتُه تتحدّث عن `users_phone_blind_index` — عمودٍ لا علاقةَ له
    //  بالإيصالات. (رسالةٌ لا تدلّ على سببها — الصنفُ الأكثر تكراراً هنا.)
    //
    //  و`DatabaseTruncation` يُعطي ما يحتاجه: هجرةٌ مرّةً واحدة، وcommit
    //  حقيقيّ، وتفريغُ الجداول بين الحالات — **بلا تراجع**.
    use DatabaseTruncation;

    /** @test ربط سجل البيع يبطل النسخة المطبوعة ويعيد الطابور فقط. */
    public function attaching_a_sale_invalidates_only_the_printed_copy(): void
    {
        Queue::fake();
        Storage::fake('local');
        $customer = User::factory()->create(['type' => CUSTOMER_TYPE, 'phone' => '967770071031']);
        $merchant = User::factory()->create(['type' => MERCHANT_TYPE, 'phone' => '967770071032']);
        $path = 'receipts/old.pdf';
        Storage::disk('local')->put($path, '%PDF-old');

        $receipt = Receipt::create([
            'receipt_number' => '260813710031',
            'verification_code' => '7300000000000001',
            'receipt_type' => 'pos_payment',
            'user_id' => $customer->id,
            'counterparty_user_id' => $merchant->id,
            'reference_transaction_id' => 'TX-LINK-DOC',
            'amount' => '5000.0000',
            'fee' => '0.0000',
            'net_amount' => '5000.0000',
            'direction' => 'debit',
            'status' => 'pdf_generated',
            'pdf_storage_path' => $path,
            'metadata' => ['print_document_version' => '1'],
            'zone_code' => 'SOUTH',
            'issued_at' => now(),
        ]);

        $count = app(ReceiptService::class)->attachBusinessReference(
            'TX-LINK-DOC', 'merchant_sale', 99, ['merchant_vertical' => 'retail'],
        );

        $fresh = $receipt->fresh();
        $this->assertSame(1, $count);
        $this->assertSame('merchant_sale', $fresh->reference_type);
        $this->assertSame(99, $fresh->reference_id);
        $this->assertSame('pending_pdf', $fresh->status);
        $this->assertNull($fresh->pdf_storage_path);
        Storage::disk('local')->assertMissing($path);
        Queue::assertPushed(GeneratePdfReceiptJob::class);
    }
}
