<?php

namespace App\Services\Agent;

use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentStaff;
use App\Models\Agent\AgentTellerRequest;
use App\Services\AuditService;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * AGT-GAP-018 — ضابط ساعات العمل الرسميّة للسحب والاستثناءات المصرَّح بها.
 *
 * القاعدة الصارمة (Fail-Closed):
 *  - عند غياب المنطقة الزمنية المعتمدة أو جدول ساعات عمل الفرع: يُمنع السحب بأمان ولا تُفترض قيم افتراضيّة.
 *  - خارج ساعات العمل الرسميّة (أو في يوم إغلاق): يُمنع السحب ما لم يوجد استثناء
 *    صريح (`after_hours_withdrawal`) معتمد من جهة ذات صلاحية معتمدة، غير منتهٍ،
 *    غير مستخدم مسبقاً، مربوط بالعميل والمبلغ، وليس بموافقة ذاتيّة.
 */
class BranchWorkingHoursPolicy
{
    public const DAYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    public function __construct(private readonly AuditService $audit)
    {
    }

    /**
     * التحقّق من اعتماد سياسة وصلاحيات الاستثناء خارج ساعات العمل — بلا افتراضات.
     *
     * @return array{roles: array<int, string>, capability: string}
     */
    public function assertExceptionPolicyApproved(): array
    {
        $roles = config('amial.branch_working_hours.exception_roles');
        $cap = config('amial.branch_working_hours.exception_capability');

        $validRoles = is_array($roles)
            ? array_values(array_filter(
                $roles,
                fn ($r) => is_string($r) && array_key_exists($r, AgentStaff::ROLE_LABELS),
            ))
            : [];

        if ($validRoles === [] || !is_string($cap) || trim($cap) === '' || !array_key_exists($cap, AgentStaff::CAPABILITIES)) {
            throw new DomainException(
                'صلاحيات طلب/اعتماد استثناء السحب خارج ساعات العمل غير معتمدة بعد — يُمنع التجاوز حتى اعتمادها رسميّاً',
            );
        }

        return ['roles' => $validRoles, 'capability' => $cap];
    }

    /**
     * هل الفرع مفتوحٌ الآن وفق الجدول والمنطقة الزمنية المعتمدَين؟
     *
     * @return array{open: bool, timezone: string, day: string, local_time: string}
     */
    public function evaluateWindow(AgentBranch $branch, ?Carbon $now = null): array
    {
        $hours = $branch->working_hours;
        $tz = is_array($hours) ? ($hours['timezone'] ?? null) : null;
        $tz = $tz ?: config('amial.branch_working_hours.timezone');

        if (!is_string($tz) || trim($tz) === '' || !in_array($tz, timezone_identifiers_list(), true)) {
            $this->recordBlock($branch, null, 'MISSING_TIMEZONE_CONFIG', [
                'configured_timezone' => $tz,
            ]);
            throw new DomainException(
                'المنطقة الزمنية الرسميّة لساعات عمل الفرع غير معتمدة بعد — يُمنع السحب بأمان حتى اعتمادها',
            );
        }

        if (!is_array($hours) || count(array_intersect_key($hours, array_flip(self::DAYS))) === 0) {
            $this->recordBlock($branch, null, 'MISSING_WORKING_HOURS', [
                'timezone' => $tz,
            ]);
            throw new DomainException(
                'ساعات العمل الرسميّة للفرع غير مضبوطة — يُمنع السحب بأمان حتى ضبط الجدول المعتمد',
            );
        }

        $localNow = ($now ?? now())->copy()->setTimezone($tz);
        $dayKey = strtolower($localNow->format('D'));
        $hhmm = $localNow->format('H:i');
        $slot = $hours[$dayKey] ?? null;

        $previousDay = strtolower($localNow->copy()->subDay()->format('D'));
        $open = $this->containsTime($slot, $hhmm, false)
            || $this->containsTime($hours[$previousDay] ?? null, $hhmm, true);

        return [
            'open' => $open,
            'timezone' => $tz,
            'day' => $dayKey,
            'local_time' => $hhmm,
        ];
    }

    private function containsTime(mixed $slot, string $time, bool $previousDay): bool
    {
        if (!is_array($slot)) return false;
        $start = $slot['open'] ?? '';
        $end = $slot['close'] ?? '';
        foreach ([$start, $end] as $value) {
            if (!is_string($value) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $value)) return false;
        }
        if (($slot['overnight'] ?? false) === true) {
            return $start > $end && ($previousDay ? $time < $end : $time >= $start);
        }
        return !$previousDay && $start < $end && $time >= $start && $time < $end;
    }

    /**
     * التحقّق من السماح بالسحب عند الشباك (واستهلاك الاستثناء مرّة واحدة داخل القفل عند `$consume = true`).
     *
     * @return array{allowed: bool, exception_request: ?string, timezone: string, day: string, local_time: string}
     */
    public function checkOrConsumeForWithdrawal(
        AgentBranch $branch,
        ?AgentStaff $staff,
        string $amount,
        int $customerUserId,
        bool $consume = false,
        ?Carbon $now = null,
    ): array {
        $window = $this->evaluateWindow($branch, $now);

        if ($window['open']) {
            return [
                'allowed' => true,
                'exception_request' => null,
                'timezone' => $window['timezone'],
                'day' => $window['day'],
                'local_time' => $window['local_time'],
            ];
        }

        try {
            $policy = $this->assertExceptionPolicyApproved();
        } catch (DomainException $e) {
            $this->recordBlock($branch, $staff, 'OUTSIDE_HOURS_NO_EXCEPTION_POLICY', $window);
            throw $e;
        }

        if (!$staff || !$staff->is_active) {
            $this->recordBlock($branch, $staff, 'OUTSIDE_WORKING_HOURS', $window + ['reason' => 'no_active_staff']);
            throw new DomainException('السحب ممنوع خارج ساعات العمل الرسميّة إلا لموظف نشط باستثناء معتمد');
        }

        $findAndOptionallyConsume = function () use ($branch, $staff, $amount, $customerUserId, $policy, $consume, $window): ?AgentTellerRequest {
            $query = AgentTellerRequest::where('staff_id', $staff->id)
                ->where('branch_id', $branch->id)
                ->where('kind', AgentTellerRequest::KIND_AFTER_HOURS_WITHDRAWAL)
                ->where('operation', 'withdraw')
                ->where('customer_user_id', $customerUserId)
                ->where('status', AgentTellerRequest::STATUS_APPROVED)
                ->whereNull('used_at')
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->orderBy('id');

            if ($consume) {
                $query->lockForUpdate();
            }

            $candidates = $query->get();

            foreach ($candidates as $candidate) {
                if (bccomp((string) $candidate->amount, $amount, 4) < 0) {
                    continue;
                }

                if (!$this->isDeciderAuthorized($candidate, $staff, $branch, $policy)) {
                    continue;
                }

                if ($consume) {
                    $candidate->used_at = now();
                    $candidate->status = AgentTellerRequest::STATUS_USED;
                    $candidate->save();

                    $this->audit->record([
                        'actor_type' => 'agent',
                        'actor_user_id' => $branch->agent_user_id,
                        'action' => 'agent.working_hours.exception_used',
                        'decision_code' => 'AFTER_HOURS_EXCEPTION_USED',
                        'severity' => 'critical',
                        'subject_type' => 'agent_teller_request',
                        'subject_id' => $candidate->request_number,
                        'metadata' => [
                            'branch_id' => $branch->id,
                            'staff_id' => $staff->id,
                            'decided_by_staff_id' => $candidate->decided_by_staff_id,
                            'customer_user_id' => $customerUserId,
                            'amount' => $amount,
                            'timezone' => $window['timezone'],
                            'day' => $window['day'],
                            'local_time' => $window['local_time'],
                        ],
                    ]);
                }

                return $candidate;
            }

            return null;
        };

        $matched = $consume
            ? DB::transaction($findAndOptionallyConsume)
            : $findAndOptionallyConsume();

        if (!$matched) {
            $this->recordBlock($branch, $staff, 'OUTSIDE_WORKING_HOURS', $window + [
                'customer_user_id' => $customerUserId,
                'amount' => $amount,
            ]);
            throw new DomainException(
                "السحب ممنوع خارج ساعات العمل الرسميّة ({$window['day']} {$window['local_time']} {$window['timezone']}) إلا باستثناء معتمد وصريح",
            );
        }

        return [
            'allowed' => true,
            'exception_request' => $matched->request_number,
            'timezone' => $window['timezone'],
            'day' => $window['day'],
            'local_time' => $window['local_time'],
        ];
    }

    /**
     * التحقّق الخادميّ من أن من اعتمد الاستثناء شخص آخر غير الصرّاف وله الدور والصلاحية المعتمدة.
     *
     * @param  array{roles: array<int, string>, capability: string}  $policy
     */
    private function isDeciderAuthorized(
        AgentTellerRequest $request,
        AgentStaff $staff,
        AgentBranch $branch,
        array $policy,
    ): bool {
        if (!$request->decided_by_staff_id) {
            return false;
        }

        if ((int) $request->decided_by_staff_id === (int) $staff->id) {
            return false;
        }

        $decider = AgentStaff::find($request->decided_by_staff_id);

        if (!$decider || !$decider->is_active) {
            return false;
        }

        if (!in_array($decider->role, $policy['roles'], true)) {
            return false;
        }

        if (!$decider->hasCapability($policy['capability'])) {
            return false;
        }

        return in_array((int) $branch->id, $decider->visibleBranchIds(), true);
    }

    /** @param array<string, mixed> $meta */
    private function recordBlock(AgentBranch $branch, ?AgentStaff $staff, string $code, array $meta): void
    {
        $this->audit->record([
            'actor_type' => 'agent',
            'actor_user_id' => $branch->agent_user_id,
            'action' => 'agent.working_hours.blocked',
            'decision_code' => $code,
            'severity' => 'high',
            'subject_type' => 'agent_branch',
            'subject_id' => (string) $branch->id,
            'metadata' => array_merge($meta, [
                'branch_id' => (int) $branch->id,
                'staff_id' => $staff?->id,
            ]),
        ]);
    }
}
