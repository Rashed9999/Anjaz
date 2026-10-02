<?php

namespace App\Services\Merchant;

use App\Models\Merchant\PosDeviceSession;
use App\Models\Merchant\PrintJob;
use App\Models\Merchant\PrinterProfile;
use App\Models\PosUser;
use App\Models\User;
use App\Services\AuditService;
use App\Support\Access\AccessConstants as A;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class MerchantPrintTrackingService
{
    public function __construct(private readonly AuditService $audit) {}

    public function report(User $actor, array $payload): PrintJob
    {
        [$merchant, $pos] = $this->merchantContext($actor);
        $session = $this->deviceSession($merchant->id, $actor->id);

        return DB::transaction(function () use ($actor, $merchant, $pos, $session, $payload) {
            $clientId = trim((string) $payload['client_job_id']);
            $storageClientId = $merchant->id.':'.$clientId;

            $existing = PrintJob::where('client_job_id', $storageClientId)
                ->where('merchant_user_id', $merchant->id)
                ->first();
            if ($existing) {
                return $existing;
            }

            $printer = $payload['printer'];
            $identity = trim((string) ($printer['connection_identity'] ?? ''));
            if ($identity === '') {
                throw new RuntimeException('هوية اتصال الطابعة مطلوبة لتسجيل نتيجة الطباعة');
            }

            $endpointHash = hash('sha256',
                $merchant->id.'|'.($session?->pos_device_id ?? 0).'|'
                .($printer['connection_type'] ?? '').'|'.$identity
            );
            $hint = $this->endpointHint(
                (string) ($printer['connection_type'] ?? ''),
                $identity,
            );

            $profile = PrinterProfile::where('merchant_user_id', $merchant->id)
                ->where('pos_device_id', $session?->pos_device_id)
                ->where('endpoint_hash', $endpointHash)
                ->lockForUpdate()
                ->first();

            if (! $profile) {
                $profile = PrinterProfile::create([
                    'profile_ulid' => (string) Str::ulid(),
                    'merchant_user_id' => $merchant->id,
                    'branch_id' => $pos?->branch_id ?? $session?->device?->branch_id,
                    'pos_device_id' => $session?->pos_device_id,
                    'name' => trim((string) ($printer['name'] ?? 'طابعة POS')) ?: 'طابعة POS',
                    'printer_type' => (string) ($printer['printer_type'] ?? 'thermal'),
                    'connection_type' => (string) ($printer['connection_type'] ?? 'bluetooth'),
                    'paper_size' => (string) ($printer['paper_size'] ?? '80mm'),
                    'endpoint_hash' => $endpointHash,
                    'endpoint_hint' => $hint,
                    'is_default' => true,
                    'status' => 'active',
                    'capabilities' => $printer['capabilities'] ?? [],
                    'settings' => $printer['settings'] ?? [],
                    'last_seen_at' => now(),
                ]);
            } else {
                $profile->fill([
                    'branch_id' => $pos?->branch_id ?? $session?->device?->branch_id,
                    'name' => trim((string) ($printer['name'] ?? $profile->name)) ?: $profile->name,
                    'printer_type' => (string) ($printer['printer_type'] ?? $profile->printer_type),
                    'connection_type' => (string) ($printer['connection_type'] ?? $profile->connection_type),
                    'paper_size' => (string) ($printer['paper_size'] ?? $profile->paper_size),
                    'endpoint_hint' => $hint,
                    'capabilities' => $printer['capabilities'] ?? $profile->capabilities,
                    'settings' => $printer['settings'] ?? $profile->settings,
                    'last_seen_at' => now(),
                ]);
            }

            $status = (string) $payload['status'];
            if ($status === 'completed') {
                $profile->status = 'active';
                $profile->last_success_at = now();
            } elseif ($status === 'failed') {
                $profile->status = 'degraded';
                $profile->last_failure_at = now();
            }
            $profile->save();

            $errorMessage = isset($payload['error_message'])
                ? mb_substr((string) $payload['error_message'], 0, 1000)
                : null;
            $errorCode = $payload['error_code'] ?? $this->errorCodeFrom($errorMessage);

            $queuedAt = $this->time($payload['queued_at'] ?? null);
            $startedAt = $this->time($payload['started_at'] ?? null);
            $finishedAt = $this->time($payload['finished_at'] ?? null) ?? now();

            $job = PrintJob::create([
                'job_ulid' => (string) Str::ulid(),
                'client_job_id' => $storageClientId,
                'merchant_user_id' => $merchant->id,
                'branch_id' => $pos?->branch_id ?? $profile->branch_id,
                'pos_user_id' => $pos?->id,
                'pos_device_id' => $session?->pos_device_id,
                'printer_profile_id' => $profile->id,
                'requested_by_user_id' => $actor->id,
                'document_type' => (string) $payload['document_type'],
                'document_id' => $payload['document_id'] ?? null,
                'document_number' => $payload['document_number'] ?? null,
                'status' => $status,
                'copies' => max(1, min(20, (int) ($payload['copies'] ?? 1))),
                'retry_count' => max(0, min(100, (int) ($payload['retry_count'] ?? 0))),
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'result_message' => isset($payload['result_message'])
                    ? mb_substr((string) $payload['result_message'], 0, 1000)
                    : null,
                'queued_at' => $queuedAt,
                'started_at' => $startedAt,
                'completed_at' => $status === 'completed' ? $finishedAt : null,
                'failed_at' => $status === 'failed' ? $finishedAt : null,
                'metadata' => $payload['metadata'] ?? [],
            ]);

            $this->audit->record([
                'actor_type' => $pos ? 'pos' : 'merchant',
                'actor_user_id' => $actor->id,
                'subject_type' => 'print_job',
                'subject_id' => $job->job_ulid,
                'action' => $status === 'completed'
                    ? 'DOCUMENT_PRINT_COMPLETED'
                    : 'DOCUMENT_PRINT_FAILED',
                'decision_code' => strtoupper($status),
                'severity' => $status === 'failed' ? 'warning' : 'info',
                'reason' => $errorMessage,
                'context' => [
                    'merchant_user_id' => $merchant->id,
                    'branch_id' => $job->branch_id,
                    'pos_user_id' => $job->pos_user_id,
                    'pos_device_id' => $job->pos_device_id,
                    'printer_profile_id' => $profile->id,
                    'document_type' => $job->document_type,
                    'document_id' => $job->document_id,
                    'copies' => $job->copies,
                    'error_code' => $job->error_code,
                ],
            ]);

            return $job->fresh(['printer']);
        });
    }

    public function dashboard(User $merchant, int $days = 30): array
    {
        $days = max(1, min(90, $days));
        $from = now()->subDays($days - 1)->startOfDay();

        $profiles = PrinterProfile::where('merchant_user_id', $merchant->id)
            ->with(['branch:id,name', 'device:id,display_name'])
            ->orderByDesc('last_seen_at')
            ->get()
            ->map(fn (PrinterProfile $p) => [
                'id' => $p->id,
                'profile_ulid' => $p->profile_ulid,
                'name' => $p->name,
                'printer_type' => $p->printer_type,
                'connection_type' => $p->connection_type,
                'paper_size' => $p->paper_size,
                'endpoint_hint' => $p->endpoint_hint,
                'status' => $p->status,
                'is_default' => (bool) $p->is_default,
                'branch_name' => $p->branch?->name,
                'device_name' => $p->device?->display_name,
                'capabilities' => $p->capabilities ?? [],
                'settings' => $p->settings ?? [],
                'last_seen_at' => $p->last_seen_at?->toIso8601String(),
                'last_success_at' => $p->last_success_at?->toIso8601String(),
                'last_failure_at' => $p->last_failure_at?->toIso8601String(),
            ])->all();

        $base = PrintJob::where('merchant_user_id', $merchant->id)
            ->where('created_at', '>=', $from);
        $total = (clone $base)->count();
        $completed = (clone $base)->where('status', 'completed')->count();
        $failed = (clone $base)->where('status', 'failed')->count();

        $jobs = PrintJob::where('merchant_user_id', $merchant->id)
            ->with([
                'printer:id,name',
                'branch:id,name',
                'posUser:id,display_name',
                'device:id,display_name',
            ])
            ->orderByDesc('id')->limit(50)->get()
            ->map(fn (PrintJob $j) => [
                'job_ulid' => $j->job_ulid,
                'document_type' => $j->document_type,
                'document_id' => $j->document_id,
                'document_number' => $j->document_number,
                'status' => $j->status,
                'copies' => $j->copies,
                'error_code' => $j->error_code,
                'error_message' => $j->error_message,
                'result_message' => $j->result_message,
                'printer_name' => $j->printer?->name,
                'branch_name' => $j->branch?->name,
                'employee_name' => $j->posUser?->display_name,
                'device_name' => $j->device?->display_name,
                'created_at' => $j->created_at?->toIso8601String(),
                'completed_at' => $j->completed_at?->toIso8601String(),
                'failed_at' => $j->failed_at?->toIso8601String(),
            ])->all();

        return [
            'days' => $days,
            'profiles' => $profiles,
            'jobs' => $jobs,
            'stats' => [
                'total' => $total,
                'completed' => $completed,
                'failed' => $failed,
                'failure_rate' => $total > 0 ? round(($failed / $total) * 100, 2) : 0.0,
                'queue_length' => (clone $base)
                    ->whereIn('status', ['queued', 'printing', 'retrying'])->count(),
            ],
        ];
    }

    /** @return array{0:User,1:?PosUser} */
    private function merchantContext(User $actor): array
    {
        if ($actor->role === A::ROLE_MERCHANT || (int) $actor->type === MERCHANT_TYPE) {
            return [$actor, null];
        }

        $pos = PosUser::where('user_id', $actor->id)->where('is_active', true)->first();
        if (! $pos) {
            throw new RuntimeException('الطباعة التشغيلية متاحة للتاجر أو موظف POS فقط');
        }

        $merchant = User::find($pos->merchant_user_id);
        if (! $merchant) throw new RuntimeException('منشأة موظف نقطة البيع غير موجودة');

        return [$merchant, $pos];
    }

    private function deviceSession(int $merchantUserId, int $actorUserId): ?PosDeviceSession
    {
        return PosDeviceSession::with('device')
            ->where('merchant_user_id', $merchantUserId)
            ->where('actor_user_id', $actorUserId)
            ->whereNull('ended_at')
            ->latest('id')
            ->first();
    }

    private function endpointHint(string $connection, string $identity): string
    {
        if ($connection === 'bluetooth') {
            return '••••'.mb_substr($identity, -5);
        }

        return mb_substr($identity, 0, 120);
    }

    private function errorCodeFrom(?string $message): ?string
    {
        if (! $message) return null;
        return preg_match('/\b(PRINT_\d{4})\b/', $message, $m) ? $m[1] : null;
    }

    private function time(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') return null;
        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
