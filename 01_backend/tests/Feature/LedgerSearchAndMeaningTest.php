<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\User;
use App\Services\LedgerReportService;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-LEDGER-SEARCH-001/002 — **مدخلُ المحقّق، ومعنى الاتّجاه.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * المحوران ٢٦ و٢٧ من وثيقة مركز الدفتر:
 *
 *     ٢٦) لا تسمِّ `Debit = من` و`Credit = إلى` — استخدم «مدين» و«دائن»
 *         ثمّ أضف **تفسيراً اقتصاديّاً منفصلاً**.
 *     ٢٧) وسّع البحثَ ليشمل رقمَ المعاملة والهاتفَ ومعرّفَ المستخدم…
 *
 * **والمحقّقُ لا يبدأ من رقم القيد**: يبدأ ممّا في يده — رقمِ معاملةٍ
 * يشتكي منها عميل، أو هاتفٍ على شاشة الدعم. وبحثٌ لا يقبل إلّا
 * `entry_ulid` **يفترض أنّ السائلَ يعرف الجوابَ سلفاً**.
 */
class LedgerSearchAndMeaningTest extends TestCase
{
    use RefreshDatabase;

    private function reports(): LedgerReportService
    {
        return app(LedgerReportService::class);
    }

    private function customer(string $phone): User
    {
        $u = User::factory()->create(['type' => CUSTOMER_TYPE, 'phone' => $phone]);
        EMoney::where('user_id', $u->id)->delete();
        EMoney::create([
            'user_id' => $u->id, 'current_balance' => '0.0000',
            'charge_earned' => '0.0000', 'pending_balance' => '0.0000',
            'held_balance' => '0.0000', 'zone_code' => 'SOUTH', 'version' => 0,
        ]);

        return $u;
    }

    /** قيدُ إيداعٍ حقيقيّ: نقدٌ يدخل (أصل) ومحفظةُ العميل تُقيَّد دائنة. */
    private function depositFor(User $u, string $amount, string $sourceId): void
    {
        $l = app(LedgerService::class);
        $cash = $l->getOrCreateSystemAccount('TREASURY_CASH_RESERVE', 'asset', 'نقد الخزينة', 'debit');
        $wallet = $l->getOrCreateUserWallet($u->id);

        $l->post(
            sourceType: 'add_money', sourceId: $sourceId,
            description: 'إيداعُ عميل',
            lines: [
                ['account' => $cash->account_code, 'direction' => 'debit', 'amount' => $amount],
                ['account' => $wallet->account_code, 'direction' => 'credit', 'amount' => $amount],
            ],
            idempotencyKey: 'search-probe-'.$sourceId,
        );
    }

    /**
     * @test
     *
     * **① يُبحث برقم المعاملة — لا برقم القيد وحدَه.**
     */
    public function an_entry_is_findable_by_its_source_reference(): void
    {
        $u = $this->customer('967771230001');
        $this->depositFor($u, '5000', 'TXN-ALPHA');
        $this->depositFor($u, '3000', 'TXN-BETA');

        $found = $this->reports()->searchEntries(['source_id' => 'TXN-ALPHA']);

        $this->assertCount(1, $found,
            '**البحثُ لا يقبل رقمَ المعاملة** — والمحقّقُ لا يملك رقمَ القيد');
        $this->assertSame('TXN-ALPHA', $found[0]['source_id']);
    }

    /**
     * @test
     *
     * **② ويُبحث بهاتف صاحب الحساب — وهو ما على شاشة الدعم.**
     */
    public function entries_are_findable_by_the_account_holders_phone(): void
    {
        $a = $this->customer('967771230002');
        $b = $this->customer('967771230003');
        $this->depositFor($a, '7000', 'TXN-A1');
        $this->depositFor($b, '9000', 'TXN-B1');

        $found = $this->reports()->searchEntries(['phone' => '771230002']);

        $this->assertCount(1, $found, 'البحثُ بالهاتف لم يُقصر النتيجةَ على صاحبه');
        $this->assertSame('TXN-A1', $found[0]['source_id']);
    }

    /**
     * @test
     *
     * **③ وهاتفٌ لا حسابَ له يُخرج «لا نتائج» — لا الدفترَ كلَّه.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **وهذا أخطرُ ما في المرشِّحات:** مرشِّحٌ يُسقَط بصمتٍ عند غياب قيمته
     * يعرض الدفترَ كلَّه لمن سأل عن شخصٍ واحد — **وهو تسريبٌ لا نقصُ
     * دقّة**، ويقع بلا خطأٍ في أيّ سجلّ.
     */
    public function an_unknown_phone_returns_nothing_rather_than_everything(): void
    {
        $u = $this->customer('967771230004');
        $this->depositFor($u, '1000', 'TXN-C1');

        $found = $this->reports()->searchEntries(['phone' => '700000000']);

        $this->assertSame([], $found,
            '**هاتفٌ مجهولٌ عرض الدفترَ كلَّه** — تسريبٌ بلا خطأٍ في أيّ سجلّ');
    }

    /**
     * @test
     *
     * **④ والأثرُ الاقتصاديُّ يُقال — و«من/إلى» تنقلب في نصف القيود.**
     *
     * ══════════════════════════════════════════════════════════════════
     * حسابُ العميل **التزامٌ** على المنصّة: إيداعُه يُقيَّد **دائناً** —
     * أي «إلى» بمنطق التحويل — وهو في الحقيقة **زيادةُ ما ندين به له**.
     * ومن قرأ «إلى العميل» على قيد إيداعٍ ظنّ أنّ مالاً خرج من المنصّة
     * إليه، **والعكسُ هو الواقع**.
     */
    public function the_economic_effect_is_stated_not_left_to_from_and_to(): void
    {
        $u = $this->customer('967771230005');
        $this->depositFor($u, '12000', 'TXN-D1');

        $entry = $this->reports()->searchEntries(['source_id' => 'TXN-D1'])[0];
        $lines = $entry['economic_effect'];

        $this->assertNotEmpty($lines,
            'لا تفسيرَ اقتصاديّ — والاتّجاهُ وحدَه يُقرأ خطأً في نصف القيود');

        // **كلُّ سطرٍ يُثبَّت على حدة.** وتأكيدٌ يبحث عن كلمةٍ في النصّ
        // كلِّه يمرّ ولو انقلب سطرٌ واحد — وقد وقع ذلك في هذا الملفّ:
        // أُعيد العطلُ («مدين = زيادةٌ دائماً») **فمرّ الحارس**. وحارسٌ
        // يمرّ والعطلُ قائمٌ أسوأ من غيابه.
        $of = function (string $needle) use ($lines): string {
            foreach ($lines as $line) {
                if (str_contains($line, $needle)) {
                    return $line;
                }
            }

            return '';
        };

        // النقدُ أصلٌ مدينٌ ⇒ **زاد**.
        $this->assertStringStartsWith('زاد', $of('نقد'),
            'قيدٌ مدينٌ على أصلٍ لم يُقرأ زيادة');

        // ومحفظةُ العميل **التزامٌ دائن** ⇒ **زاد** ما ندين به له.
        // وهذا هو السطرُ الذي ينقلب حين يُقرأ «مدين = من».
        $this->assertStringStartsWith('زاد', $of('محفظة'),
            '**سطرُ المحفظة انقلب**: إيداعٌ قُرئ نقصاً في التزامنا تجاه '
            . 'العميل — وهو أشيعُ خطأٍ في قراءة الدفتر');

        $this->assertStringNotContainsString('من ', implode(' | ', $lines),
            '**عاد اصطلاحُ «من/إلى»** — وهو ينقلب في نصف القيود');
    }

    /**
     * @test
     *
     * **⑤ والمدينُ على التزامٍ يُقرأ نقصاً لا زيادة.**
     *
     * فالقاعدةُ المحاسبيّةُ نفسُها هي الحكم، لا موضعُ السطر في القيد.
     */
    public function a_debit_on_a_liability_reads_as_a_decrease(): void
    {
        $u = $this->customer('967771230006');
        // **يُودع أوّلاً** — والدفترُ يرفض بحقٍّ خصماً من حسابٍ فارغ.
        // (‏وهذا الرفضُ نفسُه حارسٌ قائم، لا عائقٌ في التركيبة.)
        $this->depositFor($u, '10000', 'TXN-E0');

        $l = app(LedgerService::class);
        $wallet = $l->getOrCreateUserWallet($u->id);
        $cash = $l->getOrCreateSystemAccount('TREASURY_CASH_RESERVE', 'asset', 'نقد الخزينة', 'debit');

        // سحب: محفظةُ العميل مدينة (‏ينقص ما ندين به)، والنقدُ دائنٌ (‏ينقص).
        $l->post(
            sourceType: 'cash_out_executed', sourceId: 'TXN-E1',
            description: 'سحبُ عميل',
            lines: [
                ['account' => $wallet->account_code, 'direction' => 'debit', 'amount' => '4000'],
                ['account' => $cash->account_code, 'direction' => 'credit', 'amount' => '4000'],
            ],
            idempotencyKey: 'search-probe-E1',
        );

        $lines = $this->reports()->searchEntries(['source_id' => 'TXN-E1'])[0]['economic_effect'];

        $of = function (string $needle) use ($lines): string {
            foreach ($lines as $line) {
                if (str_contains($line, $needle)) {
                    return $line;
                }
            }

            return '';
        };

        $this->assertStringStartsWith('انخفض', $of('نقد'),
            'دائنٌ على أصلٍ لم يُقرأ نقصاً');

        // **السطرُ الحاسم**: مدينٌ على التزامٍ = نقصٌ فيما ندين به.
        $this->assertStringStartsWith('انخفض', $of('محفظة'),
            'مدينٌ على التزامٍ لم يُقرأ نقصاً — **وهو أشيعُ خطأٍ في قراءة الدفتر**');
    }

    /**
     * @test
     *
     * **⑥ والشاشةُ لا تسمّي العمودين «من» و«إلى».**
     */
    public function the_screen_no_longer_labels_the_columns_from_and_to(): void
    {
        $html = (string) file_get_contents(
            resource_path('views/admin-views/amial/ledger/index.blade.php'));

        $this->assertStringNotContainsString('من (مدين)', $html,
            '**«من (مدين)» باقية** — والاصطلاحُ ينقلب في نصف القيود');
        $this->assertStringNotContainsString('إلى (دائن)', $html);
        $this->assertStringContainsString('الأثر الاقتصاديّ', $html,
            'لا عمودَ للأثر الاقتصاديّ — والاتّجاهُ وحدَه لا يُقرأ');
        $this->assertStringContainsString('lg-e-phone', $html,
            'مدخلُ الهاتف في الخدمة ولا يُرى في الشاشة — **وحقلٌ لا يُرى ليس مبنيّاً**');
    }
}
