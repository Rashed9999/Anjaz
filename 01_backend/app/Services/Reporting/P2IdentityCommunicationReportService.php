<?php

namespace App\Services\Reporting;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-REPORTING-IDENTITY-001 — مؤشرات الهوية والمصادقة بلا PII أو أسرار.
 *
 * لا يخرج البريد/الهاتف/IP/identifier أو OTP/hash/token/payload. التقرير
 * يقيس الخدمة والمخاطر التجميعية فقط، وتبقى التفاصيل الشخصية خلف مراكزها
 * المخصصة وصلاحيات PII المنفصلة.
 */
class P2IdentityCommunicationReportService
{
    /** @return array<string,mixed> */
    public function emailOtp(?string $from = null, ?string $to = null): array
    {
        [$fromDate, $toDate] = $this->period($from, $to);
        if (! Schema::hasTable('otp_challenges')) {
            return $this->unavailable('otp_challenges');
        }

        $base = DB::table('otp_challenges')
            ->where('channel', 'email')
            ->whereBetween('created_at', [$fromDate, $toDate]);

        $total = (clone $base)->count();
        $verified = (clone $base)->whereNotNull('verified_at')->count();
        $consumed = (clone $base)->whereNotNull('consumed_at')->count();
        $expiredUnverified = (clone $base)
            ->whereNull('verified_at')
            ->where('expires_at', '<', now())
            ->count();
        $withProviderError = (clone $base)->whereNotNull('last_error')->count();
        $attempts = (int) ((clone $base)->sum('attempts') ?? 0);

        $delivery = (clone $base)
            ->selectRaw('delivery_status as value, COUNT(*) as total')
            ->groupBy('delivery_status')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => ['value' => (string) $r->value, 'total' => (int) $r->total])
            ->all();

        $purposes = (clone $base)
            ->selectRaw("purpose as value, COUNT(*) as total,
                SUM(CASE WHEN verified_at IS NOT NULL THEN 1 ELSE 0 END) as verified,
                SUM(CASE WHEN delivery_status = 'delivered' THEN 1 ELSE 0 END) as delivered")
            ->groupBy('purpose')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'value' => (string) $r->value,
                'total' => (int) $r->total,
                'verified' => (int) $r->verified,
                'delivered' => (int) $r->delivered,
                'verification_rate_pct' => $this->ratio((int) $r->verified, (int) $r->total),
            ])->all();

        $delivered = (clone $base)->where('delivery_status', 'delivered')->count();
        $bounced = (clone $base)->where('delivery_status', 'bounced')->count();
        $complained = (clone $base)->where('delivery_status', 'complained')->count();
        $delayed = (clone $base)->where('delivery_status', 'delayed')->count();

        return [
            'report' => 'email_otp',
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'basis' => 'otp_challenges aggregate fields only; no identifier, OTP hash, verification token, provider id, or error body',
            'total_challenges' => $total,
            'verified' => $verified,
            'consumed' => $consumed,
            'expired_unverified' => $expiredUnverified,
            'attempts_total' => $attempts,
            'attempts_average' => $total > 0 ? bcdiv((string) $attempts, (string) $total, 2) : '0.00',
            'verification_rate_pct' => $this->ratio($verified, $total),
            'delivery_confirmation_rate_pct' => $this->ratio($delivered, $total),
            'delivered' => $delivered,
            'delayed' => $delayed,
            'bounced' => $bounced,
            'complained' => $complained,
            'provider_error_challenges' => $withProviderError,
            'by_delivery_status' => $delivery,
            'by_purpose' => $purposes,
            'provider_readiness' => [
                'resend_api_key_configured' => trim((string) config('amial_otp.resend.api_key')) !== '',
                'webhook_signature_configured' => trim((string) config('amial_otp.resend.webhook_secret')) !== '',
                'sender_address_configured' => trim((string) config('amial_otp.resend.from_address')) !== '',
                'sender_name' => (string) config('amial_otp.resend.from_name', 'Amial Pay'),
            ],
            'privacy' => ['pii_included' => false, 'secret_fields_included' => false],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    public function authenticationSecurity(?string $from = null, ?string $to = null): array
    {
        [$fromDate, $toDate] = $this->period($from, $to);
        if (! Schema::hasTable('unified_login_attempts')) {
            return $this->unavailable('unified_login_attempts');
        }

        $base = DB::table('unified_login_attempts')
            ->whereBetween('attempted_at', [$fromDate, $toDate]);

        $total = (clone $base)->count();
        $success = (clone $base)->where('success', 1)->count();
        $failure = $total - $success;

        $byRole = (clone $base)
            ->selectRaw("role as value, COUNT(*) as total,
                SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as success,
                SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failure")
            ->groupBy('role')->orderByDesc('total')->get()
            ->map(fn ($r) => [
                'value' => (string) $r->value,
                'total' => (int) $r->total,
                'success' => (int) $r->success,
                'failure' => (int) $r->failure,
                'success_rate_pct' => $this->ratio((int) $r->success, (int) $r->total),
            ])->all();

        $failureReasons = (clone $base)
            ->where('success', 0)
            ->whereNotNull('failure_reason')
            ->selectRaw('failure_reason as value, COUNT(*) as total')
            ->groupBy('failure_reason')->orderByDesc('total')->limit(30)->get()
            ->map(fn ($r) => ['value' => (string) $r->value, 'total' => (int) $r->total])
            ->all();

        // نعد مصادر الهجوم المحتملة فقط، ولا نعيد الـIP نفسه إلى التقرير.
        $repeatedFailureSources = (clone $base)
            ->where('success', 0)
            ->selectRaw('ip_address, COUNT(*) as failures')
            ->groupBy('ip_address')
            ->havingRaw('COUNT(*) >= 5')
            ->get()->count();

        $lockouts = null;
        if (Schema::hasTable('audit_decisions')) {
            $lockouts = DB::table('audit_decisions')
                ->whereBetween('created_at', [$fromDate, $toDate])
                ->where('decision_code', 'ACCOUNT_TEMP_LOCKED')
                ->count();
        }

        return [
            'report' => 'auth_security',
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'basis' => 'unified_login_attempts aggregate fields + audit lockout decisions; identifier/IP/user-agent are not exposed',
            'attempts' => $total,
            'successful' => $success,
            'failed' => $failure,
            'success_rate_pct' => $this->ratio($success, $total),
            'failure_rate_pct' => $this->ratio($failure, $total),
            'repeated_failure_sources' => $repeatedFailureSources,
            'temporary_lockouts' => $lockouts,
            'by_role' => $byRole,
            'failure_reasons' => $failureReasons,
            'privacy' => [
                'identifier_included' => false,
                'ip_address_included' => false,
                'user_agent_included' => false,
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** @return array{0:Carbon,1:Carbon} */
    private function period(?string $from, ?string $to): array
    {
        return [
            Carbon::parse($from ?: now()->startOfMonth()->toDateString())->startOfDay(),
            Carbon::parse($to ?: now()->toDateString())->endOfDay(),
        ];
    }

    private function ratio(int $numerator, int $denominator): string
    {
        if ($denominator <= 0) return '0.00';
        return bcdiv(bcmul((string) $numerator, '100', 4), (string) $denominator, 2);
    }

    private function unavailable(string $source): array
    {
        return ['available' => false, 'source' => $source, 'reason' => 'source_table_missing', 'generated_at' => now()->toIso8601String()];
    }
}
