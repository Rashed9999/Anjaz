<?php

namespace App\Console\Commands;

use App\Support\Access\Capability;
use App\Support\Access\CapabilityRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

/**
 * AMIAL-ENTITLEMENTS-003 — **تقريرٌ آليٌّ يُجيب، لا ادّعاءٌ يُقال.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الشرطُ الذي وُلد منه:** «لا تقل ›كلُّ القدرات محميّة‹ إلّا إذا كان
 * `unprotected paid capabilities = 0`».
 *
 * فلا يُقاس بعدد اختباراتٍ ناجحة. يُقاس **لكلّ قدرةٍ على حدة**، ويُعاد
 * القياسُ في كلّ تشغيل — فادّعاءٌ صحيحٌ اليوم يشيخ غداً بلا أن يعلم أحد.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وخانةٌ غيرُ مثبتةٍ ليست «نعم» ولا «لا» — هي ❓.** (القاعدة السابعة:
 * «غير معروف» ليس صفراً.) وما لا يُقاس من هنا يُقال صراحةً في ذيل
 * التقرير بدل أن يُملأ تخميناً:
 *
 *   · **الملاحة في التطبيق** — تُقرأ من Dart نصّاً، لا بضغطِ زرّ
 *   · **التحقّقُ بالتشغيل** — يحتاج خادماً حيّاً وقاعدةَ إنتاج
 *
 * الاستعمال:
 *     php artisan amial:entitlement-report
 *     php artisan amial:entitlement-report --json
 *     php artisan amial:entitlement-report --fail-on-unprotected
 */
class EntitlementCoverageReport extends Command
{
    protected $signature = 'amial:entitlement-report
        {--json : يُخرج JSON بدل الجدول}
        {--fail-on-unprotected : يخرج بواحدٍ إن وُجدت قدرةٌ مدفوعةٌ بلا حارس}';

    protected $description = 'تقريرُ تغطيةِ الاستحقاقات — قدرةً قدرة، باثنتَي عشرةَ خانة';

    public function handle(): int
    {
        $rows = [];

        $gatedByRoute = $this->gatedByRouteMiddleware();
        $gatedInCtrl = $this->gatedInsideControllers();
        $tested = $this->capabilitiesNamedInTests();
        $negativeTested = $this->capabilitiesWithNegativeTests();
        $screensInApp = $this->screensReachableInApp();
        $shadow = (array) config('amial.entitlements.shadow', []);

        foreach (CapabilityRegistry::all() as $cap) {
            $a = $cap->toArray();
            $code = $a['code'];

            $paid = ! $a['is_core'] && $a['min_plan'] !== null && $a['min_plan'] !== 'free';

            $apiGated = isset($gatedByRoute[$code]) || isset($gatedInCtrl[$code]);

            $rows[] = [
                'code' => $code,
                'paid' => $paid,
                'min_plan' => $a['is_core'] ? 'core' : ($a['min_plan'] ?? 'open'),

                // ① مسجَّلة؟ — بديهيّة، فهي من السجلّ. تبقى للاكتمال.
                'registered' => true,

                // ② لها شاشة؟
                'has_screen' => ! empty($a['screen']),

                // ③ يُوصَل إليها من التطبيق؟ — **يُقرأ من Dart، ولا يُضغَط زرّ.**
                'has_navigation' => ! empty($a['screen'])
                    ? (isset($screensInApp[$a['screen']]) ? true : null)
                    : false,

                // ④ لها API معلَن؟
                'has_api' => $a['routes'] !== [] || $apiGated,

                // ⑤ الـAPI محروس؟
                'api_gated' => $apiGated,

                // ⑥ الباقةُ مفروضة؟ — محروسٌ **وليس في الظلّ**.
                //    فقدرةٌ في الظلّ محروسةُ الشكل، والمنعُ لا يقع.
                'plan_enforced' => $apiGated && ! in_array($code, $shadow, true),

                // ⑦ انطباقُ صنف النشاط مفروض؟ — `NOT_APPLICABLE` تُنفَّذ
                //    دائماً ولا تدخل الظلَّ، فيكفي أن تُعلن أصنافَها.
                'business_enforced' => $a['business_types'] !== [] ? $apiGated : null,

                // ⑧ صلاحيّةُ الموظّف مفروضة؟
                'employee_enforced' => $a['permissions'] !== [] ? $apiGated : null,

                // ⑨ حدُّ الكمّيّة مفروض؟ — يحتاج مفتاحَ حدٍّ **وحارساً**.
                'limit_enforced' => $a['limit'] !== null ? $apiGated : null,

                // ⑩ لها اختبار يذكرها؟
                'tests_exist' => isset($tested[$code]),

                // ⑪ ولها اختبارٌ سالب (يُثبت المنع)؟
                'negative_tests' => isset($negativeTested[$code]),

                // ⑫ متحقَّقٌ منها بالتشغيل؟ — **لا يُقاس من هنا.**
                'runtime_verified' => null,

                'in_shadow' => in_array($code, $shadow, true),
            ];
        }

        return $this->option('json')
            ? $this->emitJson($rows)
            : $this->emitTable($rows);
    }

    // ══════════════════════════════════════════════════════════════════
    //  القياسات — كلُّها من المصدر، ولا قائمةَ مكتوبةً بيد
    // ══════════════════════════════════════════════════════════════════

    /**
     * **يُقرأ من جدول المسارات المبنيّ، لا من نصّ الملفّ.**
     *
     * وهذا أقوى من قراءة النصّ: يرى الوسيطَ الموروثَ من المجموعة، وهو ما
     * أخفق فيه أوّلُ قياسٍ في هذه الجولة فأعطى رقماً أقلّ من الحقيقة.
     *
     * @return array<string,true>
     */
    private function gatedByRouteMiddleware(): array
    {
        $out = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $m) {
                if (is_string($m) && str_starts_with($m, 'capability:')) {
                    $out[substr($m, strlen('capability:'))] = true;
                }
            }
        }

        return $out;
    }

    /** @return array<string,true> */
    private function gatedInsideControllers(): array
    {
        $byConstant = $this->scanForCapabilityCodes(app_path('Http/Controllers'),
            '~(?:hasFeature|gate)\([^,]+,\s*(?:A|AccessConstants)::(F_[A-Z_]+)~');

        // **وحراسةٌ برمزٍ نصّيّ تُرى أيضاً** — `DeniesByPlan::denyUnless`.
        //
        // وبدون هذا كان التقريرُ يقول «بلا حارس» عن قدرةٍ محروسةٍ فعلاً
        // (‏`retail.returns.by_line`)، **وهو بلاغٌ كاذبٌ يُنفَق عليه وقتٌ
        // ثمّ لا شيء** — وأسوأُ منه أنّه يُخفي البلاغَ الصادقَ في الضجيج.
        $byLiteral = [];

        foreach ($this->phpFiles(app_path('Http/Controllers')) as $path) {
            if (preg_match_all('~denyUnless\([^,]+,\s*[\x27"]([a-z0-9_.]+)[\x27"]~',
                (string) file_get_contents($path), $m)) {
                foreach ($m[1] as $code) {
                    $byLiteral[$code] = true;
                }
            }
        }

        return $byConstant + $byLiteral;
    }

    /** @return array<string,true> */
    private function capabilitiesNamedInTests(): array
    {
        return $this->scanForCapabilityCodes(base_path('tests'),
            '~(?:A|AccessConstants)::(F_[A-Z_]+)~')
            + $this->literalCodesIn(base_path('tests'));
    }

    /**
     * اختبارٌ **سالب** — يُثبت المنعَ لا الإتاحة.
     *
     * ويُقاس بذكر القدرة في ملفٍّ يطلب ٤٠٢ أو ٤٠٣ صراحةً. **وهو قياسٌ
     * تقريبيّ**: ملفٌّ يذكر قدرتين ويمنع إحداهما يُحسب لكلتيهما. فيُقال
     * ذلك ولا يُدّعى أدقُّ ممّا هو.
     */
    private function capabilitiesWithNegativeTests(): array
    {
        $out = [];

        foreach ($this->phpFiles(base_path('tests')) as $path) {
            $src = (string) file_get_contents($path);

            if (! preg_match('~assertStatus\(40[23]\)|assertSame\(40[23]|assertForbidden|PLAN_UPGRADE_REQUIRED|PLAN_LIMIT_REACHED~', $src)) {
                continue;
            }

            $out += $this->codesInSource($src);
        }

        return $out;
    }

    /**
     * شاشاتٌ يصل إليها التطبيقُ فعلاً — **تُقرأ من Dart نصّاً**.
     *
     * ولا تُقاس بالضغط: لا مُصرِّفَ Flutter هنا. فما لم يُوجد اسمُه في
     * شيفرة التطبيق يُقال ❓ لا ✗ — الغيابُ من البحث ليس غياباً من المنتج.
     *
     * @return array<string,true>
     */
    private function screensReachableInApp(): array
    {
        $dir = base_path('../02_flutter_app/lib');

        if (! is_dir($dir)) {
            return [];
        }

        $out = [];

        foreach ($this->files($dir, 'dart') as $path) {
            $src = (string) file_get_contents($path);

            if (preg_match_all("~'(/[a-z0-9/_-]+)'~", $src, $m)) {
                foreach ($m[1] as $screen) {
                    $out[$screen] = true;
                }
            }
        }

        return $out;
    }

    /** @return array<string,true> */
    private function scanForCapabilityCodes(string $dir, string $pattern): array
    {
        $out = [];

        foreach ($this->phpFiles($dir) as $path) {
            $src = (string) preg_replace('~//[^\n]*|/\*.*?\*/~s', '',
                (string) file_get_contents($path));

            if (! preg_match_all($pattern, $src, $m)) {
                continue;
            }

            foreach ($m[1] as $const) {
                $name = \App\Support\Access\AccessConstants::class . '::' . $const;

                if (defined($name)) {
                    $out[constant($name)] = true;
                }
            }
        }

        return $out;
    }

    /** رموزٌ نصّيّةٌ مثل `'retail.catalog'` — لا كلُّ قدرةٍ لها ثابت. */
    private function literalCodesIn(string $dir): array
    {
        $out = [];

        foreach ($this->phpFiles($dir) as $path) {
            $out += $this->codesInSource((string) file_get_contents($path));
        }

        return $out;
    }

    private function codesInSource(string $src): array
    {
        $out = [];

        foreach (array_keys(CapabilityRegistry::all()) as $code) {
            if (str_contains($src, "'{$code}'")) {
                $out[$code] = true;
            }
        }

        return $out;
    }

    /** @return array<int,string> */
    private function phpFiles(string $dir): array
    {
        return $this->files($dir, 'php');
    }

    /** @return array<int,string> */
    private function files(string $dir, string $ext): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $out = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

        foreach ($it as $f) {
            if (! $f->isDir() && $f->getExtension() === $ext) {
                $out[] = $f->getPathname();
            }
        }

        return $out;
    }

    // ══════════════════════════════════════════════════════════════════
    //  المخرَج
    // ══════════════════════════════════════════════════════════════════

    private function mark(?bool $v): string
    {
        return $v === true ? '✓' : ($v === false ? '✗' : '❓');
    }

    private function emitTable(array $rows): int
    {
        $paid = array_values(array_filter($rows, fn ($r) => $r['paid']));
        // ══════════════════════════════════════════════════════════════
        // **«بلا حارس» ≠ «بلا نقطة نهاية».**
        //
        // قدرةٌ مدفوعةٌ **لا مسارَ لها إطلاقاً** ليست ثغرةً: لا يبلغها
        // طلبٌ أصلاً. وعدُّها ثغرةً يُضخّم الرقمَ بما لا يُخترَق، فيُخفي
        // الثغراتِ الحقيقيّةَ في الضجيج — **وحارسٌ يبالغ يُهمَل كحارسٍ
        // يسكت**.
        //
        // وهي **ليست سليمةً** أيضاً: قدرةٌ تُباع ولا تُبنى وعدٌ لا
        // يُنفَّذ. فتُعدّ في بابٍ ثانٍ بصوتٍ مسموع، ولا تُخلَط بالأوّل.
        $unprotected = array_values(array_filter($paid,
            fn ($r) => $r['has_api'] && ! $r['api_gated']));

        $soldWithoutApi = array_values(array_filter($paid, fn ($r) => ! $r['has_api']));
        $shadowed = array_values(array_filter($paid, fn ($r) => $r['in_shadow']));

        $this->line('');
        $this->line('  ══ تغطيةُ الاستحقاقات ══');
        $this->line('');
        $this->line(sprintf('  %-24s %-6s %-3s %-3s %-3s %-3s %-3s %-3s %-3s %-3s %-3s %-3s',
            'القدرة', 'الباقة', 'شا', 'ملا', 'API', 'حرس', 'خطة', 'صنف', 'موظ', 'حدّ', 'اخت', 'سلب'));
        $this->line('  ' . str_repeat('─', 84));

        foreach ($rows as $r) {
            $this->line(sprintf('  %-24s %-6s  %s   %s   %s   %s   %s   %s   %s   %s   %s   %s',
                mb_substr($r['code'], 0, 24),
                mb_substr($r['min_plan'], 0, 6),
                $this->mark($r['has_screen']),
                $this->mark($r['has_navigation']),
                $this->mark($r['has_api']),
                $this->mark($r['api_gated']),
                $this->mark($r['plan_enforced']),
                $this->mark($r['business_enforced']),
                $this->mark($r['employee_enforced']),
                $this->mark($r['limit_enforced']),
                $this->mark($r['tests_exist']),
                $this->mark($r['negative_tests']),
            ));
        }

        $this->line('');
        $this->line('  ══ الخلاصة ══');
        $this->line(sprintf('  القدراتُ كلُّها            : %d', count($rows)));
        $this->line(sprintf('  المدفوعة                  : %d', count($paid)));
        $this->line(sprintf('  المجّانيّةُ والأساسيّة       : %d', count($rows) - count($paid)));
        $this->line(sprintf('  **مدفوعةٌ بلا حارس**       : %d', count($unprotected)));
        $this->line(sprintf('  مدفوعةٌ بلا نقطةِ نهاية    : %d', count($soldWithoutApi)));
        $this->line(sprintf('  مدفوعةٌ في الظلّ           : %d', count($shadowed)));
        $this->line(sprintf('  بلا شاشة                  : %d',
            count(array_filter($rows, fn ($r) => ! $r['has_screen']))));
        $this->line(sprintf('  بلا اختبارٍ يذكرها         : %d',
            count(array_filter($rows, fn ($r) => ! $r['tests_exist']))));
        $this->line(sprintf('  بلا اختبارٍ سالب           : %d',
            count(array_filter($paid, fn ($r) => ! $r['negative_tests']))));

        if ($unprotected !== []) {
            $this->line('');
            $this->line('  ── مدفوعةٌ ولا يحرسها الخادمُ بشيء ──');

            foreach ($unprotected as $r) {
                $this->line(sprintf('    · %-24s %s', $r['code'], $r['min_plan']));
            }
        }

        $this->line('');
        $this->line('  ── ما لا يُقاس من هنا (❓ لا ✓ ولا ✗) ──');
        $this->line('    · الملاحة: تُقرأ من نصّ Dart، **ولا يُضغَط زرّ**');
        $this->line('    · التحقّقُ بالتشغيل: يحتاج خادماً حيّاً — لا يدّعيه هذا التقرير');
        $this->line('');

        if ($soldWithoutApi !== []) {
            $this->newLine();
            $this->line('  ── مدفوعةٌ ولا نقطةَ نهايةٍ لها (‏تُباع ولا تُبنى) ──');

            foreach ($soldWithoutApi as $r) {
                $this->line(sprintf('    · %-24s %s', $r['code'], $r['min_plan']));
            }
        }

        if ($this->option('fail-on-unprotected') && $unprotected !== []) {
            $this->error(sprintf('  %d قدرةً مدفوعةً بلا حارس — التقريرُ يخرج بواحد.',
                count($unprotected)));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function emitJson(array $rows): int
    {
        $paid = array_filter($rows, fn ($r) => $r['paid']);
        $unprotected = array_filter($paid, fn ($r) => ! $r['api_gated']);

        $this->line((string) json_encode([
            'summary' => [
                'total' => count($rows),
                'paid' => count($paid),
                'free_or_core' => count($rows) - count($paid),
                'unprotected_paid' => count($unprotected),
                'in_shadow' => count(array_filter($paid, fn ($r) => $r['in_shadow'])),
                'without_screen' => count(array_filter($rows, fn ($r) => ! $r['has_screen'])),
                'without_tests' => count(array_filter($rows, fn ($r) => ! $r['tests_exist'])),
                'paid_without_negative_tests' => count(array_filter($paid, fn ($r) => ! $r['negative_tests'])),
            ],
            'capabilities' => $rows,
            'not_measurable_here' => [
                'has_navigation' => 'يُقرأ من نصّ Dart — لا يُضغَط زرّ',
                'runtime_verified' => 'يحتاج خادماً حيّاً وقاعدةَ إنتاج',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $this->option('fail-on-unprotected') && $unprotected !== []
            ? self::FAILURE : self::SUCCESS;
    }
}
