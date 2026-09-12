<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\CustomerCreditService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-CREDIT-GAP-001 — **ألفٌ في الرصيد بلا سطرٍ في القائمة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **من أين جاءت:** وصلت رقعتان من Codex تُضيفان «فواتيري الآجلة» — يرى
 * العميلُ كلَّ فاتورةٍ بمتبقّيها ويسدّد واحدةً بعينها. وهو تحسينٌ حقيقيّ.
 *
 * وقِيس أثرُه على حالةٍ قائمة، **فظهر فراغٌ لا يفسّره شيء**:
 *
 *     تعديلٌ يدويٌّ موجب  1000   (دَينٌ قديمٌ مُرحَّل — وبابُه قائمٌ
 *                                  في لوحة التاجر: `recordAdjustment`)
 *     ثمّ بيعةٌ آجلة       500
 *     ──────────────────────────
 *     current_balance = 1500
 *     invoices        =  500     ← وألفٌ بلا سطر
 *
 * لأنّ `openInvoices` تقتصر على قيود `sale` عمداً — **وهو قرارٌ صحيح**:
 * التعديلُ اليدويُّ ليس فاتورةً تُسدَّد وحدَها، واختراعُ سطرٍ له بزرّ
 * سدادٍ لا يقبله المحرّك أسوأُ من غيابه.
 *
 * **لكنّ الصمتَ عن الفرق ليس صحيحاً.** يقرأ العميلُ «عليّ ٥٠٠» في
 * القائمة و«١٥٠٠» في البطاقة، فيظنّ أحدَهما عطلاً — أو يظنّ أنّ سدادَ
 * الفاتورة يُبرئ ذمّتَه. (القاعدة السابعة: الغيابُ يُقال ولا يُترَك
 * فراغاً يُقرأ صفراً.)
 *
 * **ولا يُغيَّر منطقُ التوزيع.** الرقعةُ من غيري، ونيّتُها مكتوبةٌ في
 * شيفرتها؛ وهذا يُضيف بياناً فوقها ولا يمسّ ريالاً.
 */
class CreditInvoiceGapGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = User::factory()->create([
            'type' => MERCHANT_TYPE, 'role' => A::ROLE_MERCHANT,
            'is_active' => 1, 'zone_code' => 'SOUTH',
        ]);

        MerchantProfile::create([
            'user_id' => $this->merchant->id, 'tier' => 'small',
            'verification_status' => 'verified',
            'business_type' => A::BIZ_RETAIL,
            'subscription_plan' => A::PLAN_BUSINESS,
        ]);

        $this->customer = User::factory()->create([
            'type' => CUSTOMER_TYPE, 'phone' => '967771112223',
            'is_active' => 1, 'zone_code' => 'SOUTH',
        ]);
    }

    private function accountWithGap(): int
    {
        $credit = app(CustomerCreditService::class);

        $account = $credit->findOrCreateAccount(
            $this->merchant->id, '967771112223', 'زبون قياس');

        // دَينٌ قديمٌ مُرحَّل — ليس فاتورةً، وهو في الرصيد.
        $credit->recordAdjustment($account, '1000', 'دين قديم مُرحَّل', $this->merchant->id);

        $credit->recordSale(
            account: $account->fresh(),
            amount: '500',
            note: 'بيعة آجلة',
            createdBy: $this->merchant->id,
            referenceType: 'merchant_sale',
            referenceId: 'SALE-XYZ',
        );

        return $account->id;
    }

    /**
     * @test
     *
     * **① الفرقُ يُرسَل باسمه ومعه سببُه.**
     */
    public function the_statement_states_what_is_not_an_invoice(): void
    {
        $id = $this->accountWithGap();

        $meta = $this->actingAs($this->customer, 'api')
            ->getJson("/api/v1/amial/customer/credits/{$id}/statement")
            ->assertOk()->json('meta');

        $this->assertSame(0, bccomp((string) $meta['current_balance'], '1500', 4));
        $this->assertSame(0, bccomp((string) $meta['invoices_total'], '500', 4),
            'مجموعُ الفواتير لا يطابق ما تعرضه القائمة');

        $this->assertSame(0, bccomp((string) $meta['unlinked_balance'], '1000', 4),
            'الفرقُ بين الرصيد ومجموع الفواتير ('
            . ($meta['unlinked_balance'] ?? 'غائب')
            . ') لا يُقال — فيقرأ العميلُ رقمين ولا يعرف أيّهما دَينُه.');

        $this->assertNotEmpty($meta['unlinked_note_ar'] ?? '',
            'الفرقُ رقمٌ بلا تفسير — والرقمُ وحدَه يُقرأ عطلاً');
    }

    /**
     * @test
     *
     * **② ولا لافتةَ حيث لا فرق.**
     *
     * حسابٌ كلُّ دينِه فواتيرُ لا يُقال له «مبلغٌ خارج الفواتير» — لافتةٌ
     * تظهر بلا سببٍ تُعوّد القارئَ أن يتجاهلها يومَ تصدق.
     */
    public function no_banner_when_every_riyal_has_an_invoice(): void
    {
        $credit = app(CustomerCreditService::class);

        $account = $credit->findOrCreateAccount(
            $this->merchant->id, '967771112223', 'زبون قياس');

        $credit->recordSale(
            account: $account,
            amount: '700',
            note: 'بيعة آجلة',
            createdBy: $this->merchant->id,
            referenceType: 'merchant_sale',
            referenceId: 'SALE-ONLY',
        );

        $meta = $this->actingAs($this->customer, 'api')
            ->getJson("/api/v1/amial/customer/credits/{$account->id}/statement")
            ->assertOk()->json('meta');

        $this->assertSame(0, bccomp((string) $meta['unlinked_balance'], '0', 4));
        $this->assertNull($meta['unlinked_note_ar'],
            'لافتةٌ تظهر بلا فرق — وتعويدُ القارئ على تجاهلها يُفقدها يومَ تصدق');
    }

    /**
     * @test
     *
     * **③ ومجموعُ الفواتير يُحسب من القائمة نفسِها لا من مصدرٍ ثانٍ.**
     *
     * (القاعدة السادسة.) مجموعٌ يُستعلَم عنه على حدةٍ يمكن أن يخالف ما
     * رُسم — فيقول الصدرُ «٥٠٠» والسطورُ تجمع ٧٠٠، ولا خطأ في أيّ سجلّ.
     */
    public function the_total_equals_the_sum_of_the_rows_that_are_shown(): void
    {
        $id = $this->accountWithGap();

        $meta = $this->actingAs($this->customer, 'api')
            ->getJson("/api/v1/amial/customer/credits/{$id}/statement")
            ->assertOk()->json('meta');

        $sum = '0';
        foreach (($meta['invoices'] ?? []) as $invoice) {
            $sum = bcadd($sum, (string) $invoice['remaining'], 4);
        }

        $this->assertSame(0, bccomp($sum, (string) $meta['invoices_total'], 4),
            "مجموعُ السطور {$sum} والمعلَن {$meta['invoices_total']} — ورقمان يفترقان");
    }
}
