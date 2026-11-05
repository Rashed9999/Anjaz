<?php

namespace Tests\Feature;

use App\Models\FuelCompanyAccount;
use App\Models\FuelCompanyCard;
use App\Services\FuelCompanyCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-FUEL-CARD-LIMIT-001 — **حدُّ بطاقةٍ يُضبَط ويُعرَض ولا يُطبَّق.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما قِيس:**
 *
 *   الحدُّ على مستوى **الحساب**  →  مفروضٌ في `FuelStationService`
 *   الحدُّ على مستوى **البطاقة** →  `assertCardLimits` **بصفر مُنادٍ**
 *
 * والدالّةُ تفرض اثنين: أنّ البطاقةَ نشطة، وأنّ يومَها لم يُستنفَد.
 * و`FuelSale::create` كانت تكتب `company_card_id` مباشرةً بلا أن تسألها.
 *
 * **فبطاقةُ سائقٍ مسحوبةٌ أو ملغاةٌ تشتري** حتّى سقف الشركة كلِّه،
 * وحدُّها اليوميُّ يُعرَض في اللوحة ولا يقع. **والوقودُ بالأجل** — فما
 * ظنّه صاحبُ المحطّة سقفاً على سائقٍ واحدٍ كان سقفَ الشركة كلِّها.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وهو الصنفُ الخامسُ من نوعه في هذا التدقيق** — بعد حدّ استلام التاجر،
 * وقفلِ رمز العمليّات، وحارسِ الحساب القديم في «الاستلام السريع»،
 * ومسارِ استعادة الحساب. **كلُّها «مبنيٌّ ومُختبَرٌ ولا يُوصَل إليه»**،
 * وكلُّها أخرجها جردُ ساهر لا مجموعةُ الاختبارات: الدالّةُ خضراءُ
 * الاختبار، ولا شيءَ كان يسأل **من يناديها**.
 */
class FuelCardLimitGuardTest extends TestCase
{
    use RefreshDatabase;

    private function card(array $over = []): FuelCompanyCard
    {
        $merchant = \App\Models\User::factory()->create(['type' => 3]);

        $account = FuelCompanyAccount::create(array_merge([
            'merchant_user_id' => $merchant->id,
            'company_name' => 'شركةُ النقل',
            'current_balance' => '0',
            'credit_limit' => '1000000',
            'is_active' => true,
        ], $over['account'] ?? []));

        return FuelCompanyCard::create(array_merge([
            'company_account_id' => $account->id,
            'card_number' => 'CARD-001',
            'card_label' => 'سيّارةُ المدير',
            'daily_limit' => '5000',
            'is_active' => true,
        ], $over['card'] ?? []));
    }

    // ══════════════════════════════════════════════════════════════════
    // الدالّةُ نفسُها — وهي كانت تعمل، ولا يناديها أحد
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function an_inactive_card_is_refused(): void
    {
        // **وبطاقةٌ مُلغاةٌ تشتري هي أخطرُ الحالتين**: الإلغاءُ إجراءٌ
        // يتّخذه صاحبُ الشركة على سائقٍ ترك العمل أو فقد بطاقتَه.
        $card = $this->card(['card' => ['is_active' => false]]);

        $this->expectException(\RuntimeException::class);

        app(FuelCompanyCardService::class)->assertCardLimits($card, '100');
    }

    /** @test */
    public function a_sale_over_the_daily_limit_is_refused(): void
    {
        $card = $this->card(['card' => ['daily_limit' => '5000']]);

        $this->expectException(\RuntimeException::class);

        app(FuelCompanyCardService::class)->assertCardLimits($card, '9000');
    }

    /** @test */
    public function a_card_with_no_daily_limit_is_not_blocked(): void
    {
        // **وحاجزٌ يشلّ عملاً سليماً يُطفَأ عند أوّل شكوى.** صفرٌ يعني
        // «بلا حدّ» لا «الحدُّ صفر» — والفرقُ بينهما إيقافُ محطّةٍ كاملة.
        $card = $this->card(['card' => ['daily_limit' => '0']]);

        app(FuelCompanyCardService::class)->assertCardLimits($card, '999999');

        $this->addToAssertionCount(1);
    }

    // ══════════════════════════════════════════════════════════════════
    // التوصيل — وهو ما كان غائباً
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_sale_path_actually_calls_the_check(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **هذا هو العطلُ بعينه.** الدالّةُ صحيحةٌ ومُختبَرةٌ منذ كُتبت،
        // ولا شيءَ يناديها. ويُقرأ من الشيفرة **بلا تعليقاتها** — فتعليقٌ
        // يذكر الاسمَ أخفى غيابَه في حارسٍ آخرَ بهذه الجلسة.
        // ══════════════════════════════════════════════════════════════
        $src = $this->codeOnly('app/Services/FuelStationService.php');

        $this->assertStringContainsString('assertCardLimits', $src,
            'مسارُ البيع لا يسأل حدَّ البطاقة — فالحدُّ المضبوطُ في اللوحة زينة، '
            . 'وبطاقةٌ ملغاةٌ تشتري حتّى سقف الشركة');
    }

    /** @test */
    public function the_check_sits_inside_the_lock(): void
    {
        // **والعدُّ اليوميُّ يجمع مبيعاتِ البطاقة**، ودفعتان متزامنتان
        // تقرآن المجموعَ نفسَه فتمرّان معاً. (الطبقةُ الثامنة.)
        $src = $this->codeOnly('app/Services/FuelStationService.php');

        $lock = strpos($src, 'lockForUpdate');
        $check = strpos($src, 'assertCardLimits');

        $this->assertNotFalse($lock);
        $this->assertNotFalse($check);
        $this->assertGreaterThan($lock, $check,
            'الفحصُ قبل القفل — فبيعتان متزامنتان تتجاوزان الحدَّ معاً');
    }

    /** @test */
    public function a_card_from_another_company_is_refused(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **والهويّةُ تحدّد النطاق لا ما يرسله الطلب.** رقمُ البطاقة يأتي
        // في الحمولة؛ فبلا ربطه بحساب الشركة المقفول، يستطيع من يعرف
        // رقمَ بطاقةِ شركةٍ أخرى أن يُحمّلها وقودَه.
        // ══════════════════════════════════════════════════════════════
        $src = $this->codeOnly('app/Services/FuelStationService.php');

        $this->assertMatchesRegularExpression(
            "~where\('company_account_id',\s*\\\$companyAccount->id\)~", $src,
            'البطاقةُ تُبحث برقمها وحدَه — فبطاقةُ شركةٍ أخرى تُقبل هنا');
    }

    // ── أدوات ─────────────────────────────────────────────────────────

    /** الشيفرةُ بلا تعليقاتها — مرحلتان لا تعبيرٌ واحدٌ جشع. */
    private function codeOnly(string $rel): string
    {
        $s = (string) file_get_contents(base_path($rel));
        $s = preg_replace('~/\*.*?\*/~s', '', $s) ?? '';

        return preg_replace('~^[ \t]*//[^\n]*$~m', '', $s) ?? '';
    }
}
