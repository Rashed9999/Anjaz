<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\Ledger\LedgerAccount;
use App\Models\Ledger\LedgerEntryLine;
use App\Models\User;
use App\Services\PlatformTreasuryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-TREASURY-SOURCE-001 — **«أين وصل المالُ الحقيقيّ؟»**
 *
 * ══════════════════════════════════════════════════════════════════════
 * المحورُ ٣٣ من وثيقة المركز الماليّ يطلب أن يختفي «إنشاء رصيد» ويحلَّ
 * محلَّه طلبُ إصدارٍ **يبدأ من مصدر التمويل**.
 *
 * **وليس هذا حقلاً إضافيّاً في نموذج.** كلُّ إصدارٍ كان يُقيَّد مديناً
 * على `TREASURY_CASH_RESERVE` — أي «دخل نقدٌ إلى خزينتنا». وهي صحيحةٌ إن
 * جاء المالُ توريدَ خزينةٍ أو تسويةَ وكيل، **وكاذبةٌ في ثلاثةٍ من الخمسة**:
 * الإيداعُ البنكيُّ يزيد رصيدَ البنك، ورأسُ المال يزيد حقوقَ الملكيّة،
 * والتصحيحُ المحاسبيُّ ينتظر تفسيراً في حسابٍ معلَّق.
 *
 * فميزانيّةٌ تُقرأ من هذا الدفتر تقول إنّ في الخزنة نقداً ليس فيها.
 * **ومن جرَد الخزنةَ وجدها ناقصةً بمقدار كلّ إيداعٍ بنكيٍّ منذ النشأة** —
 * ولا عطلَ في أيّ سجلّ، ولا سببَ يُعرف.
 */
class TreasuryFundingSourceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'super_admin']);
        EMoney::where('user_id', $this->admin->id)->delete();
        EMoney::create([
            'user_id' => $this->admin->id, 'current_balance' => '0.0000',
            'charge_earned' => '0.0000', 'pending_balance' => '0.0000',
            'held_balance' => '0.0000', 'zone_code' => 'SOUTH', 'version' => 0,
        ]);
    }

    private function issue(string $source, string $amount = '1000', ?string $ref = null): array
    {
        return app(PlatformTreasuryService::class)->issueAdminFloat(
            $amount, $this->admin, $ref ?? ('REF-'.uniqid()), 'اختبارُ مصدر التمويل',
            null, $source,
        );
    }

    private function debitAccountOf(int $entryId): ?string
    {
        $line = LedgerEntryLine::where('journal_entry_id', $entryId)
            ->where('direction', 'debit')->first();

        return $line ? LedgerAccount::find($line->account_id)?->account_code : null;
    }

    /**
     * @test
     *
     * **① كلُّ مصدرٍ يُقيَّد في حسابه — لا كلُّهم في نقد الخزينة.**
     *
     * @dataProvider sources
     */
    public function each_funding_source_posts_to_its_own_counter_account(
        string $source, string $expectedAccount, string $expectedType): void
    {
        $issued = $this->issue($source);

        $code = $this->debitAccountOf($issued['entry']->id);

        $this->assertSame($expectedAccount, $code, sprintf(
            '**كذبةٌ في الميزانيّة**: «%s» قُيّد على «%s» بدل «%s»',
            PlatformTreasuryService::FUNDING_SOURCES[$source]['label'], (string) $code, $expectedAccount));

        $this->assertSame($expectedType,
            LedgerAccount::where('account_code', $expectedAccount)->value('account_type'),
            'صنفُ الحساب لا يطابق طبيعةَ المصدر');
    }

    public static function sources(): array
    {
        return [
            'توريدُ خزينة' => ['treasury_supply', 'TREASURY_CASH_RESERVE', 'asset'],
            'تسويةُ وكيل' => ['agent_settlement', 'TREASURY_CASH_RESERVE', 'asset'],
            'إيداعٌ بنكيّ' => ['bank_deposit', 'TREASURY_BANK', 'asset'],
            'رأسُ مال' => ['capital', 'PLATFORM_CAPITAL', 'equity'],
            'تصحيحٌ محاسبيّ' => ['accounting_correction', 'ISSUANCE_CORRECTION_SUSPENSE', 'asset'],
        ];
    }

    /**
     * @test
     *
     * **② والإيداعُ البنكيُّ لا يزيد نقدَ الخزينة بريالٍ واحد.**
     *
     * ══════════════════════════════════════════════════════════════════
     * وهذا هو الأثرُ الذي يُقاس في الجرد: خزنةٌ تُعدّ فتُوجد ناقصةً بمقدار
     * كلّ إيداعٍ بنكيٍّ سُجّل منذ النشأة — **وميزانُ المراجعة متوازنٌ طوال
     * الوقت**، فلا شيءَ يدلّ على السبب.
     */
    public function a_bank_deposit_does_not_inflate_the_cash_reserve(): void
    {
        $this->issue('bank_deposit', '500000');

        $cash = LedgerAccount::where('account_code', 'TREASURY_CASH_RESERVE')->first();

        $this->assertNull($cash,
            '**إيداعٌ بنكيٌّ لمس نقدَ الخزينة** — والجردُ سيجدها ناقصةً بمقداره');
    }

    /**
     * @test
     *
     * **③ ولا مصدرَ مخترَع — يُرفض قبل أن يُكتب حرف.**
     *
     * فقيمةٌ غيرُ معروفةٍ تعني أنّ الطرفَ المقابلَ مجهول، والقيدُ عندئذٍ
     * يخترع حساباً لا معنى له في أيّ تقرير.
     */
    public function an_unknown_funding_source_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('مصدرُ التمويل غيرُ معروف');

        $this->issue('من_عندي');
    }

    /**
     * @test
     *
     * **④ والمصدرُ يبقى في القيد — فيُصنَّف الإصدارُ في أيّ تقريرٍ لاحق.**
     */
    public function the_source_is_recorded_on_the_entry(): void
    {
        $issued = $this->issue('capital', '250000');
        $meta = (array) $issued['entry']->metadata;

        $this->assertSame('capital', $meta['funding_source'] ?? null,
            'المصدرُ غائبٌ عن القيد — فلا يُصنَّف الإصدارُ في تقرير');
        $this->assertSame('PLATFORM_CAPITAL', $meta['counter_account'] ?? null);
    }

    /**
     * @test
     *
     * **⑤ والقيدُ متوازنٌ في كلّ مصدر.**
     */
    public function every_source_posts_a_balanced_entry(): void
    {
        foreach (array_keys(PlatformTreasuryService::FUNDING_SOURCES) as $source) {
            $issued = $this->issue($source, '1000', 'BAL-'.$source);

            $d = LedgerEntryLine::where('journal_entry_id', $issued['entry']->id)
                ->where('direction', 'debit')->sum('amount');
            $c = LedgerEntryLine::where('journal_entry_id', $issued['entry']->id)
                ->where('direction', 'credit')->sum('amount');

            $this->assertSame(0, bccomp(
                number_format((float) $d, 4, '.', ''),
                number_format((float) $c, 4, '.', ''), 4),
                "قيدٌ غيرُ متوازنٍ في المصدر «{$source}»");
        }
    }

    /**
     * @test
     *
     * **⑥ والمستهلكُ الذي لا يذكر مصدراً يبقى عاملاً بسلوكه السابق.**
     *
     * ══════════════════════════════════════════════════════════════════
     * `treasury_supply` هو ما كان يفعله المسارُ دائماً. **والافتراضُ لا
     * يخترع مصدراً لم يُذكر** — يُبقي القديمَ على حاله بالضبط، فلا يتغيّر
     * معنى إصدارٍ سابقٍ بأثرٍ رجعيّ.
     */
    public function an_unspecified_source_keeps_the_previous_behaviour(): void
    {
        $issued = app(PlatformTreasuryService::class)->issueAdminFloat(
            '700', $this->admin, 'LEGACY-'.uniqid(), 'بلا مصدرٍ مذكور');

        $this->assertSame('TREASURY_CASH_RESERVE', $this->debitAccountOf($issued['entry']->id));
    }

    /**
     * @test
     *
     * **⑦ والشاشةُ تعرض الاختيارَ — وحقلٌ لا يُرى ليس مبنيّاً.**
     */
    public function the_screen_offers_the_choice(): void
    {
        $html = (string) file_get_contents(
            resource_path('views/admin-views/emoney/index.blade.php'));

        $this->assertStringContainsString('name="funding_source"', $html,
            '**الحقلُ في الخدمة ولا يُرى في الشاشة** — فيُصدَر كلُّ شيءٍ '
            . 'بالافتراض، ويبقى العطلُ قائماً حيث يقع');

        $this->assertStringContainsString('FUNDING_SOURCES', $html,
            'الخياراتُ مكتوبةٌ يدويّاً في القالب — تشيخ مع أوّل مصدرٍ جديد');

        $this->assertStringNotContainsString("translate('إنشاء الرصيد')", $html,
            '«إنشاء الرصيد» هي بعينها العبارةُ التي تطلب الوثيقةُ اختفاءها');
    }
}
