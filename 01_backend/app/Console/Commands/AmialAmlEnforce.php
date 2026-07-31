<?php

namespace App\Console\Commands;

use App\Models\Aml\AmlRule;
use App\Services\AuditService;
use Illuminate\Console\Command;

/**
 * AMIAL-AML-ENFORCE-001 — نقل قاعدة من المراقبة إلى المنع، بقرارٍ موثَّق.
 *
 * **لماذا أمرٌ لا تبديلٌ في الشاشة؟**
 * إخراجُ قاعدةٍ من وضع الظلّ يعني أنها ستبدأ **بإيقاف معاملات حقيقية**.
 * وهذا قرارٌ يُتّخذ بعد قراءة `aml_shadow_decisions`: كم مرّة كانت ستُطلق؟
 * وعلى من؟ وهل كانت ستمنع بريئاً؟ فالأمر يعرض ذلك قبل السؤال، ويرفض التنفيذ
 * بلا سبب، ويكتب القرار في سجلّ التدقيق.
 *
 * وتبديلٌ في شاشةٍ يُضغط في ثانية ولا يُسأل عنه بعد شهر.
 *
 * **الاتّجاه الآخر متاح عمداً** (`--shadow`): قاعدةٌ تُنفَّذ وتُخطئ يجب أن
 * تُعاد إلى المراقبة فوراً — والطريق إلى الوراء أهمّ من الطريق إلى الأمام
 * حين يكون الخطأ إيقافَ مالِ بريء.
 */
class AmialAmlEnforce extends Command
{
    protected $signature = 'amial:aml-enforce
                            {codes?* : أكواد القواعد (فارغ = اعرض الحالة فقط)}
                            {--shadow : أعِدها إلى المراقبة بدل الإنفاذ}
                            {--reason= : سبب القرار (إلزامي عند التغيير)}
                            {--dry-run : اعرض ما سيحدث بلا كتابة}';

    protected $description = 'ينقل قواعد AML بين المراقبة (shadow) والإنفاذ الفعلي';

    public function handle(AuditService $audit): int
    {
        $codes = (array) $this->argument('codes');

        if ($codes === []) {
            return $this->showStatus();
        }

        $toShadow = (bool) $this->option('shadow');
        $reason = trim((string) $this->option('reason'));
        $dry = (bool) $this->option('dry-run');

        if ($reason === '' && !$dry) {
            $this->error('السبب إلزامي: --reason="..."');
            $this->line('قرارٌ يوقف معاملات الناس لا يُتّخذ بلا سببٍ مكتوب.');

            return self::FAILURE;
        }

        $changed = 0;

        foreach ($codes as $code) {
            $rule = AmlRule::where('code', $code)->first();

            if (!$rule) {
                $this->error("  ✗ لا توجد قاعدة بالكود {$code}");
                continue;
            }

            $was = (bool) $rule->shadow_mode;
            if ($was === $toShadow) {
                $this->line("  — {$code}: بالفعل في " . ($toShadow ? 'المراقبة' : 'الإنفاذ'));
                continue;
            }

            // ما كانت ستفعله لو نُفِّذت — الرقم الذي يُبنى عليه القرار.
            $wouldHave = $this->shadowHits($code);
            $this->line("  {$code}: " . ($toShadow ? 'إنفاذ ← مراقبة' : 'مراقبة ← إنفاذ')
                . " (أطلقت {$wouldHave} مرّة في سجلّ الظلّ)");

            if ($dry) {
                $changed++;
                continue;
            }

            $rule->shadow_mode = $toShadow;
            $rule->save();

            $audit->record([
                'actor_type' => 'system',
                'actor_user_id' => null,
                'subject_type' => 'aml_rule',
                'subject_id' => (string) $rule->id,
                'action' => $toShadow ? 'AML_RULE_TO_SHADOW' : 'AML_RULE_ENFORCED',
                'decision_code' => 'AML_MODE_CHANGE',
                'reason' => mb_substr($reason, 0, 500),
                // نقلُ قاعدةٍ إلى الإنفاذ يبدأ بإيقاف مالٍ حقيقيّ — حرجٌ لا معلومة.
                'severity' => 'critical',
                'context' => [
                    'code' => $code,
                    'from' => $was ? 'shadow' : 'enforce',
                    'to' => $toShadow ? 'shadow' : 'enforce',
                    'shadow_hits' => $wouldHave,
                ],
            ]);

            $changed++;
        }

        $this->newLine();
        $this->info($dry
            ? "تجربة جافّة: {$changed} قاعدة ستتغيّر."
            : "تمّ: {$changed} قاعدة.");

        return self::SUCCESS;
    }

    private function showStatus(): int
    {
        $rules = AmlRule::orderBy('priority')->get();

        if ($rules->isEmpty()) {
            $this->warn('لا قواعد مبذورة — شغّل AmlDefaultRulesSeeder أولاً.');

            return self::SUCCESS;
        }

        $this->table(
            ['الكود', 'الإجراء', 'الوضع', 'أطلقت في الظلّ'],
            $rules->map(fn ($r) => [
                $r->code,
                $r->action_on_match,
                $r->shadow_mode ? '👁 مراقبة' : '🛑 إنفاذ',
                $r->shadow_mode ? $this->shadowHits($r->code) : '—',
            ])->all(),
        );

        $enforcing = $rules->where('shadow_mode', false)->count();
        $this->newLine();
        $this->line("منفَّذة: {$enforcing} من {$rules->count()}");

        if ($enforcing === 0) {
            $this->warn('لا قاعدة واحدة تمنع شيئاً — المحرّك يراقب ولا يحمي.');
        }

        return self::SUCCESS;
    }

    /** كم مرّة كانت هذه القاعدة ستُطلق لو نُفِّذت. */
    private function shadowHits(string $code): int
    {
        try {
            return (int) \Illuminate\Support\Facades\DB::table('aml_rule_evaluations')
                ->where('rule_code', $code)
                ->where('matched', true)
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
