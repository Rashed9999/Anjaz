<?php

namespace App\Console\Commands;

use App\Services\AgentDailySettlementService;
use App\Services\Whatsapp\AgentAlertService;
use Illuminate\Console\Command;

/**
 * AMIAL-WA-AGENT-002 — تذكيرٌ قبل إغلاق نافذة التسوية.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **النافذة تُغلق في منتصف الليل، ومن نسي يدفع ثمن النسيان إدارياً.**
 *
 * بعد الإغلاق لا يُرفع اليوم إلّا بفكٍّ من إدارة أميال، ويُسجَّل متأخّراً في
 * سجلٍّ لا يُمحى. فتذكيرٌ واحدٌ قبل الإغلاق يوفّر مكالمةً صباحيّة وتدخّلاً
 * إدارياً — لكلٍّ من الطرفين.
 *
 * ويُذكَّر **من لم يرفع وحده**: التذكير بما فُعل يُعلّم الناس تجاهل
 * التذكيرات، فيُتجاهل الذي يهمّ حين يأتي.
 */
class AmialAgentSettlementReminder extends Command
{
    protected $signature = 'amial:agent-settlement-reminder
                            {--date= : يوم التسوية (افتراضاً اليوم)}';

    protected $description = 'تذكير وكلاء لم يرفعوا تسوية اليوم قبل إغلاق النافذة';

    public function handle(AgentDailySettlementService $svc, AgentAlertService $alerts): int
    {
        $date = (string) ($this->option('date') ?: now()->toDateString());
        $window = $svc->windowState($date);

        // خارج النافذة لا معنى للتذكير: قبلها سابقٌ لأوانه، وبعدها متأخّر.
        if (!$window['open']) {
            $this->line("النافذة ليست مفتوحة — {$window['message']}");

            return self::SUCCESS;
        }

        $closesAt = \Illuminate\Support\Carbon::parse($window['closes_at']);
        $left = (int) max(0, round(now()->diffInMinutes($closesAt, false)));

        $net = $svc->networkDay($date);
        $sent = 0;

        foreach ($net['rows'] ?? [] as $row) {
            if (($row['status'] ?? '') !== 'not_submitted') {
                continue;
            }

            $alerts->settlementWindowClosing(
                (int) $row['agent_user_id'],
                $date,
                $closesAt->format('H:i'),
                $left,
            );
            $sent++;
        }

        $this->info("ذُكّر {$sent} وكيلاً لم يرفع تسوية {$date} — تُغلق النافذة بعد {$left} دقيقة.");

        return self::SUCCESS;
    }
}
