<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-LEDGER-BLOCKING-003 — لا يعود ابتلاعُ فشل الدفتر.
 *
 * **العطل الذي يحرسه، بالقياس لا بالوصف:**
 * كان الترحيل يقع بعد الـ commit داخل `safeLedgerPost` الذي يبتلع الاستثناء
 * ويكتفي بسطرٍ في اللوج. وقياسٌ حيّ على الشيفرة قبل الإصلاح أعطى:
 *
 *     رصيد المحفظة بعد التحويل: 9000.0000
 *     عدد قيود send_money في الدفتر: 0
 *
 * أي أن الدفتر لم يكن «مؤجَّلاً» بل **معطَّلاً**، ولم يظهر ذلك في أربعمئة
 * اختبار لأنها جميعاً تفحص القيد حين يُكتب، ولا واحد يسأل: هل كُتب أصلاً؟
 *
 * **لماذا حارسٌ نصّيّ ولم يكفِ الاختبار السلوكيّ؟**
 * `LedgerWalletReconciliationTest` يثبت أن المسارات الحالية تُرحّل. لكنه لا
 * يمنع أحداً من إضافة `try { ... } catch { Log::error(...) }` حول ترحيلٍ
 * جديد غداً — وهو أسهل ما يُكتب حين يُزعج استثناءٌ في الإنتاج. فالحارس على
 * **النمط** لا على المسار، لأن العطل الأصليّ كان نمطاً لا سهواً.
 */
class LedgerBlockingGuardTest extends TestCase
{
    /** ملفّات المال التي يشملها الحارس. */
    private function moneyFiles(): array
    {
        $out = [];
        foreach ([base_path('app/Services'), base_path('app/Traits')] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($it as $f) {
                if ($f->isFile() && $f->getExtension() === 'php') {
                    $out[$f->getPathname()] = file_get_contents($f->getPathname());
                }
            }
        }

        return $out;
    }

    public function test_the_scan_sees_the_money_files(): void
    {
        // حارسٌ لا يجد ما يحرسه يمرّ دائماً — وقد وقع لي مثله في هذه الجولة.
        $files = $this->moneyFiles();

        $this->assertGreaterThanOrEqual(50, count($files),
            'المسح وجد ' . count($files) . ' ملفّاً فقط — تغيّرت البنية وصار أعمى');

        $this->assertArrayHasKey(base_path('app/Traits/PostsToLedger.php'), $files,
            'لم يُعثر على PostsToLedger — الحارس يفحص مكاناً خاطئاً');
    }

    public function test_the_swallowing_helper_is_not_reintroduced(): void
    {
        $offenders = [];

        foreach ($this->moneyFiles() as $path => $src) {
            // التعليقات التاريخية تذكر الاسم عمداً لتشرح لماذا حُذف.
            // المرفوض تعريفٌ أو نداءٌ فعليّ: `safeLedgerPost(` بقوس.
            $code = preg_replace('~^\s*(//|\*|/\*).*$~m', '', $src);

            if (preg_match('/\bsafeLedgerPost\s*\(/', (string) $code)) {
                $offenders[] = str_replace(base_path() . '/', '', $path);
            }
        }

        $this->assertSame([], $offenders,
            "عاد ابتلاع فشل الدفتر في: " . implode('، ', $offenders) . "\n"
            . "هذا النمط جعل الدفتر معطَّلاً صامتاً: المال يتحرّك والقيد لا "
            . "يُكتب، بلا خطأ ولا انهيار. القيد يوضع داخل DB::transaction "
            . "نفسها، فإمّا أن يتمّ المال وقيدُه معاً وإمّا لا يتمّ شيء.");
    }

    public function test_no_ledger_call_is_wrapped_in_a_silencing_catch(): void
    {
        // النمط نفسه بصياغة أخرى: try حول نداء ترحيل، وcatch يكتفي بـ Log.
        // هذه هي الصورة التي كانت في DonationsService و add_money قبل الإصلاح.
        $offenders = [];

        foreach ($this->moneyFiles() as $path => $src) {
            // كتلة try تحوي نداء ترحيل، يتبعها catch لا يُعيد الرمي.
            //
            // لا يُستعمل `[^}]` هنا: النصّ بين `try {` والنداء يحوي أقواساً
            // (مصفوفات، دوالّ مجهولة)، فيفشل النمط صامتاً. جُرّب عكسياً
            // بإدخال catch مُسكِت فمرّ الحارس — وهو صنف «حارسٍ ينجح لسببٍ
            // خاطئ» الذي وُضع هذا الملفّ ليمنع مثله.
            $pattern = '~try\s*\{.{0,3000}?(ledger(Transfer|Donation|BillPayment|HoldEscrow'
                . '|ReleaseEscrow|RefundEscrow|TransferWithFee)|\$ledger->post)\b'
                . '.{0,3000}?\}\s*catch\s*\(.{0,400}?\}~s';

            if (preg_match($pattern, $src, $m)) {
                // يُقبل إن كان الـ catch يُعيد الرمي — فذاك ليس إسكاتاً.
                if (!preg_match('/throw\s/', $m[0])) {
                    $offenders[] = str_replace(base_path() . '/', '', $path);
                }
            }
        }

        $this->assertSame([], $offenders,
            "نداء ترحيلٍ مُحاطٌ بـ catch يُسكته في: " . implode('، ', $offenders) . "\n"
            . "إن كان الإسكات مقصوداً فأعِد رمي الاستثناء بعد التسجيل، "
            . "أو انقل القيد داخل المعاملة. أمّا catch يكتفي بـ Log فيعيدنا "
            . "إلى دفترٍ اختياريّ — وسجلٌّ اختياريّ ليس سجلّاً.");
    }
}
