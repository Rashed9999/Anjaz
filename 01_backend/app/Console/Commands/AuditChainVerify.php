<?php

namespace App\Console\Commands;

use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-INSIDER-001 — التحقق من سلامة سلسلة سجل التدقيق.
 *
 * يعيد حساب بصمة كل سجل ويقارنها بالمخزّنة وبربطها بسابقتها.
 * أي تعديل/حذف/إدراج يدوي في audit_decisions يكسر السلسلة هنا.
 *
 *   php artisan amial:audit-verify            # كامل السلسلة
 *   php artisan amial:audit-verify --last=1000 # آخر 1000 سجل فقط (فحص سريع)
 */
class AuditChainVerify extends Command
{
    protected $signature = 'amial:audit-verify {--last=0 : فحص آخر N سجل فقط (0 = الكل)}';
    protected $description = 'يتحقق من سلامة سلسلة تجزئة سجل التدقيق (كشف العبث)';

    public function handle(): int
    {
        $last = (int) $this->option('last');

        // نقطة البداية
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
        $prevHash = null;

        foreach ($query->cursor() as $row) {
            // أول سجل في نطاق الفحص: نعتمد prev_hash المخزّن كبداية،
            // إلا في الفحص الكامل حيث يجب أن يساوي بذرة السلسلة.
            if ($prevHash === null) {
                $prevHash = $row->prev_hash;
                if ($last === 0 && $row->prev_hash !== $genesis) {
                    $broken[] = ['id' => $row->id, 'why' => 'أول سجل لا يبدأ من بذرة السلسلة'];
                }
            } elseif ($row->prev_hash !== $prevHash) {
                $broken[] = ['id' => $row->id, 'why' => 'رابط السلسلة مكسور (حذف/إدراج سجل قبله؟)'];
            }

            $expected = AuditService::computeEntryHash($row->prev_hash, (array) $row);
            if ($expected !== $row->entry_hash) {
                $broken[] = ['id' => $row->id, 'why' => 'محتوى السجل عُدِّل بعد كتابته'];
            }

            $prevHash = $row->entry_hash;
            $checked++;
        }

        // رأس السلسلة يطابق آخر سجل
        $head = DB::table('audit_chain_head')->where('id', 1)->first();
        if ($head && $prevHash !== null && $last === 0 && $head->last_hash !== $prevHash) {
            $broken[] = ['id' => 0, 'why' => 'رأس السلسلة لا يطابق آخر سجل (سجلات محذوفة من النهاية؟)'];
        }

        if (empty($broken)) {
            $this->info("✓ السلسلة سليمة — فُحص {$checked} سجلاً، لا عبث.");
            return self::SUCCESS;
        }

        $this->error("✗ عُثر على عبث في السلسلة! فُحص {$checked} سجلاً:");
        foreach ($broken as $b) {
            $this->error("  - سجل #{$b['id']}: {$b['why']}");
        }
        return self::FAILURE;
    }
}
