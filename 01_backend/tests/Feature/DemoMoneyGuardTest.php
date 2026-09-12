<?php

namespace Tests\Feature;

use App\Console\Commands\EnsureDemoMerchants;
use App\Models\EMoney;
use App\Models\Ledger\LedgerJournalEntry;
use App\Models\User;
use App\Services\LedgerReportService;
use App\Support\DemoAccountPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * AMIAL-DEMO-MONEY-001 — **مالٌ يُسَكّ في كلّ إقلاعٍ على الإنتاج.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * سأل صاحبُ المشروع: «كلّ محافظ التجّار فيها ١٠٠ ألف — من أين أتت؟».
 * وقِيس: `EnsureDemoMerchants::ensureWallet()` يكتب `100000.0000`،
 * و`docker/entrypoint.sh` يستدعيه في **كلّ إقلاع حاوية**.
 *
 * **وهي في الدفتر** — `EMoneyObserver` يكتب قيدَ افتتاحٍ متوازناً، فالمصالحةُ
 * تُخرج فرقاً صفراً. **وتوازنُ القيد ليس مشروعيّةَ المال**: حسابُ الافتتاح
 * يعني «وُجد قبل الدفتر»، ولا أحدَ أودعه.
 *
 * وستُّ حالاتٍ تحرس الحدَّ، **وأخطرُها ⑤**: قيدٌ عكسيٌّ أعمى يبتلع ما ربحه
 * تاجرٌ من بيعةٍ حقيقيّة.
 */
class DemoMoneyGuardTest extends TestCase
{
    use RefreshDatabase;

    private function inProduction(?string $optIn = null, ?string $balance = null): void
    {
        $this->app['env'] = 'production';
        $this->set(DemoAccountPolicy::OPT_IN, $optIn);
        $this->set(DemoAccountPolicy::WALLET_ENV, $balance);
    }

    private function set(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);

            return;
        }

        $_ENV[$key] = $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    }

    protected function tearDown(): void
    {
        $this->set(DemoAccountPolicy::OPT_IN, null);
        $this->set(DemoAccountPolicy::WALLET_ENV, null);
        parent::tearDown();
    }

    /** @test */
    public function in_production_the_demo_merchant_is_born_with_an_empty_wallet(): void
    {
        $this->inProduction();

        Artisan::call('amial:ensure-demo-merchants');

        $merchant = User::where('phone', '967777200001')->first();

        $this->assertNotNull($merchant,
            'الحسابُ نفسُه لم يُنشأ — والحدُّ على المال لا على الباب. '
            .'(فقدُ الوصول أسوأ من رصيدٍ معروض.)');

        $this->assertSame('0.0000',
            (string) EMoney::where('user_id', $merchant->id)->value('current_balance'),
            'سُكّت ١٠٠٬٠٠٠ ريالاً على الإنتاج بلا موافقة — التزامٌ على المنصّة '
            .'لم يودعه أحد، ويدخل «إجمالي أرصدة التجّار» كأنّه مالُ تاجر');

        $this->assertSame(0,
            LedgerJournalEntry::where('source_type', 'opening_balance')->count(),
            'قيدُ افتتاحٍ كُتب لمحفظةٍ فارغة — قيدٌ بمجموع صفر لا معنى له');
    }

    /** @test */
    public function outside_production_the_demo_keeps_its_money(): void
    {
        // بيئةُ الاختبار ليست إنتاجاً — وهذا هو ما يُبقي العرضَ ممكناً.
        $this->assertSame(
            EnsureDemoMerchants::DEMO_BALANCE,
            DemoAccountPolicy::seededWalletBalance(EnsureDemoMerchants::DEMO_BALANCE),
            'أُفرغت محافظُ العرض في التطوير أيضاً — فلا تجربةَ بيعٍ ولا تحويل');
    }

    /** @test */
    public function production_honours_an_explicit_balance(): void
    {
        $this->inProduction(balance: '25000');

        $this->assertSame('25000.0000',
            DemoAccountPolicy::seededWalletBalance(EnsureDemoMerchants::DEMO_BALANCE),
            'ما يُملى صراحةً في البيئة لا يُقرأ — فلا بابَ لعرضٍ مقصود');

        // **وما ليس رقماً لا يصير رقماً.** قيمةٌ مشوّهة تُقرأ صفراً ولا
        // تُمرَّر إلى القاعدة.
        $this->set(DemoAccountPolicy::WALLET_ENV, 'كثير');
        $this->assertSame('0.0000',
            DemoAccountPolicy::seededWalletBalance(EnsureDemoMerchants::DEMO_BALANCE));
    }

    /** @test */
    public function the_revocation_removes_the_money_and_keeps_the_ledger_true(): void
    {
        Artisan::call('amial:ensure-demo-merchants');

        $merchant = User::where('phone', '967777200001')->first();
        $this->assertSame('100000.0000',
            (string) EMoney::where('user_id', $merchant->id)->value('current_balance'));

        Artisan::call('amial:revoke-demo-money', ['--confirm' => true]);

        $this->assertSame('0.0000',
            (string) EMoney::where('user_id', $merchant->id)->value('current_balance'),
            'المالُ باقٍ بعد السحب');

        // **والمحفظةُ والدفترُ يتحرّكان معاً أو لا يتحرّكان.** سحبٌ يمسّ
        // أحدَهما يستبدل عطلاً بانحرافٍ يشكو منه المصالحُ كلَّ ليلة.
        $report = app(LedgerReportService::class)->walletReconciliation(50);
        $this->assertSame(0, (int) $report['divergent'],
            'انحرافٌ بين المحفظة والدفتر بعد السحب — أي العطلُ نفسُه معكوساً');

        $this->assertSame(1, LedgerJournalEntry::where('source_type', 'opening_balance_reversal')
            ->where('source_id', (string) $merchant->id)->count(),
            'المالُ ذهب بلا قيدٍ عكسيّ — فلا أثرَ يُقرأ منه أين ذهب');
    }

    /** @test */
    public function it_refuses_to_take_what_a_merchant_earned(): void
    {
        Artisan::call('amial:ensure-demo-merchants');

        $merchant = User::where('phone', '967777200001')->first();

        // أنفق التاجرُ من الرصيد في التجربة — فبقي دون المسكوك.
        EMoney::where('user_id', $merchant->id)->update(['current_balance' => '40000.0000']);

        Artisan::call('amial:revoke-demo-money', ['--confirm' => true]);

        $this->assertSame('40000.0000',
            (string) EMoney::where('user_id', $merchant->id)->value('current_balance'),
            'سُحبت ١٠٠٬٠٠٠ من محفظةٍ فيها ٤٠٬٠٠٠ — فنزلت تحت الصفر، '
            .'وذهب معها ما ربحه التاجرُ من بيعةٍ حقيقيّة');

        $this->assertSame(0, LedgerJournalEntry::where('source_type', 'opening_balance_reversal')
            ->where('source_id', (string) $merchant->id)->count(),
            'كُتب قيدٌ عكسيٌّ لمن لم يُسحَب منه');
    }

    /** @test */
    public function without_confirm_nothing_moves(): void
    {
        Artisan::call('amial:ensure-demo-merchants');

        $merchant = User::where('phone', '967777200001')->first();

        Artisan::call('amial:revoke-demo-money');

        $this->assertSame('100000.0000',
            (string) EMoney::where('user_id', $merchant->id)->value('current_balance'),
            'تحرّك مالٌ بلا --confirm — وأمرٌ ماليٌّ يقع بالعرض وحدَه لا يُوثَق به');
    }

    /** @test */
    public function the_boot_script_never_moves_money_by_itself(): void
    {
        $entrypoint = file_get_contents(base_path('docker/entrypoint.sh'));

        $this->assertStringNotContainsString('revoke-demo-money', (string) $entrypoint,
            'أمرُ السحب استُدعي من الإقلاع — أمرٌ يُحرّك مالاً في كلّ إقلاعِ '
            .'حاويةٍ هو العطلُ نفسُه بثوبٍ معكوس');
    }
}
