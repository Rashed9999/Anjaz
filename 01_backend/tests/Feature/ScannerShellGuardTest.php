<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-SCANNER-SHELL-001 — الكاميرا تعمل، أو تقول لماذا لا.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **العطل الذي وُلد منه هذا الحارس:**
 *
 * «كاميرا مسح باركود المنتج في الكاشير لا تعمل.»
 *
 * وقِيس فوجد أنّ الخادمَ سليم (`products/lookup` مسجَّلٌ ويردّ)، وأنّ
 * الإذنين معلَنان في `AndroidManifest` و`Info.plist`، وأنّ إعدادَ
 * `MobileScannerController` مطابقٌ للماسحات الأخرى.
 *
 * **والعطلُ كان أنّ الشاشة لا تملك حالةَ فشل**: `MobileScanner` بلا
 * `errorBuilder`. فحين لا تبدأ الكاميرا — إذنٌ رُفض نهائيّاً، أو كاميرا
 * مشغولةٌ بتطبيقٍ آخر — **يُرسَم مستطيلٌ أسودُ صامت**: لا رسالة، ولا رمز
 * خطأ، ولا طريقَ خروج.
 *
 * وهو نصُّ ما تقوله القاعدة التاسعة: «يُضغط الزرّ ولا يحدث شيء، ولا
 * رسالة، ولا طلبٌ يصل».
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ حارسٌ في PHP على شيفرة Dart:**
 *
 * لا أدواتِ Dart في بيئة البناء هذه (`dart: command not found`)، فلا
 * `flutter analyze` ولا اختبارَ ودجت. **وحارسٌ يعمل خيرٌ من حارسٍ صحيحٍ
 * لا يُشغَّل.** وهذا يقرأ المصدر نفسَه ويسقط في `verify.sh` مع كلّ فحص.
 */
class ScannerShellGuardTest extends TestCase
{
    private function appPath(string $rel): string
    {
        return base_path('../02_flutter_app/' . $rel);
    }

    private function scannerFiles(): array
    {
        $root = base_path('../02_flutter_app/lib');

        if (!is_dir($root)) {
            return [];
        }

        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'dart') {
                continue;
            }

            $src = file_get_contents($f->getPathname());

            if (str_contains($src, 'MobileScanner(')) {
                $out[str_replace($root . '/', '', $f->getPathname())] = $src;
            }
        }

        return $out;
    }

    /**
     * @test
     *
     * **الغلافُ موجود، وفيه ما يجعل الفشلَ مسموعاً.**
     */
    public function the_scanner_shell_exists_and_handles_failure(): void
    {
        $p = $this->appPath('lib/features/shared/widgets/scanner_shell.dart');

        $this->assertFileExists($p, 'غلافُ الماسح غير موجود');

        $src = file_get_contents($p);

        foreach ([
            'errorBuilder' => 'لا بنّاءَ خطأ — الفشلُ يُرسم سواداً صامتاً',
            'Permission.camera' => 'لا يُطلب إذنُ الكاميرا صراحةً',
            'openAppSettings' => 'لا طريقَ إلى إعدادات النظام — الرفضُ النهائيّ بلا مخرج',
            'permanentlyDenied' => 'لا تمييزَ للرفض النهائيّ عن الرفض العابر',
            'didChangeAppLifecycleState' => 'من عاد من الإعدادات يجد السوادَ نفسَه',
        ] as $needle => $why) {
            $this->assertStringContainsString($needle, $src, $why);
        }
    }

    /**
     * @test
     *
     * **ولا ماسحَ واحدٌ خارج الغلاف.**
     *
     * ══════════════════════════════════════════════════════════════════
     * وهذا هو الحارسُ الذي يبقى: إصلاحُ الشاشات الثلاث اليوم لا يمنع
     * رابعةً تُكتب غداً بـ`MobileScanner` عارٍ — **فتعود الشاشةُ السوداء
     * الصامتة من حيث لا يُتوقَّع.**
     *
     * فيُفحص المصدرُ كلُّه: كلُّ من يستعمل `MobileScanner` مباشرةً يسقط
     * هنا، إلّا الغلافَ نفسَه.
     */
    public function no_scanner_bypasses_the_shell(): void
    {
        $files = $this->scannerFiles();

        $this->assertNotEmpty($files,
            'لم يُعثر على أيّ ماسح — الحارسُ يفحص فراغاً');

        $shell = 'features/shared/widgets/scanner_shell.dart';

        $this->assertArrayHasKey($shell, $files, 'الغلافُ لا يستعمل MobileScanner');

        unset($files[$shell]);

        $this->assertSame([], array_keys($files),
            'ماسحٌ يستعمل MobileScanner مباشرةً بلا غلاف: ' . implode(' · ', array_keys($files)));
    }

    /**
     * @test
     *
     * **وكلُّ ماسحٍ يمرّ بالغلاف فعلاً — لا يستورده ويهمله.**
     *
     * فاستيرادٌ بلا استعمالٍ يُرضي الحارسَ السابق ولا يحرس شيئاً.
     */
    public function every_scanner_screen_actually_uses_the_shell(): void
    {
        $screens = [
            'lib/features/merchant/screens/cashier_scan_screen.dart' => 'مسح باركود الكاشير',
            'lib/features/shared/widgets/qr_widgets.dart' => 'مسح رمز التاجر',
            'lib/features/barcode/screens/continuous_scanner_screen.dart' => 'المسح المستمرّ',
        ];

        foreach ($screens as $rel => $label) {
            $p = $this->appPath($rel);

            $this->assertFileExists($p, "اختفت شاشةُ {$label}");

            $src = file_get_contents($p);

            $this->assertStringContainsString('ScannerShell(', $src,
                "شاشةُ {$label} لا تستعمل الغلاف");

            $this->assertStringContainsString('scanner_shell.dart', $src,
                "شاشةُ {$label} تستعمل الغلافَ بلا استيراد — لا تُصرَّف");
        }
    }

    /**
     * @test
     *
     * **وشاشةُ الكاشير تُبقي طريقَ الإدخال اليدويّ.**
     *
     * فإضاءةٌ ضعيفةٌ عند صندوق، أو عدسةٌ مخدوشة، أو باركود مطبوعٌ بهت —
     * كلُّها تقع كلَّ يوم. ومن لا طريقَ له إلّا الكاميرا يقف عاجزاً
     * والزبونُ ينتظر.
     */
    public function the_cashier_scanner_keeps_a_manual_way_out(): void
    {
        $src = file_get_contents($this->appPath('lib/features/merchant/screens/cashier_scan_screen.dart'));

        $this->assertStringContainsString('onManualEntry:', $src,
            'لا طريقَ خروجٍ حين تفشل الكاميرا في الكاشير');

        $this->assertStringContainsString('_manualEntry', $src,
            'زرُّ الإدخال اليدويّ لا يشير إلى دالّةٍ موجودة');
    }

    /**
     * @test
     *
     * **والإذنان معلَنان في المنصّتين.**
     *
     * فغيابُ الإعلان يجعل الطلبَ يُرفض فوراً بلا أن يُسأل المستعمل —
     * وحينها تُعرض شاشةُ «مرفوض» ولا يفهم أحدٌ لماذا.
     */
    public function the_camera_permission_is_declared_on_both_platforms(): void
    {
        $manifest = file_get_contents($this->appPath('android/app/src/main/AndroidManifest.xml'));

        $this->assertStringContainsString('android.permission.CAMERA', $manifest,
            'إذنُ الكاميرا غيرُ معلَنٍ في أندرويد');

        $plist = file_get_contents($this->appPath('ios/Runner/Info.plist'));

        $this->assertStringContainsString('NSCameraUsageDescription', $plist,
            'وصفُ استعمال الكاميرا غائبٌ في iOS — يرفضه المتجر ويسقط الطلب');
    }
}
