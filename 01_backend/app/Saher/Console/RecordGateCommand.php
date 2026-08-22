<?php

namespace App\Saher\Console;

use App\Saher\Support\GitTree;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SAHER-GATE-003 — `php artisan saher:record-gate`
 *
 * ══════════════════════════════════════════════════════════════════════
 * تُنادى من `scripts/verify.sh` في خاتمته، **ولا تُسقطه أبداً**.
 *
 * §2.1: «إذا توقف ساهر يجب أن يستمر أميال». والبوّابةُ من أميال لا من
 * ساهر — فأمرُ تسجيلٍ يسقط لأنّ القاعدةَ نائمةٌ يجب ألّا يقلب بوّابةً
 * خضراءَ إلى حمراء. **وراصدٌ يُسقط ما يرصده أسوأ من غيابه.**
 *
 * فيخرج بالنجاح دائماً، ويقول ما وقع في سطر.
 */
class RecordGateCommand extends Command
{
    protected $signature = 'saher:record-gate
                            {--verdict= : PASS أو FAIL}
                            {--passed=0 : عدد الفحوص الناجحة}
                            {--failed=0 : عدد الفحوص الساقطة}
                            {--tests= : عدد الاختبارات الناجحة — يُترك فارغاً إن لم تُشغَّل}
                            {--fast : جولةٌ سريعةٌ تخطّت الاختبارات والضغط}
                            {--duration= : بالمِلّي ثانية}';

    protected $description = 'ساهر — تسجيل إيصال بوّابة الفحص لشجرة المحتوى المفحوصة';

    public function handle(): int
    {
        $verdict = strtoupper((string) $this->option('verdict'));

        if (! in_array($verdict, ['PASS', 'FAIL'], true)) {
            $this->warn('ساهر: حكمٌ مجهول — لم يُسجَّل إيصال.');

            return self::SUCCESS;
        }

        $tree = GitTree::ofWorkingTree();

        if ($tree === null) {
            // **ولا يُخترَع مفتاح.** إيصالٌ بشجرةٍ مجهولةٍ يشهد للا شيء،
            // وأسوأُ من ذلك أنّه يُحسَب شهادةً فيُخفي التزاماً غيرَ مفحوص.
            $this->warn('ساهر: تعذّر حساب شجرة المحتوى — لم يُسجَّل إيصال.');

            return self::SUCCESS;
        }

        try {
            DB::table('saher_gate_receipts')->insert([
                'tree_sha' => $tree,
                'head_sha' => GitTree::ofCommit('HEAD'),
                'branch' => GitTree::currentBranch(),
                'verdict' => $verdict,
                'checks_passed' => (int) $this->option('passed'),
                'checks_failed' => (int) $this->option('failed'),
                'tests_passed' => $this->option('tests') === null || $this->option('tests') === ''
                    ? null : (int) $this->option('tests'),
                'is_full' => ! $this->option('fast'),
                'duration_ms' => $this->option('duration') !== null && $this->option('duration') !== ''
                    ? (int) $this->option('duration') : null,
                'runner' => trim((string) (gethostname() ?: '')) . ':' . get_current_user(),
                'ran_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // **البوّابةُ لا تسقط بسبب الراصد.**
            $this->warn('ساهر: تعذّر تسجيل الإيصال — ' . $e->getMessage());

            return self::SUCCESS;
        }

        $this->line(sprintf('  ساهر: سُجّل إيصالُ البوّابة (%s) للشجرة %s',
            $verdict, substr($tree, 0, 12)));

        return self::SUCCESS;
    }
}
