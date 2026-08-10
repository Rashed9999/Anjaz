<?php

namespace Tests\Feature;

use App\CentralLogics\Helpers;
use App\Models\EMoney;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-LEGACY-WALLET-001 — **المسارُ القديم يقرأ الرصيد ثمّ يكتبه بلا قفل.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * `Helpers::updateEmoney()` كانت:
 *
 *     $emoney = EMoney::where('user_id', $id)->firstOrFail();   // قراءة
 *     $emoney->current_balance += $amount;                      // حساب
 *     $emoney->save();                                          // كتابة
 *
 * ثلاثُ خطواتٍ بلا `lockForUpdate` وبلا معاملة. وتُنادى من **عشرة
 * مواضع**، منها ما يُحرّك مالاً إداريّاً: توليدُ رصيدٍ إلكترونيّ
 * (`EMoneyController`)، وتحويلُ الإدارة (`TransferController`)، ومركزُ
 * التمويل (`AdminHubController`).
 *
 * **والخطرُ ليس نظريّاً:** عمليّتان متزامنتان على المحفظة نفسها تقرآن
 * الرصيدَ نفسَه، فتكتب الثانيةُ فوق الأولى — **ويختفي مبلغُ إحداهما**
 * بينما سجلُّ الحركات يُظهر العمليّتين ناجحتين.
 *
 * وهو الصنفُ الذي وصفته القاعدةُ الأولى في `CLAUDE.md`: «فحصٌ يقع خارج
 * القفل — لا يظهر إلّا بالتوازي». والمسارُ الجديد
 * (`FinancialGuardService`) سليمٌ منذ البداية: `assertInTransaction()`
 * ثمّ `lockWallet()`. **والقديمُ بقي بلا واحدٍ منهما.**
 */
class LegacyWalletRaceGuardTest extends TestCase
{
    use RefreshDatabase;

    private function walletUser(string $start = '1000'): User
    {
        $u = User::factory()->create(['type' => 1, 'role' => 'customer', 'is_active' => 1]);
        EMoney::create([
            'user_id' => $u->id,
            'current_balance' => $start,
            'pending_balance' => '0',
            'held_balance' => '0',
            'charge_earned' => '0',
        ]);

        return $u;
    }

    /**
     * **القفلُ موجودٌ فعلاً في الاستعلام** — نصّاً لا سلوكاً.
     *
     * ولماذا نصّاً: إثباتُ ضياع التحديث يحتاج عمليّتين متزامنتين
     * حقيقيّتين، ومجموعةُ الاختبارات كلُّها في عمليّةٍ واحدةٍ متتابعة —
     * فالسلوكُ يمرّ دائماً ولو كان القفل غائباً. **وهو بالضبط سببُ بقاء
     * العطل.** فيُفحص الشرطُ الذي يجعله مستحيلاً.
     */
    public function test_the_legacy_wallet_writer_locks_the_row(): void
    {
        $src = file_get_contents(app_path('CentralLogics/Helpers.php'));

        $start = strpos($src, 'function updateEmoney');
        $this->assertNotFalse($start, 'updateEmoney اختفت — حدِّث هذا الحارس');

        $body = substr($src, $start, 3000);

        $this->assertStringContainsString('lockForUpdate', $body,
            'updateEmoney تقرأ المحفظة بلا قفل — عمليّتان متزامنتان تكتب '
            . 'إحداهما فوق الأخرى، ويختفي مبلغُ واحدةٍ منهما بينما سجلُّ '
            . 'الحركات يُظهر العمليّتين ناجحتين.');

        // **والقفلُ خارج معاملةٍ لا يعني شيئاً**: MySQL يُحرّره فور انتهاء
        // الاستعلام. فيُشترط أن تُنفَّذ داخل معاملة.
        $this->assertStringContainsString('transactionLevel', $body,
            'لا شرطَ يمنع تنفيذها خارج معاملة — والقفلُ عندئذٍ زينة');
    }

    /**
     * **ولماذا لا يُختبَر الرفضُ سلوكيّاً:**
     *
     * `RefreshDatabase` يلفّ كلَّ اختبارٍ في معاملة، فـ`transactionLevel()`
     * لا تكون صفراً أبداً داخل المجموعة. أي أنّ الحالة السالبة **غيرُ
     * قابلةٍ للقياس هنا بحكم بنية الاختبارات**، لا بحكم كسلٍ في كتابتها.
     *
     * وهذا نفسُه سببُ بقاء العطل الأصليّ حيّاً: بيئةُ الاختبار تجعل
     * المسارَ الخطأ يبدو صحيحاً. فيُفحص النصُّ حيث يعجز السلوك — ويُقال
     * ذلك صراحةً بدل أن يُترك حارسٌ ناقصٌ يبدو كاملاً.
     */
    public function test_the_transaction_requirement_is_documented_as_untestable_behaviourally(): void
    {
        $this->assertGreaterThan(0, DB::transactionLevel(),
            'إن صارت الاختباراتُ تعمل بلا معاملةٍ ملتفّة، فحوِّل الحارسَ '
            . 'أعلاه إلى اختبارٍ سلوكيّ: نادِ updateEmoney خارج معاملة '
            . 'وتأكّد أنّها ترمي RuntimeException.');
    }

    /** وداخل معاملةٍ يعمل كما كان — لا يُكسر ما هو قائم. */
    public function test_inside_a_transaction_it_still_moves_the_money(): void
    {
        $u = $this->walletUser('1000');

        $after = DB::transaction(
            fn () => Helpers::updateEmoney($u->id, 250.0, 'credit', 'cash_in', 0.0));

        $this->assertSame('1250.0000', (string) EMoney::where('user_id', $u->id)->value('current_balance'));
        $this->assertEqualsWithDelta(1250.0, $after, 0.0001);
    }

    public function test_a_debit_still_subtracts(): void
    {
        $u = $this->walletUser('1000');

        DB::transaction(fn () => Helpers::updateEmoney($u->id, 400.0, 'debit', 'cash_out', 0.0));

        $this->assertSame('600.0000', (string) EMoney::where('user_id', $u->id)->value('current_balance'));
    }

    /**
     * **وكلُّ نادٍ للمسار القديم يلفّه بمعاملة.**
     *
     * وإلّا سقط الاستثناءُ الجديد في وجه المستعمل بدل أن يحمي المال —
     * وهو ما يحوّل إصلاحاً إلى عطل. (القاعدة الرابعة: ميزةٌ لها مدخلان
     * تُختبَر من مدخليها؛ وهنا عشرة.)
     */
    public function test_every_caller_of_the_legacy_path_wraps_it_in_a_transaction(): void
    {
        // **ويُفحص كلُّ موضعٍ لا كلُّ ملفّ.** فحارسٌ يقبل وجود
        // `DB::transaction` في أيّ سطرٍ من ملفٍّ طوله ألفُ سطرٍ يمرّ ولو
        // كان النداءُ في دالّةٍ أخرى بلا معاملة — يُطمئن ولا يحرس.
        $offenders = [];
        $files = [];

        foreach (['app', 'routes'] as $dir) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS));

            foreach ($it as $f) {
                if (str_ends_with((string) $f, '.php')) {
                    $files[] = (string) $f;
                }
            }
        }

        $found = 0;

        foreach ($files as $file) {
            if (str_contains($file, 'CentralLogics/Helpers.php')
                && ! str_contains(file_get_contents($file), 'Helpers::make_transaction')) {
                continue;
            }

            $lines = file($file);

            foreach ($lines as $i => $line) {
                if (! preg_match('/(make_transaction|updateEmoney)\s*\(/', $line)) {
                    continue;
                }

                if (str_contains($line, 'function ')) {
                    continue;   // التعريفُ نفسُه
                }

                // **التمريرُ الداخليّ ليس مدخلاً.** `make_transaction` تنادي
                // `updateEmoney` تمريراً، وهي نفسُها تُنادى من داخل معاملةٍ
                // في مواضعها العشرة. والحمايةُ عند الوجهة لا عند الممرّ —
                // فـ`updateEmoney` ترمي بنفسها إن كانت خارج معاملة.
                if (str_contains($file, 'CentralLogics/Helpers.php')
                    && str_contains($line, 'self::updateEmoney')) {
                    continue;
                }

                $found++;

                // **التعليقاتُ تُطرح قبل الفحص.**
                //
                // وهذا الدرسُ نفسُه وقع ثلاثَ مرّاتٍ في المشروع: أوّلُ نسخةٍ
                // من هذا الحارس مرّت بينما `DB::beginTransaction()` مُعلَّقةٌ
                // بشرطة‌مائلتين — لأنّ النصّ ما زال في الملفّ. وهو مكتوبٌ في
                // `CLAUDE.md`: «حارسٌ مرّ لأنّ الكلمة وردت في تعليق».
                $ctx = '';

                foreach (array_slice($lines, max(0, $i - 60), min(60, $i)) as $c) {
                    $ctx .= preg_replace('#(//|\#).*$#', '', ltrim($c)) . "\n";
                }

                if (! str_contains($ctx, 'DB::transaction')
                    && ! str_contains($ctx, 'beginTransaction')) {
                    $offenders[] = str_replace(base_path() . '/', '', $file) . ':' . ($i + 1);
                }
            }
        }

        $this->assertGreaterThan(5, $found, 'المسحُ لم يجد نداءات — الحارسُ معطَّل');

        $this->assertSame([], $offenders, "\n"
            . 'نداءٌ للمسار الماليّ القديم بلا معاملةٍ في سياقه:' . "\n  "
            . implode("\n  ", $offenders) . "\n\n"
            . 'و`updateEmoney` صارت ترمي RuntimeException خارج المعاملة — '
            . 'فهذا النداءُ سيسقط في وجه المستعمل بدل أن يحمي المال.');
    }
}
