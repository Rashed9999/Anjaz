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
        'unfreeze' => ['طلب إلغاء التجميد', 'platform.customers.unfreeze.request'],
        'suspend' => ['إيقاف مؤقت', 'platform.customers.lifecycle.manage'],
        'activate' => ['تفعيل الحساب', 'platform.customers.lifecycle.manage'],
        'close' => ['إغلاق الحساب', 'platform.customers.close.request'],
        'mark_deceased' => ['فتح معاملة تعليم متوفّى', 'platform.customers.deceased.request'],
        'reset_pin' => ['إعادة تعيين PIN', 'platform.customers.reset_pin'],
        // لا ندّعي إنهاء «كل» جلسة بينما ليست كلّ فئات الجلسات موحّدة في
        // مخزنٍ واحد. هذه العملية تلغي الجلسات والرموز التي يستطيع النظام
        // إثباتها وتعدّها في سجلّ التدقيق.
        'revoke_sessions' => ['إلغاء الجلسات المسجّلة', 'platform.customers.sessions'],
        'require_kyc' => ['طلب تحديث الهوية', 'platform.customers.kyc.request'],
        'update_limits' => ['تعديل حدود التحويل', 'platform.customers.limits.update'],
        'add_note' => ['إضافة ملاحظة', 'platform.customers.notes.create'],
        'escalate_risk' => ['تحويل إلى فريق المخاطر', 'platform.risk.investigations.create'],
    ];

    public function __construct(
        private readonly AuditService $audit,
        private readonly ApprovalService $approvals,
        private readonly KycUpdateRequestService $kycUpdates,
        private readonly CustomerCenterTargetPolicy $targetPolicy,
        private readonly AccountClosureService $closures,
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

        $this->targetPolicy->assertActionable($customer, $actor);

        // قفل العميل مع سجلّ التدقيق في المعاملة نفسها. فلا يستقر تغييرٌ حرج
        // بلا أثر دائم، ولا تتغلب شاشة قديمة على حالةٍ تغيّرت للتو.
        $result = DB::transaction(function () use ($customer, $actor, $action, $reason, $payload) {
            $locked = User::whereKey($customer->id)->lockForUpdate()->firstOrFail();

            $result = match ($action) {
                'freeze' => $this->setFrozen($locked, true),
                'unfreeze' => $this->requestApproval($locked, $actor, 'unfreeze_wallet', $reason),
                'suspend' => $this->setLifecycle($locked, 'suspended', $reason),
                'activate' => $this->setLifecycle($locked, 'active', $reason),
                'close' => $this->requestClosureApproval($locked, $actor, $reason),
                'mark_deceased' => $this->requestDeceasedApproval($locked, $actor, $reason),
                'reset_pin' => $this->requestApproval($locked, $actor, 'reset_pin', $reason),
                'revoke_sessions' => $this->revokeSessions($locked),
                'require_kyc' => $this->requireKyc($locked, $actor, $reason),
                'update_limits' => $this->updateLimits($locked, $actor, $payload),
                'add_note' => $this->addNote($locked, $actor, $payload['body'] ?? $reason, (bool) ($payload['pin'] ?? false)),
                'escalate_risk' => $this->escalateToRisk($locked, $actor, $reason),
            };

            $auditId = $this->audit->record([
                'actor_type' => 'admin',
                'actor_user_id' => $actor->id,
                'subject_type' => 'user',
                'subject_id' => (string) $locked->id,
                'action' => 'CUSTOMER_' . strtoupper($action),
                'decision_code' => strtoupper($action),
                'reason' => mb_substr($reason, 0, 500),
                'severity' => 'critical',
                'context' => array_merge(['customer_id' => $locked->id], $result['context'] ?? []),
            ]);

            if ($auditId === null) {
                throw new DomainException('AUDIT_PERSISTENCE_FAILED: لم يُنفّذ الإجراء لأن سجل التدقيق لم يُحفظ');
            }

            return $result;
        });

        return [
            'message' => $result['message'],
            'action' => $action,
            'approval_required' => (bool) ($result['approval_required'] ?? false),
            'approval_request_number' => $result['approval_request_number'] ?? null,
            // AMIAL-APPROVAL-ID-001 — **الرقمُ المعروضُ ليس المعرّفَ الذي
            // يُعتمَد به.** توحيدُ إجراءات العميل في هذه الخدمة أبقى
            // `request_number` (‏نصٌّ يُقرأ: `APR-000123`) وأسقط `request_id`
            // (‏المفتاحَ الذي يبني به السطحُ مسارَ الاعتماد). فصار السطحُ
            // يبني `…/approvals//approve` — **شرطةٌ مزدوجةٌ ومسارٌ لا وجودَ
            // له**: أربعُ عيونٍ مبنيّةٌ ولا يُوصَل إليها. يُعادان معاً.
            'approval_request_id' => $result['approval_request_id'] ?? null,
            // لا يُخفى أثر الإجراء عن السطح الذي طلبه، لكن يُبنى من الخدمة
            // لا من عدّادٍ مستقل في كلّ متحكّم.
            'context' => $result['context'] ?? [],
        ];
    }

    // ── التنفيذ ─────────────────────────────────────────────────────────

    private function setFrozen(User $c, bool $frozen): array
    {
        if ((int) ($c->is_temp_blocked ?? 0) === ($frozen ? 1 : 0)) {
            throw new DomainException('NO_CHANGE: حالة التجميد مطبّقة بالفعل');
        }
        $c->forceFill([
            'is_temp_blocked' => $frozen ? 1 : 0,
            'temp_block_time' => $frozen ? now() : null,
        ])->save();

        return ['message' => $frozen ? 'جُمّد الحساب' : 'رُفع التجميد'];
    }

    private function requestApproval(User $customer, User $actor, string $approvalAction, string $reason): array
    {
        $request = $this->approvals->submit(
            maker: $actor,
            actionType: $approvalAction,
            subjectUserId: (int) $customer->id,
            reason: $reason,
        );

        return [
            'message' => 'أُنشئ طلب ' . $request->request_number . ' لاعتماد مشرف مختلف قبل التنفيذ',
            'approval_required' => true,
            'approval_request_number' => $request->request_number,
            'approval_request_id' => (int) $request->id,
            'context' => ['approval_request_number' => $request->request_number],
        ];
    }

    private function setLifecycle(User $c, string $state, string $reason): array
    {
        // «مغلق» نهائيّ: لا يُعاد فتحه من هذه الشاشة. وإعادةُ فتح حسابٍ
        // أُغلق قرارٌ أكبر من زرٍّ في ملفّ العميل.
        if ($c->lifecycle_state === 'closed' && $state !== 'closed') {
            throw new DomainException('الحساب مغلق نهائياً — إعادة فتحه قرارٌ خارج هذه الشاشة');
        }
        if ($c->lifecycle_state === $state) {
            throw new DomainException('NO_CHANGE: حالة الحساب مطبّقة بالفعل');
        }
        if ($c->lifecycle_state === 'deceased') {
            throw new DomainException('DECEASED_ACCOUNT: هذا الحساب تحت مسار التركة ولا يُدار من الإجراءات العادية');
        }

        $c->forceFill([
            'lifecycle_state' => $state,
            'lifecycle_changed_at' => now(),
            'lifecycle_reason' => mb_substr($reason, 0, 1000),
        ])->save();

        return ['message' => 'تغيّرت حالة الحساب إلى: ' . $state];
    }

    private function requestClosureApproval(User $customer, User $actor, string $reason): array
    {
        $preflight = $this->closures->preflight($customer);
        if (! $preflight['allowed']) {
            throw new DomainException('CLOSURE_PREFLIGHT_FAILED: ' . implode('؛ ', array_column($preflight['blockers'], 'message')));
        }

        return $this->requestApproval($customer, $actor, 'close_customer', $reason);
    }

    private function requestDeceasedApproval(User $customer, User $actor, string $reason): array
    {
        if ($customer->lifecycle_state === 'deceased') {
            throw new DomainException('NO_CHANGE: الحساب معلَّم كمتوفّى بالفعل');
        }

        $request = $this->approvals->submit(
            maker: $actor,
            actionType: 'mark_customer_deceased',
            subjectUserId: (int) $customer->id,
            reason: $reason,
        );

        if (Schema::hasTable('customer_death_cases')) {
            DB::table('customer_death_cases')->updateOrInsert(
                ['approval_request_id' => $request->id],
                [
                    'case_number' => 'DTH-' . str_pad((string) $request->id, 8, '0', STR_PAD_LEFT),
                    'customer_user_id' => $customer->id,
                    'opened_by' => $actor->id,
                    'evidence_summary' => mb_substr($reason, 0, 2000),
                    'status' => 'pending_review',
                    'created_at' => now(), 'updated_at' => now(),
                ],
            );
        }

        return [
            'message' => 'فُتحت معاملة وفاة وطلب ' . $request->request_number . ' لمراجعة موظف مختلف قبل التعليم النهائي',
            'approval_required' => true,
            'approval_request_number' => $request->request_number,
            'approval_request_id' => (int) $request->id,
            'context' => ['approval_request_number' => $request->request_number],
        ];
    }

    private function revokeSessions(User $c): array
    {
        $deviceRows = DB::table('user_log_histories')->where('user_id', $c->id)
            ->update(['is_active' => 0]);

        $accessTokenIds = collect();
        if (Schema::hasTable('oauth_access_tokens')) {
            $accessTokenIds = DB::table('oauth_access_tokens')
                ->where('user_id', $c->id)
                ->where('revoked', false)
                ->pluck('id');

            if ($accessTokenIds->isNotEmpty()) {
                DB::table('oauth_access_tokens')->whereIn('id', $accessTokenIds)
                    ->update(['revoked' => true]);
            }
        }

        // رمزُ التجديد بابُ عودةٍ إلى الجلسة. إلغاء access token وحده ثم
        // ترك refresh token صالحاً يجعل عبارة «ألغيت الجلسات» غير صادقة.
        $refreshTokens = 0;
        if ($accessTokenIds->isNotEmpty() && Schema::hasTable('oauth_refresh_tokens')) {
            $refreshTokens = DB::table('oauth_refresh_tokens')
                ->whereIn('access_token_id', $accessTokenIds)
                ->where('revoked', false)
                ->update(['revoked' => true]);
        }

        return [
            'message' => sprintf(
                'أُلغيت %d جلسة API و%d رمز تجديد، وعُطّلت %d سجلات أجهزة',
                $accessTokenIds->count(), $refreshTokens, $deviceRows,
            ),
            'context' => [
                'revoked_access_tokens' => $accessTokenIds->count(),
                'revoked_refresh_tokens' => $refreshTokens,
                'deactivated_device_rows' => $deviceRows,
            ],
        ];
    }

    private function requireKyc(User $customer, User $actor, string $reason): array
    {
        $out = $this->kycUpdates->request($customer, $actor, 'customer_center');

        return [
            'message' => $out['already_required']
                ? 'طلب تحديث الهوية قائم بالفعل والعمليات الحساسة مقيّدة'
                : 'طُلب تحديث الهوية وقُيّدت العمليات الحساسة حتى الاعتماد',
            'context' => $out,
        ];
    }

    private function updateLimits(User $c, User $actor, array $payload): array
    {
        $allowed = ['max_balance', 'max_single_transaction', 'max_daily_total', 'max_monthly_total'];
        $clean = [];

        foreach ($allowed as $k) {
            if (array_key_exists($k, $payload) && $payload[$k] !== null && $payload[$k] !== '') {
                $value = trim((string) $payload[$k]);
                if (!preg_match('/^\d+(?:\.\d{1,4})?$/', $value)) {
                    throw new DomainException('قيمة الحدّ «' . $k . '» غير صالحة');
                }
                $clean[$k] = $value;
            }
        }

        if ($clean === []) {
            throw new DomainException('لا حدود صالحة للحفظ');
        }

        $existing = is_array($c->limit_override)
            ? $c->limit_override
            : (json_decode((string) $c->limit_override, true) ?: []);
        // Patch لا Replace: تعديل سقفٍ واحد لا يمحو الاستثناءات الأخرى.
        $merged = array_merge($existing, $clean);

        // حدٌّ يوميّ يفوق الشهريّ يمرّ في الحفظ ويُربك في التنفيذ — يُمنع هنا.
        if (isset($merged['max_daily_total'], $merged['max_monthly_total'])
            && bccomp((string) $merged['max_daily_total'], (string) $merged['max_monthly_total'], 4) > 0) {
            throw new DomainException('الحدّ اليوميّ أكبر من الشهريّ — راجع القيم');
        }

        $c->forceFill([
            'limit_override' => $merged,
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
        $existing = AmlInvestigation::where('subject_user_id', $c->id)->open()
            ->orderByDesc('id')->first();
        if ($existing) {
            app(AmlInvestigationService::class)->addEvidence(
                $existing, $actor, 'تصعيد إضافي من مركز العملاء: ' . trim($reason),
            );
            return [
                'message' => 'أُلحق التصعيد بالقضية المفتوحة ' . $existing->case_number,
                'context' => ['case_number' => $existing->case_number, 'linked_existing_case' => true],
            ];
        }

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
