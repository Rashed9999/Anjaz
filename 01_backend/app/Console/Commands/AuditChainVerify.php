<?php

namespace App\Console\Commands;

use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-INSIDER-001 — التحقق الكامل/الجزئي من سلامة سلسلة التدقيق.
 *
 * لا يساوي بين اختلاف البصمة والعبث. إذا أمكن إعادة بناء البصمة القديمة
 * من القيم الحالية نفسها (مثلاً اختلاف ترتيب مفاتيح JSON التاريخي على
 * MySQL 8) يصنفها «مفسرة» ويترك غير المفسر وحده كحالة فشل.
 */
class AuditChainVerify extends Command
{
    protected $signature = 'amial:audit-verify {--last=0 : فحص آخر N سجل فقط (0 = الكل)}';
    protected $description = 'يتحقق من سلامة سلسلة التدقيق ويفصل الاختلافات المفسرة عن غير المفسرة';

    public function handle(): int
    {
        $last = (int) $this->option('last');
        $genesis = hash('sha256', 'AMIAL-AUDIT-CHAIN-GENESIS');

        $query = DB::table('audit_decisions')
            ->whereNotNull('entry_hash')
            ->orderBy('id');

        if ($last > 0) {
            $startId = DB::table('audit_decisions')
                ->whereNotNull('entry_hash')
                ->orderByDesc('id')->limit(1)->offset($last - 1)
                ->value('id');
            if ($startId) {
                $query->where('id', '>=', $startId);
            }
        }

        $checked = 0;
        $broken = [];
        $explained = [];
        $prevHash = null;

        foreach ($query->cursor() as $row) {
            $rowArray = (array) $row;

            // أول سجل في فحص جزئي يبدأ من prev_hash المخزن. في الفحص
            // الكامل يجب أن يبدأ من genesis.
            if ($prevHash === null) {
                $prevHash = $row->prev_hash;
                if ($last === 0 && $row->prev_hash !== $genesis) {
                    $broken[] = ['id' => $row->id, 'why' => 'أول سجل لا يبدأ من بذرة السلسلة'];
                }
            } elseif ($row->prev_hash !== $prevHash) {
                $broken[] = [
                    'id' => $row->id,
                    'why' => 'رابط السلسلة مكسور؛ السبب غير محسوم ويحتاج تحقيقاً',
                ];
            }

            $hashMatches = AuditService::hashMatches(
                (string) $row->prev_hash,
                $rowArray,
                (string) $row->entry_hash,
            );

            if (! $hashMatches) {
                $why = AuditService::explainMismatch(
                    (string) $row->prev_hash,
                    $rowArray,
                    (string) $row->entry_hash,
                );

                if (($why['benign'] ?? false) === true) {
                    $explained[] = [
                        'id' => $row->id,
                        'why' => (string) $why['cause'],
                        'code' => $why['code'] ?? null,
                    ];
                } else {
                    $broken[] = [
                        'id' => $row->id,
                        'why' => $why === null
                            ? 'البصمة لا تطابق محتوى السجل الحالي؛ السبب غير مفسر'
                            : (string) $why['cause'],
                    ];
                }
            }

            $prevHash = $row->entry_hash;
            $checked++;
        }

        $head = DB::table('audit_chain_head')->where('id', 1)->first();
        if ($head && $prevHash !== null && $last === 0 && $head->last_hash !== $prevHash) {
            $broken[] = [
                'id' => 0,
                'why' => 'رأس السلسلة لا يطابق آخر سجل؛ السبب غير محسوم ويحتاج تحقيقاً',
            ];
        }

        if ($explained !== []) {
            $this->warn('ⓘ اختلافات تاريخية مفسرة: ' . count($explained));
            foreach ($explained as $e) {
                $this->line("  - سجل #{$e['id']}: {$e['why']}");
            }
        }

        if ($broken === []) {
            $message = $explained === []
                ? "✓ سلسلة التدقيق سليمة — فُحص {$checked} سجلاً ولم يُعثر على اختلاف سلامة."
                : "✓ لا توجد اختلافات سلامة غير مفسرة — فُحص {$checked} سجلاً، و" . count($explained) . ' اختلافاً تاريخياً له تفسير تقني مثبت.';
            $this->info($message);

            return self::SUCCESS;
        }

        $this->error(
            "✗ سلامة سجل التدقيق تحتاج تحقيقاً — فُحص {$checked} سجلاً، "
            . count($broken) . ' اختلافاً غير مفسر.'
        );
        foreach ($broken as $b) {
            $this->error("  - سجل #{$b['id']}: {$b['why']}");
        }

        $this->warn(
            'مهم: اختلاف البصمة يثبت اختلافاً في السلامة، لكنه لا يثبت وحده عبثاً متعمداً أو أثراً مالياً. '
            . 'لا تُعد كتابة البصمات؛ احتفظ بالدليل وحقق في السبب.'
        );

        return self::FAILURE;
    }
}
