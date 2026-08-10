<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-OTP-ENV-001 — **`env()` تكذب في الإنتاج، وحدَه.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمن الذي دُفع لهذا الحارس — وهو أخطرُ ما وُجد في التدقيق:**
 *
 * ثلاثةُ متحكّمات كانت تكتب:
 *
 *     $otp = (env('APP_MODE') != 'live') ? '1234' : rand(1000, 9999);
 *
 * و`docker/entrypoint.prod.sh:109` يشغّل `php artisan config:cache`.
 * ولارافيل حين يجد إعداداً مخزَّناً **لا يحمّل ملفّ `.env` إطلاقاً**
 * (`LoadEnvironmentVariables` تعود مبكّراً). فـ`env()` خارج ملفّات
 * `config` تُرجع `null`، و`null != 'live'` صحيحة —
 * **فالرمزُ كان `1234` ثابتاً على الخادم الحيّ.**
 *
 * والثلاثةُ هي: رمزُ العمليّات، و**استعادةُ كلمة مرور العميل**،
 * و**استعادةُ كلمة مرور الوكيل**. أي أنّ من يعرف رقمَ هاتفٍ يستولي على
 * الحساب — ومنه حسابُ وكيلٍ يحمل سيولةً إلكترونيّةً ونقداً في خزنته.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولماذا لم يمسكه شيء:**
 *
 * ① المتغيّرُ مضبوطٌ في البيئة فعلاً — فمن يقرأ `.env` يراه سليماً.
 * ② الشيفرةُ صحيحةٌ نحويّاً ومنطقيّاً لمن يقرؤها.
 * ③ **الاختباراتُ كلُّها تمرّ** — لأنّها تعمل بلا إعدادٍ مخزَّن، فتقرأ
 *    `env()` قيمتَها الحقيقيّة. أي أنّ بيئة الاختبار **تُخفي** العطل.
 * ④ لا سطرَ في أيّ سجلّ: لا خطأ، ولا تحذير، ولا استثناء.
 *
 * فلا يمسكه إلّا حارسٌ يفحص **الشيفرة نفسَها** لا سلوكَها.
 */
class EnvAfterConfigCacheGuardTest extends TestCase
{
    /**
     * ملفّاتُ PHP كلُّها تحت `app/`.
     *
     * @return array<int,string>
     */
    private function sources(): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));

        foreach ($it as $f) {
            if (str_ends_with((string) $f, '.php')) {
                $out[] = (string) $f;
            }
        }

        return $out;
    }

    public function test_the_scan_actually_reads_the_application_sources(): void
    {
        // حارسٌ يمسح فلا يجد ملفّات يمرّ دائماً.
        $this->assertGreaterThan(200, count($this->sources()),
            'المسحُ لم يجد ملفّات — الحارسُ نفسُه معطَّل');
    }

    /**
     * **لا قرارَ أمنيٍّ يُبنى على `env()` خارج `config/`.**
     *
     * والاستثناء الوحيد المقبول صيغةُ `config(...) ?? env(...)`: الإعدادُ
     * أوّلاً والمتغيّرُ احتياطٌ — وهي تعمل بعد التخزين لأنّ `config()`
     * تُجيب.
     */
    public function test_no_security_decision_reads_env_directly(): void
    {
        $sensitive = '/(APP_MODE|APP_DEBUG|DEMO_OTP|_OTP|PASSWORD|SECRET|_KEY|TOKEN|LIVE)/i';
        $offenders = [];

        foreach ($this->sources() as $file) {
            foreach (file($file) as $i => $line) {
                // **التعليقُ الذي يصف العطل كان يُسقط الحارس.**
                //
                // وقع هذا حرفيّاً في `CLAUDE.md` من قبل: «حارسٌ مرّ لأنّ
                // الكلمة وردت في تعليقٍ عربيّ يشرح أنّ النقطة غير موصولة».
                // فتُطرح التعليقاتُ أوّلاً — والحارسُ يقرأ الشيفرة لا شرحَها.
                $code = preg_replace('#(//|\#).*$#', '', ltrim($line));
                $code = preg_replace('#^\s*\*.*$#', '', (string) $code);

                if (! preg_match('/\benv\s*\(\s*[\'"]([A-Z0-9_]+)[\'"]/', (string) $code, $m)) {
                    continue;
                }

                // `config('x') ?? env('X')` — الإعدادُ هو الحكم والمتغيّرُ احتياط.
                if (str_contains((string) $code, 'config(')) {
                    continue;
                }

                if (! preg_match($sensitive, $m[1])) {
                    continue;
                }

                $offenders[] = str_replace(base_path() . '/', '', $file) . ':' . ($i + 1)
                    . '  →  env(' . $m[1] . ')';
            }
        }

        $this->assertSame([], $offenders, "\n"
            . 'قرارٌ أمنيٌّ يُبنى على env() خارج ملفّات config:' . "\n  "
            . implode("\n  ", $offenders) . "\n\n"
            . 'وبعد `php artisan config:cache` — وهو ما يفعله '
            . '`docker/entrypoint.prod.sh` — لا يُحمَّل `.env` إطلاقاً، '
            . 'فتُرجع env() قيمةَ null في الإنتاج وحدَه. '
            . 'انقل القيمة إلى ملفّ `config/` واقرأها بـ`config()`.');
    }

    /**
     * **ورمزُ التحقّق يُولَّد من بابٍ واحد.**
     *
     * ونصٌّ لا سلوك: توليدُ الرمز في متحكّمٍ يمرّ بكلّ اختبارٍ سلوكيٍّ
     * ما دامت البيئةُ غيرَ مخزَّنة — وهو بالضبط ما جعل العطل يعيش.
     */
    public function test_every_otp_is_minted_by_the_one_policy(): void
    {
        $offenders = [];

        // **الاستثناءُ يُكتب بسببه، لا يُسكَت عنه.**
        //
        // رمزُ ربط واتساب ليس رمزَ دخولٍ ولا استعادةِ كلمةِ مرور: هو رمزٌ
        // يُعرض في التطبيق فيُرسله صاحبُ الرقم من هاتفه إلى رقم الخدمة.
        // فالإثباتُ فيه **وصولُ الرسالة من الرقم نفسه** لا سرّيّةُ الرمز،
        // ولا معنى لرقم عرضٍ فيه. وكلاهما `random_int` — عشوائيٌّ آمنٌ
        // مشفَّر، لا `mt_rand` ولا ثابتٌ في البيئة.
        $exempt = [
            'Services/Whatsapp/AgentWhatsappService.php',
            'Services/Whatsapp/WhatsappAccountLinkingService.php',
        ];

        foreach ($this->sources() as $file) {
            if (str_contains($file, 'Services/Otp/')) {
                continue;   // البابُ نفسُه
            }

            foreach ($exempt as $e) {
                if (str_contains($file, $e)) {
                    continue 2;
                }
            }

            foreach (file($file) as $i => $line) {
                // `rand(1000,9999)` أو `random_int(...)` مقروناً بكلمة otp
                if (preg_match('/\b(rand|mt_rand|random_int)\s*\(/i', $line)
                    && preg_match('/\$otp\b|\botp\s*=/i', $line)) {
                    $offenders[] = str_replace(base_path() . '/', '', $file) . ':' . ($i + 1);
                }
            }
        }

        $this->assertSame([], $offenders, "\n"
            . 'رمزُ تحقّقٍ يُولَّد خارج OtpPolicy:' . "\n  "
            . implode("\n  ", $offenders) . "\n\n"
            . 'وبابان لتوليد الرمز يعنيان أنّ إصلاح أحدهما لا يُصلح الآخر — '
            . 'وهو ما جعل ثلاثةَ متحكّماتٍ تُصدر `1234` في الإنتاج بينما '
            . 'مسارُ التسجيل الجديد سليم.');
    }
}
