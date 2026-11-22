<?php

namespace App\Services;

use App\Exceptions\FeatureHasFundsException;
use App\Exceptions\FeatureBalanceUnknownException;
use App\Models\FeatureFlag;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Real operations only. A control without a server-side feature guard is
 * READ-ONLY and must never pretend that a toggle has disabled a service.
 * The same feature flag powers both old maintenance endpoints and this UI.
 */
final class ControlCenterService
{
    public function __construct(
        private readonly FeatureFlagService $features,
        private readonly AuditService $audit,
    ) {}

    /** @return list<string> */
    public function guardedFeatures(): array
    {
        $keys = [];
        foreach (Route::getRoutes() as $route) {
            // A control is real only if a write route is protected by this flag.
            if (array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) === []) {
                continue;
            }
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'feature:')) {
                    continue;
                }
                $key = trim(substr($middleware, strlen('feature:')));
                if ($key !== '') {
                    $keys[$key] = true;
                }
            }
        }
        // Approved production scope: the Safe Payment create endpoint only.
        // Other flags are visible in the catalog but NOT actionable until
        // their new-operation routes and lifecycle exceptions are reviewed.
        $approved = [];
        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();
            if (! str_ends_with($name, 'amial.safe-pay.create')) {
                continue;
            }
            if (isset($keys['safe_payment']) && in_array('POST', $route->methods(), true)
                && in_array('feature:safe_payment', $route->gatherMiddleware(), true)) {
                $approved[] = 'safe_payment';
            }
        }
        return $approved;
    }

    public function needsApproval(FeatureFlag $flag): bool
    {
        return (bool) $flag->is_core
            || in_array((string) $flag->category, ['financial', 'merchant'], true);
    }

    public function overview(): array
    {
        $guarded = array_fill_keys($this->guardedFeatures(), true);
        $safePolicyMode = \Illuminate\Support\Facades\Schema::hasTable('control_center_policies')
            ? DB::table('control_center_policies')->where('feature_key', 'safe_payment')->value('mode')
            : null;

        return array_map(function (array $f) use ($guarded, $safePolicyMode): array {
            $connected = isset($guarded[$f['key']]);
            $f['connected'] = $connected;
            $f['approval_required'] = (bool) $f['is_core']
                || in_array((string) $f['category'], ['financial', 'merchant'], true);
            $f['can_control'] = $connected;
            if ($f['key'] === 'safe_payment') {
                // This is the effective INTAKE state, not the ledger settlement state.
                $f['mode'] = $safePolicyMode;
                $f['enabled'] = $f['enabled'] && $safePolicyMode === 'RUNNING';
                // Pausing new intake does not strand a funded payment.
                $f['can_disable'] = $connected && $safePolicyMode === 'RUNNING';
            }
            return $f;
        }, $this->features->overview());
    }

    /**
     * @return array{state:string,request_id:?int}
     */
    public function request(User $actor, string $key, bool $enable, string $reason): array
    {
        if (mb_strlen(trim($reason)) < 20 || mb_strlen($reason) > 1000) {
            throw new \DomainException('REASON_REQUIRED');
        }

        return DB::transaction(function () use ($actor, $key, $enable, $reason): array {
            $flag = FeatureFlag::where('key', $key)->lockForUpdate()->firstOrFail();
            $this->assertConnected($key);

            // Safe Payment is a narrowly scoped PAUSE_NEW operation. Existing
            // payments, refunds and disputes remain active; funds need not
            // reach zero before pausing new intake.
            $currentEnabled = (bool) $flag->enabled;
            $policy = null;
            if ($key === 'safe_payment') {
                $policy = DB::table('control_center_policies')->where('feature_key', $key)
                    ->lockForUpdate()->first();
                if (! $policy || ! in_array($policy->mode, ['RUNNING', 'PAUSE_NEW'], true)) {
                    throw new \DomainException('CONTROL_POLICY_NOT_READY');
                }
                $currentEnabled = $currentEnabled && $policy->mode === 'RUNNING';
            }

            if ($currentEnabled === $enable) {
                return ['state' => 'unchanged', 'request_id' => null];
            }

            // Full disable (other features) still requires zero verified funds.
            // Safe Payment PAUSE_NEW does not touch existing funded payments.
            if (! $enable && $key !== 'safe_payment') {
                $this->assertSafeToDisable($key);
            }

            if ($this->needsApproval($flag)) {
                $existing = DB::table('control_center_requests')
                    ->where('feature_key', $key)
                    ->where('status', 'pending')
                    ->where('expires_at', '>', now())
                    ->lockForUpdate()->first();

                if ($existing) {
                    if ((bool) $existing->desired_enabled !== $enable) {
                        throw new \DomainException('PENDING_CONFLICT');
                    }
                    return ['state' => 'pending', 'request_id' => (int) $existing->id];
                }

                $id = DB::table('control_center_requests')->insertGetId([
                    'feature_key' => $key,
                    'expected_enabled' => $currentEnabled,
                    'expected_revision' => $policy?->revision,
                    'desired_enabled' => $enable,
                    'status' => 'pending',
                    'requested_by' => $actor->id,
                    'reason' => trim($reason),
                    'expires_at' => now()->addDay(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->event($key, 'REQUESTED', $actor->id, trim($reason), $id);
                return ['state' => 'pending', 'request_id' => (int) $id];
            }

            $this->apply($actor, $key, $enable, $reason);
            $this->event($key, $enable ? 'ENABLED' : 'DISABLED', $actor->id, trim($reason));
            return ['state' => 'applied', 'request_id' => null];
        }, 3);
    }

    public function decide(User $checker, int $requestId, bool $approve, string $reason): string
    {
        if (mb_strlen(trim($reason)) < 20 || mb_strlen($reason) > 1000) {
            throw new \DomainException('REASON_REQUIRED');
        }

        return DB::transaction(function () use ($checker, $requestId, $approve, $reason): string {
            $request = DB::table('control_center_requests')->where('id', $requestId)
                ->lockForUpdate()->first();
            if (! $request || $request->status !== 'pending') {
                throw new \DomainException('NOT_PENDING');
            }
            if ((int) $request->requested_by === (int) $checker->id) {
                throw new \DomainException('SELF_APPROVAL_FORBIDDEN');
            }
            if (now()->greaterThan($request->expires_at)) {
                throw new \DomainException('EXPIRED');
            }

            $flag = FeatureFlag::where('key', $request->feature_key)->lockForUpdate()->firstOrFail();
            if (! $this->needsApproval($flag)) {
                throw new \DomainException('POLICY_CHANGED');
            }
            $this->assertConnected($flag->key);

            $currentEnabled = (bool) $flag->enabled;
            $policy = null;
            if ($flag->key === 'safe_payment') {
                $policy = DB::table('control_center_policies')
                    ->where('feature_key', $flag->key)->lockForUpdate()->first();
                if (! $policy || ! in_array($policy->mode, ['RUNNING', 'PAUSE_NEW'], true)) {
                    throw new \DomainException('CONTROL_POLICY_NOT_READY');
                }
                $currentEnabled = $currentEnabled && $policy->mode === 'RUNNING';
                // Old v1 approval requests cannot silently change semantics to
                // an intake policy. Any revision change invalidates the request.
                if ($approve && ($request->expected_revision === null
                    || (int) $request->expected_revision !== (int) $policy->revision)) {
                    throw new \DomainException('STALE_POLICY_REVISION');
                }
            }

            if ($approve && $currentEnabled !== (bool) $request->expected_enabled) {
                throw new \DomainException('STALE_STATE');
            }

            if ($approve) {
                if ($flag->key === 'safe_payment') {
                    $mode = $request->desired_enabled ? 'RUNNING' : 'PAUSE_NEW';
                    DB::table('control_center_policies')
                        ->where('feature_key', $flag->key)
                        ->where('revision', $policy->revision)
                        ->update([
                            'mode' => $mode, 'revision' => $policy->revision + 1,
                            'changed_by' => $checker->id, 'reason' => $request->reason,
                            'updated_at' => now(),
                        ]);
                    // A previously disabled legacy flag must explicitly
                    // be re-enabled only via the second approver.
                    if ($request->desired_enabled && ! $flag->enabled) {
                        $this->features->enable($checker, $flag->key, $request->reason);
                    }
                } else {
                    if (! $request->desired_enabled) {
                        $this->assertSafeToDisable($flag->key);
                    }
                    $this->apply($checker, $flag->key, (bool) $request->desired_enabled, $request->reason);
                }
            }

            $status = $approve ? 'approved' : 'rejected';
            DB::table('control_center_requests')->where('id', $requestId)->update([
                'status' => $status,
                'reviewed_by' => $checker->id,
                'review_note' => trim($reason),
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);
            $this->event($flag->key, strtoupper($status), $checker->id, trim($reason), $requestId);
            return $status;
        }, 3);
    }

    private function assertSafeToDisable(string $key): void
    {
        $out = $this->features->outstandingBalance($key);
        // Unknown balance is NOT zero. A broken DB query must stop the switch.
        if ((int) $out['count'] < 0) {
            throw new FeatureBalanceUnknownException($key);
        }
        if (bccomp((string) $out['amount'], '0', 4) !== 0) {
            throw new FeatureHasFundsException($key, (string) $out['amount'], (int) $out['count']);
        }
    }

    private function assertConnected(string $key): void
    {
        if (! in_array($key, $this->guardedFeatures(), true)) {
            throw new \DomainException('FEATURE_NOT_CONNECTED');
        }
    }

    private function apply(User $actor, string $key, bool $enable, string $reason): void
    {
        $enable
            ? $this->features->enable($actor, $key, $reason)
            : $this->features->disable($actor, $key, $reason);
    }

    private function event(string $key, string $action, int $actorId, string $reason, ?int $requestId = null): void
    {
        DB::table('control_center_events')->insert([
            'feature_key' => $key,
            'action' => $action,
            'actor_id' => $actorId,
            'request_id' => $requestId,
            'reason' => mb_substr($reason, 0, 1000),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $actorId,
            'subject_type' => 'feature',
            'subject_id' => $key,
            'action' => 'CONTROL_CENTER_'. $action,
            'decision_code' => strtoupper($key),
            'reason' => $reason,
            'severity' => 'warning',
            'context' => ['request_id' => $requestId],
        ]);
    }
}
