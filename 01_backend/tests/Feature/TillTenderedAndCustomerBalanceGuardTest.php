<?php

namespace Tests\Feature;

use App\Models\CustomerCreditAccount;
use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\User;
use App\Services\CashierService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-CASH-TENDERED-001 · AMIAL-CREDIT-AT-TILL-001 — **فكرتان من
 * شاشاتٍ منافسة، مُطبَّقتان على قطاعاتها.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * أرسل صاحبُ المشروع شاشاتِ تطبيقٍ محاسبيٍّ لتاجر التجزئة والجملة وسأل
 * عمّا يستحقّ الأخذ. وقِيست كلُّ فكرةٍ مقابل ما هو مبنيٌّ، فخرجت اثنتان
 * لا وجودَ لهما في أميال إطلاقاً:
 *
 * ① **«المبلغ المستلم»** — ولا حقلَ له ولا للباقي. **والكاشيرُ يحسب
 *   الباقيَ في رأسه في كلّ بيعةٍ نقديّة** والزبونُ واقف. ورقمٌ يُقال
 *   شفاهاً ولا يُطبَع لا يُدافَع عنه بعد ساعة.
 *
 * ② **رصيدُ الحساب بجانب اسمه لحظةَ اختياره** — ودفترُ الديون في أميال
 *   شاشةٌ أخرى، **فالبائعُ يبيع آجلاً وهو لا يعرف كم على الزبون**. فيبيع
 *   لمن عليه أربعون ألفاً وحدُّه ثلاثون، ولا يُكتشَف إلّا بعد أيّام.
 *
 * **والتطبيقُ على القطاعات لا على شاشةٍ واحدة:** الكاشيرُ العامُّ (تجزئة
 * · بيع سريع · مطعم) وشبّاكُ الصيدليّة كلاهما يبيع نقداً وآجلاً — وجدولُ
 * الصيدليّة مستقلٌّ (`pharmacy_sales`). **فحقلٌ في واحدٍ دون الآخر يجعل
 * نصفَ البائعين يرى الباقيَ ونصفَهم لا يراه.** (القاعدة الرابعة.)
 */
class TillTenderedAndCustomerBalanceGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // بوّابةُ مقعد الجهاز — `actingAs` لا تُصدر رمزاً فلا ربطَ لجلسة.
        // ولولا إطفاؤها لَخرج فحصُ الكاشير أخضرَ **لأنّ الطلبَ رُدّ عند
        // الباب**. وهي محروسةٌ في موضعها (`PosDeviceEnforcementTest`).
        config()->set('amial.pos_devices.enforce_session_binding', false);
    }

    private const PHONE = '773334455';

    private function merchant(string $vertical = A::BIZ_RETAIL): User
    {
        $user = User::factory()->create([
            'type' => MERCHANT_TYPE, 'role' => A::ROLE_MERCHANT,
            'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ]);

        MerchantProfile::create([
            'user_id' => $user->id, 'tier' => 'small',
            'verification_status' => 'verified', 'business_type' => $vertical,
            // **والباقةُ المجّانيّة عمداً** — الفحصُ ⑤ يثبت أنّ رصيدَ
            // العميل يصل صاحبَها، فهو معلومةٌ تمنع خسارةً لا ميزةٌ تُباع.
            'subscription_plan' => A::PLAN_FREE,
            'single_receive_limit' => '5000000', 'daily_receive_limit' => '50000000',
        ]);

        return $user;
    }

    private function accountWith(User $merchant, string $balance, string $limit = '0'): CustomerCreditAccount
    {
        return CustomerCreditAccount::create([
            'merchant_user_id' => $merchant->id,
            'customer_phone' => self::PHONE,
            'customer_name' => 'سالم',
            'credit_limit' => $limit,
            'current_balance' => $balance,
            'classification' => 'bronze',
            'is_active' => true,
        ]);
    }

    // ═════════════════════════════════════════════════════════════════
    //  ① المبلغ المستلم
    // ═════════════════════════════════════════════════════════════════

    /**
     * **① يُحفَظ مع البيعة** — ولا يبقى رقماً على الشاشة يذهب مع إغلاقها.
     */
    /** @test */
    public function the_tendered_amount_is_stored_with_the_sale(): void
    {
        $merchant = $this->merchant();

        $sale = app(CashierService::class)->recordSale(
            merchant: $merchant, total: '4700', paymentMethod: 'cash',
            amountReceived: '5000',
        );

        $this->assertSame(5000.0, (float) $sale->fresh()->amount_received,
            'المستلَمُ لم يُحفَظ — والباقي يبقى رقماً قيل شفاهاً ولا يُراجَع');
    }

    /**
     * **② و`null` تعني «لم يُدخَل» لا صفراً.**
     *
     * وصفرٌ على إيصالِ بيعةٍ آجلةٍ يُقرأ «استُلم صفر» — وهو صحيحٌ حرفيّاً
     * وكاذبٌ معنىً. (القاعدة السابعة.)
     */
    /** @test */
    public function a_sale_without_tendered_cash_stores_null_not_zero(): void
    {
        $merchant = $this->merchant();

        $sale = app(CashierService::class)->recordSale(
            merchant: $merchant, total: '4700', paymentMethod: 'cash',
        );

        $this->assertNull($sale->fresh()->amount_received);
    }

    /**
     * **③ ويُطبَع على الإيصال — المستلَمُ والباقي معاً.**
     *
     * ورقمٌ قيل ولم يُطبَع لا يُدافَع عنه. **والباقي يُحسب من مصدره**
     * (المستلَم − الإجماليّ) ولا يُخزَّن عموداً ثالثاً يناقضهما.
     */
    /** @test */
    public function the_receipt_prints_the_tendered_amount_and_the_change(): void
    {
        $merchant = $this->merchant();

        $sale = app(CashierService::class)->recordSale(
            merchant: $merchant, total: '4700', paymentMethod: 'cash',
            amountReceived: '5000',
        );

        $doc = $this->receiptDocFor($sale->fresh());

        $lines = collect($doc['tendered_lines'] ?? []);

        $this->assertSame('المبلغ المستلم', $lines->firstWhere('label', 'المبلغ المستلم')['label'] ?? null,
            'الإيصالُ لا يذكر المستلَم');
        $this->assertSame(300.0, (float) ($lines->firstWhere('label', 'الباقي')['value'] ?? -1),
            'الباقي ليس ٣٠٠ — والزبونُ يقرأ الورقةَ ويعدّ نقدَه عليها');
    }

    /**
     * **④ وبيعةٌ بلا مستلَمٍ لا تُطبَع لها سطورٌ فارغة.**
     */
    /** @test */
    public function no_tendered_lines_are_printed_when_none_was_entered(): void
    {
        $merchant = $this->merchant();

        $sale = app(CashierService::class)->recordSale(
            merchant: $merchant, total: '4700', paymentMethod: 'credit',
            customer: ['name' => 'سالم', 'phone' => self::PHONE],
        );

        $doc = $this->receiptDocFor($sale->fresh());

        $this->assertSame([], $doc['tendered_lines'] ?? null,
            '«المستلَم: ٠» على إيصال بيعةٍ آجلة يُقرأ «لم يدفع»');
    }

    /**
     * **⑤ والصيدليّةُ لها الحقلُ نفسُه** — وجدولُها مستقلّ.
     *
     * فلولا هذا لَرأى بائعُ البقالة الباقيَ على إيصاله ولم يره بائعُ
     * الصيدليّة، **والفرقُ يتبع القطاعَ لا قرارَ أحد**.
     */
    /** @test */
    public function the_pharmacy_till_has_the_same_field(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasColumn('pharmacy_sales', 'amount_received'),
            'شبّاكُ الصيدليّة يبيع نقداً ولا حقلَ للمستلَم في جدوله');

        $this->assertContains('amount_received',
            (new \App\Models\PharmacySale)->getFillable(),
            'العمودُ موجودٌ و`create()` يُسقطه صامتاً — وهو عطلٌ وقع مرّتين في هذا المشروع');
    }


    /**
     * وثيقةُ الإيصال لبيعةٍ — **من المسار الحقيقيّ لا بنداءٍ داخليّ.**
     *
     * `merchantInvoice` خاصّةٌ وتأخذ `Receipt`، وبناءُ مصفوفةٍ بيدي هنا
     * يفحص ما كتبتُه لا ما يُطبَع. فيُصنَع إيصالٌ كما يصنعه المنتج.
     *
     * @return array<string,mixed>
     */
    private function receiptDocFor(\App\Models\MerchantSale $sale): array
    {
        // **والحقولُ منقولةٌ عن `ReceiptDocumentSystemTest`** — وهو مَن
        // يبني إيصالَ بيعةٍ في هذا المشروع منذ بُني. ولا تُخترَع صيغةٌ
        // ثانيةٌ تفترق عنها.
        $receipt = \App\Models\Receipt::create([
            'receipt_number' => now()->format('ymd') . random_int(100000, 999999),
            'verification_code' => str_repeat('7', 16),
            'receipt_type' => 'pos_payment',
            'user_id' => $sale->merchant_user_id,
            'reference_transaction_id' => 'TX-TENDER-' . random_int(1000, 9999),
            'reference_type' => 'merchant_sale',
            'reference_id' => $sale->id,
            'amount' => (string) $sale->total_amount,
            'fee' => '0.0000',
            'net_amount' => (string) $sale->total_amount,
            'direction' => 'credit',
            'status' => 'pending_pdf',
            'zone_code' => 'SOUTH',
            'issued_at' => now(),
        ]);

        return app(\App\Services\ReceiptDocumentService::class)->build($receipt->fresh());
    }

    // ═════════════════════════════════════════════════════════════════
    //  ② رصيد العميل في الشبّاك
    // ═════════════════════════════════════════════════════════════════

    /**
     * **⑥ الدَّينُ يُقرأ من الشبّاك — وبالباقة المجّانيّة.**
     *
     * ولو حُرست بـ`customers` المدفوعة لَصار الحاجزُ على المعلومة التي
     * تمنع الخسارة، وهو نفسُ التعليل المكتوب في مسارات هذه المجموعة.
     */
    /** @test */
    public function the_till_reads_the_customers_debt_on_the_free_plan(): void
    {
        $merchant = $this->merchant();
        $this->accountWith($merchant, '40000', '30000');

        $body = $this->actingAs($merchant, 'api')
            ->getJson('/api/v1/amial/merchant/credit/lookup?phone=' . self::PHONE)
            ->assertOk()->json('meta');

        $this->assertTrue($body['found']);
        $this->assertSame('سالم', $body['customer_name']);
        $this->assertSame(40000.0, (float) $body['current_balance']);
        $this->assertTrue($body['is_over_limit'],
            'بلغ حدَّه ولا شيءَ يقول ذلك للبائع قبل الضغط');
    }

    /**
     * **⑦ وعميلٌ جديدٌ ليس عميلاً عليه صفر.**
     */
    /** @test */
    public function an_unknown_customer_is_not_a_customer_who_owes_zero(): void
    {
        $merchant = $this->merchant();

        $body = $this->actingAs($merchant, 'api')
            ->getJson('/api/v1/amial/merchant/credit/lookup?phone=700000001')
            ->assertOk()->json('meta');

        $this->assertFalse($body['found']);
        $this->assertNull($body['current_balance'],
            'صفرٌ هنا يُقرأ «فحصنا فلم نجد ديناً»، والحقيقةُ «لا حسابَ له» (القاعدة السابعة)');
    }

    /**
     * **⑧ وحدٌّ صفرٌ يعني «غير مضبوط» لا «ممنوعٌ من الآجل».**
     *
     * ولولا التفريق لَخرج كلُّ عميلٍ حدُّه غيرُ مضبوطٍ «بلغ حدَّه» —
     * لافتةٌ حمراءُ على الجميع تُعوّد البائعَ أن يتجاهلها **يومَ تصدق**.
     */
    /** @test */
    public function an_unset_limit_is_not_a_zero_limit(): void
    {
        $merchant = $this->merchant();
        $this->accountWith($merchant, '15000', '0');

        $body = $this->actingAs($merchant, 'api')
            ->getJson('/api/v1/amial/merchant/credit/lookup?phone=' . self::PHONE)
            ->assertOk()->json('meta');

        $this->assertNull($body['credit_limit']);
        $this->assertNull($body['remaining']);
        $this->assertFalse($body['is_over_limit'],
            'حدٌّ غيرُ مضبوطٍ عُومل حدّاً صفريّاً — فكلُّ عميلٍ «بلغ حدَّه»');
    }

    /**
     * **⑨ والكاشيرُ يقرؤه كما يقرؤه المالك.**
     *
     * وهو من يقف أمام العميل. (القاعدة الرابعة — وهي التي كلّفت هذا
     * المشروعَ في نقاط الولاء قبل ساعات.)
     */
    /** @test */
    public function the_pos_cashier_reads_it_too(): void
    {
        $merchant = $this->merchant();
        $this->accountWith($merchant, '25000');

        $cashier = User::factory()->create([
            'type' => 4, 'role' => 'pos', 'is_active' => 1, 'zone_code' => 'SOUTH',
        ]);
        PosUser::create([
            'user_id' => $cashier->id, 'merchant_user_id' => $merchant->id,
            'role' => 'pos', 'is_active' => 1, 'pos_number' => 'POS-1',
        ]);

        $body = $this->actingAs($cashier, 'api')
            ->getJson('/api/v1/amial/merchant/credit/lookup?phone=' . self::PHONE)
            ->assertOk()->json('meta');

        $this->assertSame(25000.0, (float) $body['current_balance'],
            'الكاشيرُ — وهو من يبيع آجلاً فعلاً — لا يرى دَينَ زبونه');
    }

    /**
     * **⑩ ولا يُقرأ دَينُ عميلٍ عند تاجرٍ آخر.**
     *
     * فالدفترُ لكلّ تاجرٍ على حدة، ورقمُ هاتفٍ واحدٌ قد يكون عميلاً عند
     * عشرة. وتسريبُ دَينه بينهم كشفُ بياناتٍ لا خدمة.
     */
    /** @test */
    public function one_merchant_cannot_read_another_merchants_ledger(): void
    {
        $a = $this->merchant();
        $b = $this->merchant();
        $this->accountWith($a, '90000');

        $body = $this->actingAs($b, 'api')
            ->getJson('/api/v1/amial/merchant/credit/lookup?phone=' . self::PHONE)
            ->assertOk()->json('meta');

        $this->assertFalse($body['found'], 'دَينُ عميلٍ عند تاجرٍ ظهر لتاجرٍ آخر');
    }

    /**
     * **⑪ والشاشتان تناديانه فعلاً.**
     *
     * فالخادمُ كلُّه أعلاه بلا معنىً إن بقي المدخلُ غائباً — وهو نمطُ
     * العطل الأكثر تكراراً في هذا المشروع. (القاعدة الثانية عشرة.)
     */
    /** @test */
    public function both_tills_actually_call_them(): void
    {
        $cashier = file_get_contents(base_path(
            '../02_flutter_app/lib/features/merchant/screens/cashier_payment_screen.dart'));
        $pharmacy = file_get_contents(base_path(
            '../02_flutter_app/lib/features/pharmacy/screens/pharmacy_sale_screen.dart'));

        foreach (['الكاشير' => $cashier, 'الصيدليّة' => $pharmacy] as $name => $src) {
            $this->assertStringContainsString('CustomerBalanceBadge(', $src,
                "شبّاكُ {$name} لا يعرض دَينَ العميل قبل البيع الآجل");
            $this->assertStringContainsString('CashTenderedField(', $src,
                "شبّاكُ {$name} لا يسأل عن المبلغ المستلم");
        }

        $badge = file_get_contents(base_path(
            '../02_flutter_app/lib/features/merchant/widgets/customer_balance_badge.dart'));

        $this->assertStringContainsString('/api/v1/amial/merchant/credit/lookup', $badge,
            'الأداةُ لا تنادي مساراً — بطاقةٌ تُرسَم ولا تسأل أحداً');
    }
}
