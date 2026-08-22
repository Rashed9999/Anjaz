<?php

namespace App\Console\Commands;

use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-INSIDER-001 — التحقق من سلامة سلسلة سجل التدقيق.
 *
 * يعيد حساب بصمة كل سجل ويقارنها بالمخزّنة وبربطها بسابقتها.
 * أي اختلاف غير مفسّر في المحتوى أو الربط يُبلّغ كخلل سلامة يحتاج تحقيقاً؛
 * ولا يُسمّى «عبثاً» لمجرد أن البصمة لا تطابق، لأن سبب الاختلاف يحتاج دليلاً مستقلاً.
 *
 *   php artisan amial:audit-verify             # كامل السلسلة
 *   php artisan amial:audit-verify --last=1000 # آخر 1000 سجل فقط (فحص سريع)
 */
class AuditChainVerify extends Command
{
    protected $signature = 'amial:audit-verify {--last=0 : فحص آخر N سجل فقط (0 = الكل)}';
    protected $description = 'يتحقق من سلامة سلسلة تجزئة سجل التدقيق ويبلغ الاختلافات غير المفسرة';

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
                $broken[] = ['id' => $row->id, 'why' => 'رابط السلسلة مكسور؛ السبب غير محسوم ويحتاج تحقيقاً'];
            }

            // AMIAL-AUDIT-JSON-001 — يُقبل الشكلان: القانونيُّ للسجلّات
            // الجديدة، والخامُّ لما كُتب قبل الإصلاح. أي اختلاف يكشفه الفحص،
            // لكن الفحص وحده لا يثبت إن كان السبب هجرةً أو خطأً أو تعديلاً متعمداً.
            if (! AuditService::hashMatches(
                (string) $row->prev_hash, (array) $row, (string) $row->entry_hash)) {
                $broken[] = ['id' => $row->id, 'why' => 'البصمة لا تطابق محتوى السجل الحالي؛ السبب غير مفسّر'];
            }

            $prevHash = $row->entry_hash;
            $checked++;
        }

        // رأس السلسلة يطابق آخر سجل
        $head = DB::table('audit_chain_head')->where('id', 1)->first();
        if ($head && $prevHash !== null && $last === 0 && $head->last_hash !== $prevHash) {
            $broken[] = ['id' => 0, 'why' => 'رأس السلسلة لا يطابق آخر سجل؛ السبب غير محسوم ويحتاج تحقيقاً'];
        }

        if (empty($broken)) {
            $this->info("✓ سلسلة التدقيق سليمة — فُحص {$checked} سجلاً ولم يُعثر على اختلاف سلامة.");
            return self::SUCCESS;
        }

        $this->error("✗ سلامة سجل التدقيق تحتاج تحقيقاً — فُحص {$checked} سجلاً وعُثر على اختلافات غير مفسّرة:");
        foreach ($broken as $b) {
            $this->error("  - سجل #{$b['id']}: {$b['why']}");
        }
        $this->warn('مهم: اختلاف البصمة يثبت وجود اختلاف في السلامة، لكنه لا يثبت وحده عبثاً متعمداً أو أثراً مالياً. لا تُعد كتابة البصمات؛ احتفظ بالدليل وحقق في السبب.');

        return self::FAILURE;
    }
}
