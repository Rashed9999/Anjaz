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
    /** الشدّات المقبولة في العمود — وما يُرادفها ممّا يُكتب عادةً. */
    private const SEVERITY_MAP = [
        'low' => 'info', 'debug' => 'info', 'info' => 'info',
        'medium' => 'notice', 'notice' => 'notice',
        'high' => 'warning', 'warn' => 'warning', 'warning' => 'warning',
        'critical' => 'critical', 'severe' => 'critical', 'fatal' => 'critical',
    ];

    private function normalizeSeverity(?string $value): string
    {
        return self::SEVERITY_MAP[mb_strtolower(trim((string) $value))] ?? 'info';
    }

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
                'correlation_id' => $payload['correlation_id'] ?? $this->currentCorrelationId(),
                'zone_code' => $payload['zone_code'] ?? null,
                // **الشدّة تُطبَّع ولا تُمرَّر كما جاءت.**
                //
                // العمود محصورٌ بأربع قيم، وقيمةٌ خارجها تُسقط الإدراج
                // كلَّه — و`catch` أدناه يبتلع الاستثناء. فالنتيجة أنّ
                // **سطر التدقيق يُفقد بصمت** بسبب كلمة.
                //
                // ووقع هذا فعلاً: ثلاثة مواضع كتبت `high` و`medium`،
                // فكان رفعُ حدِّ صرّافٍ وقرارُ موافقةٍ يمرّان بلا أثر —
                // وهما بالضبط ما يُبحث عنه في أيّ تحقيق.
                //
                // وفقدُ سطرٍ لأجل كلمة أسوأ من الكلمة: فتُترجَم.
                'severity' => $this->normalizeSeverity($payload['severity'] ?? null),
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

    private function currentCorrelationId(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        $value = app('request')->attributes->get('amial.correlation_id');
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * بصمة السجل: SHA-256(بصمة السابق + الحقول الجوهرية بترتيب ثابت).
     * تُستخدم عند الكتابة وعند التحقق (amial:audit-verify) — يجب أن تبقى متطابقة.
     */
    /**
     * صيغة JSON قانونية مستقلة عن MariaDB/MySQL. MySQL 8 قد يعيد
     * ترتيب مفاتيح object؛ القوائم تبقى بترتيبها لأن ترتيبها جزء من القيمة.
     */
    public static function canonicalContext(?string $context): string
    {
        if ($context === null || $context === '') {
            return '';
        }

        $decoded = json_decode($context, true);
        if (! is_array($decoded)) {
            return $context;
        }

        $sort = static function (array &$a) use (&$sort): void {
            if (! array_is_list($a)) {
                ksort($a);
            }
            foreach ($a as &$v) {
                if (is_array($v)) {
                    $sort($v);
                }
            }
        };
        $sort($decoded);

        return (string) json_encode(
            $decoded,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * يقبل البصمة القانونية الحالية أو البصمة الخام التاريخية.
     */
    public static function hashMatches(string $prevHash, array $a, string $stored): bool
    {
        if (self::computeEntryHash($prevHash, $a) === $stored) {
            return true;
        }

        return self::computeEntryHash($prevHash, $a, legacy: true) === $stored;
    }

    /**
     * يفسر اختلافاً إذا أمكن إثبات سبب تقني من القيم الحالية نفسها،
     * ولا يحوّل اختلافاً مجهولاً إلى «سليم».
     *
     * @param array<string,mixed> $a
     * @return array{field:string,cause:string,benign:bool,code?:string}|null
     */
    public static function explainMismatch(string $prevHash, array $a, string $stored): ?array
    {
        $try = static function (array $patch) use ($prevHash, $a, $stored): bool {
            $candidate = array_replace($a, $patch);

            return self::computeEntryHash($prevHash, $candidate) === $stored
                || self::computeEntryHash($prevHash, $candidate, legacy: true) === $stored;
        };

        $rawContext = isset($a['context']) ? (string) $a['context'] : '';
        $decoded = $rawContext !== '' ? json_decode($rawContext, true) : null;

        $hypotheses = [
            [['subject_type' => 'safe_payment'], 'نوعُ الموضوع', 'أُعيدت كتابتُه بتراجع هجرة التعداد', true],
            [['subject_type' => 'e_payment'], 'نوعُ الموضوع', 'أُعيدت كتابتُه بتراجع هجرة التعداد', true],
            [['subject_type' => 'family_fund'], 'نوعُ الموضوع', 'أُعيدت كتابتُه بتراجع هجرة التعداد', true],
            [['subject_type' => 'donation'], 'نوعُ الموضوع', 'أُعيدت كتابتُه بتراجع هجرة التعداد', true],
            [['subject_type' => 'pending_transfer'], 'نوعُ الموضوع', 'أُعيدت كتابتُه بتراجع هجرة التعداد', true],
            [['subject_type' => 'agent_shift'], 'نوعُ الموضوع', 'أُعيدت كتابتُه بتراجع هجرة التعداد', true],
            [['subject_type' => 'agent_staff'], 'نوعُ الموضوع', 'أُعيدت كتابتُه بتراجع هجرة التعداد', true],
            [['subject_type' => 'support_ticket'], 'نوعُ الموضوع', 'أُعيدت كتابتُه بتراجع هجرة التعداد', true],
            [['subject_type' => ''], 'نوعُ الموضوع', 'قُصّ إلى فراغ عند كتابة تعداد قديم', true],
        ];

        if (is_array($decoded)) {
            foreach ([
                JSON_UNESCAPED_UNICODE,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                JSON_UNESCAPED_SLASHES,
                0,
            ] as $flags) {
                $hypotheses[] = [
                    ['context' => json_encode($decoded, $flags)],
                    'السياق',
                    'فرق ترميز في تخزين JSON — لا تغيير في القيم',
                    true,
                ];
            }
        }

        foreach ($hypotheses as [$patch, $field, $cause, $benign]) {
            if ($try($patch)) {
                return ['field' => $field, 'cause' => $cause, 'benign' => $benign];
            }
        }

        if (is_array($decoded)
            && ! array_is_list($decoded)
            && count($decoded) >= 2
            && count($decoded) <= 7
            && self::legacyJsonKeyOrderMatches($prevHash, $a, $stored, $decoded)) {
            return [
                'field' => 'السياق',
                'cause' => 'اختلاف تاريخي في ترتيب مفاتيح JSON قبل توحيد البصمة — القيم نفسها',
                'benign' => true,
                'code' => 'legacy_json_key_order',
            ];
        }

        foreach ([
            [['context' => null], 'السياق', 'أُفرغ بعد الكتابة'],
            [['reason' => null], 'السبب', 'أُفرغ بعد الكتابة'],
            [['zone_code' => null], 'النطاق', 'أُفرغ بعد الكتابة'],
            [['transaction_id' => null], 'رقم المعاملة', 'أُفرغ بعد الكتابة'],
        ] as [$patch, $field, $cause]) {
            if ($try($patch)) {
                return ['field' => $field, 'cause' => $cause, 'benign' => false];
            }
        }

        return null;
    }

    /**
     * يستعيد احتمال ترتيب object التاريخي دون تغيير أي قيمة.
     *
     * @param array<string,mixed> $decoded
     */
    private static function legacyJsonKeyOrderMatches(
        string $prevHash,
        array $a,
        string $stored,
        array $decoded,
    ): bool {
        $keys = array_keys($decoded);
        $count = count($keys);
        $used = array_fill(0, $count, false);
        $orderedKeys = [];

        $walk = function () use (
            &$walk, &$used, &$orderedKeys, $keys, $count,
            $decoded, $prevHash, $a, $stored
        ): bool {
            if (count($orderedKeys) === $count) {
                $candidateContext = [];
                foreach ($orderedKeys as $key) {
                    $candidateContext[$key] = $decoded[$key];
                }

                $json = json_encode($candidateContext, JSON_UNESCAPED_UNICODE);
                if ($json === false) {
                    return false;
                }

                $candidate = array_replace($a, ['context' => $json]);

                return self::computeEntryHash(
                    $prevHash,
                    $candidate,
                    legacy: true,
                ) === $stored;
            }

            for ($i = 0; $i < $count; $i++) {
                if ($used[$i]) {
                    continue;
                }

                $used[$i] = true;
                $orderedKeys[] = $keys[$i];

                if ($walk()) {
                    return true;
                }

                array_pop($orderedKeys);
                $used[$i] = false;
            }

            return false;
        };

        return $walk();
    }

    public static function computeEntryHash(
        string $prevHash,
        array $a,
        bool $legacy = false,
    ): string {
        $context = $legacy
            ? (string) ($a['context'] ?? '')
            : self::canonicalContext(
                isset($a['context']) ? (string) $a['context'] : null,
            );

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
            $context,
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
