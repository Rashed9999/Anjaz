<?php

namespace Tests\Feature;

use App\Services\AuditService;
use Tests\TestCase;

/**
 * AMIAL-AUDIT-KEYS-001 — **مفتاحٌ مجهولٌ في حمولة التدقيق لا يمرّ بصمت.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمن الذي دُفع:** كُتب `'metadata' => [...]` في **سبعةَ عشرَ موضعاً**
 * في جانب الوكيل — الورديّاتُ والتسوياتُ والموظّفون وطلباتُ الشبّاك
 * وأحداثُه — و`AuditService::record()` لا تقرأ إلّا `context`.
 *
 * فكان كلُّ ما في الحمولة **يُسقَط بلا رسالةٍ ولا سطرٍ في أيّ سجلّ**:
 * `staff_id` و`branch_id` و`opening_float` و`reference`. والسجلُّ يقول
 * «فُتحت ورديّة» ولا يقول **من ولا في أيّ فرعٍ ولا بكم عهدة** — وهو
 * بالضبط ما يُبحث عنه في أيّ تحقيق.
 *
 * وظهر بعد شهورٍ حين فُتحت شاشةُ التدقيق وقُرئ فيها «لا سياق مسجَّل».
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والحارسُ ساكنٌ لا يعمل في وقت التشغيل** — وهذا مقصود.
 *
 * `record()` كلُّها داخل `try`، فرمي استثناءٍ على مفتاحٍ مجهولٍ يُبتلع في
 * `catch` **فيُفقَد سطرُ التدقيق كلُّه**: عقوبةٌ على خطأٍ صغيرٍ بفقدِ
 * الأثر نفسِه. فالمنعُ هنا — قبل النشر — والأثرُ سطرُ تحذيرٍ هناك.
 */
class AuditPayloadKeysGuardTest extends TestCase
{
    /**
     * @test
     *
     * لا نداءَ لـ`record()` يمرّر مفتاحاً خارج `KNOWN_KEYS`.
     */
    public function every_audit_call_uses_only_keys_the_service_reads(): void
    {
        $offences = [];
        $seen = 0;

        foreach ($this->phpFiles() as $file) {
            $src = file_get_contents($file);

            foreach ($this->recordCalls($src) as [$line, $keys]) {
                $seen++;
                $unknown = array_diff($keys, AuditService::KNOWN_KEYS);

                foreach ($unknown as $k) {
                    $offences[] = sprintf('%s:%d — %s',
                        str_replace(base_path() . '/', '', $file), $line, $k);
                }
            }
        }

        // ══════════════════════════════════════════════════════════════
        // **وحارسٌ لا يجد ما يفحص ليس حارساً.**
        //
        // لو تغيّرت صيغةُ النداء (اسمٌ آخرُ للخدمة، أو مُعامِلٌ مسمّى)
        // لتوقّف المُطابِقُ عن الالتقاط **وخرج الفحصُ أخضرَ على صفر** —
        // وهو الصمتُ نفسُه بثوب نجاح. فيُشترَط حدٌّ أدنى.
        // ══════════════════════════════════════════════════════════════
        $this->assertGreaterThan(60, $seen,
            "لم يُلتقط إلّا {$seen} نداءً لـ`AuditService::record()` — والمشروعُ "
            . 'فيه أضعافُها. تغيّرت صيغةُ النداء ولم يعد المُطابِقُ يراها، '
            . '**فالفحصُ يمرّ على لا شيء**. يُحدَّث `recordCalls()` ولا يُحذف.');

        $this->assertSame([], $offences,
            "مفاتيحُ حمولةٍ لا تقرؤها `AuditService::record()` — **تُسقَط بصمت**:\n  "
            . implode("\n  ", $offences) . "\n\n"
            . "والمفاتيحُ المقروءة: " . implode(' · ', AuditService::KNOWN_KEYS) . "\n"
            . "فإمّا يُصحَّح الاسم، وإمّا يُضاف المفتاحُ إلى `KNOWN_KEYS`\n"
            . "**ويُقرأ فعلاً في `record()`** — وإضافتُه إلى القائمة وحدَها\n"
            . 'تُسكت الحارسَ ولا تُوصل البيانات.');
    }

    /**
     * @test
     *
     * **والقائمةُ نفسُها لا تكذب**: كلُّ مفتاحٍ فيها يُقرأ في الخدمة.
     *
     * فمفتاحٌ مُدرَجٌ ولا يُقرأ يجعل الحارسَ الأوّلَ يمرّ على فقدٍ قائم —
     * وهو نمطُ «حارسٌ يمرّ والعطلُ قائم» بعينه.
     */
    public function every_known_key_is_actually_read_by_the_service(): void
    {
        $src = file_get_contents(app_path('Services/AuditService.php'));

        // يُقصّ متنُ `record()` وحدَه — فذكرُ المفتاح في `KNOWN_KEYS`
        // نفسِها ليس قراءةً له.
        $start = strpos($src, 'public function record(array $payload)');
        $body = substr($src, (int) $start, 4000);

        $missing = [];

        foreach (AuditService::KNOWN_KEYS as $key) {
            if (! str_contains($body, "\$payload['{$key}']")) {
                $missing[] = $key;
            }
        }

        $this->assertSame([], $missing,
            "مفاتيحُ مُدرَجةٌ في `KNOWN_KEYS` ولا تُقرأ في `record()`:\n  "
            . implode("\n  ", $missing) . "\n\n"
            . 'فالحارسُ الأوّلُ يمرّ عليها بينما البياناتُ تُسقَط — '
            . 'وحارسٌ يمرّ والعطلُ قائمٌ أسوأ من غيابه.');
    }

    /** @return list<string> */
    private function phpFiles(): array
    {
        $out = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));

        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $out[] = $f->getPathname();
            }
        }

        sort($out);

        return $out;
    }

    /**
     * مفاتيحُ **المستوى الأوّل** في كلّ نداءٍ لـ`record([...])`.
     *
     * ولا يُستعمل تعبيرٌ نمطيٌّ على المتن كلِّه (القاعدة الخامسة): مصفوفاتٌ
     * متداخلةٌ داخل `context` لها مفاتيحُها، والجشعُ يخلطها بمفاتيح
     * الحمولة فيُبلّغ عن عشراتٍ لا وجودَ لها. فتُتتبَّع الأقواسُ عمقاً.
     *
     * @return list<array{0:int,1:list<string>}>
     */
    private function recordCalls(string $src): array
    {
        $calls = [];
        $offset = 0;

        while (($pos = strpos($src, 'record([', $offset)) !== false) {
            $offset = $pos + 8;

            // ══════════════════════════════════════════════════════
            // **ويُشترَط أن يكون النداءُ لخدمة التدقيق بعينها.**
            //
            // أوّلُ تشغيلٍ للحارس بلّغ عن `ReconcileNightly.php:43` —
            // وهي `$this->record()` **دالّةٌ خاصّةٌ في الأمر نفسِه**
            // تكتب في جدولٍ آخر. فمُطابِقٌ يقبل كلَّ `record([` يخلط
            // الأسماءَ المتشابهة ويُرسل خلف عطلٍ لا وجودَ له.
            //
            // والصيغُ المستعملة كلُّها تذكر `audit` قبلها بقليل:
            // `$this->audit->record([` · `app(AuditService::class)->record([`
            // ══════════════════════════════════════════════════════
            $before = substr($src, max(0, $pos - 60), min(60, $pos));

            if (stripos($before, 'audit') === false) {
                continue;
            }

            $line = substr_count(substr($src, 0, $pos), "\n") + 1;
            $depth = 1;
            $i = $offset;
            $keys = [];
            $len = strlen($src);

            while ($i < $len && $depth > 0) {
                $c = $src[$i];

                if ($c === '[' || $c === '(') {
                    $depth++;
                } elseif ($c === ']' || $c === ')') {
                    $depth--;
                } elseif ($c === "'" && $depth === 1) {
                    $end = strpos($src, "'", $i + 1);

                    if ($end === false) {
                        break;
                    }

                    $key = substr($src, $i + 1, $end - $i - 1);
                    $after = ltrim(substr($src, $end + 1, 4));

                    if (str_starts_with($after, '=>')) {
                        $keys[] = $key;
                    }

                    $i = $end;
                }

                $i++;
            }

            if ($keys !== []) {
                $calls[] = [$line, $keys];
            }
        }

        return $calls;
    }
}
