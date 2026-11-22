<?php

namespace App\Services;

use App\Saher\Remediation\RemediationPlanner;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saher is diagnostic and must never write to financial, customer, merchant,
 * approval, auth, or deployment data as part of a repair request.
 */
final class SaherRemediationService
{
    public function __construct(private readonly RemediationPlanner $planner) {}

    public function open(object $finding, int $actorId, string $reason): bool
    {
        if (! in_array($finding->status, ['OPEN', 'REOPENED'], true)
            || ($finding->severity ?? 'INFO') === 'INFO') {
            throw ValidationException::withMessages([
                'reason' => 'لا يُفتح طلب إصلاح لاكتشاف مغلق أو إخباري فقط.',
            ]);
        }

        $plan = $this->planner->forFinding($finding);

        return DB::transaction(function () use ($finding, $actorId, $reason, $plan): bool {
            // Unique finding_id prevents duplicate repair tasks across concurrent clicks.
            $now = now();
            $inserted = DB::table('saher_remediation_cases')->insertOrIgnore([
                'finding_id' => $finding->id,
                'finding_fingerprint' => $finding->fingerprint,
                'rule_id' => $finding->rule_id,
                'strategy' => $plan['strategy'],
                'status' => 'OPEN',
                'plan' => json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'reason' => $reason,
                'created_by_user_id' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($inserted === 0) {
                return false;
            }

            DB::table('saher_finding_events')->insert([
                'finding_id' => $finding->id,
                'event' => 'REMEDIATION_CASE_CREATED',
                'from_status' => $finding->status,
                'to_status' => $finding->status,
                'note' => mb_substr($reason, 0, 2000),
                'actor_user_id' => $actorId,
                'actor_type' => 'admin',
                'occurred_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return true;
        }, 3);
    }
}
