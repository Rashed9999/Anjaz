<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-SAHER-RULING-001 — **فرزٌ مبنيٌّ ولا يُوصَل إليه.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما قِيس:**
 *
 *   · `FindingStore::HUMAN_HELD` = FALSE_POSITIVE · ACCEPTED_RISK · SUPPRESSED
 *     — حالاتٌ **تنجو من كلّ مسح**، مبنيّةٌ ومُختبَرة
 *   · والفهرسُ يترجمها الثلاثَ في الشاشة
 *   · والصلاحيّتان `saher.findings.suppress` و`.acknowledge` معرَّفتان
 *     في هجرةٍ منذ يوم
 *   · **ولا مسارَ ولا زرَّ ولا دالّةَ تضع أيّاً منها**
 *
 * فالفرزُ كلُّه جاهزٌ ولا يُوصَل إليه — **النمطُ نفسُه، واقعاً داخل
 * الأداة التي بُنيت لكشفه**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وثمنُه مقيس:** ٩٦ نتيجةً قائمة. ومنها `FinancialGuardService::releaseHold`
 * — وهي **متروكةٌ عمداً**: نسخةٌ صارمةٌ، والتحريرُ يقع بـ`releaseHoldUpTo`،
 * والتعليقُ في الخدمة يقوله. ولا سبيلَ لتسجيل ذلك.
 *
 * **فكلُّ تدقيقٍ يُعيد قراءتها من الصفر، ويعود إغراءُ الحذف الجَماعيّ.**
 * وحذفُ ما هو ناقصُ التوصيل — `refundDonation` · `assertCardLimits` ·
 * `openFromAlert` — يمحو سجلَّ ما ينقص ويُفقر المنتجَ بصمت.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والحكمُ ينتهي — وإلّا صار كتماناً.** الدرسُ مدفوعٌ هنا:
 * `gradle-floor-waiver.json` يذكر الحدَّين معاً فينتهي من تلقائه أوّلَ ما
 * يتحرّك أحدُهما. **وإقرارٌ لا ينتهي صمتٌ مؤجَّل.**
 */
class SaherRulingGuardTest extends TestCase
{
    use RefreshDatabase;

    private function finding(array $over = []): int
    {
        // **والصفُّ يُبنى بأعمدةِ المُدرِج الحقيقيِّ لا بتخمينها.**
        // أوّلُ كتابةٍ أسقطت `reference` و`fingerprint` — وهما إلزاميّان،
        // والبصمةُ هي `sha256(ruleId|assetKey)` كما يحسبها `Finding`.
        $assetKey = 'App\\Services\\FinancialGuardService::releaseHold';
        $ruleId = 'SAHER.DATA.SERVICE_METHOD_UNREACHED';

        return (int) DB::table('saher_findings')->insertGetId(array_merge([
            'reference' => 'SHR-' . substr(hash('sha256', $ruleId . '|' . $assetKey), 0, 10),
            'fingerprint' => hash('sha256', $ruleId . '|' . $assetKey),
            'rule_id' => $ruleId,
            'source_code' => 'data_truth',
            'category' => 'DATA',
            'title' => 'دالّةٌ بلا مُنادٍ',
            'expected_behavior' => 'تُنادى',
            'actual_behavior' => 'لا تُنادى',
            'impact' => 'شيفرةٌ لا تعمل',
            'suggested_action' => 'راجِعها',
            'severity' => 'MEDIUM',
            'confidence' => 'SUSPECTED',
            'status' => 'OPEN',
            'asset_type' => 'method',
            'asset_key' => $assetKey,
            'file_path' => 'app/Services/FinancialGuardService.php',
            'symbol' => 'FinancialGuardService::releaseHold',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'occurrence_count' => 1,
            'risk_score' => 40,
            'created_at' => now(),
            'updated_at' => now(),
        ], $over));
    }

    // ══════════════════════════════════════════════════════════════════
    // التوصيل — وهو ما كان غائباً
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function a_route_exists_that_can_actually_set_a_held_state(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **هذا هو العطلُ بعينه.** الحالاتُ والصلاحيّاتُ والترجماتُ كلُّها
        // كانت جاهزة، ولا فعلَ يبلغها.
        // ══════════════════════════════════════════════════════════════
        $found = null;

        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $r) {
            if (str_contains($r->uri(), 'saher/findings') && in_array('POST', $r->methods(), true)) {
                $found = $r;
            }
        }

        $this->assertNotNull($found,
            'لا مسارَ يضع حالةً بشريّة — فالفرزُ مبنيٌّ ولا يُوصَل إليه');

        $this->assertContains('platform:saher.findings.suppress',
            array_filter($found->gatherMiddleware(), 'is_string'),
            'الفرزُ بلا صلاحيّة — ومن يرى لا يحكم بالضرورة');
    }

    /** @test */
    public function the_finding_page_carries_the_button(): void
    {
        // **ومسارٌ بلا زرٍّ ليس موصولاً** — القاعدةُ الثانيةَ عشرة.
        $src = (string) file_get_contents(
            resource_path('views/admin-views/amial/saher/show.blade.php'));

        $this->assertStringContainsString('saher-rule-submit', $src,
            'صفحةُ الاكتشاف بلا زرِّ فرز — فالمسارُ يُبلَغ بأداةٍ خارجيّة لا بيد');

        $this->assertStringContainsString("hasPlatformPermission('saher.findings.suppress')", $src,
            'الزرُّ يظهر لمن لا يملك الصلاحيّة — وإخفاءُ الواجهة ليس حماية، '
            . 'لكنّ إظهارَ ما يُرفض يُنتج شكوى');
    }

    // ══════════════════════════════════════════════════════════════════
    // السبب — فحكمٌ بلا سببٍ كتمان
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function a_ruling_without_a_real_reason_is_refused(): void
    {
        // **«تمام» ليست سبباً.** حكمٌ بلا سببٍ يُقرأ بعد شهرين «أحدُهم
        // رآه ولم يقل لماذا» — وهو أسوأ من قائمةٍ مفتوحة.
        $src = (string) file_get_contents(
            app_path('Http/Controllers/Admin/SaherController.php'));
        $src = preg_replace('~/\*.*?\*/~s', '', $src) ?? '';
        $src = preg_replace('~^[ \t]*//[^\n]*$~m', '', $src) ?? '';

        $this->assertMatchesRegularExpression(
            "~'reason' => 'required\|string\|min:(2[0-9]|[3-9][0-9])~", $src,
            'السببُ غيرُ مشترطٍ أو حدُّه الأدنى أقصرُ من أن يكون سبباً');
    }

    // ══════════════════════════════════════════════════════════════════
    // الانتهاء — فإقرارٌ لا ينتهي صمتٌ مؤجَّل
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function a_ruling_records_the_code_it_judged(): void
    {
        // **وبصمةُ الاكتشاف `ruleId|assetKey` ثابتةٌ عبر إعادة كتابة
        // الدالّة كلِّها.** فبلا تجزيءِ الملفّ يبقى الحكمُ ساريّاً على
        // خَلَفٍ لم يره أحد.
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasColumn('saher_findings', 'ruling_source_hash'),
            'لا بصمةَ شيفرةٍ مع الحكم — فلا ينتهي أبداً');

        $src = (string) file_get_contents(
            app_path('Http/Controllers/Admin/SaherController.php'));

        $this->assertStringContainsString('hash_file(', $src,
            'الحكمُ لا يقرأ تجزيءَ الملفّ — فالعمودُ يبقى فارغاً وهو زينة');
    }

    /** @test */
    public function a_rescan_lifts_a_ruling_whose_file_changed(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **وهذا هو الشرطُ الذي يفرّق الفرزَ عن الكتمان.**
        //
        // حكمٌ صدر على شيفرةٍ لا يسري على خَلَفٍ لم يره أحد. ولو بقي،
        // لصار كتماناً دائماً بثوب مراجعة.
        // ══════════════════════════════════════════════════════════════
        $id = $this->finding([
            'status' => 'SUPPRESSED',
            'ruling_reason' => 'نسخةٌ صارمةٌ متروكةٌ عمداً — التحريرُ بـreleaseHoldUpTo',
            'ruled_at' => now(),
            'ruling_source_hash' => str_repeat('a', 64),   // تجزيءٌ لا يطابق الملفّ
        ]);

        $store = app(\App\Saher\Findings\FindingStore::class);
        $run = $store->beginRun('data_truth', 'test');

        $store->commitRun($run, 'data_truth', [
            new \App\Saher\Findings\Finding(
                ruleId: 'SAHER.DATA.SERVICE_METHOD_UNREACHED',
                sourceCode: 'data_truth',
                category: 'DATA',
                title: 'دالّةٌ بلا مُنادٍ',
                severity: 'MEDIUM',
                confidence: 'SUSPECTED',
                assetKey: 'App\\Services\\FinancialGuardService::releaseHold',
                assetType: 'method',
                expected: 'تُنادى',
                actual: 'لا تُنادى',
                impact: 'شيفرةٌ لا تعمل',
                suggestedAction: 'راجِعها',
                filePath: 'app/Services/FinancialGuardService.php',
                symbol: 'FinancialGuardService::releaseHold',
            ),
        ], 1);

        $row = DB::table('saher_findings')->where('id', $id)->first();

        $this->assertSame('REOPENED', $row->status,
            'الحكمُ بقي رغم تغيّر الملفّ الذي صدر عليه — فهو كتمانٌ دائم');

        $this->assertNull($row->ruling_source_hash,
            'بقيت البصمةُ القديمة — فسببٌ قديمٌ يُقرأ على شيفرةٍ جديدة');

        $this->assertDatabaseHas('saher_finding_events', [
            'finding_id' => $id,
            'event' => 'REOPENED',
        ]);
    }

    /** @test */
    public function a_ruling_survives_a_rescan_when_the_file_is_unchanged(): void
    {
        // **وحاجزٌ يشلّ عملاً سليماً يُطفَأ عند أوّل شكوى.** فحكمٌ صحيحٌ
        // على شيفرةٍ لم تتغيّر يجب أن يصمد، وإلّا لم يُفرَز شيءٌ أبداً.
        $path = app_path('Services/FinancialGuardService.php');

        $id = $this->finding([
            'status' => 'SUPPRESSED',
            'ruling_reason' => 'نسخةٌ صارمةٌ متروكةٌ عمداً — التحريرُ بـreleaseHoldUpTo',
            'ruled_at' => now(),
            'ruling_source_hash' => hash_file('sha256', $path),
        ]);

        $store = app(\App\Saher\Findings\FindingStore::class);
        $run = $store->beginRun('data_truth', 'test');

        $store->commitRun($run, 'data_truth', [
            new \App\Saher\Findings\Finding(
                ruleId: 'SAHER.DATA.SERVICE_METHOD_UNREACHED',
                sourceCode: 'data_truth',
                category: 'DATA',
                title: 'دالّةٌ بلا مُنادٍ',
                severity: 'MEDIUM',
                confidence: 'SUSPECTED',
                assetKey: 'App\\Services\\FinancialGuardService::releaseHold',
                assetType: 'method',
                expected: 'تُنادى',
                actual: 'لا تُنادى',
                impact: 'شيفرةٌ لا تعمل',
                suggestedAction: 'راجِعها',
                filePath: 'app/Services/FinancialGuardService.php',
                symbol: 'FinancialGuardService::releaseHold',
            ),
        ], 1);

        $this->assertSame('SUPPRESSED',
            DB::table('saher_findings')->where('id', $id)->value('status'),
            'رُفع حكمٌ صحيحٌ على شيفرةٍ لم تتغيّر — فلا يُفرَز شيءٌ أبداً');
    }
}
