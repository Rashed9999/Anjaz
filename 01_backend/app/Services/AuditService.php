<?php

namespace App\Services;

use App\Models\AuditDecision;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AMIAL-REFACTOR-CORE-001
 *
 * AuditService — الواجهة الوحيدة لكتابة سجل القرارات.
 *
 * هدفه:
 *   - تبسيط كتابة audit_decisions (model أحياناً مع حقول كثيرة).
 *   - تنظيف PII الحساس من الـ context قبل التخزين.
 *   - failover إلى Laravel Log إن فشل DB.
 *
 * مهم: لا يجب أن يفشل audit الـ flow الرئيسي. نلتقط أي exception
 * ونلوغها فقط — نموت بصمت ولا نعطل عملية مالية بسبب فشل audit.
 */
class AuditService
{
    /** قائمة المفاتيح الممنوع لها الدخول للـ context (PII حساس) */
    private const FORBIDDEN_KEYS = [
        'password', 'pin', 'old_pin', 'new_pin', 'transaction_pin',
        'otp', 'token', 'access_token', 'refresh_token', 'authorization',
        'card_number', 'cvv', 'cvc', 'iban', 'private_key', 'secret',
    ];

    /**
     * يكتب decision. الـ payload:
     *   actor_type, actor_user_id, subject_type, subject_id,
     *   action, decision_code, reason, severity, context,
     *   transaction_id, idempotency_key, zone_code
     */
    public function record(array $payload): ?string
    {
        try {
            // فلترة context
            $context = $payload['context'] ?? [];
            if (is_array($context)) {
                $context = $this->sanitizeContext($context);
            }

            $decisionId = (string) Str::ulid();

            $attributes = [
                'decision_id' => $decisionId,
                'actor_type' => $payload['actor_type'] ?? 'system',
                'actor_user_id' => $payload['actor_user_id'] ?? null,
                'subject_type' => $payload['subject_type'] ?? 'user',
                'subject_id' => isset($payload['subject_id']) ? (string)$payload['subject_id'] : null,
                'action' => $payload['action'] ?? 'UNKNOWN',
                'decision_code' => $payload['decision_code'] ?? 'UNKNOWN',
                'reason' => isset($payload['reason']) ? mb_substr($payload['reason'], 0, 255) : null,
                'context' => !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : null,
                'transaction_id' => $payload['transaction_id'] ?? null,
                'idempotency_key' => $payload['idempotency_key'] ?? null,
                'zone_code' => $payload['zone_code'] ?? null,
                'severity' => $payload['severity'] ?? 'info',
            ];

            // AMIAL-INSIDER-001: سلسلة تجزئة — كل سجل يحمل بصمة سابقه.
            // أي حذف/تعديل لاحق يكسر السلسلة ويُكشف بـ amial:audit-verify.
            DB::transaction(function () use ($attributes) {
                $head = DB::table('audit_chain_head')->where('id', 1)->lockForUpdate()->first();

                $prevHash = $head?->last_hash ?? hash('sha256', 'AMIAL-AUDIT-CHAIN-GENESIS');
                $entryHash = self::computeEntryHash($prevHash, $attributes);

                $row = AuditDecision::create($attributes + [
                    'prev_hash' => $prevHash,
                    'entry_hash' => $entryHash,
                ]);

                if ($head) {
                    DB::table('audit_chain_head')->where('id', 1)->update([
                        'last_hash' => $entryHash,
                        'last_audit_id' => $row->id,
                        'updated_at' => now(),
                    ]);
                }
            });

            return $decisionId;

        } catch (\Throwable $e) {
            // لا نسمح لـ audit بإفشال الـ flow الرئيسي.
            // نلوغ إلى Laravel log كـ fallback.
            Log::channel('stack')->error('AuditService failed to persist decision', [
                'error' => $e->getMessage(),
                'payload_action' => $payload['action'] ?? null,
                'payload_code' => $payload['decision_code'] ?? null,
            ]);
            return null;
        }
    }

    /**
     * بصمة السجل: SHA-256(بصمة السابق + الحقول الجوهرية بترتيب ثابت).
     * تُستخدم عند الكتابة وعند التحقق (amial:audit-verify) — يجب أن تبقى متطابقة.
     */
    public static function computeEntryHash(string $prevHash, array $a): string
    {
        $canonical = implode('|', [
            $prevHash,
            (string) ($a['decision_id'] ?? ''),
            (string) ($a['actor_type'] ?? ''),
            (string) ($a['actor_user_id'] ?? ''),
            (string) ($a['subject_type'] ?? ''),
            (string) ($a['subject_id'] ?? ''),
            (string) ($a['action'] ?? ''),
            (string) ($a['decision_code'] ?? ''),
            (string) ($a['reason'] ?? ''),
            (string) ($a['context'] ?? ''),
            (string) ($a['transaction_id'] ?? ''),
            (string) ($a['zone_code'] ?? ''),
            (string) ($a['severity'] ?? ''),
        ]);

        return hash('sha256', $canonical);
    }

    /**
     * Sanitize context recursively. كل قيمة لمفتاح محظور تُستبدل بـ '[REDACTED]'.
     */
    private function sanitizeContext(array $ctx): array
    {
        foreach ($ctx as $k => $v) {
            $lowerKey = is_string($k) ? strtolower($k) : $k;
            if (is_string($lowerKey) && in_array($lowerKey, self::FORBIDDEN_KEYS, true)) {
                $ctx[$k] = '[REDACTED]';
                continue;
            }
            if (is_array($v)) {
                $ctx[$k] = $this->sanitizeContext($v);
            }
        }
        return $ctx;
    }
}
