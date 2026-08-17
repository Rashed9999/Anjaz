<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-FEE-TRUTH-003 — **محرّكُ رسومٍ واحد، ومحرّرٌ واحد.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمنُ الذي قِيس في هذا القطاع:**
 *
 * كان في المشروع **محرّكان للرسم نفسِه**:
 *
 *   `fee_schemes` + `FeeService`   ← تكتبه شاشةُ «إدارة الرسوم والعمولات»
 *   `business_settings`            ← تكتبه شاشةُ `charge-setup` القديمة
 *
 * و**المالُ الحيُّ كان على القديم**: `Customer\TransactionController`
 * ينادي `Helpers::get_sendmoney_charge()` و`get_cashout_charge()`.
 *
 * وأخطرُ ما فيه أنّ الصوابَ كان مبنيّاً: `send_money_with_fee_engine` و
 * `cash_out_with_fee_engine` في `TransactionTrait` تفعلان الصواب —
 * **ولا يناديهما إلّا الاختبارات**. فالمجموعةُ تُثبت أنّ المحرّك يعمل،
 * والمالُ يمرّ من مكانٍ آخر، **ولا خطأَ في أيّ سجلّ**.
 *
 * فالمديرُ يغيّر رسمَ التحويل في الشاشة ولا يتغيّر شيء.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وهذا الحارسُ يمنع العودة**: أيُّ مسارٍ ماليٍّ حيٍّ يقرأ رسماً من
 * `business_settings` يُسقطه.
 */
class FeeSingleTruthGuardTest extends TestCase
{
    /**
     * دوالُّ الرسوم القديمة — تُقرأ من `business_settings`.
     *
     * **وتبقى موجودةً عمداً**: `ConfigController` يعرضها للتطبيق للعرض،
     * وحذفُها اليومَ يكسر شاشاتٍ تقرأ الإعداد. **والممنوعُ استعمالُها في
     * حسابِ رسمٍ يُخصَم فعلاً.**
     *
     * @var array<int,string>
     */
    private const LEGACY_CALCULATORS = [
        'get_sendmoney_charge',
        'get_cashout_charge',
        'get_withdraw_charge',
    ];

    /**
     * الملفّاتُ التي **تُحرّك مالاً** — وفيها وحدَها يُمنع القديم.
     *
     * ولا يُمسح المشروعُ كلُّه: `ConfigController` يعرض القيمَ للتطبيق،
     * و`BusinessSettingsController` يكتبها، وكلاهما ليس حساباً ماليّاً.
     *
     * @var array<int,string>
     */
    private function financialPaths(): array
    {
        return [
            app_path('Http/Controllers/Api/V1/Customer/TransactionController.php'),
            app_path('Http/Controllers/Api/V1/Agent/TransactionController.php'),
            app_path('Traits/TransactionTrait.php'),
            app_path('Services/AgentCounterService.php'),
            app_path('Services/CustomerWithdrawService.php'),
            app_path('Services/SafePaymentService.php'),
            app_path('Services/PaymentRequestService.php'),
        ];
    }

    /**
     * @test
     *
     * **① لا مسارَ ماليٌّ حيٌّ يحسب رسماً من `business_settings`.**
     */
    public function no_live_money_path_computes_a_fee_from_legacy_settings(): void
    {
        $offenders = [];

        foreach ($this->financialPaths() as $path) {
            if (! is_file($path)) {
                continue;
            }

            $src = (string) file_get_contents($path);

            // **التعليقاتُ تُنزع أوّلاً.** ذكرُ الدالّة في شرحٍ يفسّر
            // لماذا نُزعت ليس استعمالاً لها — **وهذا الفخُّ أوقع هذا
            // المشروعَ من قبل**: حارسٌ مرّ لأنّ الكلمةَ وردت في تعليقٍ
            // عربيٍّ يصف العطل.
            $code = (string) preg_replace('~//[^\n]*|/\*.*?\*/~s', '', $src);

            foreach (self::LEGACY_CALCULATORS as $fn) {
                if (preg_match('~(?:Helpers|helpers)::'.preg_quote($fn, '~').'\s*\(~', $code)) {
                    // **المسارُ النسبيُّ لا الاسمُ المجرّد** — ملفّان باسم
                    // `TransactionController.php` (‏عميلٌ ووكيل) يُقرآن
                    // واحداً فيُخفي أحدُهما الآخر في البلاغ.
                    $offenders[] = str_replace(base_path().'/', '', $path).' → '.$fn.'()';
                }
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "مسارٌ ماليٌّ حيٌّ يحسب الرسمَ من `business_settings` بينما "
            . "«إدارة الرسوم» تكتب في `fee_schemes` — **فالمديرُ يغيّر "
            . "الرقمَ ولا يتغيّر شيء**:\n  %s\n\n"
            . 'استعمِل `FeeService::calculate()`.',
            implode("\n  ", $offenders)));
    }

    /**
     * @test
     *
     * **② ومحرّكُ الرسوم له مستهلكٌ إنتاجيٌّ لا اختباراتٌ فقط.**
     *
     * ══════════════════════════════════════════════════════════════
     * فدالّةٌ صحيحةٌ **لا يناديها إلّا الاختبار** تُنتج أسوأَ حالة: مجموعةٌ
     * خضراءُ فوق مالٍ يمرّ من طريقٍ آخر. وقد كانت هذه حالَ
     * `send_money_with_fee_engine` بالضبط.
     */
    public function the_fee_engine_has_a_production_consumer_not_only_tests(): void
    {
        $live = [];

        foreach ($this->financialPaths() as $path) {
            if (! is_file($path)) {
                continue;
            }

            $code = (string) preg_replace('~//[^\n]*|/\*.*?\*/~s', '',
                (string) file_get_contents($path));

            if (preg_match('~FeeService::class\)?\s*\)?->calculate\(|fees->calculate\(~', $code)) {
                $live[] = basename($path);
            }
        }

        $this->assertNotEmpty($live,
            '**لا مسارَ إنتاجيٍّ واحدٍ ينادي محرّكَ الرسوم** — فالمحرّكُ '
            . 'مبنيٌّ ومختبَرٌ ولا يُستعمل، والمالُ يمرّ من مكانٍ آخر.');

        // والمسارُ الأهمُّ بالاسم: تحويلُ العميل وسحبُه.
        $this->assertContains('TransactionController.php', $live,
            'متحكّمُ معاملات العميل لا ينادي محرّكَ الرسوم — وهو أكثرُ '
            . 'المسارات استعمالاً في المنتج');
    }

    /**
     * @test
     *
     * **③ وشاشةُ الرسوم القديمة لا تكتب رسماً بلا صلاحيّةِ رسوم.**
     *
     * فبابان يغيّران المالَ نفسَه وأحدُهما بلا حارسٍ دقيقٍ هو **بابُ
     * الالتفاف**: تُقفل الشاشةُ الجديدة ويُترك القديمُ مفتوحاً.
     */
    public function the_legacy_fee_screen_requires_the_fee_permission(): void
    {
        $routes = collect(\Illuminate\Support\Facades\Route::getRoutes())
            ->filter(fn ($r) => str_contains($r->uri(), 'charge-setup'));

        if ($routes->isEmpty()) {
            $this->markTestSkipped('لا مسارَ `charge-setup` — أُزيل المحرّرُ القديم');
        }

        $unguarded = [];

        foreach ($routes as $r) {
            $middleware = $r->gatherMiddleware();

            $hasFeePermission = false;

            foreach ($middleware as $m) {
                if (is_string($m) && str_contains($m, 'platform.fees')) {
                    $hasFeePermission = true;
                }
            }

            if (! $hasFeePermission) {
                $unguarded[] = implode('|', $r->methods()).' '.$r->uri();
            }
        }

        $this->assertSame([], $unguarded, sprintf(
            "شاشةُ رسومٍ قديمةٌ تكتب بلا صلاحيّة `platform.fees.*`:\n  %s",
            implode("\n  ", $unguarded)));
    }
}
