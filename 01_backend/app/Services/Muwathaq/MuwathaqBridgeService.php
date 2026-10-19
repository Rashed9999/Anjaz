<?php

namespace App\Services\Muwathaq;

use App\Jobs\DeliverMuwathaqApplicationJob;
use App\Models\AgentProfile;
use App\Models\MerchantProfile;
use App\Models\MuwathaqAccountOpeningApplication;
use App\Models\MuwathaqApplicationSubmission;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * قناة أميال باي الصادرة إلى موثّق.
 *
 * أميال باي هو صاحب التسجيل، وموثّق هو صاحب القضية والقرار. هذا الصندوق
 * يمنع فقدان طلب فتح الحساب إذا كانت بوابة النطاق الفرعي متوقفة وقت التسجيل.
 */
class MuwathaqBridgeService
{
    public function recordApplication(MuwathaqAccountOpeningApplication $application): MuwathaqApplicationSubmission
    {
        $submission = MuwathaqApplicationSubmission::query()
            ->where('application_id', $application->id)
            ->first();

        if ($submission !== null) {
            return $submission;
        }

        $application->loadMissing('user');
        $submission = MuwathaqApplicationSubmission::query()->create([
            'application_id' => $application->id,
            'event_ulid' => (string) Str::ulid(),
            'payload' => $this->applicationPayload($application),
            'status' => MuwathaqApplicationSubmission::STATUS_PENDING,
        ]);

        if ($this->isConfigured()) {
            DB::afterCommit(fn () => DeliverMuwathaqApplicationJob::dispatch($submission->id));
        }

        return $submission;
    }

    /** يستدعيه الـ job أو المجدول؛ يعيد false إذا كان قد وصل مسبقاً. */
    public function deliver(int $submissionId): bool
    {
        if (!$this->isConfigured()) {
            throw new DomainException('قناة موثّق الصادرة غير مضبوطة.');
        }

        $submission = DB::transaction(function () use ($submissionId): ?MuwathaqApplicationSubmission {
            $row = MuwathaqApplicationSubmission::query()->lockForUpdate()->find($submissionId);
            if ($row === null || $row->isDelivered()) {
                return null;
            }
            $row->attempts++;
            $row->last_attempted_at = now();
            $row->failure_code = null;
            $row->save();

            return $row;
        });

        if ($submission === null) {
            return false;
        }

        $body = json_encode($submission->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $this->bridgeSecret());

        try {
            $response = Http::acceptJson()
                ->withHeaders([
                    'X-Amial-Timestamp' => $timestamp,
                    'X-Amial-Signature' => $signature,
                    'Idempotency-Key' => $submission->event_ulid,
                    'Content-Type' => 'application/json',
                ])
                ->timeout((int) config('muwathaq.timeout_seconds', 10))
                ->send('POST', $this->applicationsEndpoint(), ['body' => $body]);
        } catch (\Throwable $e) {
            $this->markFailed($submission->id, 'NETWORK_ERROR');
            throw $e;
        }

        if (!$response->successful()) {
            $this->markFailed($submission->id, 'HTTP_'.$response->status(), $response->status());
            throw new DomainException('موثّق رفض استلام طلب فتح الحساب (HTTP '.$response->status().').');
        }

        MuwathaqApplicationSubmission::query()->whereKey($submission->id)->update([
            'status' => MuwathaqApplicationSubmission::STATUS_DELIVERED,
            'delivered_at' => now(),
            'response_status' => $response->status(),
            'failure_code' => null,
            'updated_at' => now(),
        ]);

        return true;
    }

    public function dispatchPending(int $limit = 100): int
    {
        if (!$this->isConfigured()) {
            return 0;
        }

        $ids = MuwathaqApplicationSubmission::query()
            ->where('status', MuwathaqApplicationSubmission::STATUS_PENDING)
            ->oldest('id')
            ->limit(max(1, min(500, $limit)))
            ->pluck('id');

        foreach ($ids as $id) {
            DeliverMuwathaqApplicationJob::dispatch((int) $id);
        }

        return $ids->count();
    }

    public function isConfigured(): bool
    {
        return trim((string) config('muwathaq.base_url')) !== '' && $this->bridgeSecret() !== '';
    }

    private function applicationsEndpoint(): string
    {
        return rtrim((string) config('muwathaq.base_url'), '/').'/api/v1/internal/applications';
    }

    private function bridgeSecret(): string
    {
        return trim((string) config('muwathaq.bridge_secret'));
    }

    private function markFailed(int $submissionId, string $code, ?int $responseStatus = null): void
    {
        MuwathaqApplicationSubmission::query()->whereKey($submissionId)->update([
            'status' => MuwathaqApplicationSubmission::STATUS_PENDING,
            'response_status' => $responseStatus,
            'failure_code' => $code,
            'updated_at' => now(),
        ]);
    }

    /** @return array<string,mixed> */
    private function applicationPayload(MuwathaqAccountOpeningApplication $application): array
    {
        /** @var User $user */
        $user = $application->user;
        $applicant = [
            // هذا مرجع داخلي بين النظامين، وليس رقم محفظة أو بيانات دخول.
            'amial_user_id' => $user->id,
            'legal_name' => trim(implode(' ', array_filter([
                $user->f_name, $user->father_name, $user->grandfather_name, $user->family_name ?: $user->l_name,
            ]))),
            'phone' => $user->phone,
            'email' => $user->email,
            'gender' => $user->gender,
        ];

        $subject = null;
        if ($application->subject_kind === 'merchant') {
            $profile = MerchantProfile::query()->where('user_id', $user->id)->first();
            $subject = $profile ? [
                'business_type' => $profile->business_type,
                'declared_monthly_volume' => (string) $profile->declared_monthly_volume,
                'declared_daily_customers' => $profile->declared_daily_customers,
            ] : null;
        }
        if ($application->subject_kind === 'agent') {
            $profile = AgentProfile::query()->where('user_id', $user->id)->first();
            $subject = $profile ? [
                'business_name' => $profile->business_name,
                'license_number' => $profile->license_number,
                'location_city' => $profile->location_city,
                'location_address' => $profile->location_address,
            ] : null;
        }

        return [
            'event_version' => 1,
            'event_type' => 'account_opening.submitted',
            'application' => [
                'application_ulid' => $application->application_ulid,
                'subject_kind' => $application->subject_kind,
                'verification_path' => $application->verification_path,
                'business_type' => $application->business_type,
                'requested_tier' => $application->requested_tier,
                'submitted_at' => $application->submitted_at?->toIso8601String(),
            ],
            'applicant' => $applicant,
            'subject' => $subject,
        ];
    }
}
