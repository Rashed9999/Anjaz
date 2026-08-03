<?php

namespace App\Services\Whatsapp;

use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentCashTill;
use App\Models\Agent\AgentDailySettlement;
use App\Models\Agent\AgentShift;
use App\Models\Agent\AgentStaff;
use App\Models\WhatsappLinkedDevice;
use Illuminate\Support\Facades\Log;

/**
 * AMIAL-WA-AGENT-002 — التنبيهات الصادرة إلى موظّفي شركات الصرافة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الاستعلام يفترض أنّ أحداً سأل. والتنبيه لا يفترض شيئاً.**
 *
 * ومعظم ما يضرّ في هذا العمل لا يُسأل عنه في وقته: مديرُ فرعٍ لا يفتح
 * البوّابة ليقرأ أنّ خزنته نزلت تحت الحدّ — يكتشفه حين يقف عميلٌ أمام
 * الشبّاك ولا نقد. وصاحبُ الشركة لا يتذكّر نافذة العاشرة كلّ ليلة —
 * يتذكّرها صباحاً حين يجد يومه مقفلاً يحتاج تدخّل أميال.
 *
 * فما يُعرف متأخّراً يُكلِّف، وإن كان معروضاً في شاشةٍ لم تُفتَح.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وثلاث قواعد تحكم كلّ سطرٍ هنا:**
 *
 * ١. **النطاق نفسه نطاقُ الاستعلام.** الصرّاف يُبلَّغ بفرق ورديّته هو ولا
 *    يُبلَّغ برقم خزنة الفرع. ولو سرّبها التنبيه لصار البابُ الذي أُغلق في
 *    `AgentWhatsappService` مفتوحاً من الجهة الأخرى.
 *
 * ٢. **التنبيه لا يُسقط العمليّة.** يُرسَل بعد إتمام المعاملة لا داخلها،
 *    وكلّ استدعاءٍ ملفوفٌ بـ`try`. وانقطاعُ مزوّد واتساب لا يجوز أن يمنع
 *    صرّافاً من إغلاق ورديّته.
 *
 * ٣. **يُسكَت بأمرٍ من صاحبه.** `alerts_enabled=false` يوقف الصادر ويُبقي
 *    الوارد — وإلّا أوقفه صاحبه بحظر الرقم فقطع القناة كلّها.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولا مالَ يتحرّك من هنا.** هذا الصنف يقرأ ليصوغ نصّاً — لا يكتب في
 * دفترٍ ولا محفظةٍ ولا خزنة.
 */
class AgentAlertService
{
    /**
     * ورديّةٌ أُغلقت بفرق.
     *
     * تُنادى **بعد** اكتمال معاملة الإغلاق — لا داخلها.
     */
    public function shiftClosedWithVariance(AgentShift $shift): void
    {
        $variance = (string) $shift->variance;

        // المطابِقة لا تُبلَّغ: تنبيهٌ عن لا شيء يُعلِّم الناس تجاهل التنبيهات.
        if (bccomp($variance, '0', 4) === 0) {
            return;
        }

        $branch = AgentBranch::find($shift->branch_id);
        $teller = AgentStaff::find($shift->staff_id);
        $kind = bccomp($variance, '0', 4) < 0 ? '🔴 عجز' : '🟡 فائض';
        $abs = $this->m(ltrim($variance, '-'));

        // الصرّاف: أرقامه هو، بلا ذكرٍ لخزنة الفرع ولا لزملائه.
        if ($teller) {
            $this->send($teller, "{$kind} في ورديّتك\n\n"
                . "🪟 ورديّة SHIFT-{$shift->id}\n"
                . '🧮 المعدود: ' . $this->m($shift->counted_cash) . " ر.ي\n"
                . '📐 المتوقَّع: ' . $this->m($shift->expectedCash()) . " ر.ي\n"
                . "⚖️ الفرق: *{$abs}* ر.ي\n\n"
                . '📌 كشفك مرفوعٌ إلى إدارة شركتك بانتظار قرارها.');
        }

        // الإدارة: الفرق منسوباً إلى موظّفٍ وفرع — وهو ما تحتاجه للقرار.
        $this->sendToManagers($branch, "{$kind} في ورديّةٍ أُغلقت\n\n"
            . '🏬 ' . ($branch?->name ?? '—') . "\n"
            . '👤 ' . ($teller?->name ?? '—') . " ({$teller?->username})\n"
            . "🪟 SHIFT-{$shift->id}\n"
            . '🧮 المعدود: ' . $this->m($shift->counted_cash) . " ر.ي\n"
            . '📐 المتوقَّع: ' . $this->m($shift->expectedCash()) . " ر.ي\n"
            . "⚖️ الفرق: *{$abs}* ر.ي\n"
            . '📝 ' . ($shift->close_note ?: 'بلا سبب مكتوب') . "\n\n"
            . '📌 القرار من تبويب «الموظّفون» ← كشف الورديّة.');
    }

    /**
     * نقدُ فرعٍ نزل تحت حدّ التنبيه.
     *
     * **ولا تُرسَل مع كلّ عمليّة.** فرعٌ تحت الحدّ يبقى تحته عشرات
     * العمليّات، ورسالةٌ مع كلٍّ منها تجعل المدير يكتم البوت. فتُرسَل مرّةً
     * كلّ ستّ ساعاتٍ للفرع الواحد، وتُعاد إن تعافى ثمّ نزل.
     */
    public function tillLow(AgentBranch $branch): void
    {
        $till = AgentCashTill::where('branch_id', $branch->id)->first();

        if (!$till || !$till->isLow()) {
            // تعافى ⇒ يُنسى الكتم، فينبّه فوراً إن نزل مرّة أخرى.
            cache()->forget($this->lowKey($branch));
            return;
        }

        $key = $this->lowKey($branch);

        if (cache()->has($key)) {
            return;
        }

        cache()->put($key, 1, now()->addHours(6));

        $this->sendToManagers($branch, "⚠️ *نقد الفرع منخفض*\n\n"
            . "🏬 {$branch->name} ({$branch->code})\n"
            . '🏦 في الخزنة: *' . $this->m($till->cash_on_hand) . "* ر.ي\n"
            . '📉 حدّ التنبيه: ' . $this->m($till->min_cash_alert) . " ر.ي\n\n"
            . "السحوبات النقديّة تتوقّف عند نفاد الخزنة — لا الرصيد.\n"
            . '📌 ادعم الفرع من بوّابة الشركة ← تبويب «الفروع».');
    }

    /**
     * نافذة التسوية تُغلق بعد قليل ولم تُرفع تسوية اليوم.
     *
     * يُنادى من `amial:agent-settlement-reminder` — والوكيل الذي رفع لا
     * يُذكَّر: التذكيرُ بما فُعل يُفقد التذكير معناه.
     */
    public function settlementWindowClosing(
        int $agentUserId,
        string $date,
        string $closesAt,
        int $minutesLeft,
    ): void {
        $this->sendToHeadOffice($agentUserId, "🌙 *نافذة التسوية تُغلق قريباً*\n\n"
            . "📅 يوم {$date}\n"
            . "⏰ تُغلق {$closesAt} — بقي نحو {$minutesLeft} دقيقة\n\n"
            . "لم تُرفع تسوية اليوم بعد. وبعد الإغلاق يحتاج الرفع فكّاً من\n"
            . "إدارة أميال، ويُسجَّل يومُك متأخّراً.\n\n"
            . '📌 الرفع من بوّابة الشركة ← تبويب «التسوية اليوميّة».');
    }

    /** قرارُ أميال في تسوية يوم — قبولاً أو رفضاً. */
    public function settlementDecided(AgentDailySettlement $row): void
    {
        $date = $row->settlement_date->toDateString();
        $accepted = $row->status === AgentDailySettlement::STATUS_ACCEPTED;

        if (!$accepted && $row->status !== AgentDailySettlement::STATUS_REJECTED) {
            return;
        }

        $amount = (string) $row->conversion_amount;

        $conv = $row->conversion === 'topup'
            ? '📥 سلّمتَ ورقاً واستلمتَ رصيداً: *' . $this->m($amount) . "* ر.ي\n"
            : ($row->conversion === 'payout'
                ? '📤 أعدتَ رصيداً وتستلم ورقاً: *' . $this->m($amount) . "* ر.ي\n"
                : "اليوم متعادل — لا تحويل\n");

        $body = $accepted
            ? "✅ *قُبلت تسوية يوم {$date}*\n\n" . $conv
                . ($row->linked_settlement_ulid
                    ? '🔗 سند التسوية: ' . $row->linked_settlement_ulid . "\n" : '')
                . "\n📌 التفاصيل في بوّابة الشركة ← «التسويات»."
            : "❌ *رُفضت تسوية يوم {$date}*\n\n"
                . '📝 السبب: ' . ($row->decision_note ?: '—') . "\n\n"
                . '📌 عالِج الملاحظة ثمّ أعد الرفع — راجع إدارة أميال إن أُغلقت النافذة.';

        $this->sendToHeadOffice((int) $row->agent_user_id, $body);
    }

    /**
     * بلاغُ طوارئ من شبّاك — يصل الإدارة فوراً.
     *
     * **وهو التنبيه الوحيد هنا الذي لا يُسكَت.** «إيقاف التنبيهات» يُسكت
     * فروق الورديّات ونقد الخزنة وقرارات التسوية — ولا يُسكت هذا. فمديرٌ
     * أوقف تنبيهاته الشهر الماضي لا يجوز أن يفوته سطوٌ على فرعه اليوم.
     */
    public function panicRaised(
        \App\Models\Agent\AgentPanicAlert $alert,
        AgentStaff $staff,
        ?AgentBranch $branch,
    ): void {
        $body = "🚨🚨 *بلاغ طوارئ من الشبّاك* 🚨🚨\n\n"
            . (\App\Models\Agent\AgentPanicAlert::KIND_LABELS[$alert->kind] ?? $alert->kind) . "\n\n"
            . '🏬 ' . ($branch?->name ?? '—') . "\n"
            . "👤 {$staff->name} ({$staff->username})\n"
            . '🕐 ' . $alert->created_at?->format('Y-m-d H:i:s') . "\n"
            . '🔖 ' . $alert->alert_number . "\n"
            . ($alert->note ? '📝 ' . $alert->note . "\n" : '')
            . "\n📍 " . ($alert->geo_state === 'ok'
                ? "الموقع: https://maps.google.com/?q={$alert->lat},{$alert->lng}"
                : (\App\Models\Agent\AgentPanicAlert::GEO_LABELS[$alert->geo_state] ?? 'الموقع غير متاح'))
            . "\n\n📌 اتّصل بالفرع الآن.";

        // **يُتجاوز `alerts_enabled` هنا وحده** — انظر التوثيق أعلاه.
        foreach ($this->panicRecipients($staff) as $target) {
            $this->send($target, $body, respectMute: false);
        }
    }

    /**
     * من يُبلَّغ ببلاغ الطوارئ: إدارةُ الشركة، ومديرو الفرع نفسه.
     *
     * ولا يُبلَّغ زملاؤه الصرّافون: أحدُهم قد يكون الطرف الآخر في البلاغ.
     *
     * @return iterable<AgentStaff>
     */
    private function panicRecipients(AgentStaff $staff): iterable
    {
        return AgentStaff::where('agent_user_id', $staff->agent_user_id)
            ->where('is_active', true)
            ->where('id', '!=', $staff->id)
            ->where(fn ($q) => $q
                ->where('role', AgentStaff::ROLE_HEAD_OFFICE)
                ->orWhere(fn ($w) => $w
                    ->where('role', AgentStaff::ROLE_BRANCH_MANAGER)
                    ->where('branch_id', $staff->branch_id)))
            ->get();
    }

    // ══════════════════════════════════════════════════════════════════
    // التوصيل
    // ══════════════════════════════════════════════════════════════════

    /** إدارةُ الفرع: مديروه، ومعهم الإدارة العامّة للشركة. */
    private function sendToManagers(?AgentBranch $branch, string $body): void
    {
        if (!$branch) {
            return;
        }

        $staff = AgentStaff::where('agent_user_id', $branch->agent_user_id)
            ->where('is_active', true)
            ->where(fn ($q) => $q
                ->where('role', AgentStaff::ROLE_HEAD_OFFICE)
                ->orWhere(fn ($w) => $w
                    ->where('role', AgentStaff::ROLE_BRANCH_MANAGER)
                    ->where('branch_id', $branch->id)))
            ->get();

        foreach ($staff as $s) {
            $this->send($s, $body);
        }
    }

    private function sendToHeadOffice(int $agentUserId, string $body): void
    {
        $staff = AgentStaff::where('agent_user_id', $agentUserId)
            ->where('role', AgentStaff::ROLE_HEAD_OFFICE)
            ->where('is_active', true)->get();

        foreach ($staff as $s) {
            $this->send($s, $body);
        }
    }

    /**
     * إرسالةٌ واحدة — وفشلُها لا يخرج من هنا.
     *
     * **وهذا `catch` مقصود، لا كسل.** المنادي في منتصف إغلاق ورديّةٍ أو
     * قبول تسوية؛ واستثناءٌ من مزوّد واتساب يعني أنّ انقطاع شبكةٍ عندهم
     * يمنع صرّافاً في المكلا من إقفال درجه.
     */
    private function send(AgentStaff $staff, string $body, bool $respectMute = true): void
    {
        try {
            $link = WhatsappLinkedDevice::where('agent_staff_id', $staff->id)
                ->where('status', WhatsappLinkedDevice::STATUS_ACTIVE)
                ->first();

            if (!$link) {
                return;
            }

            // الإسكات يُحترم في كلّ شيء **إلّا الطوارئ**: مديرٌ أوقف
            // تنبيهاته الشهر الماضي لا يفوته سطوٌ على فرعه اليوم.
            if ($respectMute && !$link->alerts_enabled) {
                return;
            }

            \App\CentralLogics\WhatsappModule::sendText(
                (string) $link->whatsapp_number,
                "🏦 *أميال باي*\n\n" . $body,
            );
        } catch (\Throwable $e) {
            Log::warning('AgentAlertService: تعذّر إرسال تنبيه واتساب', [
                'staff_id' => $staff->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function lowKey(AgentBranch $branch): string
    {
        return 'wa_agent_low_cash:' . $branch->id;
    }

    private function m(string|float|null $n): string
    {
        return number_format((float) ($n ?? 0), 0);
    }
}
