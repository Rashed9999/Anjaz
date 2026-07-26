<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * AMIAL-AUDIT-ORPHAN-002 — حارس سطح المسارات.
 *
 * الفحص العميق كشف 63 صفحة معطّلة و39 مسار كتابة يتيماً داخل لوحة 6cash
 * المهجورة. أُغلقت. لكن إغلاقها مرّة لا يمنع عودتها: القالب الأصل ما زال
 * مصدر أي شيفرة تُنسَخ، والمسار الذي يُضاف اليوم لا يمرّ على أحد.
 *
 * هذا الحارس يمنع الصنف لا الحالات:
 *
 *   1) مسار GET يكتب في القاعدة. حماية Laravel من CSRF تسري على POST و
 *      PUT و DELETE — لا على GET. فرابط صورة في أي صفحة يزورها مديرٌ
 *      مسجَّل الدخول ينفّذ الكتابة بجلسته، بلا نقرة ولا رمز. كان في
 *      المشروع من هذا النوع: توثيق KYC، وحظر عميل، وحذف سجلّات.
 *
 *   2) مسار يُصيّر قالباً غير موجود — خطأ 500 مضمون لمن يفتحه.
 */
class RouteSurfaceGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * أساليب تكتب بلا شكّ. لا نعتمد اسم الأسلوب (status/toggle) بل استدعاء
     * الكتابة نفسه في متنه — الاسم يكذب، والاستدعاء لا.
     */
    private const WRITE_CALLS = '/->delete\(|->update\(|->save\(|->increment\(|->decrement\(|::destroy\(|::create\(|->forceDelete\(/';

    /** أساليب تُصيّر صفحة: وجود view() يعني أنها للعرض لا للتنفيذ. */
    private function methodBody(string $action): ?string
    {
        if (!preg_match('/^([A-Za-z0-9_\\\\]+)@(\w+)$/', $action, $m)) {
            return null;
        }

        $path = base_path('app/' . str_replace(['App\\', '\\'], ['', '/'], $m[1]) . '.php');
        if (!is_file($path)) {
            return null;
        }

        $parts = preg_split(
            '/\n\s*(?:public|protected|private)\s+function\s+(\w+)/',
            file_get_contents($path), -1, PREG_SPLIT_DELIM_CAPTURE
        );

        for ($i = 1; $i < count($parts); $i += 2) {
            if ($parts[$i] === $m[2]) {
                return $parts[$i + 1];
            }
        }

        return null;
    }

    /**
     * القائمة فارغة — وهذا هو المقصود.
     *
     * كانت تحمل أحد عشر مساراً GET يكتب، ورثناها من لوحة 6cash. سِتّة منها
     * كانت مستعملة فعلاً فحُوّلت إلى POST/DELETE مع روابطها، وخمسة لم يكن
     * يشير إليها شيء فحُذفت.
     *
     * تبقى الثابتة موجودةً فارغةً عمداً: إبقاؤها يجعل إضافة استثناء قراراً
     * ظاهراً في المراجعة، لا تعديلاً صامتاً في منطق الاختبار.
     */
    private const KNOWN_GET_WRITES = [];

    public function test_no_get_route_writes_to_the_database(): void
    {
        $offenders = [];

        foreach (Route::getRoutes() as $route) {
            if (!in_array('GET', $route->methods(), true)) {
                continue;
            }

            // مسارات الـ API تُصادَق برمز Bearer لا بكعكة جلسة. المتصفّح لا
            // يُرفق الرمز تلقائياً مع طلب من موقع آخر، فلا ينطبق عليها CSRF.
            // إدراجها هنا يُغرق الحارس بضجيج يُخفي الخطر الحقيقي.
            if (str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            if (in_array($route->uri(), self::KNOWN_GET_WRITES, true)) {
                continue;
            }

            $body = $this->methodBody((string) $route->getActionName());
            if ($body === null) {
                continue;
            }

            // ما يُصيّر صفحة قد يكتب عرضاً (تسجيل زيارة مثلاً) — لا يُحسب.
            if (preg_match("/view\(\s*'/", $body)) {
                continue;
            }

            if (preg_match(self::WRITE_CALLS, $body)) {
                $offenders[] = $route->uri() . '  →  ' . $route->getActionName();
            }
        }

        $this->assertSame([], $offenders,
            "مسارات GET تكتب في القاعدة (قابلة لـ CSRF — حوّلها إلى POST):\n  "
            . implode("\n  ", $offenders));
    }

    public function test_no_route_renders_a_missing_view(): void
    {
        $offenders = [];

        foreach (Route::getRoutes() as $route) {
            $body = $this->methodBody((string) $route->getActionName());
            if ($body === null) {
                continue;
            }

            preg_match_all("/view\(\s*'([a-zA-Z0-9_.\-]+)'/", $body, $m);
            foreach ($m[1] as $view) {
                if (!view()->exists($view)) {
                    $offenders[] = $route->uri() . "  →  قالب مفقود: {$view}";
                }
            }
        }

        $this->assertSame([], array_unique($offenders),
            "مسارات تُصيّر قوالب محذوفة (خطأ 500 مضمون):\n  "
            . implode("\n  ", array_unique($offenders)));
    }
}
