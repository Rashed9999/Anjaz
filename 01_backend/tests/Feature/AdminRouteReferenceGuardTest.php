<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * AMIAL-ROUTE-REF-001 — **`route()` يسقط عند التصيير، لا عند الضغط.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمنُ الذي قِيس — ولوحةُ الإدارة كلُّها كانت ساقطةً به:**
 *
 * حُذف مساران من `routes/admin/amial.php` (‏`system.health`
 * و`system.errors.update`) **وبقيت القائمةُ الجانبيّةُ تربطهما**. وتلك
 * القائمةُ مُضمَّنةٌ في **كلّ** صفحةِ إدارة.
 *
 * فالنتيجة: `Route [admin.amial.system.health] not defined` — و**كلُّ
 * صفحةٍ في اللوحة تردّ ٥٠٠**. لا صفحةٌ واحدة: الخمسون كلُّها. وقِيس في
 * المجموعة: ٥٧ اختباراً يتوقّع ٢٠٠ فيتلقّى ٥٠٠.
 *
 * **ولا شيءَ في المشروع كان يمسك هذا.** فالمتحكّمُ باقٍ، والشاشةُ باقية،
 * والاختباراتُ التي تخصّ تلك الشاشة وحدَها لم تكن تُشغَّل قبل غيرها.
 * والعطلُ ليس في الصفحة المحذوف مسارُها — بل في **كلّ صفحةٍ سواها**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وهو صنفُ العطل الذي يحاربه المشروع كلُّه، مقلوباً:**
 *
 * القاعدةُ الثانيةَ عشرةَ تقول «صفحةٌ لا يُوصل إليها ليست مبنيّة».
 * وهذا عكسُها: **رابطٌ يُوصل إلى ما لا وجودَ له يُسقط ما حوله**.
 *
 * فيُفحَص كلُّ اسمِ مسارٍ تُناديه قوالبُ اللوحة — قبل أن يُصيَّر.
 */
class AdminRouteReferenceGuardTest extends TestCase
{
    /**
     * قوالبُ اللوحة كلُّها + الجزئيّاتُ المشتركةُ التي تُضمَّن فيها.
     *
     * @return array<int,string>
     */
    private function adminTemplates(): array
    {
        $out = [];

        foreach (['views/admin-views', 'views/layouts/admin', 'views/partials'] as $rel) {
            $root = resource_path($rel);

            if (! is_dir($root)) {
                continue;
            }

            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $f) {
                if ($f->isFile() && str_ends_with($f->getFilename(), '.blade.php')) {
                    $out[] = $f->getPathname();
                }
            }
        }

        return $out;
    }

    /**
     * @test
     *
     * **كلُّ اسمِ مسارٍ تُناديه قوالبُ اللوحة مسجَّلٌ فعلاً.**
     */
    public function every_named_route_referenced_by_an_admin_template_exists(): void
    {
        $missing = [];
        $scanned = 0;

        foreach ($this->adminTemplates() as $file) {
            // تعليقاتُ Blade تُنزع: شرحٌ يذكر `route('x')` ليصف ما أُزيل
            // ليس نداءً له. (‏وهذا الفخُّ أوقع المشروعَ من قبل.)
            $src = (string) preg_replace(
                ['~\{\{--.*?--\}\}~s', '~<!--.*?-->~s'], '',
                (string) file_get_contents($file));

            // `route('name')` و`route("name", …)` — والاسمُ حرفيٌّ وحدَه.
            // (‏اسمٌ يُركَّب من متغيّرٍ لا يُفحَص هنا، ويُقال ذلك.)
            preg_match_all('~\broute\(\s*[\'"]([a-zA-Z0-9_.\-]+)[\'"]~', $src, $m);

            foreach ($m[1] ?? [] as $name) {
                $scanned++;

                if (Route::getRoutes()->getByName($name) === null) {
                    $missing[] = sprintf('%s  ←  %s',
                        $name, str_replace(resource_path('views').'/', '', $file));
                }
            }
        }

        $this->assertGreaterThan(50, $scanned,
            'لم يُقرأ إلّا '.$scanned.' نداءَ مسار — **التعبيرُ لم يعد يطابق '
            . 'والحارسُ يمرّ فارغاً**');

        $missing = array_values(array_unique($missing));

        $this->assertSame([], $missing, sprintf(
            "قوالبُ إدارةٍ تنادي أسماءَ مساراتٍ **لا وجودَ لها** — و`route()` "
            . "يسقط عند التصيير، فتردّ الصفحةُ ٥٠٠:\n  %s\n\n"
            . 'وإن كان الرابطُ في جزئيّةٍ مشتركة (‏القائمة الجانبيّة مثلاً) '
            . 'فالسقوطُ يعمّ **كلَّ صفحةٍ في اللوحة** لا صفحةً واحدة.',
            implode("\n  ", $missing)));
    }

    /**
     * @test
     *
     * **والقائمةُ الجانبيّةُ تُصيَّر وحدَها بلا سقوط.**
     *
     * ══════════════════════════════════════════════════════════════════
     * فالفحصُ الأوّلُ نصّيّ: يقرأ `route('x')` ولا يرى اسماً يُبنى في
     * متغيّرٍ أو مصفوفة. وهذه الجزئيّةُ بعينها هي **أخطرُ ملفٍّ في اللوحة**:
     * كلُّ صفحةٍ تُضمّنها، فعطلٌ فيها عطلٌ في الخمسين.
     */
    public function the_shared_sidebar_renders_without_throwing(): void
    {
        $path = resource_path('views/admin-views/amial/partials/_sidebar.blade.php');

        if (! is_file($path)) {
            $this->markTestSkipped('لا قائمةَ جانبيّةً بهذا المسار');
        }

        $admin = \App\Models\User::factory()->create([
            'type' => ADMIN_TYPE, 'role' => 'super_admin', 'phone' => '967770008801',
        ]);

        $this->actingAs($admin, 'user');

        // **يُصيَّر فعلاً** — لا يُقرأ نصّاً. فالتصييرُ وحدَه يُشغّل
        // `route()` على كلّ اسمٍ مهما بُني.
        $html = view('admin-views.amial.partials._sidebar')->render();

        $this->assertNotSame('', trim($html),
            'القائمةُ الجانبيّةُ تُصيَّر فارغةً — واللوحةُ بلا تنقّل');
    }
}
