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

    /**
     * AMIAL-TEST-POLLUTION-001 — **يُنظّف بعده لا قبله وحدَه.**
     *
     * ══════════════════════════════════════════════════════════════════
     * هذا الملفُّ وحدَه في المشروع يستعمل `DatabaseTruncation` — وهو
     * ضروريٌّ هنا: يفحص سلوكَ `after_commit`، ولا يعمل داخل معاملةٍ
     * تلفّه كما تفعل `RefreshDatabase`.
     *
     * **والثمن الذي دُفع:** `DatabaseTruncation` تُفرّغ **قبل** كلّ
     * اختبارٍ لا بعده، فصفوفُه تبقى **مُثبَتةً** في القاعدة. والاختبارُ
     * التالي — بـ`RefreshDatabase` — يفتح معاملةً فوق قاعدةٍ فيها
     * إيصالاتُ هذا الملفّ، فيقرأ `Receipt::count()` واحداً حيث يتوقّع
     * صفراً.
     *
     * **وثمانيةُ اختباراتٍ كانت تسقط بهذا وتمرّ منفردةً** — وهي أخبثُ
     * صور السقوط: الشيفرةُ سليمةٌ والترتيبُ هو الجاني، فيضيع الوقتُ في
     * ملاحقة ميزةٍ لا عيبَ فيها.
     *
     * **وجداولُ المرجع لا تُمسّ.** الأدوارُ والتصنيفاتُ وجداولُ الرسوم
     * تُزرع بهجراتٍ تجري مرّةً واحدة، ومسحُها لا يُعاد ملؤه — فأوّلُ
     * محاولةِ إسنادِ دورٍ بعده تسقط بـ«role_id cannot be null».
     *
     * (وقع هذا فعلاً في أوّل صياغةٍ لهذا التنظيف: أصلحتُ ثمانيةَ سقوطٍ
     * فأحدثتُ ستّة.)
     * ══════════════════════════════════════════════════════════════════
     */
    protected array $exceptTables = [
        'migrations',
        'roles', 'permissions', 'role_permissions',
        'charity_categories',
        'fee_schemes', 'zones',
    ];

    /**
     * **وسلسلةُ التدقيق تُصفَّر رأساً وصفوفاً معاً.**
     *
     * `audit_chain_head.last_hash` يشير إلى بصمة آخر صفّ. فتفريغُ الصفوف
     * وحدَها يترك الرأسَ يشير إلى معدوم، ويُبنى ما بعده فوق أساسٍ لا وجود
     * له — فيردّ `amial:audit-verify` كسراً على سلسلةٍ لم يعبث بها أحد،
     * **ويسقط اختبارُ العبث قبل أن يعبث**.
     */
    protected function tearDownAuditChain(): void
    {
        \Illuminate\Support\Facades\DB::table('audit_decisions')->delete();

        // **يُعاد إلى نقطة البدء لا يُحذف.** الإدراجُ يقرأ الرأسَ ويُحدّثه
        // ولا يُنشئه، فحذفُ صفّه يجعل السلسلةَ تُبنى بلا رأسٍ يتقدّم —
        // ويسقط الفحصُ حتّى منفرداً. (جُرّب فسقط، فأُعيد التصفيرُ بدل الحذف.)
        \Illuminate\Support\Facades\DB::table('audit_chain_head')
            ->where('id', 1)
            ->update(['last_hash' => hash('sha256', 'AMIAL-AUDIT-CHAIN-GENESIS')]);
    }

    protected function tearDown(): void
    {
        $this->truncateDatabaseTables();
        $this->tearDownAuditChain();

        parent::tearDown();
    }

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
