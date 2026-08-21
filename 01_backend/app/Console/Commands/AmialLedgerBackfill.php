<?php

namespace App\Console\Commands;

use App\Models\EMoney;
use App\Models\ReconciliationCase;
use App\Services\LedgerService;
use Illuminate\Console\Command;

/**
 * AMIAL-LEDGER-OPENING-002 — إدخال المحافظ القائمة في الدفتر.
 *
 * `EMoneyObserver` يعالج المحافظ التي **تولد** بعد اليوم. أمّا محافظ الإنتاج
 * القائمة فقد وُلدت قبله، وحساباتها في الدفتر تبدأ بصفر بينما أرصدتها
 * الفعلية أكبر. وأوّل خصمٍ على أيٍّ منها يُرفض بـ«الرصيد لا يكفي».
 *
 * فهذا الأمر يُشغَّل **مرّة واحدة عند النشر**، ويجب أن يسبق تفعيل الترحيل
 * الإلزامي. تشغيله بعده يعني رفض كل عملية على كل محفظة قديمة.
 *
 * وهو آمن للتكرار: كل محفظة لها مفتاح تفرّد واحد، فإعادة التشغيل لا تضاعف
 * شيئاً — وهذا مقصود، لأن أوامر النشر تُعاد عند الفشل الجزئيّ.
 *
 * ولا يُصحّح هذا الأمر ماضياً: إن كان رصيدٌ خاطئاً أصلاً، ورث الدفتر الخطأ.
 * وظيفته أن يجعل كل ما **بعد** هذه اللحظة موثَّقاً، لا أن يخترع تاريخاً.
 */
class AmialLedgerBackfill extends Command
{
    protected $signature = 'amial:ledger-backfill
                            {--dry-run : اعرض ما سيحدث بلا كتابة}
                            {--execute-approved-cases : نفّذ فقط قضايا المحافظ المعتمدة صراحةً}
                            {--chunk=200 : عدد المحافظ في الدفعة}';

    protected $description = 'يسجّل رصيداً افتتاحياً في الدفتر لكل محفظة قائمة (يُشغَّل مرّة عند النشر)';

    public function handle(LedgerService $ledger): int
    {
        $dry = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));
        if (!$dry && !$this->option('execute-approved-cases')) {
            $this->error('تم إيقاف الترحيل: يلزم --execute-approved-cases، وقضية pending_approval مستقلة لكل محفظة.');
            return self::FAILURE;
        }

        $this->info($dry
            ? '— تجربة جافّة: لن يُكتب شيء —'
            : '— تنفيذ فعليّ —');

        $opened = 0;
        $already = 0;
        $empty = 0;
        $failed = 0;
        $totalOpened = '0';

        EMoney::orderBy('user_id')->chunkById($chunk, function ($wallets) use (
            $ledger, $dry, &$opened, &$already, &$empty, &$failed, &$totalOpened
        ) {
            foreach ($wallets as $wallet) {
                $userId = (int) $wallet->user_id;
                $balance = (string) ($wallet->current_balance ?? '0');

                if (bccomp($balance, '0', 4) <= 0) {
                    $empty++;
                    continue;
                }

                $account = $ledger->getOrCreateUserWallet(
                    $userId, (string) ($wallet->zone_code ?? 'SOUTH')
                );

                // ما دخل الدفتر فعلاً — لا يُعاد. والمقارنة بالقيود لا
                // بالعمود المخزَّن: العمود قد ينحرف هو الآخر.
                $inLedger = $ledger->computeBalanceFromLines($account->id);
                if (bccomp($inLedger, '0', 4) !== 0) {
                    $already++;
                    continue;
                }

                if ($dry) {
                    $this->line("  سيُفتح: مستخدم {$userId} برصيد {$balance}");
                    $opened++;
                    $totalOpened = bcadd($totalOpened, $balance, 4);
                    continue;
                }

                try {
                    $case = ReconciliationCase::query()
                        ->where('case_type', 'wallet')
                        ->where('subject_user_id', $userId)
                        ->where('status', 'pending_approval')
                        ->whereNotNull('maker_admin_id')
                        ->whereNotNull('checker_admin_id')
                        ->orderByDesc('last_detected_at')
                        ->first();

                    if (!$case || trim((string) $case->action_taken) === '') {
                        throw new \RuntimeException('لا توجد قضية محفظة معتمدة بملاحظة مراجع مستقلة.');
                    }

                    $ledger->reconcileWalletBalance($userId, 'ترحيل افتتاحي معتمد', [
                        'case_ulid' => $case->case_ulid,
                        'maker_admin_id' => (int) $case->maker_admin_id,
                        'checker_admin_id' => (int) $case->checker_admin_id,
                        'approval_note' => (string) $case->action_taken,
                    ]);
                    $opened++;
                    $totalOpened = bcadd($totalOpened, $balance, 4);
                } catch (\Throwable $e) {
                    // لا يُوقَف الترحيل كلّه لأجل محفظة واحدة: الأهمّ أن تُدخَل
                    // البقيّة، وتُسمّى الفاشلة بالاسم كي تُعالج يدوياً.
                    $failed++;
                    $this->error("  فشل مستخدم {$userId}: {$e->getMessage()}");
                }
            }
        });

        $this->newLine();
        $this->table(['البند', 'العدد'], [
            ['محافظ أُدخلت', $opened],
            ['محافظ داخل الدفتر أصلاً', $already],
            ['محافظ فارغة (لا قيد لها)', $empty],
            ['فشل', $failed],
            ['إجمالي المبالغ المفتتحة', $totalOpened],
        ]);

        if ($failed > 0) {
            $this->error('انتهى وفيه فشل — راجع الأسطر أعلاه قبل تفعيل الترحيل الإلزامي.');
            return self::FAILURE;
        }

        $this->info($dry
            ? 'التجربة الجافّة انتهت. أعِد التشغيل بلا --dry-run للتنفيذ.'
            : 'اكتمل. الدفتر الآن يعرف أرصدة المحافظ القائمة.');

        return self::SUCCESS;
    }
}
