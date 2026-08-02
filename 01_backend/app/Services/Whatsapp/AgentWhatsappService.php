<?php

namespace App\Services\Whatsapp;

use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentCashTill;
use App\Models\Agent\AgentDailySettlement;
use App\Models\Agent\AgentShift;
use App\Models\Agent\AgentStaff;
use App\Models\EMoney;
use App\Models\User;
use App\Models\WhatsappLinkedDevice;
use App\Services\AgentDailySettlementService;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-WA-AGENT-001 — واتساب شركات الصرافة: الوكيل وفروعه وموظّفوه.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **قاعدةٌ واحدة تحكم هذا الملفّ كلّه: لا يتحرّك مالٌ من واتساب.**
 *
 * الشبّاك على كمبيوترٍ في الفرع، بجلسةٍ ودورٍ وورديّةٍ مفتوحة ودرجٍ يُجرَد
 * آخر اليوم. وواتساب قناةٌ لا تملك من ذلك شيئاً: لا تُثبت أنّ من يكتب هو
 * صاحب الرقم، ولا تعرف أيّ ورديّةٍ مفتوحة، ولا يُجرَد فيها درج.
 *
 * فرسالةٌ واحدة تُنفّذ إيداعاً تعني أنّ **من سرق هاتف صرّافٍ يستطيع أن
 * يودع باسمه**. ولذلك كلّ ما هنا **قراءةٌ فقط** — سؤالٌ وجواب. وما يحرّك
 * المال يبقى حيث يُسأل عنه صاحبُه.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والجواب يُقصّ على قدر الدور:**
 *
 *   • الصرّاف        ← درجُه هو وورديّتُه هو، ولا شيء عن زملائه
 *   • مدير الفرع     ← فرعُه: خزنتُه وشبابيكُه وفروقُ اليوم
 *   • الإدارة العامّة ← الشركة كلّها وتسويةُ اليوم مع أميال
 *
 * ولو رأى الصرّافُ أرقام الفرع لعرف كم في الخزنة قبل أن يقرّر شيئاً —
 * وذلك أوّل ما يُسأل عنه في أيّ تحقيق داخليّ.
 */
class AgentWhatsappService
{
    /**
     * الموظّف المرتبط برقم واتساب — أو `null` إن لم يكن موظّفاً.
     */
    public function resolveStaff(string $waNumber): ?AgentStaff
    {
        $link = WhatsappLinkedDevice::where('whatsapp_number', $waNumber)
            ->where('status', WhatsappLinkedDevice::STATUS_ACTIVE)
            ->whereNotNull('agent_staff_id')
            ->first();

        if (!$link) {
            return null;
        }

        $link->forceFill(['last_activity_at' => now()])->save();

        $staff = AgentStaff::find($link->agent_staff_id);

        // موظّفٌ عُطّل لا يُجاب — تعطيلُه في اللوحة يجب أن يُغلق كلّ أبوابه.
        return ($staff && $staff->is_active) ? $staff : null;
    }

    /**
     * ربطُ رقم واتساب بموظّف — تبدأه إدارةُ الشركة ويُتمّه صاحب الرقم.
     *
     * **ولا يكتمل بقرار المدير وحده.** لو رُبط الرقم فوراً لصار خطأٌ في
     * رقمٍ واحد يُسلّم أرقام فرعٍ إلى غريب. فيُرسَل رمزٌ إلى الرقم نفسه،
     * ولا يُفعَّل الربط حتى يُعيده صاحبه إلى البوت — فيثبت أنّ الهاتف بيده.
     *
     * @return array{ok: bool, message: string}
     */
    public function startLink(AgentStaff $actor, AgentStaff $target, string $waNumber): array
    {
        if (!$actor->isHeadOffice() && !$actor->isBranchManager()) {
            return ['ok' => false, 'message' => 'ربطُ واتساب الموظّفين من صلاحية الإدارة'];
        }

        if ((int) $actor->agent_user_id !== (int) $target->agent_user_id) {
            return ['ok' => false, 'message' => 'الموظّف خارج شركتك'];
        }

        $number = preg_replace('/\D+/', '', $waNumber);

        if (mb_strlen($number) < 9) {
            return ['ok' => false, 'message' => 'رقم واتساب غير صالح'];
        }

        // **رقمٌ واحدٌ لشخصٍ واحد.** ولو ارتبط رقمٌ بعميلٍ وموظّفٍ معاً لصار
        // «ما رصيدي؟» سؤالاً غامضاً — أمحفظتُه أم درجُه؟
        $taken = WhatsappLinkedDevice::where('whatsapp_number', $number)
            ->where('status', '!=', WhatsappLinkedDevice::STATUS_REVOKED)
            ->where(fn ($q) => $q->whereNull('agent_staff_id')
                ->orWhere('agent_staff_id', '!=', $target->id))
            ->exists();

        if ($taken) {
            return ['ok' => false, 'message' => 'هذا الرقم مرتبطٌ بحسابٍ آخر — اطلب فكّه أوّلاً'];
        }

        $otp = (string) random_int(100000, 999999);

        WhatsappLinkedDevice::updateOrCreate(
            ['whatsapp_number' => $number],
            [
                'agent_staff_id' => $target->id,
                'user_id' => null,
                'status' => WhatsappLinkedDevice::STATUS_PENDING,
                'risk_score' => 0,
                'otp_verified_at' => null,
                'revoked_at' => null, 'revoked_by' => null, 'revoke_reason' => null,
            ],
        );

        cache()->put($this->otpKey($number), $otp, now()->addMinutes(10));

        \App\CentralLogics\WhatsappModule::sendText(
            $number,
            "رمز ربط واتساب بحسابك في أميال باي: {$otp}\n"
            . "أعِد إرسال الرمز في هذه المحادثة خلال ١٠ دقائق.\n"
            . 'إن لم تطلبه فتجاهل الرسالة وأبلغ إدارة شركتك.',
        );

        return ['ok' => true, 'message' => "أُرسل رمزُ التفعيل إلى {$number} — يُعيده الموظّف في محادثة البوت"];
    }

    /**
     * محاولةُ إتمام ربطٍ معلَّق برمزٍ وصل من صاحب الرقم.
     *
     * تُنادى **قبل** `resolveStaff` — فالرقم لم يصر مرتبطاً بعد.
     */
    public function tryVerifyPending(string $waNumber, string $body): ?string
    {
        $link = WhatsappLinkedDevice::where('whatsapp_number', $waNumber)
            ->where('status', WhatsappLinkedDevice::STATUS_PENDING)
            ->whereNotNull('agent_staff_id')
            ->first();

        if (!$link) {
            return null;
        }

        $entered = preg_replace('/\D+/', '', $body);
        $expected = cache()->get($this->otpKey($waNumber));

        if ($expected === null) {
            return 'انتهت صلاحية رمز الربط — اطلب من إدارة شركتك إعادة الإرسال.';
        }

        if ($entered === '' || !hash_equals((string) $expected, $entered)) {
            return 'رمز غير صحيح. أعد المحاولة أو اطلب رمزاً جديداً من إدارة شركتك.';
        }

        cache()->forget($this->otpKey($waNumber));

        $link->forceFill([
            'status' => WhatsappLinkedDevice::STATUS_ACTIVE,
            'otp_verified_at' => now(),
            'last_activity_at' => now(),
        ])->save();

        $staff = AgentStaff::find($link->agent_staff_id);

        return "✅ تمّ ربط رقمك.\n\n" . ($staff ? $this->menu($staff) : '');
    }

    /** فكُّ الربط — بيد الإدارة، وبيد الموظّف نفسه. */
    public function revoke(AgentStaff $target, string $reason = 'agent_admin'): bool
    {
        return WhatsappLinkedDevice::where('agent_staff_id', $target->id)
            ->where('status', '!=', WhatsappLinkedDevice::STATUS_REVOKED)
            ->update([
                'status' => WhatsappLinkedDevice::STATUS_REVOKED,
                'revoked_at' => now(),
                'revoke_reason' => $reason,
            ]) > 0;
    }

    private function otpKey(string $number): string
    {
        return 'wa_agent_link_otp:' . $number;
    }

    /** إسكاتُ التنبيهات الصادرة أو إعادتها — والوارد يبقى في الحالين. */
    private function setAlerts(AgentStaff $staff, bool $on): string
    {
        WhatsappLinkedDevice::where('agent_staff_id', $staff->id)
            ->where('status', WhatsappLinkedDevice::STATUS_ACTIVE)
            ->update(['alerts_enabled' => $on]);

        return $on
            ? "🔔 عادت التنبيهات.\n\nستصلك تنبيهات فروق الورديّات ونقد الفرع وقرارات التسوية."
            : "🔕 أُوقفت التنبيهات.\n\n**والاستعلام يعمل كما هو** — اكتب «قائمة» في أيّ وقت.\n"
                . 'ولإعادتها اكتب «تشغيل التنبيهات».';
    }

    /**
     * القائمة — ما يستطيع هذا الدور أن يسأل عنه، لا قائمةٌ واحدةٌ للجميع.
     */
    public function menu(AgentStaff $staff): string
    {
        $head = "🏦 *أميال باي — بوّابة شركات الصرافة*\n"
            . "مرحباً {$staff->name} ({$staff->username})\n"
            . '📋 ' . $staff->roleLabel() . "\n\n";

        if ($staff->isTeller()) {
            return $head
                . "اكتب رقماً أو كلمة:\n\n"
                . "*1* أو «درجي» — النقد في درجك وورديّتك\n"
                . "*2* أو «عملياتي» — آخر عمليّاتك اليوم\n"
                . "*3* أو «كشفي» — آخر كشف تسويةٍ رفعتَه\n\n"
                . "_العمليّات تُنفَّذ من شبّاكك على الكمبيوتر — واتساب للاستعلام فقط._\n"
                . '_«إيقاف التنبيهات» يُسكت الصادر ويُبقي الاستعلام._';
        }

        if ($staff->isBranchManager()) {
            return $head
                . "اكتب رقماً أو كلمة:\n\n"
                . "*1* أو «فرعي» — نقد الخزنة والأدراج\n"
                . "*2* أو «الشبابيك» — ورديّات اليوم وفروقها\n"
                . "*3* أو «اليوم» — إيداعات وسحوبات الفرع اليوم\n\n"
                . "_للاستعلام فقط._\n"
                . '_«إيقاف التنبيهات» يُسكت الصادر ويُبقي الاستعلام._';
        }

        return $head
            . "اكتب رقماً أو كلمة:\n\n"
            . "*1* أو «الشركة» — رصيدك الإلكترونيّ ونقد فروعك\n"
            . "*2* أو «الفروع» — كلّ فرعٍ ونقده ورصيده\n"
            . "*3* أو «اليوم» — حركة الشركة اليوم\n"
            . "*4* أو «التسوية» — حالة تسوية اليوم مع أميال\n\n"
            . "_للاستعلام فقط — الشحن والصرف من بوّابة الشركة._\n"
            . '_«إيقاف التنبيهات» يُسكت الصادر ويُبقي الاستعلام._';
    }

    /**
     * يوجّه الأمر حسب الدور. ويعيد `null` إن لم يفهم — فيتولّى المنادي.
     */
    public function handle(AgentStaff $staff, string $body): ?string
    {
        $t = trim(mb_strtolower($body));

        if ($t === '' || in_array($t, ['0', 'قائمة', 'القائمة', 'menu', 'ابدأ', 'مرحبا', 'السلام عليكم'], true)) {
            return $this->menu($staff);
        }

        // **الإسكات قبل التوجيه بالدور** — لأنّه حقُّ كلّ دور.
        //
        // ومن لا يجد كيف يُسكت التنبيهات يحظر الرقم، فيفقد الاستعلام معها
        // ولا تعرف إدارتُه أنّ قناته انقطعت.
        if (str_contains($t, 'إيقاف التنبيه') || str_contains($t, 'ايقاف التنبيه')
            || $t === 'إيقاف' || $t === 'ايقاف' || $t === 'stop') {
            return $this->setAlerts($staff, false);
        }

        if (str_contains($t, 'تشغيل التنبيه') || str_contains($t, 'تفعيل التنبيه')
            || $t === 'تشغيل' || $t === 'start') {
            return $this->setAlerts($staff, true);
        }

        if ($staff->isTeller()) {
            return match (true) {
                $t === '1' || str_contains($t, 'درج') || str_contains($t, 'ورديت') => $this->tellerDrawer($staff),
                $t === '2' || str_contains($t, 'عمليات') => $this->tellerOperations($staff),
                $t === '3' || str_contains($t, 'كشف') => $this->tellerStatement($staff),
                default => null,
            };
        }

        if ($staff->isBranchManager()) {
            return match (true) {
                $t === '1' || str_contains($t, 'فرعي') || str_contains($t, 'خزن') => $this->branchCash($staff),
                $t === '2' || str_contains($t, 'شبابيك') || str_contains($t, 'وردي') => $this->branchShifts($staff),
                $t === '3' || str_contains($t, 'اليوم') => $this->branchToday($staff),
                default => null,
            };
        }

        return match (true) {
            $t === '1' || str_contains($t, 'شركة') || str_contains($t, 'رصيد') => $this->companySummary($staff),
            $t === '2' || str_contains($t, 'فروع') => $this->companyBranches($staff),
            $t === '3' || str_contains($t, 'اليوم') => $this->companyToday($staff),
            $t === '4' || str_contains($t, 'تسوي') => $this->companySettlement($staff),
            default => null,
        };
    }

    // ══════════════════════════════════════════════════════════════════
    // الصرّاف
    // ══════════════════════════════════════════════════════════════════

    private function tellerDrawer(AgentStaff $staff): string
    {
        $shift = $staff->openShift();

        if (!$shift) {
            // «لا ورديّة» ليست «درجٌ فيه صفر» — يُقال أيّهما.
            return "🪟 *درجك*\n\nلا ورديّة مفتوحة الآن.\n"
                . "افتح ورديّتك من الشبّاك لتبدأ العمل.";
        }

        $branch = AgentBranch::find($shift->branch_id);
        $safe = (string) (AgentCashTill::where('branch_id', $shift->branch_id)->value('cash_on_hand') ?? '0');

        return "🪟 *درجك — {$branch?->name}*\n\n"
            . '💵 النقد في درجك: *' . $this->m($shift->cash_on_hand) . "* ر.ي\n"
            . '📥 إيداعات: ' . $this->m($shift->deposits_total) . " ({$shift->deposits_count})\n"
            . '📤 سحوبات: ' . $this->m($shift->withdrawals_total) . " ({$shift->withdrawals_count})\n"
            . '🎒 العهدة الافتتاحيّة: ' . $this->m($shift->opening_float) . " ر.ي\n\n"
            . '🏦 خزنة الفرع: ' . $this->m($safe) . " ر.ي\n"
            . '⏰ فُتحت: ' . ($shift->opened_at?->format('H:i') ?? '—');
    }

    private function tellerOperations(AgentStaff $staff): string
    {
        $rows = DB::table('agent_cash_movements')
            ->where('staff_id', $staff->id)
            ->where('is_drawer', true)
            ->whereDate('created_at', now()->toDateString())
            ->whereIn('reason', ['customer_deposit', 'customer_withdraw'])
            ->orderByDesc('id')->limit(8)->get();

        if ($rows->isEmpty()) {
            return "📋 *عمليّاتك اليوم*\n\nلا عمليّات بعد اليوم.";
        }

        $lines = $rows->map(fn ($r) => ($r->reason === 'customer_deposit' ? '📥' : '📤')
            . ' ' . $this->m($r->amount) . ' — ' . substr((string) $r->created_at, 11, 5));

        return "📋 *آخر عمليّاتك اليوم*\n\n" . $lines->implode("\n");
    }

    private function tellerStatement(AgentStaff $staff): string
    {
        $s = AgentShift::where('staff_id', $staff->id)
            ->where('status', AgentShift::STATUS_CLOSED)
            ->orderByDesc('id')->first();

        if (!$s) {
            return "🧾 *كشوفك*\n\nلم تُغلق ورديّةً بعد.";
        }

        $v = (string) $s->variance;
        $kind = bccomp($v, '0', 4) === 0 ? '✅ مطابِقة'
            : (bccomp($v, '0', 4) < 0 ? '🔴 عجز ' . $this->m(ltrim($v, '-'))
                                      : '🟡 فائض ' . $this->m($v));

        return "🧾 *آخر كشفٍ رفعتَه — SHIFT-{$s->id}*\n\n"
            . '📅 ' . ($s->closed_at?->format('Y-m-d H:i') ?? '—') . "\n"
            . '📥 إيداعات: ' . $this->m($s->deposits_total) . "\n"
            . '📤 سحوبات: ' . $this->m($s->withdrawals_total) . "\n"
            . '🧮 المعدود: ' . $this->m($s->counted_cash) . "\n"
            . "الحالة: {$kind}\n"
            . '📌 قرار الإدارة: ' . (AgentShift::REVIEW_LABELS[$s->review_status] ?? $s->review_status);
    }

    // ══════════════════════════════════════════════════════════════════
    // مدير الفرع
    // ══════════════════════════════════════════════════════════════════

    private function branchCash(AgentStaff $staff): string
    {
        $branch = AgentBranch::find($staff->branch_id);

        if (!$branch) {
            return 'حسابك بلا فرع — راجع إدارة شركتك.';
        }

        $safe = (string) (AgentCashTill::where('branch_id', $branch->id)->value('cash_on_hand') ?? '0');
        $drawers = (string) (AgentShift::where('branch_id', $branch->id)
            ->where('status', AgentShift::STATUS_OPEN)->sum('cash_on_hand') ?: '0');
        $emoney = (string) (EMoney::where('user_id', $branch->branch_user_id)->value('current_balance') ?? '0');

        return "🏬 *{$branch->name}*\n\n"
            . '🏦 خزنة الفرع: *' . $this->m($safe) . "* ر.ي\n"
            . '🪟 في أدراج الصرّافين: ' . $this->m($drawers) . " ر.ي\n"
            . '💳 الرصيد الإلكترونيّ: ' . $this->m($emoney) . " ر.ي\n\n"
            . "_النقد يحدّ السحب، والرصيد الإلكترونيّ يحدّ الإيداع._";
    }

    private function branchShifts(AgentStaff $staff): string
    {
        $shifts = AgentShift::with('staff')
            ->where('branch_id', $staff->branch_id)
            ->whereDate('opened_at', now()->toDateString())
            ->orderByDesc('id')->limit(10)->get();

        if ($shifts->isEmpty()) {
            return "🪟 *شبابيك اليوم*\n\nلا ورديّات اليوم.";
        }

        $lines = $shifts->map(function (AgentShift $s) {
            if ($s->status === AgentShift::STATUS_OPEN) {
                return '🟢 ' . ($s->staff?->name ?? '—') . ' — مفتوحة، في الدرج ' . $this->m($s->cash_on_hand);
            }

            $v = (string) $s->variance;
            $tag = bccomp($v, '0', 4) === 0 ? '✅ مطابِقة'
                : (bccomp($v, '0', 4) < 0 ? '🔴 عجز ' . $this->m(ltrim($v, '-'))
                                          : '🟡 فائض ' . $this->m($v));

            return '⚪ ' . ($s->staff?->name ?? '—') . " — {$tag}";
        });

        return "🪟 *شبابيك اليوم*\n\n" . $lines->implode("\n");
    }

    private function branchToday(AgentStaff $staff): string
    {
        return $this->todayFor([(int) $staff->branch_id], '🏬 *حركة فرعك اليوم*');
    }

    // ══════════════════════════════════════════════════════════════════
    // الإدارة العامّة
    // ══════════════════════════════════════════════════════════════════

    private function companySummary(AgentStaff $staff): string
    {
        $agent = User::find($staff->agent_user_id);
        $branches = AgentBranch::where('agent_user_id', $staff->agent_user_id)->get();

        $emoney = (string) (EMoney::where('user_id', $staff->agent_user_id)->value('current_balance') ?? '0');
        $branchEmoney = (string) (EMoney::whereIn('user_id', $branches->pluck('branch_user_id'))
            ->sum('current_balance') ?: '0');
        $safes = (string) (AgentCashTill::whereIn('branch_id', $branches->pluck('id'))
            ->sum('cash_on_hand') ?: '0');
        $drawers = (string) (AgentShift::whereIn('branch_id', $branches->pluck('id'))
            ->where('status', AgentShift::STATUS_OPEN)->sum('cash_on_hand') ?: '0');

        return '🏦 *' . (trim(($agent->f_name ?? '') . ' ' . ($agent->l_name ?? '')) ?: 'شركتك') . "*\n\n"
            . '💳 رصيد الشركة: *' . $this->m($emoney) . "* ر.ي\n"
            . '💳 أرصدة الفروع: ' . $this->m($branchEmoney) . " ر.ي\n"
            . '🏦 نقد الخزائن: ' . $this->m($safes) . " ر.ي\n"
            . '🪟 نقد الأدراج: ' . $this->m($drawers) . " ر.ي\n\n"
            . '🏬 الفروع: ' . $branches->count() . ' (' . $branches->where('is_active', true)->count() . ' نشِط)';
    }

    private function companyBranches(AgentStaff $staff): string
    {
        $branches = AgentBranch::with('till')
            ->where('agent_user_id', $staff->agent_user_id)->orderBy('name')->get();

        if ($branches->isEmpty()) {
            return "🏬 *فروعك*\n\nلا فروع بعد — أنشئ فرعك الأوّل من بوّابة الشركة.";
        }

        $lines = $branches->map(function (AgentBranch $b) {
            $emoney = (string) (EMoney::where('user_id', $b->branch_user_id)->value('current_balance') ?? '0');
            $cash = (string) ($b->till->cash_on_hand ?? '0');
            $low = $b->till && $b->till->isLow() ? ' ⚠️ نقدٌ منخفض' : '';

            return "🏬 *{$b->name}* ({$b->code}){$low}\n"
                . '   نقد ' . $this->m($cash) . ' · رصيد ' . $this->m($emoney);
        });

        return "🏬 *فروعك*\n\n" . $lines->implode("\n");
    }

    private function companyToday(AgentStaff $staff): string
    {
        $ids = AgentBranch::where('agent_user_id', $staff->agent_user_id)
            ->pluck('id')->map(fn ($v) => (int) $v)->all();

        return $this->todayFor($ids, '🏦 *حركة شركتك اليوم*');
    }

    private function companySettlement(AgentStaff $staff): string
    {
        $agent = User::find($staff->agent_user_id);
        $date = now()->toDateString();
        $svc = app(AgentDailySettlementService::class);

        $day = $svc->computeDay($agent, $date);
        $window = $svc->windowState($date);
        $row = AgentDailySettlement::where('agent_user_id', $agent->id)
            ->whereDate('settlement_date', $date)->first();

        $conv = $day['conversion'] === 'topup'
            ? '📥 تسلّم نقداً وتستلم رصيداً: *' . $this->m($day['conversion_amount']) . '*'
            : ($day['conversion'] === 'payout'
                ? '📤 تعيد رصيداً وتستلم نقداً: *' . $this->m($day['conversion_amount']) . '*'
                : 'اليوم متعادل — لا تحويل');

        $state = $row
            ? '✅ ' . (AgentDailySettlement::STATUS_LABELS[$row->status] ?? $row->status)
            : '⏳ لم تُرفع بعد';

        return "🌙 *تسوية اليوم {$date}*\n\n"
            . '📥 إيداعات: ' . $this->m($day['deposits_total']) . " ({$day['deposits_count']})\n"
            . '📤 سحوبات: ' . $this->m($day['withdrawals_total']) . " ({$day['withdrawals_count']})\n"
            . '🔴 عجز: ' . $this->m($day['shortage_total']) . " ({$day['shortage_count']})\n"
            . '🟡 فائض: ' . $this->m($day['overage_total']) . " ({$day['overage_count']})\n\n"
            . $conv . "\n\n"
            . "الحالة: {$state}\n"
            . '🕙 ' . $window['message'] . "\n\n"
            . "_الرفع من بوّابة الشركة — تبويب «التسويات»._";
    }

    // ══════════════════════════════════════════════════════════════════

    /** @param array<int> $branchIds */
    private function todayFor(array $branchIds, string $title): string
    {
        if ($branchIds === []) {
            return "{$title}\n\nلا فروع.";
        }

        $r = DB::table('agent_cash_movements')
            ->whereIn('branch_id', $branchIds)
            ->where('is_drawer', true)
            ->whereDate('created_at', now()->toDateString())
            ->whereIn('reason', ['customer_deposit', 'customer_withdraw'])
            ->selectRaw('reason, count(*) as n, sum(amount) as total')
            ->groupBy('reason')->get()->keyBy('reason');

        $dep = (string) ($r['customer_deposit']->total ?? '0');
        $wdr = (string) ($r['customer_withdraw']->total ?? '0');

        return "{$title}\n\n"
            . '📥 إيداعات: ' . $this->m($dep) . ' (' . (int) ($r['customer_deposit']->n ?? 0) . ")\n"
            . '📤 سحوبات: ' . $this->m($wdr) . ' (' . (int) ($r['customer_withdraw']->n ?? 0) . ")\n"
            . '📊 حجم العمل: ' . $this->m(bcadd($dep, $wdr, 4)) . " ر.ي\n"
            . '💵 صافي النقد لديك: ' . $this->m(bcsub($dep, $wdr, 4)) . ' ر.ي';
    }

    private function m(string|float|null $n): string
    {
        return number_format((float) ($n ?? 0), 0);
    }
}
