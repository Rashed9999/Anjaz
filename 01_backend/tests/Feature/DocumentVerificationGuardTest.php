<?php

namespace Tests\Feature;

use App\Models\FuelSale;
use App\Models\Merchant;
use App\Models\MerchantSale;
use App\Models\PharmacySale;
use App\Models\Receipt;
use App\Models\RestaurantOrder;
use App\Models\User;
use App\Models\WholesaleBusiness;
use App\Models\WholesaleInvoice;
use App\Services\DocumentVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AMIAL-DOC-VERIFY-001 — **رمزٌ واحدٌ يُتحقَّق منه، ويقول الحقيقةَ الحاليّة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ثلاثةُ أعطالٍ قِيست، ولكلٍّ ثمنٌ يقع على من يحمل الورقة:**
 *
 *   ① **الملغى والمزوَّرُ جوابُهما واحد.** `verifyByCode` يقتصر على
 *      `status='pdf_generated'`، فالمستندُ الملغى يُجيب «لا إيصال صالح
 *      لهذا الرمز» — **نفسَ جوابِ رمزٍ مخترَع**. فمن ألغى فاتورتَه بحقٍّ
 *      يُتَّهم بالتزوير، ومن زوَّر يجد لنفسه عذراً: «لعلّها أُلغيت».
 *
 *   ② **وسندُ الوقود يطبع «رمز التحقّق» ولا مُتحقِّقَ يقبله.** القالبُ
 *      يطبع `substr(sale_ulid, -8)` تحت اللافتة، **والمسارُ يشترط ١٦
 *      محرفاً فيرفضه بصيغته**. فيقرأ صاحبُ السند الصحيحِ أنّه غيرُ صالح.
 *      **ولافتةٌ تَعِد بما لا تفعل أسوأ من غيابها.**
 *
 *   ③ **ولا صفحةَ يدخل إليها من يحمل ورقة.** `/v/{code}` مبنيّةٌ لمن
 *      يمسح QR وحدَه — ومن لا هاتفَ ماسحاً معه لا بابَ له.
 *
 * **وحدُّ ما يُعرَض محروسٌ أيضاً**: الصفحةُ عامّةٌ بلا مصادقة، ومن يحمل
 * الرمزَ ليس بالضرورة صاحبَ المستند.
 */
class DocumentVerificationGuardTest extends TestCase
{
    use RefreshDatabase;

    private function verifier(): DocumentVerificationService
    {
        return app(DocumentVerificationService::class);
    }

    private function merchant(string $store = 'بقالة النور'): User
    {
        $u = User::factory()->create(['type' => MERCHANT_TYPE, 'is_active' => 1, 'zone_code' => 'SOUTH']);
        $m = new Merchant();
        $m->user_id = $u->id;
        $m->store_name = $store;
        $m->merchant_number = 'AM-'.Str::upper(Str::random(6));
        $m->save();

        return $u;
    }

    private function receipt(array $attrs = []): Receipt
    {
        $tx = \App\Models\Transaction::create([
            'user_id' => User::factory()->create(['type' => CUSTOMER_TYPE])->id,
            'transaction_type' => 'pos_payment', 'debit' => '0', 'credit' => '1500',
            'charge' => '0', 'amount' => '1500', 'currency' => 'YER',
            'fx_rate_to_base' => '1', 'zone_code' => 'SOUTH', 'balance' => '1500',
            'transaction_id' => (string) Str::ulid(),
        ]);

        return Receipt::create(array_merge([
            'reference_transaction_id' => $tx->id,
            'receipt_number' => 'RC-'.Str::upper(Str::random(8)),
            'verification_code' => (string) random_int(1000000000000000, 9999999999999999),
            'receipt_type' => 'pos_payment',
            'user_id' => User::factory()->create(['type' => CUSTOMER_TYPE])->id,
            'amount' => '1500.0000',
            'fee' => '0',
            'net_amount' => '1500.0000',
            'direction' => 'credit',
            'status' => 'pdf_generated',
            'zone_code' => 'SOUTH',
            'issued_at' => now(),
        ], $attrs));
    }

    /** @test */
    public function a_cancelled_document_is_never_answered_like_a_forged_one(): void
    {
        $cancelled = $this->receipt(['status' => 'voided']);

        $a = $this->verifier()->verify($cancelled->verification_code);
        $b = $this->verifier()->verify('1111222233334444');   // رمزٌ مخترَع

        $this->assertTrue($a['found'],
            'المستندُ الملغى قُرئ «غير موجود» — فمن ألغى فاتورتَه بحقٍّ '
            .'يُتَّهم بالتزوير، ومن زوّر يجد لنفسه عذراً');

        $this->assertSame('cancelled', $a['authenticity']);
        $this->assertStringContainsString('ملغى', $a['authenticity_label']);

        $this->assertFalse($b['found']);
        $this->assertNotSame($a['authenticity'], $b['authenticity'],
            'الملغى والمزوَّرُ جوابُهما واحد — والفرقُ بينهما كلُّ المسألة');
    }

    /** @test */
    public function the_short_fuel_code_printed_on_the_voucher_actually_verifies(): void
    {
        $m = $this->merchant('محطة الأمل للوقود');
        $ulid = (string) Str::ulid();

        // **الأعمدةُ الإلزاميّةُ تُملأ بمعرّفاتٍ حقيقيّةٍ لا بأصفار** —
        // ومسبارٌ على بياناتٍ مستحيلةٍ يُطمئن ولا يفحص.
        $station = \App\Models\FuelStation::create([
            'merchant_user_id' => $m->id, 'station_name' => 'محطة الأمل',
            'is_active' => true, 'zone_code' => 'SOUTH',
        ]);
        $product = \App\Models\FuelProduct::create([
            'station_id' => $station->id, 'name' => 'بنزين',
            'price_per_liter' => '500', 'is_active' => true,
        ]);
        $pump = \App\Models\FuelPump::create([
            'station_id' => $station->id, 'pump_number' => 1,
            'pump_type' => 'petrol', 'current_meter_reading' => '0',
            'is_active' => true,
        ]);

        $sale = FuelSale::create([
            'sale_ulid' => $ulid, 'merchant_user_id' => $m->id,
            'station_id' => $station->id, 'pump_id' => $pump->id,
            'fuel_product_id' => $product->id,
            'liters' => '20', 'price_per_liter' => '500', 'total_amount' => '10000',
            'payment_method' => 'cash', 'status' => 'completed', 'sale_type' => 'retail',
            'zone_code' => 'SOUTH',
        ]);

        // **الرمزُ كما يطبعه القالبُ بالحرف** — لا كما نتمنّاه.
        $printed = strtoupper(substr($ulid, -8));

        $r = $this->verifier()->verify($printed);

        $this->assertTrue($r['found'],
            'الرمزُ المطبوعُ على سند الوقود لا يُتحقَّق منه — واللافتةُ تقول '
            .'«رمز التحقّق»، فيقرأ صاحبُ السند الصحيحِ أنّه غيرُ صالح');

        $this->assertSame('fuel_sale', $r['doc_type']);
        $this->assertSame('محطة الأمل للوقود', $r['issuer']);
        $this->assertSame(0, bccomp($r['amount'], '10000', 4));
        $this->assertSame('fuel_legacy', $r['source'],
            'الرموزُ المطبوعةُ سلفاً كُسرت — وإيصالٌ في جيب سائقٍ منذ شهر '
            .'لا يصير باطلاً لأنّنا وحّدنا الصيغة');

        // **ويُقرأ كما يكتبه الإنسان** — بمسافاتٍ وشَرَطات.
        $spaced = implode('-', str_split($printed, 4));
        $this->assertTrue($this->verifier()->verify($spaced)['found'],
            'يُرفض الرمزُ إن كُتب كما يُقرأ على الورق');

        $sale->update(['status' => 'cancelled']);
        $this->assertSame('cancelled', $this->verifier()->verify($printed)['authenticity'],
            'أُلغيت البيعةُ والسندُ ما زال «أصليّاً» — فالورقُ القديم يبقى صحيحاً');
    }

    /** @test */
    public function the_result_follows_the_live_state_of_the_sale_behind_it(): void
    {
        $m = $this->merchant();

        $sale = MerchantSale::create([
            'sale_ulid' => (string) Str::ulid(), 'merchant_user_id' => $m->id,
            'total_amount' => '1500', 'payment_method' => 'credit',
            'status' => 'credit_unpaid', 'items' => [],
        ]);

        $receipt = $this->receipt([
            'reference_type' => 'merchant_sale', 'reference_id' => $sale->id,
        ]);

        $r = $this->verifier()->verify($receipt->verification_code);

        $this->assertSame('غير مدفوع (آجل)', $r['state_label'],
            'الفاتورةُ الآجلةُ تُقرأ «مكتملة» — فيظنّ حاملُها أنّه دفع');
        $this->assertSame('بقالة النور', $r['issuer'],
            'المُصدِرُ لا يُقال — وفاتورةٌ بلا جهةٍ مُصدِرةٍ لا تُتحقَّق منها');

        // ③ **والتغيُّرُ يظهر فوراً لا بعد خمس دقائق** — وهي بالضبط
        // الدقائقُ التي يُستعمَل فيها الورقُ الملغى.
        $sale->update(['status' => 'refunded']);

        $after = $this->verifier()->verify($receipt->verification_code);
        $this->assertSame('refunded', $after['authenticity'],
            'استُرجعت البيعةُ وبقيت النتيجةُ «أصلي» — فالكاشُ يُبقي الورقَ '
            .'القديمَ صحيحاً في الدقائق التي يُستعمَل فيها');
    }

    /** @test */
    public function cash_and_credit_pos_documents_verify_by_their_own_ulid(): void
    {
        $m = $this->merchant('متجر التحقق');
        $ulid = (string) Str::ulid();

        MerchantSale::create([
            'sale_ulid' => $ulid,
            'invoice_number' => 'INV-POS-001',
            'merchant_user_id' => $m->id,
            'total_amount' => '4700',
            'payment_method' => 'cash',
            'status' => 'completed',
            'items' => [],
        ]);

        $r = $this->verifier()->verify($ulid);

        $this->assertTrue($r['found'],
            'فاتورة POS النقدية لا تُتحقَّق إلا إذا وُجد Receipt مالي — والنقد لا ينبغي أن يصنع حركة محفظة');
        $this->assertSame('merchant_sale', $r['source']);
        $this->assertSame('فاتورة نقطة بيع', $r['doc_type_label']);
        $this->assertSame('INV-POS-001', $r['document_number']);
        $this->assertSame('متجر التحقق', $r['issuer']);

        $this->get('/v/'.$ulid)
            ->assertOk()
            ->assertSee('مستند أصلي', false);
    }

    /** @test */
    public function wholesale_invoice_ulid_is_a_public_verification_code(): void
    {
        $m = $this->merchant('مؤسسة الجملة');
        $business = WholesaleBusiness::create([
            'merchant_user_id' => $m->id,
            'business_name' => 'مؤسسة الجملة',
            'invoice_prefix' => 'WG',
            'next_invoice_number' => 2,
            'is_active' => true,
            'zone_code' => 'SOUTH',
        ]);
        $ulid = (string) Str::ulid();

        WholesaleInvoice::create([
            'invoice_ulid' => $ulid,
            'invoice_number' => 'WG-2026-00001',
            'business_id' => $business->id,
            'customer_id' => 1,
            'created_by_user_id' => $m->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'subtotal' => '12000',
            'discount_amount' => '0',
            'tax_rate' => '0',
            'tax_amount' => '0',
            'total_amount' => '12000',
            'paid_amount' => '0',
            'balance_due' => '12000',
            'status' => 'issued',
            'payment_type' => 'credit',
            'zone_code' => 'SOUTH',
        ]);

        $r = $this->verifier()->verify($ulid);
        $this->assertTrue($r['found']);
        $this->assertSame('wholesale_invoice', $r['source']);
        $this->assertSame('غير مدفوع (آجل)', $r['state_label']);
        $this->assertSame('مؤسسة الجملة', $r['issuer']);
    }

    /**
     * @test
     * كل مستند قطاعي يطبع QR ورابطه؛ والطباعة الحرارية نفسها لا تستثنى.
     */
    public function every_pos_document_surface_carries_a_verification_qr(): void
    {
        foreach ([
            'pdf/cashier-sale-invoice.blade.php',
            'pdf/pharmacy-sale-invoice.blade.php',
            'pdf/fuel-sale-receipt.blade.php',
            'pdf/wholesale-invoice.blade.php',
        ] as $template) {
            $src = (string) file_get_contents(resource_path('views/'.$template));
            $this->assertStringContainsString('$qrDataUri', $src, "{$template} بلا QR تحقق");
            $this->assertStringContainsString('$verificationUrl', $src, "{$template} بلا رابط تحقق");
        }

        $thermal = (string) file_get_contents(base_path(
            '../02_flutter_app/lib/features/printer/widgets/thermal_receipt_widget.dart'));
        $this->assertStringContainsString('QrImageView(', $thermal,
            'الإيصال الحراري لا يرسم QR فعلياً');
        $this->assertStringContainsString('verificationUrl', $thermal,
            'الإيصال الحراري لا يحمل رابط التحقق');
    }

    /** @test */
    public function restaurant_sale_ulid_keeps_its_restaurant_identity(): void
    {
        $m = $this->merchant('مطعم أميال');
        $ulid = (string) Str::ulid();

        MerchantSale::create([
            'sale_ulid' => $ulid,
            'merchant_user_id' => $m->id,
            'total_amount' => '3200',
            'payment_method' => 'cash',
            'status' => 'completed',
            'items' => [],
        ]);

        RestaurantOrder::create([
            'merchant_user_id' => $m->id,
            'order_no' => 'REST-001',
            'status' => 'closed',
            'items' => [],
            'subtotal' => '3200',
            'total' => '3200',
            'sale_ulid' => $ulid,
            'closed_at' => now(),
            'zone_code' => 'SOUTH',
        ]);

        $r = $this->verifier()->verify($ulid);

        $this->assertSame('restaurant_order', $r['source'],
            'ULID المطعم موجود أيضاً في merchant_sales؛ يجب أن تبقى هوية المستند «مطعم»');
        $this->assertSame('فاتورة مطعم', $r['doc_type_label']);
        $this->assertSame('مطعم أميال', $r['issuer']);
    }

    /** @test */
    public function a_sector_wallet_receipt_resolves_the_real_sector_issuer(): void
    {
        $m = $this->merchant('صيدلية أميال');
        $sale = PharmacySale::create([
            'sale_ulid' => (string) Str::ulid(),
            'merchant_user_id' => $m->id,
            'pharmacy_id' => 9001,
            'subtotal' => '1800',
            'discount_amount' => '0',
            'total_amount' => '1800',
            'payment_method' => 'amial_pay',
            'status' => 'completed',
            'zone_code' => 'SOUTH',
        ]);

        $receipt = $this->receipt([
            'reference_type' => 'pharmacy_sale',
            'reference_id' => $sale->id,
        ]);

        $r = $this->verifier()->verify($receipt->verification_code);

        $this->assertSame('صيدلية أميال', $r['issuer'],
            'إيصال الصيدلية نُسب إلى أميال أو إلى MerchantSale يحمل id نفسه');
        $this->assertSame('فاتورة صيدلية', $r['doc_type_label']);
        $this->assertSame('مكتمل', $r['state_label']);
    }

    /** @test */
    public function the_public_page_never_leaks_a_customer(): void
    {
        $customer = User::factory()->create([
            'type' => CUSTOMER_TYPE, 'f_name' => 'زبون', 'l_name' => 'سرّي',
            'phone' => '967779990001',
        ]);
        $receipt = $this->receipt(['user_id' => $customer->id]);

        $html = $this->get('/v/'.$receipt->verification_code)->assertOk()->getContent();

        foreach (['زبون', 'سرّي', '967779990001'] as $secret) {
            $this->assertStringNotContainsString($secret, $html,
                "تسرّب «{$secret}» في صفحةٍ عامّةٍ بلا مصادقة — ومن يحمل "
                .'الرمزَ ليس بالضرورة صاحبَ المستند');
        }

        $this->assertStringContainsString($receipt->receipt_number, $html,
            'رقمُ المستند لا يُعرَض — فلا يُطابَق بالورقة التي في اليد');
    }

    /** @test */
    public function there_is_a_door_for_someone_holding_a_paper(): void
    {
        // ③ صفحةٌ ثابتةٌ يُكتب فيها الرمز — ومن لا هاتفَ ماسحاً معه.
        $html = $this->get('/verify')->assertOk()->getContent();

        $this->assertStringContainsString('name="code"', $html,
            'لا حقلَ يُكتب فيه الرمز — فمن يحمل ورقةً لا بابَ له');
        $this->assertStringContainsString('امسح رمز QR', $html);

        // **وصفحةٌ تُفتح بلا رمزٍ ليست «غير صالح»** — هي المدخل.
        $this->assertStringNotContainsString('لا مستند بهذا الرمز', $html,
            'قيل «غير صالح» لمن لم يكتب شيئاً بعد');

        // وتُتحقّق من الحقل نفسِه.
        $receipt = $this->receipt();
        $this->get('/verify?code='.$receipt->verification_code)
            ->assertOk()->assertSee('مستند أصلي', false);
    }

    /** @test */
    public function an_unknown_state_is_shown_raw_and_never_invented(): void
    {
        $m = $this->merchant();
        $sale = MerchantSale::create([
            'sale_ulid' => (string) Str::ulid(), 'merchant_user_id' => $m->id,
            'total_amount' => '900', 'payment_method' => 'cash',
            'status' => 'quantum_state', 'items' => [],
        ]);
        $receipt = $this->receipt(['reference_type' => 'merchant_sale', 'reference_id' => $sale->id]);

        $r = $this->verifier()->verify($receipt->verification_code);

        // **ولا تُخترَع ترجمة.** رمزٌ خامٌ يُوقف القارئَ ليسأل، والمخترَعةُ
        // تُمرّره واثقاً من معنىً لم يقصده أحد. (درسُ سجلّ التدقيق.)
        $this->assertStringContainsString('quantum_state', $r['state_label'],
            'حالةٌ لا يعرفها المعجمُ تُرجمت اختراعاً، أو طُويت — وكلاهما '
            .'يجعل القارئَ واثقاً من معنىً لم يقصده أحد');
    }

    /**
     * AMIAL-DOC-VERIFY-001 — **ورمزٌ بلا موضعٍ يُكتب فيه لا يُتحقَّق منه.**
     *
     * كانت المستنداتُ تطبع الرمزَ وحدَه: «التحقق: 1234…». **ومن يحمل
     * الورقةَ لا يعرف أين يذهب به.** فيُطبَع العنوانُ معه.
     *
     * @test
     */
    public function every_printed_document_says_where_to_verify(): void
    {
        foreach ([
            'receipts/thermal.blade.php',
            'receipts/merchant-invoice.blade.php',
            'pdf/fuel-sale-receipt.blade.php',
        ] as $template) {
            $src = (string) file_get_contents(resource_path('views/'.$template));

            $this->assertStringContainsString('/verify', $src,
                "المستند «{$template}» يطبع رمزاً بلا موضعٍ يُكتب فيه — "
                .'فمن يحمل الورقةَ لا يعرف أين يذهب به');
        }
    }
}
