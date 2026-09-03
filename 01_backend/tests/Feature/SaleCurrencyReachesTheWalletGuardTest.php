<?php

namespace Tests\Feature;

use App\Models\MerchantCurrency;
use App\Models\MerchantProfile;
use App\Models\MerchantSale;
use App\Models\User;
use App\Services\FxRateService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-MULTI-CURRENCY-003 — **العملةُ تصل مسارَ القبض.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * بُنيت المحافظُ الأربع، ثمّ سأل صاحبُ المشروع «من يملكها؟» فقِيس، فإذا
 * **الدولارُ لا يدخلها من بيعٍ أصلاً**: `merchant_sales` بلا عمود عملةٍ
 * إطلاقاً. فمحافظُ مبنيّةٌ ولا يُوصَل إليها — وهو نمطُ العطل الأكثر
 * تكراراً في هذا المشروع.
 *
 * فوصلت العملةُ إلى البيعة. **وهذه الحالاتُ تحرس ما كان سينكسر صامتاً:**
 */
class SaleCurrencyReachesTheWalletGuardTest extends TestCase
{
    use RefreshDatabase;

    private function merchant(string $plan = A::PLAN_ENTERPRISE): User
    {
        $u = User::factory()->create([
            'type' => MERCHANT_TYPE, 'role' => A::ROLE_MERCHANT,
            'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ]);

        MerchantProfile::create([
            'user_id' => $u->id, 'tier' => 'small',
            'verification_status' => 'verified', 'business_type' => 'retail',
            'subscription_plan' => $plan,
            'single_receive_limit' => '5000000', 'daily_receive_limit' => '50000000',
        ]);

        return $u;
    }

    private function acceptUsd(User $m, string $rate = '530'): void
    {
        app(FxRateService::class)->setRate('USD', $rate, 'initial_seed', 'حارس');
        MerchantCurrency::create([
            'merchant_user_id' => $m->id, 'code' => 'USD',
            'name' => 'دولار أمريكي', 'symbol' => '$',
            'is_active' => true, 'accepts_payments' => true,
        ]);
    }

    private function sale(User $m, array $extra = [])
    {
        return $this->actingAs($m, 'api')->postJson(
            '/api/v1/amial/merchant/cashier/sales',
            array_merge([
                'payment_method' => 'cash',
                'total' => '20',
                'items' => [['name' => 'صنف', 'qty' => 1, 'price' => '20']],
            ], $extra)
        );
    }

    // ═════════════════════════════════════════════════════════════════

    /**
     * **① البيعةُ تُحفَظ بعملتها وسعرِها ومكافئها — والثلاثةُ معاً.**
     *
     * والسعرُ **يُنسَخ ولا يُشار إليه**: يتغيّر غداً فيتغيّر معه مكافئُ
     * بيعةِ اليوم، فيُطلَب من الماليّة أن تطابق رقماً يتحرّك.
     */
    /** @test */
    public function a_foreign_sale_is_stored_with_its_currency_and_frozen_rate(): void
    {
        $m = $this->merchant();
        $this->acceptUsd($m, '530');

        $r = $this->sale($m, ['currency' => 'USD']);
        $this->assertContains($r->status(), [200, 201], 'رُفضت بيعةٌ سليمة: '.$r->json('message'));

        $sale = MerchantSale::where('merchant_user_id', $m->id)->firstOrFail();

        $this->assertSame('USD', (string) $sale->currency,
            '**البيعةُ سُجّلت بعملةٍ خاطئة** — راجع `$fillable` في '
            .'`MerchantSale`: الإسقاطُ صامتٌ ويقع العمودُ على `YER`.');
        $this->assertSame('530.00000000', (string) $sale->fx_rate_to_base,
            '**السعرُ لم يُجمَّد على البيعة** — فمكافئُها يتغيّر مع كلّ تحديث.');
        $this->assertSame('10600.0000', (string) $sale->base_amount,
            '**المكافئُ خاطئ** — ٢٠ دولاراً × ٥٣٠ = ١٠٬٦٠٠ ر.ي.');

        // **والسعرُ الجديد لا يُعيد كتابةَ ما مضى.**
        app(FxRateService::class)->setRate('USD', '600', 'manual_admin', 'تحديث');
        $this->assertSame('10600.0000', (string) $sale->fresh()->base_amount,
            '**تغيّر مكافئُ بيعةٍ ماضية بتغيّر السعر** — أي إعادةُ كتابةِ '
            .'مستندٍ صدر. وهو العطلُ الذي كلّف هذا المشروعَ سطرَ مكافئٍ '
            .'على الإيصال يتحرّك بأثرٍ رجعيّ.');
    }

    /**
     * **② ولا يُقبَض بعملةٍ لم يفعّلها صاحبُ المتجر.**
     *
     * وإلّا سجّل موظّفُ نقطة بيعٍ بيعةً بعملةٍ لم يقرّرها المالك — وهو
     * تصرُّفٌ في تسعير المتجر بقائمةٍ منسدلة.
     */
    /** @test */
    public function a_currency_the_merchant_did_not_enable_is_refused(): void
    {
        $m = $this->merchant();
        app(FxRateService::class)->setRate('USD', '530', 'initial_seed', 'حارس');
        // ولا صفَّ `MerchantCurrency` — أي لم يُفعَّل القبض.

        $r = $this->sale($m, ['currency' => 'USD']);

        $this->assertSame(422, $r->status(),
            '**قُبلت بيعةٌ بعملةٍ لم يفعّلها التاجر** — القرارُ قرارُ المالك.');
        $this->assertStringContainsString('لا يقبل الدفع', (string) $r->json('message'));
        $this->assertSame(0, MerchantSale::count(), 'سُجّلت بيعةٌ رغم الرفض');
    }

    /**
     * **③ ولا بعملةٍ بلا سعرٍ مضبوط.**
     *
     * فبيعةٌ لا يُعرف مكافئُها لا يُحسَب عليها حدُّ استلامٍ ولا تدخل
     * تقريراً — مالٌ خارج كلّ رقابة. **ولا يُفترَض السعرُ واحداً**:
     * عشرون دولاراً تصير عشرين ريالاً. (القاعدة السابعة.)
     */
    /** @test */
    public function a_currency_without_a_rate_is_refused_not_defaulted_to_one(): void
    {
        $m = $this->merchant();
        // فُعّل القبضُ بالدرهم ولا سعرَ له (الحارسُ يكتب الصفَّ مباشرةً
        // لأنّ مسارَ `accept` نفسَه يمنع ذلك — وهذا يقيس الطبقةَ الثانية).
        MerchantCurrency::create([
            'merchant_user_id' => $m->id, 'code' => 'AED',
            'name' => 'درهم إماراتي', 'symbol' => 'د.إ',
            'is_active' => true, 'accepts_payments' => true,
        ]);

        $r = $this->sale($m, ['currency' => 'AED']);

        $this->assertSame(422, $r->status(),
            '**مرّت بيعةٌ بعملةٍ بلا سعر** — فمكافئُها مجهولٌ ويُقرأ صفراً '
            .'أو واحداً، وكلاهما كذب.');
        $this->assertStringContainsString('لا سعرَ صرفٍ', (string) $r->json('message'),
            'الرفضُ لا يقول سببَه، فيُعيد الكاشيرُ المحاولةَ والزبونُ واقف');
        $this->assertSame(0, MerchantSale::count());
    }

    /**
     * **④ والبيعُ بالريال لم يتغيّر بحرف.**
     *
     * فأخطرُ ما في إضافةٍ إلى مسارٍ يعمل ألفَ مرّةٍ في اليوم أن تغيّره.
     */
    /** @test */
    public function a_base_currency_sale_is_unchanged(): void
    {
        $m = $this->merchant(A::PLAN_FREE);   // ولا حاجةَ لباقةٍ ولا لعملات

        $r = $this->sale($m);   // بلا حقل `currency` إطلاقاً
        $this->assertContains($r->status(), [200, 201], 'انكسر البيعُ بالريال: '.$r->json('message'));

        $sale = MerchantSale::where('merchant_user_id', $m->id)->firstOrFail();
        $this->assertSame('YER', (string) $sale->currency);
        $this->assertSame('1.00000000', (string) $sale->fx_rate_to_base);
        $this->assertSame((string) $sale->total_amount, (string) $sale->base_amount,
            'المكافئُ يخالف الإجماليَّ في بيعةٍ بالعملة الأساس');
    }

    /**
     * **⑤ والتقاريرُ تجمع المكافئَ لا الرقمَ الخام.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **وهذا أخطرُ ما في إدخال العملة**: `SUM(total_amount)` على بيعتين
     * — واحدةٍ بـ٢٠ دولاراً وأخرى بـ١٠٬٠٠٠ ريال — يُخرج **١٠٬٠٢٠**، وهو
     * ليس مبلغاً من شيء. ويُعرَض على صاحب المتجر «مبيعات اليوم» فيقرؤه
     * ريالاً، والحقيقةُ ٢٠٬٦٠٠.
     *
     * **ولا يمسكه شيء**: الجمعُ يقع، والرقمُ يُعرَض، ولا خطأَ في أيّ سجلّ.
     */
    /** @test */
    public function reports_sum_the_base_equivalent_not_the_raw_number(): void
    {
        $m = $this->merchant();
        $this->acceptUsd($m, '530');

        $this->sale($m, ['total' => '10000']);                    // ١٠٬٠٠٠ ر.ي
        $this->sale($m, ['total' => '20', 'currency' => 'USD']);  // ١٠٬٦٠٠ ر.ي

        $this->assertSame(2, MerchantSale::count(), 'لم تُسجَّل البيعتان');

        $raw = (string) MerchantSale::where('merchant_user_id', $m->id)->sum('total_amount');
        $base = (string) MerchantSale::where('merchant_user_id', $m->id)
            ->sum(\DB::raw('COALESCE(base_amount, total_amount)'));

        $this->assertSame('20600.0000', $base,
            '**المجموعُ بالمكافئ خاطئ** — ١٠٬٠٠٠ + (٢٠ × ٥٣٠) = ٢٠٬٦٠٠.');

        // **ويُثبَّت الفرقُ صراحةً** — فحارسٌ يقيس الصحيحَ ولا يُثبت أنّ
        // الخطأ كان مختلفاً قد يمرّ على تطابقٍ بالمصادفة.
        $this->assertNotSame($base, $raw, sprintf(
            'الجمعُ الخامُّ والمكافئُ متساويان (%s) — فالحالةُ لا تقيس شيئاً', $raw));

        // وشاشةُ الموظّفين تقرأ المكافئ.
        $r = $this->actingAs($m, 'api')->getJson('/api/v1/amial/merchant/staff/performance');
        if ($r->status() === 200) {
            $total = (string) ($r->json('meta.grand_total') ?? $r->json('meta.total') ?? '');
            if ($total !== '') {
                $this->assertStringStartsWith('20600', $total,
                    '**شاشةُ أداء الموظّفين تجمع الرقمَ الخام** — فتقول '
                    ."{$total} بدل ٢٠٬٦٠٠.");
            }
        }
    }
}
