<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-SIM-GUARD-001 — **المحاكي مرآةٌ للخادم، ومرآةٌ ناقصةٌ تكذب.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمنُ الذي دُفع:** بُني محاكي الجملة وفيه شاشةُ فاتورةٍ بطريقتَي
 * سدادٍ — «نقداً» و«آجل» — **وأُسقط الدفعُ عبر أميال باي**، وهو رِكابُ
 * المنصّة نفسِها. وكشفه صاحبُ المشروع بعينه: «لا أرى الدفع عبر أميال
 * باي».
 *
 * **وأسوأُ ما فيه أنّ المعلومة كانت حاضرة**: في دليل تشغيل الجملة
 * («فاتورة جديدة، QR، نقد، آجل، طباعة») — وقد اقتُبس نصُّه في حارسٍ
 * مكتوبٍ باليد — وفي `WholesaleController` نفسِه.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والحارسُ لا يحفظ القيمَ مكتوبةً بل يقرؤها من المُتحقِّق.** فقيمةٌ
 * تُضاف في الخادم غداً — «محفظة» أو «بطاقة» — تُسقط هذا الفحصَ حتّى
 * تظهر في المحاكي. **ورقمٌ مكتوبٌ هنا كان سيشيخ مع أوّل قرار.**
 *
 * وهذا صنفُ العطل الذي لا تمسكه بوّابةٌ خضراء: `verify.sh` أخرج ٣٣
 * نجاحاً وصفرَ فشلٍ والمحاكي بلا دفع — **لأنّها تفحص الشيفرةَ ولا تسأل
 * هل يطابق ما يُعرَض ما يقبله الخادم.**
 */
class SimulatorMirrorsServerContractGuardTest extends TestCase
{
    private const SIM = __DIR__ . '/../../../docs/محاكيات/محاكي-تاجر-الجملة.html';
    private const CTRL = __DIR__ . '/../../app/Http/Controllers/Api/V1/Amial/WholesaleController.php';

    private const RX_SIM = __DIR__ . '/../../../docs/محاكيات/محاكي-الصيدلية.html';
    private const RX_CTRL = __DIR__ . '/../../app/Http/Controllers/Api/V1/Amial/PharmacyController.php';

    private function sim(): string
    {
        $this->assertFileExists(self::SIM, 'محاكي الجملة مفقود — والحارسُ يفحص العدم.');

        return (string) file_get_contents(self::SIM);
    }

    /**
     * القيمُ المقبولةُ في قاعدة تحقّقٍ مثل `'required|in:cash,amial_pay,credit'`.
     *
     * @return list<string>
     */
    private function acceptedValues(string $field, ?string $ctrl = null): array
    {
        $src = (string) file_get_contents($ctrl ?? self::CTRL);

        $this->assertMatchesRegularExpression(
            "/'{$field}'\s*=>\s*'[^']*\bin:/", $src,
            "**لم يعد للحقل «{$field}» قاعدةُ `in:` في المتحكّم.** فإمّا "
            . 'تغيّر اسمُه وإمّا صار مفتوحاً — وفي الحالتين هذا الحارسُ '
            . 'يفحص فراغاً. (القاعدة السابعة: صفرٌ لا يعني «فُحص».)');

        preg_match("/'{$field}'\s*=>\s*'[^']*\bin:([a-z_,]+)/", $src, $m);

        return array_values(array_filter(explode(',', $m[1] ?? '')));
    }

    /**
     * **① الفاتورةُ: كلُّ طريقةِ سدادٍ يقبلها الخادمُ تُعرَض في المحاكي.**
     *
     * `createInvoice` يقبل `cash · amial_pay · credit`. وكان المحاكي
     * يعرض اثنتين.
     */
    /** @test */
    public function every_invoice_payment_type_the_server_accepts_is_shown_in_the_simulator(): void
    {
        $sim = $this->sim();
        $accepted = $this->acceptedValues('payment_type');

        $this->assertGreaterThanOrEqual(3, count($accepted),
            'قُرئت طرقُ سدادٍ أقلُّ ممّا كان — تغيّرت صياغةُ المتحقِّق.');

        $missing = array_values(array_filter($accepted,
            fn (string $v): bool => ! str_contains($sim, "data-pay=\"{$v}\"")));

        $this->assertSame([], $missing, sprintf(
            "**طرقُ سدادٍ يقبلها الخادمُ ولا يعرضها المحاكي:**\n  %s\n\n"
            . 'ومحاكٍ ينقص منه رِكابُ الدفع يُري صاحبَ المشروع منتجاً '
            . 'ليس منتجَه — **وهو أخطرُ من غياب المحاكي كلِّه**، لأنّه '
            . 'يُقرأ إثباتاً.',
            implode('، ', $missing)));
    }

    /**
     * **② والتحصيلُ كذلك — وهو أربعُ طرقٍ لا ثلاث.**
     */
    /** @test */
    public function every_collection_payment_method_the_server_accepts_is_shown(): void
    {
        $sim = $this->sim();
        $accepted = $this->acceptedValues('payment_method');

        $this->assertGreaterThanOrEqual(4, count($accepted),
            'قُرئت طرقُ تحصيلٍ أقلُّ ممّا كان — تغيّرت صياغةُ المتحقِّق.');

        // ══════════════════════════════════════════════════════════════
        // **والسمةُ تُبنى في وقت التشغيل، فلا تُطلَب حرفيّةً في المصدر.**
        // `data-collect="cash"` لا يظهر نصّاً — بل `'<button data-collect="'
        // + m[0]`. فيُقرأ **مصفوفُ الطرق** نفسُه: أوّلُ ما يُبنى منه الزرّ.
        // (أوّلُ صياغةٍ لهذا الفحص طلبت النصَّ الحرفيَّ فسقطت على الأربع
        //  جميعاً والمحاكي سليم — حارسٌ يكذب، فصُحّح.)
        // ══════════════════════════════════════════════════════════════
        $at = strpos($sim, "data-collect=\"'");
        $this->assertNotFalse($at,
            'اختفى بناءُ أزرار التحصيل من المحاكي — والحارسُ يفحص فراغاً.');

        $block = substr($sim, max(0, $at - 400), 400);

        $missing = array_values(array_filter($accepted,
            fn (string $v): bool => ! str_contains($block, "'{$v}'")));

        $this->assertSame([], $missing, sprintf(
            "**طرقُ تحصيلٍ يقبلها الخادمُ ولا يعرضها المحاكي:**\n  %s",
            implode('، ', $missing)));
    }

    /**
     * **③ والشرطُ يُحاكى لا الاسمُ وحدَه.**
     *
     * `paid_transaction_id` مشروطٌ بـ`required_if:payment_type,amial_pay`
     * — أي أنّ الفاتورةَ **لا تُصدَر** بأميال باي قبل أن تعود حركةٌ
     * مدفوعة. فمحاكٍ يُصدرها بضغطةٍ واحدةٍ يَعِد بما يرفضه الخادم.
     */
    /** @test */
    public function the_simulator_honours_the_paid_transaction_precondition(): void
    {
        $src = (string) file_get_contents(self::CTRL);

        $this->assertStringContainsString(
            "'paid_transaction_id' => 'required_if:payment_type,amial_pay", $src,
            'تغيّر شرطُ `paid_transaction_id` في الخادم — يُراجَع المحاكي معه.');

        $sim = $this->sim();

        $this->assertStringContainsString('function canIssue()', $sim,
            '**المحاكي بلا شرطٍ على الإصدار.** والخادمُ يشترط حركةً '
            . 'مدفوعةً مع أميال باي.');

        $this->assertMatchesRegularExpression(
            "/invPay !== 'amial_pay' \|\| !!paidTxn/", $sim,
            '**شرطُ الإصدار لا يذكر الحركةَ المدفوعة.** فتُصدَر الفاتورةُ '
            . 'في المحاكي بضغطةٍ ويردّها الخادمُ ٤٢٢ في التطبيق.');
    }

    /**
     * **④ ولا حقلَ مرجعٍ في شاشة الدفع.**
     *
     * التعليقُ في `wholesale_policy_screens.dart` يقول بنصّه: «أميال»
     * ليس رقمَ مرجعٍ يكتبه الموظّف: نفتح QR باسم محفظة المالك، ثمّ نسجّل
     * التحصيل **فقط بعد حركة مدفوعة**. فحقلُ نصٍّ هناك يدعو إلى ادّعاء
     * دفعٍ لم يقع.
     */
    /** @test */
    public function the_simulator_never_lets_a_clerk_type_a_payment_reference(): void
    {
        $sim = $this->sim();

        $at = strpos($sim, 'S.qr = function ()');
        $this->assertNotFalse($at, 'اختفت شاشةُ الدفع من المحاكي');

        $screen = substr($sim, $at, (int) (strpos($sim, 'S.invoiceDone') ?: strlen($sim)) - $at);

        $this->assertStringNotContainsString('<input', $screen,
            '**حقلُ إدخالٍ في شاشة دفع أميال باي.** والتطبيقُ لا يقبل '
            . 'مرجعاً مكتوباً هنا: الحركةُ تأتي من الدفع نفسِه.');
    }

    /**
     * **⑤ وأقسامُ الدرج في المحاكي هي أقسامُه في التطبيق.**
     *
     * تُقرأ من `merchant_adaptive_shell.dart` ولا تُكتب هنا — فقسمٌ
     * يُضاف أو يُعاد تسميتُه في التطبيق يُسقط هذا الفحصَ حتّى يُنقَل.
     */
    /** @test */
    public function the_simulator_drawer_matches_the_real_wholesale_drawer(): void
    {
        $shell = __DIR__ . '/../../../02_flutter_app/lib/features/merchant/screens/merchant_adaptive_shell.dart';
        $this->assertFileExists($shell);

        $src = (string) file_get_contents($shell);
        $start = strpos($src, "case 'wholesale':");
        $this->assertNotFalse($start, 'اختفى فرعُ الجملة من درج التطبيق');
        $end = strpos($src, "case '", $start + 10);
        $block = substr($src, $start, ($end ?: strlen($src)) - $start);

        // **`subtitle:` ينتهي بـ`title:`** — فأوّلُ صياغةٍ التقطت الأوصافَ
        // مع العناوين وأسقطت الفحصَ على ما ليس عنواناً. النظرةُ الخلفيّة
        // تقصره على العنوان وحدَه.
        preg_match_all("/(?<!sub)title: '([^']+)'/", $block, $m);
        $titles = $m[1] ?? [];

        $this->assertGreaterThanOrEqual(6, count($titles),
            'لم تُقرأ أقسامُ الدرج — تغيّرت الصياغةُ والحارسُ يفحص فراغاً.');

        $sim = $this->sim();
        $missing = array_values(array_filter($titles,
            fn (string $t): bool => ! str_contains($sim, $t)));

        $this->assertSame([], $missing, sprintf(
            "**أقسامٌ في درج التطبيق وليست في المحاكي:**\n  %s\n\n"
            . 'فيُعرَض على صاحب المشروع درجٌ ليس درجَه، ويُقرَّر على '
            . 'صورةٍ ناقصة.',
            implode('، ', $missing)));
    }
    /**
     * **⑥ والصيدليّةُ: ثلاثُ طرقِ دفعٍ كما يقبل `recordSale`.**
     */
    /** @test */
    public function every_pharmacy_payment_method_the_server_accepts_is_shown(): void
    {
        $this->assertFileExists(self::RX_SIM, 'محاكي الصيدلية مفقود.');
        $sim = (string) file_get_contents(self::RX_SIM);

        $accepted = $this->acceptedValues('payment_method', self::RX_CTRL);

        $this->assertGreaterThanOrEqual(3, count($accepted),
            'قُرئت طرقُ دفعٍ أقلُّ ممّا كان — تغيّرت صياغةُ المتحقِّق.');

        // الأزرارُ تُبنى في وقت التشغيل من مصفوفٍ، فيُقرأ المصفوف.
        $at = strpos($sim, "data-pay=\"' + m[0]");
        $this->assertNotFalse($at, 'اختفى بناءُ أزرار الدفع من محاكي الصيدلية.');
        $block = substr($sim, max(0, $at - 400), 400);

        $missing = array_values(array_filter($accepted,
            fn (string $v): bool => ! str_contains($block, "'{$v}'")));

        $this->assertSame([], $missing, sprintf(
            "**طرقُ دفعٍ يقبلها الخادمُ ولا يعرضها محاكي الصيدلية:**\n  %s",
            implode('، ', $missing)));
    }

    /**
     * **⑦ وقيدا الوصفة مستقلّان — والمحاكي يعرضهما اثنين.**
     *
     * ══════════════════════════════════════════════════════════════════
     * `PharmacyController::recordSale` يفرض قيدين لا يُغني أحدُهما عن
     * الآخر:
     *
     *   · الباقة — `denyUnless($request, 'pharmacy_prescriptions')`
     *   · الدور  — `guard($request, P::PHARMACY_PRESCRIPTION_RECORD)`
     *
     * والتعليقُ هناك يقول بنصّه: «صيدليّةٌ اشترت الميزةَ لا يعني أنّ
     * كاشيرَها يوثّق وصفة… **وخلطُهما يجعل شراءَ الميزة منحاً لكلّ
     * الموظّفين**».
     *
     * فمحاكٍ يدمجهما في رسالةٍ واحدة **يُرسل الصيدليَّ يشتري ما يملكه**
     * أو يعدّل صلاحيّةً لن تكفي.
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function the_pharmacy_simulator_keeps_the_two_prescription_constraints_apart(): void
    {
        $ctrl = (string) file_get_contents(self::RX_CTRL);

        foreach ([
            "denyUnless(\$request, 'pharmacy_prescriptions')" => 'قيدُ الباقة',
            'P::PHARMACY_PRESCRIPTION_RECORD' => 'قيدُ الدور',
        ] as $needle => $what) {
            $this->assertStringContainsString($needle, $ctrl,
                "**{$what} لم يعد في `recordSale`.** فإمّا تغيّر العقدُ "
                . 'وإمّا سقط قيدٌ — ويُراجَع المحاكي معه.');
        }

        $sim = (string) file_get_contents(self::RX_SIM);

        $this->assertStringContainsString('function prescriptionBlocker()', $sim,
            '**المحاكي بلا تفريقٍ بين القيدين.**');

        // وأربعُ حالاتٍ لا حالتان: كلاهما · الباقةُ وحدَها · الدورُ وحدَه · لا مانع.
        foreach (["'both'", "'plan'", "'role'"] as $state) {
            $this->assertStringContainsString($state, $sim,
                "**حالةُ {$state} غائبةٌ عن المحاكي.** فرسالةٌ واحدةٌ "
                . 'لسببين تُرسل الصيدليَّ يشتري ما يملكه.');
        }

        $this->assertStringContainsString('pharmacy.prescription.record', $sim,
            '**المحاكي لا يسمّي صلاحيّةَ الدور.** ورسالةٌ لا تسمّي ما '
            . 'يُطلَب تجعل الصيدليَّ يخمّن.');
    }

    /**
     * **⑧ و«قريباً» ليست «مقفلةً في باقتك».**
     *
     * `pharmacy_customers` مُعلَنةٌ بـ`comingSoon()` لأنّها **بلا نقطة
     * نهاية**. فعرضُها مقفلةً بباقةٍ يَعِد بأنّ الدفعَ يفتحها —
     * **ووعدٌ في صفحة تسعيرٍ لا يُوفّى أسوأ من ميزةٍ غائبةٍ معلنة.**
     */
    /** @test */
    public function a_coming_soon_capability_is_never_shown_as_plan_locked(): void
    {
        $reg = (string) file_get_contents(
            __DIR__ . '/../../app/Support/Access/CapabilityRegistry.php');

        $at = strpos($reg, 'F_PHARMACY_CUSTOMERS');
        $this->assertNotFalse($at, 'اختفت «عملاء الصيدلية» من السجلّ.');

        $this->assertStringContainsString('comingSoon()', substr($reg, $at, 400),
            '**«عملاء الصيدلية» لم تعد «قريباً».** فإن بُنيت نقطتُها '
            . 'فليُحدَّث المحاكي؛ وإن أُعيدت للبيع بلا نقطةٍ فهذا هو '
            . 'العطلُ الذي أُخرجت من أجله.');

        $sim = (string) file_get_contents(self::RX_SIM);

        $this->assertStringContainsString('COMING_SOON', $sim,
            '**المحاكي بلا صنف «قريباً».** فتُعرَض القدرةُ مقفلةً بباقةٍ '
            . 'وتَعِد بأنّ الدفعَ يفتحها — ولا يفتحها.');

        // **ولا تنفتح بأعلى باقة** — وهو ما يفرّقها عن المقفل.
        $this->assertMatchesRegularExpression(
            '/if \(COMING_SOON\[code\]\) return false;/', $sim,
            '**«قريباً» تنفتح بالترقية في المحاكي.** وهي لم تُبَع أصلاً.');
    }

    /**
     * **⑨ وأقسامُ درج الصيدليّة هي أقسامُه في التطبيق.**
     */
    /** @test */
    public function the_simulator_drawer_matches_the_real_pharmacy_drawer(): void
    {
        $shell = __DIR__ . '/../../../02_flutter_app/lib/features/merchant/screens/merchant_adaptive_shell.dart';
        $src = (string) file_get_contents($shell);

        $start = strpos($src, "case 'pharmacy':");
        $this->assertNotFalse($start, 'اختفى فرعُ الصيدليّة من درج التطبيق');
        $end = strpos($src, "case '", $start + 10);
        $block = substr($src, $start, ($end ?: strlen($src)) - $start);

        preg_match_all("/(?<!sub)title: '([^']+)'/", $block, $m);
        $titles = $m[1] ?? [];

        // الأقسامُ المشتركةُ (`sale` · `people` · `reports`) تُذكر بأسمائها
        // لا بنصِّها هنا، فتُقرأ من تعريفاتها.
        foreach (['sale' => 'البيع والتحصيل', 'people' => 'العملاء والفريق',
                  'reports' => 'التقارير والمالية'] as $var => $t) {
            if (preg_match('/\b' . $var . ',/', $block)) {
                $titles[] = $t;
            }
        }

        $this->assertGreaterThanOrEqual(4, count($titles),
            'لم تُقرأ أقسامُ درج الصيدليّة — تغيّرت الصياغةُ والحارسُ '
            . 'يفحص فراغاً.');

        $sim = (string) file_get_contents(self::RX_SIM);
        $missing = array_values(array_filter($titles,
            fn (string $t): bool => ! str_contains($sim, $t)));

        $this->assertSame([], $missing, sprintf(
            "**أقسامٌ في درج الصيدليّة وليست في محاكيها:**\n  %s",
            implode('، ', $missing)));
    }
}
