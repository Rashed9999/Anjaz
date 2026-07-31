<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-LEDGER-COVERAGE-GUARD-001 — لا تُضاف خدمةُ مالٍ جديدة بلا قرارٍ صريح.
 *
 * **صنف العطل الذي يحرسه:**
 * تبيّن بالقياس أن دفتر الأستاذ يرى **سبع خدمات من خمسٍ وعشرين** تُحرّك مالاً.
 * ولم يحدث ذلك بقرار، بل بالتراكم: تُبنى خدمةٌ جديدة، ويُنسى الترحيل، ولا شيء
 * يسأل. فالخدمة تعمل، والاختبارات تمرّ، والدفتر ينقص صامتاً.
 *
 * وهذا **نمط خطئي المتكرّر في هذا المشروع**: أُصلح النسخة ولا أسأل عن الصنف.
 * أصلحتُ لوحة أرقام واحدة وفي التطبيق ثانية، وأصلحتُ مسار PDF واحداً من سبعة.
 * فالحارس هنا ليس على خدمةٍ بعينها بل على **القاعدة**.
 *
 * **كيف يعمل:** يمسح `app/Services` عن كل خدمةٍ تُحرّك رصيداً، ثم يطالب بأن
 * تكون إمّا مُرحِّلة وإمّا **مُصنّفة صراحةً** في `EXEMPT` مع سبب مكتوب. ولا
 * يفرض الترحيل على الجميع — بعض الخدمات تحرّك أرصدةً فرعية (آجل، بطاقة هدية،
 * رصيد شركة) لا محفظة المنصّة، وهو مقبولٌ محاسبياً. المرفوض هو **الصمت**:
 * أن يمرّ الأمر بلا أن يقرّره أحد.
 *
 * وسقوطه ليس بالضرورة عطلاً. إن أضفتَ خدمةً وسقط، فقد أدّى وظيفته: قرّر —
 * إمّا أن تُرحّل وإمّا أن تُدرجها أدناه بسبب. وإدراجها بلا سبب حقيقيّ هو
 * الطريقة الوحيدة لخداع هذا الحارس، وهو خداعٌ موقَّع باسمك في git blame.
 */
class LedgerCoverageGuardTest extends TestCase
{
    /**
     * خدماتٌ تُحرّك مالاً ولا تُرحّل — كلٌّ بسببها.
     *
     * السبب ليس زينة: هو الفرق بين قرارٍ وإهمال. ومن أضاف سطراً هنا فقد
     * أقرّ أمام من يقرأ الشيفرة بعده أن الغياب مقصود.
     */
    private const EXEMPT = [
        // ── أرصدة فرعية لا تمسّ محفظة المنصّة ──
        'CustomerCreditService' => 'رصيد آجل بين التاجر وعميله — دَينٌ خارج المنصّة لا مالٌ فيها',
        'CustomerCreditSettleService' => 'تسوية الآجل تُحرّك المحفظة عبر MerchantService المُرحِّل',
        'GiftCardService' => 'رصيد مخزَّن على البطاقة — يُرحَّل عند الاستهلاك في الكاشير',
        'LoyaltyService' => 'نقاط ولاء لا عملة — لا قيمة نقدية حتى الاستبدال',
        'CorporateAccountService' => 'حدّ ائتماني للشركة — التزامٌ لا نقد',
        'WholesaleCollectionService' => 'تحصيل فواتير جملة عبر مسار الدفع المُرحِّل',

        // ── تسجيل مبيعات لا تحريك محفظة ──
        'CashierService' => 'يسجّل فاتورة البيع؛ الدفع نفسه يمرّ بـ TransactionTrait المُرحِّل',
        'FuelStationService' => 'يسجّل بيع الوقود؛ الدفع يمرّ بمسار الدفع المُرحِّل',
        'PharmacySaleService' => 'يسجّل بيع الصيدلية؛ الدفع يمرّ بمسار الدفع المُرحِّل',
        'RestaurantService' => 'يسجّل طلب المطعم؛ الدفع يمرّ بمسار الدفع المُرحِّل',
        'WholesaleService' => 'يسجّل فاتورة الجملة؛ التحصيل منفصل',
        'WholesaleInvoiceService' => 'إنشاء الفاتورة لا تحصيلها',

        // ── ديونٌ معلومة، لها بند في خطة التدقيق ──
        'CustomerWithdrawService' => 'دَين معلوم: السحب البنكيّ (طلب/قبول/إلغاء) لا يُرحَّل — ثلاث نقاط إحداها في متحكّم. أمّا السحب النقديّ عبر وكيل فصار يُرحَّل (ledgerAgentCashOut)',
        'MerchantSaleRefundService' => 'دَين معلوم: المرتجعات لا تُرحَّل — نفس البند',
        'InstallmentService' => 'دَين معلوم: الأقساط لا تُرحَّل — نفس البند',
        'SplitBillService' => 'دَين معلوم: تقسيم الفاتورة لا يُرحَّل — نفس البند',
        'FamilyFundService' => 'دَين معلوم: صندوق العائلة لا يُرحَّل — نفس البند',
        'CharityService' => 'دَين معلوم: تسوية الجمعيات لا تُرحَّل (التبرّع نفسه يُرحَّل)',
        'SubscriptionService' => 'دَين معلوم: رسوم الاشتراك لا تُرحَّل — نفس البند',
        'PaymentRequestService' => 'ينشئ طلب دفع؛ التنفيذ يمرّ بمسار الدفع المُرحِّل',

        // ── أدوات لا خدمات مال ──
        'FinancialGuardService' => 'الأداة التي تُحرّك الرصيد؛ الترحيل مسؤولية من ناداها',
        'MoneyService' => 'حساب أرقام لا تحريك أرصدة',
        'FeeService' => 'يحسب الرسم ولا يخصمه',
        'UsageLimitService' => 'عدّادات حدود لا أرصدة',
        'VelocityCounterService' => 'عدّادات مخاطر لا أرصدة',
        'WholesaleReportsService' => 'تقارير جملة — قراءة أرصدة للعرض بلا أي تعديل',
        'CreditStatementPdfService' => 'كشف حساب PDF — قراءة أرصدة للطباعة بلا أي تعديل',
        'ExecutiveDashboardService' => 'لوحة تنفيذية — تجمع الأرصدة للعرض بلا أي تعديل',
        'WhatsappBotService' => 'بوت واتساب — يقرأ الرصيد ليخبر المستخدم؛ والتحويل يمرّ بـ TransactionTrait المُرحِّل',
        'KycTierService' => 'يقرأ حدود المستوى ولا يحرّك رصيداً',
    ];

    /** خدماتٌ تُرحّل فعلاً — تُفحص أنها ما زالت تفعل. */
    private const MUST_POST = [
        'MerchantService',
        'SafePaymentService',
        'BillPayService',
        'AgentNetworkService',
        'DonationsService',
        'PendingTransferService',
        'UniversalSettlementService',
    ];

    /**
     * كل ملفّات الخدمات مفهرسةً بالاسم — بما فيها المجلّدات الفرعية.
     *
     * @return array<string,string> اسم الخدمة => مسار ملفّها
     */
    private function locateServices(): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('app/Services'))
        );
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $out[$file->getBasename('.php')] = $file->getPathname();
            }
        }

        return $out;
    }

    /** @return array<string,string> اسم الخدمة => مسار ملفّها */
    private function servicesTouchingBalances(): array
    {
        $out = [];

        foreach ($this->locateServices() as $name => $path) {
            $src = file_get_contents($path);

            // علامات تحريك المال: نداء الحارس المالي، أو مسّ عمود الرصيد.
            $touches = preg_match('/->(debit|credit|hold|releaseHold)\s*\(/', $src)
                || str_contains($src, 'current_balance')
                || str_contains($src, "increment('balance")
                || str_contains($src, "decrement('balance");

            if ($touches) {
                $out[$name] = $path;
            }
        }

        return $out;
    }

    /**
     * هل تُرحّل هذه الخدمة فعلاً؟
     *
     * بحدود كلمات لا باحتواء نصّيّ: `str_contains($src, 'PostsToLedger')`
     * يصدُق على `PostsToLedgerXX`. جُرّب عكسياً بإعادة تسمية السمة، فمرّ
     * الحارس وهو معطَّل — وهذا بالضبط صنف «اختبارٍ ينجح لسببٍ خاطئ» الذي
     * وُضع هذا الملفّ ليمنع مثله في الشيفرة.
     */
    private function posts(string $path): bool
    {
        $src = file_get_contents($path);

        return (bool) preg_match('/\bPostsToLedger\b/', $src)
            || (bool) preg_match('/\bLedgerService\b/', $src);
    }

    public function test_the_scan_actually_finds_services(): void
    {
        // حارسٌ لا يجد ما يحرسه يمرّ دائماً. وقد وقع لي في هذه الجولة فحصٌ
        // نجح لأن نمطه لم يطابق سطراً واحداً.
        $found = $this->servicesTouchingBalances();

        $this->assertGreaterThanOrEqual(15, count($found),
            'المسح وجد ' . count($found) . ' خدمة فقط — تغيّرت الصياغة وصار أعمى');

        $this->assertArrayHasKey('MerchantService', $found,
            'المسح لم يجد MerchantService وهي تُحرّك المال قطعاً');
    }

    public function test_every_money_service_either_posts_or_is_declared_exempt(): void
    {
        $undecided = [];

        foreach ($this->servicesTouchingBalances() as $name => $path) {
            if ($this->posts($path) || array_key_exists($name, self::EXEMPT)) {
                continue;
            }
            $undecided[] = $name;
        }

        $this->assertSame([], $undecided,
            "خدماتٌ تُحرّك مالاً بلا قرار: " . implode('، ', $undecided) . "\n"
            . "لا يمرّ هذا بالصمت. إمّا أن تُرحّل إلى دفتر الأستاذ، وإمّا "
            . "تُدرَج في LedgerCoverageGuardTest::EXEMPT مع سببٍ مكتوب يقرؤه "
            . "من يأتي بعدك. والدفتر اليوم يرى أقلّ من ثلث الحركة المالية "
            . "لأن هذا السؤال لم يُطرح من قبل.");
    }

    public function test_services_that_post_have_not_quietly_stopped(): void
    {
        // الاتّجاه الآخر: خدمةٌ كانت تُرحّل ثمّ حُذف نداؤها في إعادة هيكلة.
        // لا شيء يكسر، والدفتر ينقص.
        // المسار يُؤخذ من المسح لا يُبنى بالتخمين: بعض الخدمات في مجلّدات
        // فرعية (`app/Services/Whatsapp/`), فبناءُ `app/Services/X.php` يقول
        // «محذوفة» عن ملفٍّ قائم — وقد أوقعني فعلاً في هذه الجولة.
        $found = $this->locateServices();
        $stopped = [];

        foreach (self::MUST_POST as $name) {
            if (!isset($found[$name])) {
                $stopped[] = "$name (الملفّ مفقود — أعيدت تسميته؟)";
                continue;
            }
            if (!$this->posts($found[$name])) {
                $stopped[] = $name;
            }
        }

        $this->assertSame([], $stopped,
            'خدماتٌ كانت تُرحّل ولم تعد: ' . implode('، ', $stopped));
    }

    public function test_no_exempt_entry_is_left_without_a_reason(): void
    {
        // إدراجٌ بسببٍ فارغ يُبطل الحارس كلّه: يصير الإعفاء مجّانياً.
        $empty = [];
        foreach (self::EXEMPT as $name => $reason) {
            if (mb_strlen(trim($reason)) < 20) {
                $empty[] = $name;
            }
        }

        $this->assertSame([], $empty,
            'إعفاءات بلا سبب مفهوم: ' . implode('، ', $empty));
    }

    public function test_exempt_list_has_no_stale_entries(): void
    {
        // خدمةٌ أُعفيت ثم حُذفت أو صارت تُرحّل: بقاؤها في القائمة يُوهم أن
        // الدَّين ما زال قائماً، فيُشغل من يقرأ بعطلٍ لا وجود له.
        $found = $this->locateServices();
        $stale = [];
        foreach (array_keys(self::EXEMPT) as $name) {
            if (!isset($found[$name])) {
                $stale[] = "$name (محذوفة)";
            } elseif ($this->posts($found[$name])) {
                $stale[] = "$name (صارت تُرحّل — احذفها من الإعفاء)";
            }
        }

        $this->assertSame([], $stale,
            'إعفاءات متقادمة: ' . implode('، ', $stale));
    }
}
