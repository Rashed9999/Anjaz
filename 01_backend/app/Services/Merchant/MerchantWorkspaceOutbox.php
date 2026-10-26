<?php

namespace App\Services\Merchant;

use App\Models\{Merchant, MerchantProfile, MuwathaqAccountOpeningApplication, User};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Commercial access messages only. Never creates accounts, wallets, or approvals. */
final class MerchantWorkspaceOutbox
{
    private int $failedDeliveries = 0;

    public function failedDeliveries(): int { return $this->failedDeliveries; }

    public function recordFor(User $owner): void
    {
        DB::transaction(function () use ($owner): void {
            // Serialize snapshots for this merchant, including concurrent approvals/reconciliation.
            $merchant = Merchant::where('user_id', $owner->id)->lockForUpdate()->first();
            $access = app(MerchantWorkspaceAccess::class);
            $opening = $access->latestOpening($owner);
            if (! $merchant || ! $merchant->merchant_number || ! $merchant->store_name) {
                return; // Historical merchants need an explicit approval mapping; never manufacture one.
            }
            $tenant = 'legacy_merchant_'.$merchant->getKey();
            $branch = $tenant.'_main';
            $approved = $access->approved($opening);
            $active = $approved && $access->operational($owner->fresh())
                && MerchantProfile::where('user_id', $owner->id)->where('verification_status', 'verified')->exists();
            $state = $active ? 'active' : 'suspended';
            $last = DB::table('merchant_workspace_events')->where('tenant_id', $tenant)->latest('id')->first();
            if (! $last) {
                if (! $approved) return;
                $this->append($tenant, $branch, 'provision', $state, [
                    'access_status' => $state,
                    'tenant_id' => $tenant, 'merchant_number' => (string) $merchant->merchant_number,
                    'approval_event_id' => (string) $opening->application_ulid,
                    'approval_source' => 'muwathaq_approved',
                    'approved_at' => $opening->muwathaq_decided_at->toIso8601String(),
                    'owner_amial_account_id' => (string) $owner->id, 'approved' => true,
                    'branch_id' => $branch, 'branch_name' => (string) $merchant->store_name,
                ]);
                return;
            }
            if (($last->state ?? 'active') !== $state) {
                $this->append($tenant, $branch, 'lifecycle', $state, ['status' => $state]);
            }
        }, 3);
    }

    private function append(string $tenant, string $branch, string $kind, string $state, array $payload): void
    {
        $event = (string) Str::uuid();
        $context = ['actor_type' => 'system', 'actor_id' => 'amial-pay', 'tenant_id' => $tenant,
            'branch_ids' => [$branch], 'scopes' => [$kind === 'provision' ? 'merchant.provision' : 'merchant.lifecycle']];
        $id = DB::table('merchant_workspace_events')->insertGetId([
            'event_id' => $event, 'tenant_id' => $tenant, 'kind' => $kind, 'state' => $state,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'actor_context' => json_encode($context, JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($kind === 'lifecycle') {
            DB::table('merchant_workspace_events')->where('id', $id)->update([
                'payload' => json_encode($payload + ['event_id' => $event, 'sequence' => $id], JSON_THROW_ON_ERROR),
            ]);
        }
    }

    /** Preserve order per merchant; a failed merchant must not block other merchants. */
    public function deliverPending(int $limit = 100): int
    {
        $this->failedDeliveries = 0;
        if (! config('amial.merchant_workspace.enabled', false)) return 0;
        $delivered = 0;
        $blocked = [];
        $afterId = 0;
        for ($i = 0; $i < max(1, min(500, $limit)); $i++) {
            $result = DB::transaction(function () use ($blocked, $afterId): ?array {
                $row = DB::table('merchant_workspace_events')->whereNull('delivered_at')
                    ->where('id', '>', $afterId)->whereNotIn('tenant_id', $blocked)
                    ->orderBy('id')->lockForUpdate()->first();
                if (! $row) return null;
                $path = $row->kind === 'provision' ? '/api/v1/merchant/provision'
                    : '/api/v1/merchant/tenants/'.$row->tenant_id.'/lifecycle';
                try {
                    $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
                    if ($row->kind === 'provision' && ($payload['access_status'] ?? 'active') === 'active') {
                        $owner = User::find($payload['owner_amial_account_id']);
                        // An old active snapshot may narrow access at delivery, never widen it.
                        // Replays cannot reactivate an existing tenant: lifecycle owns that transition.
                        if (! $owner || ! app(MerchantWorkspaceAccess::class)->permitsLogin($owner)
                            || ! MerchantProfile::where('user_id', $owner->id)->where('verification_status', 'verified')->exists()) {
                            $payload['access_status'] = 'suspended';
                        }
                    }
                    $response = app(MerchantWorkspaceBridge::class)->deliverEvent($path,
                        $payload,
                        json_decode($row->actor_context, true, 512, JSON_THROW_ON_ERROR));
                    $expected = $row->kind === 'provision' ? 'MERCHANT_PROVISIONED' : 'MERCHANT_LIFECYCLE_ACCEPTED';
                    if (($response['code'] ?? '') !== $expected || ($response['tenant_id'] ?? '') !== $row->tenant_id) {
                        throw new \RuntimeException('Invalid merchant acknowledgement');
                    }
                    DB::table('merchant_workspace_events')->where('id', $row->id)->update([
                        'attempts' => $row->attempts + 1, 'delivered_at' => now(), 'failure_code' => null, 'updated_at' => now(),
                    ]);
                    return ['delivered' => true, 'tenant' => $row->tenant_id, 'id' => $row->id];
                } catch (\Throwable $e) {
                    // Do not persist provider bodies, signed URLs, passwords or exception messages.
                    DB::table('merchant_workspace_events')->where('id', $row->id)->update([
                        'attempts' => $row->attempts + 1, 'failure_code' => 'DELIVERY_FAILED', 'updated_at' => now(),
                    ]);
                    return ['delivered' => false, 'tenant' => $row->tenant_id, 'id' => $row->id];
                }
            }, 1);
            if ($result === null) break;
            $afterId = $result['id'];
            if ($result['delivered']) $delivered++;
            else { $blocked[] = $result['tenant']; $this->failedDeliveries++; }
        }
        return $delivered;
    }
}
