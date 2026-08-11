<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-ARABIC-MESSAGES-001 — **رسالةٌ تصل يمنيّاً تُكتب بالعربيّة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * ستُّ وثلاثون رسالةً في ستّة متحكّمات كانت تخرج بالإنجليزيّة إلى شاشة
 * المستعمل: `Receipt not found` و`Fund not found` و`Invalid input`…
 *
 * والتطبيقُ عربيٌّ كلُّه، ومستعمِلوه في اليمن. فرسالةٌ إنجليزيّةٌ ليست
 * عيباً في الترجمة — هي **رفضٌ لا يُفهم**: يقف المستعمل ولا يعرف ما
 * المطلوب منه، فيراسل الدعم أو يترك العملية.
 *
 * ولا يمسكه اختبارُ سلوك: الردُّ صحيحٌ ورمزُه صحيحٌ وحالتُه صحيحة —
 * **والنصُّ وحدَه بلغةٍ أخرى**. فلا يُمسك إلّا بمسحٍ نصّيّ.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والمفحوصُ الرسالةُ لا الرمز.** الرمزُ (`NOT_FOUND`) عقدٌ بين الخادم
 * والتطبيق يُقرأ آليّاً، ويبقى إنجليزيّاً عمداً. والرسالةُ للإنسان.
 */
class ArabicUserMessagesGuardTest extends TestCase
{
    /** @return array<int,string> */
    private function apiControllers(): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Http/Controllers/Api'),
                \FilesystemIterator::SKIP_DOTS));

        foreach ($it as $f) {
            if (str_ends_with((string) $f, '.php')) {
                $out[] = (string) $f;
            }
        }

        return $out;
    }

    public function test_the_scan_actually_reads_the_api_controllers(): void
    {
        $this->assertGreaterThan(30, count($this->apiControllers()),
            'المسحُ لم يجد متحكّمات — الحارسُ معطَّل');
    }

    /**
     * **لا رسالةَ رفضٍ إنجليزيّةٌ تخرج من نقطة نهاية.**
     *
     * والنمطُ المفحوص `->error('CODE', 'نصٌّ للإنسان', …)` — الوسيطُ
     * الثاني وحده.
     */
    public function test_no_api_refusal_speaks_english_to_the_user(): void
    {
        $offenders = [];

        foreach ($this->apiControllers() as $file) {
            foreach (file($file) as $i => $line) {
                // التعليقاتُ تُطرح — الدرسُ وقع ثلاثَ مرّاتٍ في هذا المشروع.
                $code = preg_replace('#(//|\#).*$#', '', ltrim($line));

                if (! preg_match("/->error\(\s*'[A-Z0-9_]+'\s*,\s*'([^']{6,})'/",
                        (string) $code, $m)) {
                    continue;
                }

                // فيها حرفٌ عربيّ ⇒ مقبولة.
                if (preg_match('/\p{Arabic}/u', $m[1])) {
                    continue;
                }

                // `translate(...)` أو متغيّرٌ ⇒ ليست نصّاً ثابتاً.
                if (str_contains($m[1], '$')) {
                    continue;
                }

                $offenders[] = str_replace(base_path() . '/', '', $file)
                    . ':' . ($i + 1) . '  «' . $m[1] . '»';
            }
        }

        $this->assertSame([], $offenders, "\n"
            . 'رسالةُ رفضٍ إنجليزيّةٌ تصل شاشةَ المستعمل:' . "\n  "
            . implode("\n  ", $offenders) . "\n\n"
            . 'والتطبيقُ عربيٌّ ومستعمِلوه في اليمن — فرسالةٌ لا تُفهم '
            . 'رفضٌ بلا سبب: يقف المستعمل ولا يعرف ما المطلوب. '
            . '(والرمزُ يبقى إنجليزيّاً: هو عقدٌ آليٌّ لا نصٌّ للإنسان.)');
    }

    /**
     * **والرموزُ تبقى إنجليزيّةً** — فهي عقدُ التطبيق مع الخادم.
     *
     * وحارسٌ يفرض العربيّة على كلّ شيءٍ يكسر التطبيق: يقارن الرمزَ نصّاً
     * ليقرّر أيّ شاشةٍ يفتح.
     */
    public function test_the_machine_codes_stay_english(): void
    {
        $arabicCodes = [];

        foreach ($this->apiControllers() as $file) {
            foreach (file($file) as $i => $line) {
                if (! preg_match("/->error\(\s*'([^']+)'\s*,/", ltrim($line), $m)) {
                    continue;
                }

                if (preg_match('/\p{Arabic}/u', $m[1])) {
                    $arabicCodes[] = str_replace(base_path() . '/', '', $file) . ':' . ($i + 1);
                }
            }
        }

        $this->assertSame([], $arabicCodes,
            'رمزُ خطأٍ بالعربيّة — والتطبيقُ يقارنه نصّاً ليقرّر ماذا يعرض: '
            . implode('، ', $arabicCodes));
    }
}
