<?php

namespace App\Services;

use App\Models\Aml\AmlInvestigation;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-CUSTOMER-CENTER-001 — الإجراءات السريعة (الفصل ٠٢).
 *
 * تطلب الوثيقة ستّة عشر إجراءً. وكان المتاح ستّةً موزّعةً على لوحة الدعم.
 *
 * **وقاعدةٌ واحدة تحكمها جميعاً: السبب إلزاميّ.**
 *
 * ليست شكليّة: هذه إجراءاتٌ تُتّخذ تحت ضغط مكالمة، ويُراجعها بعد شهرٍ مدقّقٌ
 * لم يحضر المكالمة. فمن لا يكتب سببه اليوم يترك قراره بلا دفاع.
 *
 * **والتصعيد إلى المخاطر ليس تسجيلاً بل فعلاً:** يفتح قضيّة تحقيقٍ حقيقية
 * في مركز التحقيقات برقمٍ وخطٍّ زمنيّ. وزرٌّ يكتب «صُعِّد» في سجلٍّ ولا يُنشئ
 * شيئاً هو أسوأ من عدمه — يظنّ الموظّف أنّه سلّم المسألة وهي لم تصل أحداً.
 */
class CustomerActionService
{
    /** الإجراءات وتسمياتها والصلاحية التي يطلبها كلٌّ منها. */
    public const ACTIONS = [
        'freeze' => ['تجميد الحساب', 'platform.customers.freeze'],
        'unfreeze' => ['إلغاء التجميد', 'platform.customers.freeze'],
        'suspend' => ['إيقاف مؤقت', 'platform.customers.freeze'],
        'activate' => ['تفعيل الحساب', 'platform.customers.freeze'],
        'close' => ['إغلاق الحساب', 'platform.customers.freeze'],
        'mark_deceased' => ['تعليم كمتوفّى', 'platform.customers.freeze'],
        'reset_pin' => ['إعادة تعيين PIN', 'platform.customers.reset_pin'],
        'revoke_sessions' => ['إنهاء جميع الجلسات', 'platform.customers.sessions'],
        'require_kyc' => ['طلب تحديث الهوية', 'platform.customers.freeze'],
        'update_limits' => ['تعديل حدود التحويل', 'platform.settings.update'],
        'add_note' => ['إضافة ملاحظة', 'platform.customers.view'],
        'escalate_risk' => ['تحويل إلى فريق المخاطر', 'platform.customers.view'],
    ];

    public function __construct(
        private readonly AuditService $audit,
    ) {
    }

    public function run(User $customer, User $actor, string $action, string $reason, array $payload = []): array
    {
        if (!isset(self::ACTIONS[$action])) {
            throw new DomainException('إجراء غير معروف');
        }

        [$label, $permission] = self::ACTIONS[$action];

        if (!$actor->hasPlatformPermission($permission)) {
            throw new DomainException("لا تملك صلاحية «{$label}»");
        }

        if (mb_strlen(trim($reason)) < 10) {
            throw new DomainException('السبب إلزاميّ (١٠ أحرف على الأقل) — يُراجَع بعد شهرٍ من لم يحضر المكالمة');
        }

        // لا يُنفِّذ الموظّف إجراءً على حسابه هو.
        //
        // موظّفو المنصّة عملاء فيها ولهم محافظ وحدود. ومن يرفع تجميداً عن
        // نفسه أو يوسّع حدوده بيده يُبطل كلّ ضابطٍ فوقه.
        if ((int) $customer->id === (int) $actor->id) {
            throw new DomainException('FOUR_EYES_VIOLATION: لا تُنفَّذ الإجراءات على حسابك الشخصيّ');
        }

        $result = match ($action) {
            'freeze' => $this->setFrozen($customer, true),
            'unfreeze' => $this->setFrozen($customer, false),
            'suspend' => $this->setLifecycle($customer, 'inactive', $reason),
            'activate' => $this->setLifecycle($customer, 'active', $reason),
            'close' => $this->setLifecycle($customer, 'closed', $reason),
            'mark_deceased' => $this->markDeceased($customer, $reason),
            'reset_pin' => $this->resetPin($customer),
            'revoke_sessions' => $this->revokeSessions($customer),
            'require_kyc' => $this->requireKyc($customer),
            'update_limits' => $this->updateLimits($customer, $actor, $payload),
            'add_note' => $this->addNote($customer, $actor, $payload['body'] ?? $reason, (bool) ($payload['pin'] ?? false)),
            'escalate_risk' => $this->escalateToRisk($customer, $actor, $reason),
        };

        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $actor->id,
            'subject_type' => 'user',
            'subject_id' => (string) $customer->id,
            'action' => 'CUSTOMER_' . strtoupper($action),
            'decision_code' => strtoupper($action),
            'reason' => mb_substr($reason, 0, 500),
            // كلّ إجراءٍ على حساب عميل حرج: هذه ليست إعداداتٍ بل مسٌّ بحسابه.
            'severity' => 'critical',
            'context' => array_merge(['customer_id' => $customer->id], $result['context'] ?? []),
        ]);

        return ['message' => $result['message'], 'action' => $action];
    }

    // ── التنفيذ ─────────────────────────────────────────────────────────

    private function setFrozen(User $c, bool $frozen): array
    {
        $c->forceFill([
            'is_temp_blocked' => $frozen ? 1 : 0,
            'temp_block_time' => $frozen ? now() : null,
        ])->save();

        return ['message' => $frozen ? 'جُمّد الحساب' : 'رُفع التجميد'];
    }

    private function setLifecycle(User $c, string $state, string $reason): array
    {
        // «مغلق» نهائيّ: لا يُعاد فتحه من هذه الشاشة. وإعادةُ فتح حسابٍ
        // أُغلق قرارٌ أكبر من زرٍّ في ملفّ العميل.
        if ($c->lifecycle_state === 'closed' && $state !== 'closed') {
            throw new DomainException('الحساب مغلق نهائياً — إعادة فتحه قرارٌ خارج هذه الشاشة');
        }

        $c->forceFill([
            'lifecycle_state' => $state,
            'lifecycle_changed_at' => now(),
            'lifecycle_reason' => mb_substr($reason, 0, 1000),
        ])->save();

        return ['message' => 'تغيّرت حالة الحساب إلى: ' . $state];
    }

    private function markDeceased(User $c, string $reason): array
    {
        // يُجمَّد معها: حسابُ متوفٍّ لا يُنفَّذ عليه شيء حتى تُسوّى التركة،
        // وتعليمُه بلا تجميد يترك الباب مفتوحاً لمن يملك هاتفه.
        $c->forceFill([
            'lifecycle_state' => 'deceased',
            'lifecycle_changed_at' => now(),
            'lifecycle_reason' => mb_substr($reason, 0, 1000),
            'is_temp_blocked' => 1,
        ])->save();

        return ['message' => 'عُلّم الحساب كمتوفٍّ وجُمّد'];
    }

    private function resetPin(User $c): array
    {
        foreach (['pin_code', 'pin', 'pin_failed_attempts', 'pin_locked_until'] as $col) {
            if (Schema::hasColumn('users', $col)) {
                $c->forceFill([$col => in_array($col, ['pin_failed_attempts'], true) ? 0 : null]);
            }
        }
        $c->save();

        return ['message' => 'أُعيد تعيين الرمز — يضبطه العميل عند أوّل دخول'];
    }

    private function revokeSessions(User $c): array
    {
        $n = DB::table('user_log_histories')->where('user_id', $c->id)
            ->update(['is_active' => 0]);

        if (Schema::hasTable('oauth_access_tokens')) {
            DB::table('oauth_access_tokens')->where('user_id', $c->id)->update(['revoked' => 1]);
        }

        return ['message' => "أُنهيت {$n} جلسة", 'context' => ['sessions' => $n]];
    }

    private function requireKyc(User $c): array
    {
        if (Schema::hasColumn('users', 'kyc_update_required')) {
            $c->forceFill(['kyc_update_required' => 1])->save();
        }

        return ['message' => 'طُلب من العميل تحديث هويّته'];
    }

    private function updateLimits(User $c, User $actor, array $payload): array
    {
        $allowed = ['max_balance', 'max_single_transaction', 'max_daily_total', 'max_monthly_total'];
        $clean = [];

        foreach ($allowed as $k) {
            if (isset($payload[$k]) && is_numeric($payload[$k]) && (float) $payload[$k] >= 0) {
                $clean[$k] = (string) $payload[$k];
            }
        }

        if ($clean === []) {
            throw new DomainException('لا حدود صالحة للحفظ');
        }

        // حدٌّ يوميّ يفوق الشهريّ يمرّ في الحفظ ويُربك في التنفيذ — يُمنع هنا.
        if (isset($clean['max_daily_total'], $clean['max_monthly_total'])
            && bccomp($clean['max_daily_total'], $clean['max_monthly_total'], 4) > 0) {
            throw new DomainException('الحدّ اليوميّ أكبر من الشهريّ — راجع القيم');
        }

        $c->forceFill([
            'limit_override' => $clean,
            'limit_override_by' => $actor->id,
            'limit_override_at' => now(),
        ])->save();

        return ['message' => 'حُفظ استثناء الحدود لهذا العميل', 'context' => ['limits' => array_keys($clean)]];
    }

    private function addNote(User $c, User $actor, string $body, bool $pin): array
    {
        if (mb_strlen(trim($body)) < 5) {
            throw new DomainException('الملاحظة قصيرة');
        }

        DB::table('customer_notes')->insert([
            'user_id' => $c->id,
            'author_id' => $actor->id,
            'body' => mb_substr(trim($body), 0, 2000),
            'is_pinned' => $pin,
            'created_at' => now(),
        ]);

        return ['message' => 'أُضيفت الملاحظة'];
    }

    /**
     * تصعيدٌ يفتح قضيّةً حقيقية — لا يكتب «صُعِّد» في سجلّ.
     *
     * زرٌّ يسجّل التصعيد ولا يُنشئ شيئاً يجعل الموظّف يظنّ أنّه سلّم المسألة
     * وهي لم تصل أحداً. والقضية لها رقمٌ وضابطٌ وخطٌّ زمنيّ وتظهر في طابور
     * التحقيقات.
     */
    private function escalateToRisk(User $c, User $actor, string $reason): array
    {
        $inv = app(AmlInvestigationService::class)->open(
            subjectUserId: $c->id,
            actor: $actor,
            openedFrom: 'manual',
            priority: 'high',
        );

        app(AmlInvestigationService::class)->addEvidence(
            $inv, $actor, 'صُعِّد من مركز العملاء: ' . trim($reason),
        );

        return [
            'message' => 'فُتحت قضية تحقيق ' . $inv->case_number,
            'context' => ['case_number' => $inv->case_number],
        ];
    }
}
