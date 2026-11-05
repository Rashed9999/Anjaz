<?php

namespace App\Services;

use App\Models\BillProvider;
use App\Models\BillService;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Administrative control plane for external bill providers. It performs no
 * direct wallet changes; activation and configuration are audited here, while
 * payment state remains exclusively in BillPayService.
 */
class BillProviderAdminService
{
    public function __construct(
        private readonly BillPayService $billPay,
        private readonly AuditService $audit,
    ) {}

    /** @param array<string, mixed> $input */
    public function configure(BillProvider $provider, array $input, User $actor, string $reason, ?string $ip = null): BillProvider
    {
        $this->assertReason($reason);
        if ($provider->integration_type !== 'free_sadad') {
            throw new \RuntimeException('إعداد الاعتماد متاح لمزوّد Free Sadad فقط');
        }
        $before = $provider->adminSnapshot();

        $config = is_array($provider->config) ? $provider->config : [];
        $config['timeout_seconds'] = max(3, min((int) ($input['timeout_seconds'] ?? ($config['timeout_seconds'] ?? 15)), 60));
        if (!empty($input['endpoint_url'])) {
            $this->assertFreeSadadUrl((string) $input['endpoint_url']);
            $provider->endpoint_url = rtrim((string) $input['endpoint_url'], '/');
        }
        $provider->config = $config;
        $provider->replaceCredentials([
            'username' => $input['username'] ?? null,
            'account_number' => $input['account_number'] ?? null,
            'password' => $input['password'] ?? null,
            'api_token' => $input['api_token'] ?? null,
            'webhook_secret' => $input['webhook_secret'] ?? null,
        ]);
        $provider->integration_status = $provider->hasRequiredCredentials() ? 'configured' : 'not_configured';
        $provider->configured_at = $provider->hasRequiredCredentials() ? now() : null;
        // Any credential change invalidates the prior health result.
        $provider->is_active = false;
        $provider->last_health_message = 'تم تغيير الإعداد؛ أجرِ اختبار الرصيد قبل التفعيل';
        $provider->save();

        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $actor->id,
            'subject_type' => 'bill_provider',
            'subject_id' => (string) $provider->id,
            'action' => 'BILL_PROVIDER_CONFIGURED',
            'decision_code' => 'BILL_PROVIDER_CONFIG_UPDATED',
            'reason' => $reason,
            'severity' => 'notice',
            'context' => ['before' => $before, 'after' => $provider->adminSnapshot()],
            'zone_code' => $provider->zone_code,
        ]);

        return $provider->fresh();
    }

    public function refresh(BillProvider $provider, User $actor, ?string $ip = null): BillProvider
    {
        $result = $this->billPay->refreshProviderHealth($provider);
        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $actor->id,
            'subject_type' => 'bill_provider',
            'subject_id' => (string) $provider->id,
            'action' => 'BILL_PROVIDER_HEALTH_CHECK',
            'decision_code' => $result->integration_status === 'ready' ? 'BILL_PROVIDER_READY' : 'BILL_PROVIDER_DEGRADED',
            'severity' => $result->integration_status === 'ready' ? 'info' : 'warning',
            'context' => [
                'integration_status' => $result->integration_status,
                'balance_currency' => $result->balance_currency,
                'balance_checked_at' => $result->balance_checked_at?->toIso8601String(),
            ],
            'zone_code' => $result->zone_code,
        ]);
        return $result;
    }

    public function toggle(BillProvider $provider, User $actor, string $reason): BillProvider
    {
        $this->assertReason($reason);
        $activate = !$provider->is_active;
        if ($activate && !$provider->isReadyForPayments()) {
            throw new \RuntimeException('لا يمكن التفعيل قبل حفظ الاعتماد واختبار الرصيد بنجاح');
        }

        $before = $provider->adminSnapshot();
        $provider->is_active = $activate;
        $provider->save();
        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $actor->id,
            'subject_type' => 'bill_provider',
            'subject_id' => (string) $provider->id,
            'action' => $activate ? 'BILL_PROVIDER_ACTIVATED' : 'BILL_PROVIDER_DEACTIVATED',
            'decision_code' => $activate ? 'BILL_PROVIDER_ON' : 'BILL_PROVIDER_OFF',
            'reason' => $reason,
            'severity' => 'notice',
            'context' => ['before' => $before, 'after' => $provider->adminSnapshot()],
            'zone_code' => $provider->zone_code,
        ]);
        return $provider->fresh();
    }

    /** @param array<string, mixed> $input */
    public function saveServiceRouting(BillProvider $provider, ?BillService $service, array $input, User $actor, string $reason): BillService
    {
        $this->assertReason($reason);
        if ($provider->integration_type !== 'free_sadad') {
            throw new \RuntimeException('إعداد التوجيه متاح لمزوّد Free Sadad فقط');
        }

        $routing = [
            'network_number' => (int) $input['network_number'],
            'inquiry_service_number' => isset($input['inquiry_service_number']) && $input['inquiry_service_number'] !== ''
                ? (int) $input['inquiry_service_number'] : null,
            'payment_service_number' => (int) $input['payment_service_number'],
            'payment_mode' => in_array($input['payment_mode'] ?? 'amount', ['amount', 'offer'], true)
                ? ($input['payment_mode'] ?? 'amount') : 'amount',
            'offer_code' => trim((string) ($input['offer_code'] ?? '')) ?: null,
        ];

        return DB::transaction(function () use ($provider, $service, $input, $routing, $actor, $reason) {
            $service ??= new BillService(['provider_id' => $provider->id]);
            $service->fill([
                'provider_id' => $provider->id,
                'code' => trim((string) $input['code']),
                'name' => trim((string) $input['name']),
                'display_name_ar' => trim((string) $input['display_name_ar']),
                'service_type' => trim((string) $input['service_type']),
                'requires_account_number' => true,
                'is_active' => !empty($input['is_active']),
                'account_validation_rules' => [
                    'free_sadad' => $routing,
                    'regex' => $input['regex'] ?? null,
                ],
            ])->save();

            $this->audit->record([
                'actor_type' => 'admin',
                'actor_user_id' => $actor->id,
                'subject_type' => 'bill_service',
                'subject_id' => (string) $service->id,
                'action' => 'BILL_SERVICE_ROUTING_SAVED',
                'decision_code' => 'BILL_SERVICE_ROUTING_UPDATED',
                'reason' => $reason,
                'severity' => 'notice',
                'context' => ['provider_id' => $provider->id, 'routing' => $routing],
                'zone_code' => $provider->zone_code,
            ]);

            return $service;
        }, 3);
    }

    private function assertReason(string $reason): void
    {
        if (mb_strlen(trim($reason)) < 10) {
            throw new \RuntimeException('السبب إلزامي (10 أحرف على الأقل)');
        }
    }

    private function assertFreeSadadUrl(string $url): void
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? null) !== 'https' || ($host !== 'free-sadad.com' && !str_ends_with($host, '.free-sadad.com'))) {
            throw new \RuntimeException('يجب أن يكون عنوان Free Sadad HTTPS وعلى نطاق free-sadad.com');
        }
    }
}
