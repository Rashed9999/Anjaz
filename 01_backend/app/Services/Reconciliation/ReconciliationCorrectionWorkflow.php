<?php

namespace App\Services\Reconciliation;

use App\Models\ReconciliationCase;
use App\Services\AuditService;
use App\Services\LedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Controlled four-eyes workflow for wallet reconciliation corrections.
 *
 * A proposal records the investigation only. A different authorised operator
 * must approve it before LedgerService can post the balanced adjustment.
 * No background job or deployment path calls this service.
 */
class ReconciliationCorrectionWorkflow
{
    public function propose(string $caseUlid, int $makerId, string $rootCause, string $evidenceReference): ReconciliationCase
    {
        return DB::transaction(function () use ($caseUlid, $makerId, $rootCause, $evidenceReference): ReconciliationCase {
            $case = $this->walletCaseForUpdate($caseUlid);

            if (in_array($case->status, ['corrected', 'closed'], true)) {
                throw ValidationException::withMessages(['case' => ['القضية أُغلقت سابقاً ولا تقبل تسوية جديدة.']]);
            }

            $case->forceFill([
                'status' => 'pending_approval',
                'maker_admin_id' => $makerId,
                'checker_admin_id' => null,
                'root_cause' => $rootCause,
                'action_taken' => 'اقتراح تسوية بانتظار مراجع مستقل.',
                'evidence' => array_merge((array) $case->evidence, [
                    'correction_proposal' => [
                        'root_cause' => $rootCause,
                        'evidence_reference' => $evidenceReference,
                        'proposed_by' => $makerId,
                        'proposed_at' => now()->toIso8601String(),
                    ],
                ]),
                'resolved_at' => null,
            ])->save();

            $this->audit($case, $makerId, 'RECONCILIATION_CORRECTION_PROPOSED', 'PENDING_INDEPENDENT_APPROVAL');

            return $case;
        });
    }

    public function approve(string $caseUlid, int $checkerId, string $approvalNote, LedgerService $ledger): ?\App\Models\Ledger\LedgerJournalEntry
    {
        return DB::transaction(function () use ($caseUlid, $checkerId, $approvalNote, $ledger): ?\App\Models\Ledger\LedgerJournalEntry {
            $case = $this->walletCaseForUpdate($caseUlid);

            if ($case->status !== 'pending_approval' || ! $case->maker_admin_id) {
                throw ValidationException::withMessages(['case' => ['القضية ليست في انتظار اعتماد مستقل.']]);
            }
            if ((int) $case->maker_admin_id === $checkerId) {
                throw ValidationException::withMessages(['case' => ['لا يجوز لمقدّم التسوية اعتمادها.']]);
            }

            $case->forceFill([
                'checker_admin_id' => $checkerId,
                'action_taken' => $approvalNote,
            ])->save();

            $entry = $ledger->reconcileWalletBalance(
                (int) $case->subject_user_id,
                'تسوية قضية مطابقة معتمدة: ' . $case->root_cause,
                [
                    'case_ulid' => $case->case_ulid,
                    'maker_admin_id' => (int) $case->maker_admin_id,
                    'checker_admin_id' => $checkerId,
                    'approval_note' => $approvalNote,
                ],
            );

            if ($entry === null) {
                // A vanished difference is evidence to verify, not a reason to
                // silently close the case: a direct database change may have
                // hidden the original variance.
                $case->forceFill([
                    'status' => 'verifying',
                    'action_taken' => $approvalNote . ' — لم يعد فرق ظاهر؛ بانتظار تحقق مستقل قبل الإغلاق.',
                ])->save();
            }

            $this->audit(
                $case,
                $checkerId,
                $entry ? 'RECONCILIATION_CORRECTION_APPROVED' : 'RECONCILIATION_CORRECTION_REQUIRES_VERIFICATION',
                $entry ? 'BALANCED_ADJUSTMENT_POSTED' : 'NO_VARIANCE_AT_APPROVAL'
            );

            return $entry;
        });
    }

    private function walletCaseForUpdate(string $caseUlid): ReconciliationCase
    {
        $case = ReconciliationCase::query()
            ->where('case_ulid', $caseUlid)
            ->lockForUpdate()
            ->first();

        if (! $case || $case->case_type !== 'wallet' || ! $case->subject_user_id) {
            throw ValidationException::withMessages(['case' => ['قضية محفظة صالحة غير موجودة.']]);
        }

        return $case;
    }

    private function audit(ReconciliationCase $case, int $actorId, string $action, string $decision): void
    {
        app(AuditService::class)->record([
            'actor_type' => 'admin',
            'actor_user_id' => $actorId,
            'subject_type' => 'reconciliation_case',
            'subject_id' => $case->case_ulid,
            'action' => $action,
            'decision_code' => $decision,
            'severity' => 'high',
            'context' => [
                'case_type' => $case->case_type,
                'status' => $case->status,
                'maker_admin_id' => $case->maker_admin_id,
                'checker_admin_id' => $case->checker_admin_id,
            ],
        ]);
    }
}
