<?php

namespace App\Services;

use App\Saher\Remediation\QuarantineAdvisor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Emergency containment, not a code fix. DB unique(route_name) prevents two
 * concurrent active records; every transition writes an immutable Saher event.
 */
final class SaherRouteQuarantineService
{
    public function __construct(private readonly QuarantineAdvisor $advisor) {}

    public function quarantine(object $finding, int $actorId, string $reason): bool
    {
        $plan = $this->advisor->assess($finding);
        if (! $plan['eligible']) {
            throw ValidationException::withMessages(['reason' => $plan['reason']]);
        }

        return DB::transaction(function () use ($finding, $actorId, $reason, $plan): bool {
            $now = now();
            $name = $plan['route_name'];
            $values = [
                'finding_id' => $finding->id,
                'finding_fingerprint' => $finding->fingerprint,
                'route_name' => $name,
                'status' => 'ACTIVE',
                'reason' => $reason,
                'created_by_user_id' => $actorId,
                'released_at' => null,
                'released_by_user_id' => null,
                'release_reason' => null,
                'updated_at' => $now,
            ];

            // Unique route_name + insertOrIgnore handles two simultaneous first clicks.
            $inserted = DB::table('saher_route_quarantines')
                ->insertOrIgnore($values + ['created_at' => $now]);

            if ($inserted === 0) {
                $existing = DB::table('saher_route_quarantines')
                    ->where('route_name', $name)->lockForUpdate()->first();

                if ($existing?->status === 'ACTIVE') {
                    return false; // Idempotent repeat, no duplicate audit event.
                }

                DB::table('saher_route_quarantines')->where('id', $existing->id)
                    ->update($values);
            }

            $this->event($finding->id, 'AUTOFIX_QUARANTINED', $reason, $actorId, $now);

            return true;
        }, 3);
    }

    public function release(object $finding, int $actorId, string $reason): bool
    {
        return DB::transaction(function () use ($finding, $actorId, $reason): bool {
            $record = DB::table('saher_route_quarantines')
                ->where('finding_id', $finding->id)->where('status', 'ACTIVE')
                ->lockForUpdate()->first();

            if (! $record) {
                return false;
            }

            $now = now();
            DB::table('saher_route_quarantines')->where('id', $record->id)->update([
                'status' => 'RELEASED',
                'released_at' => $now,
                'released_by_user_id' => $actorId,
                'release_reason' => $reason,
                'updated_at' => $now,
            ]);

            $this->event($finding->id, 'AUTOFIX_QUARANTINE_RELEASED', $reason, $actorId, $now);

            return true;
        }, 3);
    }

    private function event(int $findingId, string $name, string $reason, int $actorId, $now): void
    {
        DB::table('saher_finding_events')->insert([
            'finding_id' => $findingId,
            'event' => $name,
            'from_status' => null,
            'to_status' => null,
            'note' => $reason,
            'actor_user_id' => $actorId,
            'actor_type' => 'admin',
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
