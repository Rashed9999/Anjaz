<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-SEC-MOBILE-001 — حرّاسُ أمن تطبيق فلاتر قبل الإطلاق. تقرأ الملفّات (لا مُصرِّفَ أندرويد هنا)
 * فتمسك الرجوعَ عن كلٍّ من: التعتيم · الحركة النصّية · الصلاحيات الزائدة · سجلّات الإنتاج · FLAG_SECURE · التخزين الآمن.
 */
class MobileSecurityGuardTest extends TestCase
{
    private function f(string $rel): string
    {
        return (string) file_get_contents(base_path('../'.$rel));
    }

    /** @test */
    public function release_build_is_minified_obfuscated_and_cleartext_is_off(): void
    {
        $g = $this->f('02_flutter_app/android/app/build.gradle.kts');
        $this->assertMatchesRegularExpression('/isMinifyEnabled\s*=\s*true/', $g, 'R8 معطَّل.');
        $this->assertMatchesRegularExpression('/isShrinkResources\s*=\s*true/', $g);
        $this->assertFileExists(base_path('../02_flutter_app/android/app/proguard-rules.pro'));
        $this->assertStringContainsString('--obfuscate --split-debug-info', $this->f('codemagic.yaml'), 'تعتيم Dart غائب عن بناء الإنتاج.');

        $m = $this->f('02_flutter_app/android/app/src/main/AndroidManifest.xml');
        $this->assertStringContainsString('android:usesCleartextTraffic="false"', $m);
        $this->assertStringContainsString('android:allowBackup="false"', $m);
        $this->assertStringContainsString('networkSecurityConfig', $m);
    }

    /** @test */
    public function unneeded_permissions_stay_removed(): void
    {
        $m = $this->f('02_flutter_app/android/app/src/main/AndroidManifest.xml');
        foreach (['WRITE_CONTACTS', 'RECORD_AUDIO', 'READ_PHONE_STATE'] as $p) {
            $this->assertMatchesRegularExpression('/'.$p.'"\s+tools:node="remove"/', $m, "{$p} عاد صلاحيةً مطلوبة.");
        }
        $this->assertMatchesRegularExpression('/WRITE_EXTERNAL_STORAGE"\s+android:maxSdkVersion="\d+"/', $m);
    }

    /** @test */
    public function production_silences_debug_print_and_screens_stay_flag_secure(): void
    {
        $main = $this->f('02_flutter_app/lib/main.dart');
        $this->assertMatchesRegularExpression('/if\s*\(!kDebugMode\)\s*\{\s*debugPrint\s*=/', $main, 'debugPrint يطبع في الإنتاج.');
        $this->assertStringContainsString('FLAG_SECURE', $this->f('02_flutter_app/android/app/src/main/kotlin/amialpay/com/MainActivity.kt'));

        $raw = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('../02_flutter_app/lib'), \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'dart') {
                continue;
            }
            foreach (file($file->getPathname()) as $n => $line) {
                if (preg_match('/(^|[^A-Za-z_.])print\(/', $line) && ! str_contains($line, '//')) {
                    $raw[] = basename($file->getPathname()).':'.($n + 1);
                }
            }
        }
        $this->assertSame([], $raw, "print() خامٌ لا يُسكَت في الإنتاج:\n".implode("\n", $raw));
    }

    /** @test */
    public function tokens_live_in_encrypted_secure_storage_not_plain_prefs(): void
    {
        $s = $this->f('02_flutter_app/lib/data/api/secure_storage_helper.dart');
        $this->assertStringContainsString('encryptedSharedPreferences: true', $s);
        $auth = $this->f('02_flutter_app/lib/features/auth/controllers/unified_auth_controller.dart');
        $this->assertDoesNotMatchRegularExpression('/prefs\.setString\([^)]*(token|pin|password)/i', $auth, 'سرٌّ يُكتب في SharedPreferences.');
    }
}
