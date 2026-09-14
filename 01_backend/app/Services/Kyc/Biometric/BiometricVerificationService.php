<?php

namespace App\Services\Kyc\Biometric;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Kyc\KycPrivacyService;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * AMIAL-KYC-BIOMETRIC-RUNTIME-002 — تشغيل المزود بلا فتح باب اعتماد ثانٍ.
 *
 * النتيجة الناجحة هنا تجعل دليل الملكية evidence_ready فقط. لا يكتب هذا
 * الصنف users.is_kyc_verified ولا يستدعي قرار اعتماد الحساب؛ القرار يبقى
 * في KycDocumentService والحارس المركزي نفسه.
 */
final class BiometricVerificationService
{
    public function __construct(
        private readonly BiometricProviderManager $providers,
        private readonly KycPrivacyService $privacy,
        private readonly AuditService $audit,
    ) {}

    /** @return array<string,mixed> */
    public function start(User $user): array
    {
        $this->assertSchema();
        $state = $this->privacy->forUser($user);

        if (($state['review_mode'] ?? null) !== KycPrivacyService::MODE_AUTOMATED) {
            throw new DomainException('KYC_BIOMETRIC_MODE_REQUIRED');
        }

        $driver = $this->providers->selectedDriver();
        $provider = $this->providers->selectedAlias();
        if (!hash_equals($provider, $driver->name())) {
            throw new DomainException('KYC_BIOMETRIC_PROVIDER_ALIAS_MISMATCH');
        }

        $cooldown = max(30, (int) config('amial_kyc.biometric.restart_cooldown_seconds', 120));
        $active = DB::table('kyc_biometric_attempts')
            ->where('user_id', $user->id)
            ->whereIn('status', ['starting', 'pending'])
            ->where('started_at', '>=', now()->subSeconds($cooldown))
            ->exists();

        if ($active) {
            throw new DomainException('KYC_BIOMETRIC_ATTEMPT_ALREADY_ACTIVE');
        }

        // أي محاولة قديمة معلقة لا يجوز أن ينافس callback الخاص بها محاولة جديدة.
        DB::table('kyc_biometric_attempts')
            ->where('user_id', $user->id)
            ->whereIn('status', ['starting', 'pending'])
            ->update(['status' => 'superseded', 'updated_at' => now()]);

        $attemptUlid = (string) Str::ulid();
        $ttlMinutes = max(5, (int) config('amial_kyc.biometric.attempt_ttl_minutes', 30));
        $attemptId = DB::table('kyc_biometric_attempts')->insertGetId([
            'attempt_ulid' => $attemptUlid,
            'user_id' => (int) $user->id,
            'provider' => $provider,
            'provider_reference' => null,
            'status' => 'starting',
            'liveness_status' => 'pending',
            'liveness_score' => null,
            'face_match_status' => 'pending',
            'face_match_score' => null,
            'result_code' => null,
            'started_at' => now(),
            'completed_at' => null,
            'expires_at' => now()->addMinutes($ttlMinutes),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $launch = $driver->start($user, $attemptUlid);
            $expiry = $this->parseTimestamp($launch->expiresAt) ?? now()->addMinutes($ttlMinutes);

            DB::transaction(function () use ($attemptId, $user, $provider, $launch, $expiry): void {
                DB::table('kyc_biometric_attempts')->where('id', $attemptId)->lockForUpdate()->update([
                    'provider_reference' => $launch->providerReference,
                    'status' => 'pending',
                    'expires_at' => $expiry,
                    'updated_at' => now(),
                ]);

                // لا يكتب هذه الحالة إلا لمحاولة المستخدم الآلية الحالية.
                DB::table('kyc_verification_cases')
                    ->where('user_id', $user->id)
                    ->where('review_mode', KycPrivacyService::MODE_AUTOMATED)
                    ->update([
                        'status' => 'collecting',
                        'ownership_method' => 'biometric_liveness_face_match',
                        'biometric_provider' => $provider,
                        'provider_reference' => $launch->providerReference,
                        'liveness_status' => 'pending',
                        'liveness_score' => null,
                        'face_match_status' => 'pending',
                        'face_match_score' => null,
                        'updated_at' => now(),
                    ]);
            });
        } catch (\Throwable $e) {
            DB::table('kyc_biometric_attempts')->where('id', $attemptId)->update([
                'status' => 'failed',
                'result_code' => 'PROVIDER_START_FAILED',
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record([
                'actor_type' => 'user', 'actor_user_id' => $user->id,
                'subject_type' => 'kyc_biometric_attempt', 'subject_id' => $attemptUlid,
                'action' => 'KYC_BIOMETRIC_START_FAILED', 'decision_code' => 'PROVIDER_START_FAILED',
                'severity' => 'warning',
                'context' => ['provider' => $provider],
            ]);

            throw new DomainException('KYC_BIOMETRIC_PROVIDER_START_FAILED');
        }

        $this->audit->record([
            'actor_type' => 'user', 'actor_user_id' => $user->id,
            'subject_type' => 'kyc_biometric_attempt', 'subject_id' => $attemptUlid,
            'action' => 'KYC_BIOMETRIC_ATTEMPT_STARTED', 'decision_code' => 'BIOMETRIC_PENDING',
            'severity' => 'notice',
            'context' => ['provider' => $provider],
        ]);

        return [
            'attempt_ulid' => $attemptUlid,
            'provider' => $provider,
            'status' => 'pending',
            'launch' => $launch->clientPayload(),
        ];
    }

    /**
     * @return array{duplicate:bool,matched_attempt:bool,status:string,case_updated:bool}
     */
    public function applyWebhook(string $provider, string $rawBody, array $headers): array
    {
        $this->assertSchema();
        $driver = $this->providers->driverFor($provider);

        // Callback قد يصل بعد تعطيل المزود المختار، لكن مفاتيح التحقق يجب أن
        // تبقى متاحة. unknown/unavailable لا يُقبل.
        if (!$driver->available() || !hash_equals($provider, $driver->name())) {
            throw new DomainException('KYC_BIOMETRIC_PROVIDER_UNAVAILABLE');
        }
        if (!$driver->verifyWebhook($rawBody, $headers)) {
            throw new DomainException('KYC_BIOMETRIC_WEBHOOK_SIGNATURE_INVALID');
        }

        $payloadHash = hash('sha256', $rawBody);
        $event = $driver->parseWebhook($rawBody, $headers);
        $eventIdentity = trim($event->eventId) !== '' ? trim($event->eventId) : $payloadHash;
        $eventKeyHash = hash('sha256', $provider.'|'.$eventIdentity);
        $eventType = $this->safeCode($event->eventType, 'PROVIDER_EVENT');
        $resultCode = $this->safeCode($event->resultCode, null);
        $occurredAt = $this->parseTimestamp($event->occurredAt);

        $outcome = DB::transaction(function () use (
            $provider, $event, $payloadHash, $eventKeyHash, $eventType, $resultCode, $occurredAt
        ): array {
            $existing = DB::table('kyc_biometric_events')
                ->where('provider', $provider)
                ->where('event_key_hash', $eventKeyHash)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return [
                    'duplicate' => true,
                    'matched_attempt' => $existing->attempt_id !== null,
                    'status' => 'duplicate',
                    'case_updated' => false,
                    'attempt_ulid' => null,
                ];
            }

            $attempt = DB::table('kyc_biometric_attempts')
                ->where('provider', $provider)
                ->where('provider_reference', $event->providerReference)
                ->lockForUpdate()
                ->first();

            $eventId = DB::table('kyc_biometric_events')->insertGetId([
                'provider' => $provider,
                'event_key_hash' => $eventKeyHash,
                'attempt_id' => $attempt?->id,
                'event_type' => $eventType,
                'payload_sha256' => $payloadHash,
                'liveness_status' => $event->livenessStatus,
                'liveness_score' => $event->livenessScore,
                'face_match_status' => $event->faceMatchStatus,
                'face_match_score' => $event->faceMatchScore,
                'result_code' => $attempt ? $resultCode : 'ATTEMPT_NOT_FOUND',
                'occurred_at' => $occurredAt,
                'processed_at' => now(),
                'created_at' => now(),
            ]);

            if (!$attempt) {
                return [
                    'duplicate' => false,
                    'matched_attempt' => false,
                    'status' => 'unmatched',
                    'case_updated' => false,
                    'attempt_ulid' => null,
                    'event_id' => $eventId,
                ];
            }

            $success = $event->livenessStatus === 'passed' && $event->faceMatchStatus === 'matched';
            $failed = $event->livenessStatus === 'failed' || $event->faceMatchStatus === 'not_matched';
            $manual = $event->livenessStatus === 'manual_review' || $event->faceMatchStatus === 'manual_review';
            $attemptStatus = $success ? 'completed' : ($failed ? 'failed' : ($manual ? 'manual_review' : 'pending'));
            $terminal = in_array($attemptStatus, ['completed', 'failed', 'manual_review'], true);

            DB::table('kyc_biometric_attempts')->where('id', $attempt->id)->update([
                'status' => $attemptStatus,
                'liveness_status' => $event->livenessStatus,
                'liveness_score' => $event->livenessScore,
                'face_match_status' => $event->faceMatchStatus,
                'face_match_score' => $event->faceMatchScore,
                'result_code' => $resultCode,
                'completed_at' => $terminal ? now() : null,
                'updated_at' => now(),
            ]);

            // حارس late callback: لا يلمس القضية إلا مرجع المحاولة الحالية.
            $caseStatus = $success ? 'evidence_ready' : (($failed || $manual) ? 'manual_review' : 'collecting');
            $updated = DB::table('kyc_verification_cases')
                ->where('user_id', $attempt->user_id)
                ->where('review_mode', KycPrivacyService::MODE_AUTOMATED)
                ->where('biometric_provider', $provider)
                ->where('provider_reference', $event->providerReference)
                ->update([
                    'status' => $caseStatus,
                    'liveness_status' => $event->livenessStatus,
                    'liveness_score' => $event->livenessScore,
                    'face_match_status' => $event->faceMatchStatus,
                    'face_match_score' => $event->faceMatchScore,
                    'updated_at' => now(),
                ]);

            return [
                'duplicate' => false,
                'matched_attempt' => true,
                'status' => $attemptStatus,
                'case_updated' => $updated > 0,
                'attempt_ulid' => (string) $attempt->attempt_ulid,
                'event_id' => $eventId,
            ];
        });

        $this->audit->record([
            'actor_type' => 'system',
            'subject_type' => 'kyc_biometric_attempt',
            'subject_id' => $outcome['attempt_ulid'] ?? null,
            'action' => 'KYC_BIOMETRIC_WEBHOOK_PROCESSED',
            'decision_code' => strtoupper((string) $outcome['status']),
            'severity' => ($outcome['status'] ?? '') === 'failed' ? 'warning' : 'notice',
            'context' => [
                'provider' => $provider,
                'event_type' => $eventType,
                'payload_sha256' => $payloadHash,
                'liveness_status' => $event->livenessStatus,
                'face_match_status' => $event->faceMatchStatus,
                'duplicate' => (bool) ($outcome['duplicate'] ?? false),
                'case_updated' => (bool) ($outcome['case_updated'] ?? false),
            ],
        ]);

        unset($outcome['attempt_ulid'], $outcome['event_id']);
        return $outcome;
    }

    /** بيانات تشغيلية مجمعة للوحة، بلا PII ولا مراجع خام. */
    public function operationalSummary(): array
    {
        $providerState = $this->providers->status();
        if (!Schema::hasTable('kyc_biometric_attempts') || !Schema::hasTable('kyc_biometric_events')) {
            return $providerState + [
                'schema_ready' => false,
                'attempts' => ['pending' => 0, 'completed' => 0, 'manual_review' => 0, 'failed' => 0],
                'last_callback_at' => null,
            ];
        }

        $counts = DB::table('kyc_biometric_attempts')
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return $providerState + [
            'schema_ready' => true,
            'attempts' => [
                'pending' => (int) (($counts['pending'] ?? 0) + ($counts['starting'] ?? 0)),
                'completed' => (int) ($counts['completed'] ?? 0),
                'manual_review' => (int) ($counts['manual_review'] ?? 0),
                'failed' => (int) ($counts['failed'] ?? 0),
                'superseded' => (int) ($counts['superseded'] ?? 0),
            ],
            'last_callback_at' => DB::table('kyc_biometric_events')->max('processed_at'),
        ];
    }

    private function assertSchema(): void
    {
        if (!Schema::hasTable('kyc_verification_cases')
            || !Schema::hasTable('kyc_biometric_attempts')
            || !Schema::hasTable('kyc_biometric_events')) {
            throw new DomainException('KYC_BIOMETRIC_SCHEMA_UNAVAILABLE');
        }
    }

    private function parseTimestamp(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function safeCode(?string $value, ?string $fallback): ?string
    {
        $value = strtoupper(trim((string) $value));
        $value = preg_replace('/[^A-Z0-9_.:-]/', '_', $value) ?? '';
        $value = mb_substr($value, 0, 80);

        return $value === '' ? $fallback : $value;
    }
}
