<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-INSIDER-001 — خدمة Maker-Checker (أربع عيون).
 *
 * القواعد الصلبة:
 *   1. لا أحد يعتمد طلبه هو (حتى السوبر أدمن) — SELF_APPROVAL_FORBIDDEN.
 *   2. الطلب صالح 24 ساعة ثم ينتهي تلقائياً.
 *   3. التنفيذ يحدث ذرّياً لحظة الاعتماد، وأي فشل يُعلَّم failed (لا تنفيذ جزئي).
 *   4. كل خطوة (طلب/اعتماد/رفض) تُسجَّل في سجل التدقيق المُسلسَل.
 */
class ApprovalService
{
    public const EXPIRY_HOURS = 24;

    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /** يفتح طلب موافقة. يعيد الطلب المُنشأ. */
    public function submit(
        User $maker,
        string $actionType,
        int $subjectUserId,
        string $reason,
        array $payload = [],
    ): ApprovalRequest {
        if (!in_array($actionType, ApprovalRequest::ACTIONS, true)) {
            throw new \InvalidArgumentException("إجراء غير خاضع للموافقات: {$actionType}");
        }

        $request = DB::transaction(function () use ($maker, $actionType, $subjectUserId, $reason, $payload) {
            return ApprovalRequest::create([
                'request_number' => ApprovalRequest::nextRequestNumber(),
                'action_type' => $actionType,
                'subject_user_id' => $subjectUserId,
                'maker_admin_id' => $maker->id,
                'reason' => $reason,
                'payload' => $payload ?: null,
                'status' => 'pending',
                'expires_at' => now()->addHours(self::EXPIRY_HOURS),
            ]);
        });

        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $maker->id,
            'subject_type' => 'user',
            'subject_id' => (string) $subjectUserId,
            'action' => 'APPROVAL_REQUESTED',
            'decision_code' => strtoupper($actionType),
            'reason' => $reason,
            'severity' => 'notice',
            'context' => ['request_number' => $request->request_number],
        ]);

        return $request;
    }

    /**
     * اعتماد + تنفيذ ذرّي.
     * @throws \DomainException SELF_APPROVAL_FORBIDDEN | NOT_PENDING | EXPIRED
     */
    public function approve(User $checker, int $requestId, ?string $note = null): ApprovalRequest
    {
        // انتهاء الصلاحية يُعلَّم في معاملة مستقلة (الرمي داخل المعاملة
        // الرئيسية كان سيتراجع عن التعليم نفسه)
        $probe = ApprovalRequest::findOrFail($requestId);
        if ($probe->status === 'pending' && $probe->expires_at && $probe->expires_at->isPast()) {
            DB::transaction(function () use ($requestId) {
                ApprovalRequest::where('id', $requestId)->where('status', 'pending')
                    ->update(['status' => 'expired', 'decided_at' => now()]);
            });
            throw new \DomainException('EXPIRED');
        }

        try {
            return $this->approveTx($checker, $requestId, $note);
        } catch (\DomainException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // فشل التنفيذ: المعاملة الرئيسية تراجعت — نعلّم failed في معاملة مستقلة
            DB::transaction(function () use ($requestId, $checker, $e) {
                ApprovalRequest::where('id', $requestId)->where('status', 'pending')->update([
                    'status' => 'failed',
                    'checker_admin_id' => $checker->id,
                    'checker_note' => mb_substr('تنفيذ فاشل: ' . $e->getMessage(), 0, 500),
                    'decided_at' => now(),
                ]);
            });
            throw $e;
        }
    }

    /** المعاملة الرئيسية: قفل + فحوصات + تنفيذ + اعتماد — ذرّياً. */
    private function approveTx(User $checker, int $requestId, ?string $note): ApprovalRequest
    {
        return DB::transaction(function () use ($checker, $requestId, $note) {
            /** @var ApprovalRequest $req */
            $req = ApprovalRequest::where('id', $requestId)->lockForUpdate()->firstOrFail();

            if ($req->status !== 'pending') {
                throw new \DomainException('NOT_PENDING');
            }
            if ((int) $req->maker_admin_id === (int) $checker->id) {
                throw new \DomainException('SELF_APPROVAL_FORBIDDEN');
            }
            if ($req->expires_at && $req->expires_at->isPast()) {
                // سباق نادر: انتهى بين الفحص والقفل — يُعلَّم في الطلب التالي
                throw new \DomainException('EXPIRED');
            }

            $this->execute($req);

            $req->update([
                'status' => 'approved',
                'checker_admin_id' => $checker->id,
                'checker_note' => $note,
                'decided_at' => now(),
                'executed_at' => now(),
            ]);

            $this->audit->record([
                'actor_type' => 'admin',
                'actor_user_id' => $checker->id,
                'subject_type' => 'user',
                'subject_id' => (string) $req->subject_user_id,
                'action' => 'APPROVAL_GRANTED',
                'decision_code' => strtoupper($req->action_type),
                'reason' => $note ?: 'معتمد',
                'severity' => 'warning',
                'context' => [
                    'request_number' => $req->request_number,
                    'maker_admin_id' => $req->maker_admin_id,
                ],
            ]);

            return $req->fresh();
        });
    }

    /** رفض الطلب (بدون تنفيذ). */
    public function reject(User $checker, int $requestId, string $note): ApprovalRequest
    {
        return DB::transaction(function () use ($checker, $requestId, $note) {
            /** @var ApprovalRequest $req */
            $req = ApprovalRequest::where('id', $requestId)->lockForUpdate()->firstOrFail();

            if ($req->status !== 'pending') {
                throw new \DomainException('NOT_PENDING');
            }
            if ((int) $req->maker_admin_id === (int) $checker->id) {
                throw new \DomainException('SELF_APPROVAL_FORBIDDEN');
            }

            $req->update([
                'status' => 'rejected',
                'checker_admin_id' => $checker->id,
                'checker_note' => $note,
                'decided_at' => now(),
            ]);

            $this->audit->record([
                'actor_type' => 'admin',
                'actor_user_id' => $checker->id,
                'subject_type' => 'user',
                'subject_id' => (string) $req->subject_user_id,
                'action' => 'APPROVAL_REJECTED',
                'decision_code' => strtoupper($req->action_type),
                'reason' => $note,
                'severity' => 'notice',
                'context' => ['request_number' => $req->request_number],
            ]);

            return $req->fresh();
        });
    }

    /** التنفيذ الفعلي للإجراء المعتمد. */
    private function execute(ApprovalRequest $req): void
    {
        $user = User::findOrFail($req->subject_user_id);

        match ($req->action_type) {
            'unfreeze_wallet' => tap($user, function (User $u) {
                $u->is_temp_blocked = false;
                $u->temp_block_time = null;
                $u->save();
            }),
            'reset_pin' => tap($user, function (User $u) {
                $u->transaction_pin = null;
                $u->requires_pin_setup = true;
                $u->pin_failed_attempts = 0;
                $u->pin_locked_until = null;
                $u->save();
            }),
            default => throw new \InvalidArgumentException("منفّذ غير معروف: {$req->action_type}"),
        };
    }
}
