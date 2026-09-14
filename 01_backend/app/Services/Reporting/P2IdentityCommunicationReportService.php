<?php

namespace App\Services\Reporting;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-REPORTING-OTP-001 — تقرير البريد وOTP بلا PII أو أسرار.
 *
 * لا يقرأ identifier ولا token_hash ولا verification_token_hash ولا
 * last_error ولا provider_message_id. الهدف قياس الخدمة، لا تحويل مركز
 * التقارير إلى شاشة قادرة على كشف هوية المستخدم أو مادة تحقق حساسة.
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
            'privacy' => [
                'pii_included' => false,
                'secret_fields_included' => false,
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
