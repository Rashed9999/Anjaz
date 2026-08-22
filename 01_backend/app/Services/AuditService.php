<?php

namespace App\Services;

use App\Models\AuditDecision;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AuditService — الواجهة الوحيدة لكتابة سجل القرارات.
 *
 * السجل append-only ومربوط بسلسلة SHA-256. لا يجوز أن يفشل التدفق
 * الرئيسي بسبب تعذر كتابة التدقيق، لذلك يكون Laravel log هو fallback.
 */
class AuditService
{
    private const FORBIDDEN_KEYS = [
        'password', 'pin', 'old_pin', 'new_pin', 'transaction_pin',
        'otp', 'token', 'access_token', 'refresh_token', 'authorization',
        'card_number', 'cvv', 'cvc', 'iban', 'private_key', 'secret',
    ];

    private const SEVERITY_MAP = [
        'low' => 'info', 'debug' => 'info', 'info' => 'info',
        'medium' => 'notice', 'notice' => 'notice',
        'high' => 'warning', 'warn' => 'warning', 'warning' => 'warning',
        'critical' => 'critical', 'severe' => 'critical', 'fatal' => 'critical',
    ];

    public const KNOWN_KEYS = [
        'actor_type', 'actor_user_id', 'subject_type', 'subject_id',
        'action', 'decision_code', 'reason', 'severity', 'context',
        'transaction_id', 'idempotency_key', 'zone_code',
        'metadata',
    ];

    private function normalizeSeverity(?string $value): string
    {
        return self::SEVERITY_MAP[mb_strtolower(trim((string) $value))] ?? 'info';
    }

    public function record(array $payload): ?string
    {
        try {
            $this->assertKnownKeys($payload);

            // metadata اسم تاريخي لـ context. قبول الاثنين يمنع فقد أدلة قديمة.
            $context = $payload['context'] ?? $payload['metadata'] ?? [];
            if (is_array($context)) {
                $context = $this->sanitizeContext($context);
            }

            $decisionId = (string) Str::ulid();
            $attributes = [
                'decision_id' => $decisionId,
                'actor_type' => $payload['actor_type'] ?? 'system',
                'actor_user_id' => $payload['actor_user_id'] ?? null,
                'subject_type' => $payload['subject_type'] ?? 'user',
                'subject_id' => isset($payload['subject_id']) ? (string) $payload['subject_id'] : null,
                'action' => $payload['action'] ?? 'UNKNOWN',
                'decision_code' => $payload['decision_code'] ?? 'UNKNOWN',
                'reason' => isset($payload['reason']) ? mb_substr($payload['reason'], 0, 255) : null,
                'context' => ! empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : null,
                'transaction_id' => $payload['transaction_id'] ?? null,
                'idempotency_key' => $payload['idempotency_key'] ?? null,
                'zone_code' => $payload['zone_code'] ?? null,
                'severity' => $this->normalizeSeverity($payload['severity'] ?? null),
            ];

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
            Log::channel('stack')->error('AuditService failed to persist decision', [
                'error' => $e->getMessage(),
                'payload_action' => $payload['action'] ?? null,
                'payload_code' => $payload['decision_code'] ?? null,
            ]);

            return null;
        }
    }

    /**
     * صيغة JSON قانونية مستقلة عن MariaDB/MySQL.
     * MySQL 8 قد يعيد ترتيب مفاتيح JSON؛ لذلك لا تدخل الصيغة الخام في
     * البصمات الجديدة.
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

        return (string) json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * يقبل البصمة القانونية الحالية أو البصمة الخام القديمة إذا ظل النص
     * الخام نفسه كما كُتب.
     */
    public static function hashMatches(string $prevHash, array $a, string $stored): bool
    {
        if (self::computeEntryHash($prevHash, $a) === $stored) {
            return true;
        }

        return self::computeEntryHash($prevHash, $a, legacy: true) === $stored;
    }

    /**
     * يفسر اختلاف البصمة عندما يمكن إثبات السبب بإعادة بناء محتوى قديم
     * معروف، ولا يخمن النية.
     *
     * @param  array<string,mixed>  $a
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
            [['subject_type' => 'safe_payment'], 'نوعُ الموضوع', 'أُعيدت كتابتُه بتراجُع هجرة التعداد (كان نوعاً خارج القائمة القديمة)', true],
            [['subject_type' => 'e_payment'], 'نوعُ الموضوع', 'أُعيدت كتابتُه بتراجُع هجرة التعداد (كان نوعاً خارج القائمة القديمة)', true],
            [['subject_type' => 'family_fund'], 'نوعُ الموضوع', 'أُعيدت كتابتُه بتراجُع هجرة التعداد (كان نوعاً خارج القائمة القديمة)', true],
            [['subject_type' => 'donation'], 'نوعُ الموضوع', 'أُعيدت كتابتُه بتراجُع هجرة التعداد (كان نوعاً خارج القائمة القديمة)', true],
            [['subject_type' => 'pending_transfer'], 'نوعُ الموضوع', 'أُعيدت كتابتُه بتراجُع هجرة التعداد (كان نوعاً خارج القائمة القديمة)', true],
            [['subject_type' => 'agent_shift'], 'نوعُ الموضوع', 'أُعيدت كتابتُه بتراجُع هجرة التعداد (كان نوعاً خارج القائمة القديمة)', true],
            [['subject_type' => 'agent_staff'], 'نوعُ الموضوع', 'أُعيدت كتابتُه بتراجُع هجرة التعداد (كان نوعاً خارج القائمة القديمة)', true],
            [['subject_type' => 'support_ticket'], 'نوعُ الموضوع', 'أُعيدت كتابتُه بتراجُع هجرة التعداد (كان نوعاً خارج القائمة القديمة)', true],
            [['subject_type' => ''], 'نوعُ الموضوع', 'قُصّ إلى فراغٍ عند الكتابة — العمودُ كان تعداداً لا يقبل القيمة', true],
        ];

        // فروق encoding البسيطة التي لا تغيّر ترتيب المفاتيح.
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
                    'فرقُ ترميزٍ في تخزين JSON — لا تغييرَ في القيم',
                    true,
                ];
            }
        }

        foreach ($hypotheses as [$patch, $field, $cause, $benign]) {
            if ($try($patch)) {
                return ['field' => $field, 'cause' => $cause, 'benign' => $benign];
            }
        }

        // AMIAL-AUDIT-LEGACY-JSON-002
        // قبل canonicalContext كانت البصمة تُحسب من json_encode() بترتيب
        // PHP، ثم يحفظ MySQL 8 JSON بصيغة قد تعيد ترتيب المفاتيح. عند
        // القراءة تصبح القيم نفسها ولكن لا يمكن استعادة ترتيب المصدر من
        // النص المخزن. لذلك نجرب *فقط* تبديلات ترتيب مفاتيح object مسطح
        // وبحد صارم؛ فإذا أعاد أحدها البصمة القديمة فهذا إثبات أن القيم
        // نفسها كانت موجودة وأن الاختلاف Serialization فقط.
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

        // ══════════════════════════════════════════════════════════════
        // **فرضيّةُ «الحقلُ كان فارغاً وقتَ البصم» — وليست benign.**
        //
        // وما تُثبته هذه الفرضيّةُ دقيقٌ ومحدود: المحتوى المبصومُ كان
        // **بلا هذا الحقل**، وهو اليوم يحمل قيمة. **ولا تقول أيَّ
        // اتّجاهٍ سلكه التغيير.**
        //
        // وكانت تُسمّى «أُفرغ بعد الكتابة» — **وهو استنتاجٌ معكوس**:
        // الحقلُ في هذه الحالة **مُلئ** لا أُفرغ. والفرقُ ليس لفظيّاً في
        // سجلّ تدقيق: «أُفرغ» تُقرأ فقدَ بياناتٍ (عطلٌ بريء)، و«مُلئ بعد
        // البصم» تُقرأ **إضافةَ أثرٍ لم يكن** — وهي صورةُ التزوير بعد
        // الحادثة بعينها. **فتسميةٌ خاطئةٌ هنا تدفع المحقّقَ عن الأثر.**
        foreach ([
            [['context' => null], 'السياق'],
            [['reason' => null], 'السبب'],
            [['zone_code' => null], 'النطاق'],
            [['transaction_id' => null], 'رقمُ المعاملة'],
        ] as [$patch, $field]) {
            if ($try($patch)) {
                $key = array_key_first($patch);
                $nowEmpty = ($a[$key] ?? null) === null || $a[$key] === '';

                return [
                    'field' => $field,
                    // **ويُقال ما قِيس لا ما يُظنّ.**
                    'cause' => $nowEmpty
                        ? 'كان فارغاً وقت البصم وهو فارغٌ الآن — والاختلاف من غيره'
                        : 'كان فارغاً وقت البصم وله قيمةٌ الآن — أي أُضيف بعد البصم',
                    'benign' => false,
                ];
            }
        }

        return null;
    }

    /**
     * يحاول استعادة ترتيب object القديم دون تغيير أي قيمة.
     * الحد الأعلى 7 مفاتيح = 5040 احتمالاً فقط، ويُستدعى عند mismatch.
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

        $walk = function () use (&$walk, &$used, &$orderedKeys, $keys, $count, $decoded, $prevHash, $a, $stored): bool {
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

                return self::computeEntryHash($prevHash, $candidate, legacy: true) === $stored;
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

    public static function computeEntryHash(string $prevHash, array $a, bool $legacy = false): string
    {
        $context = $legacy
            ? (string) ($a['context'] ?? '')
            : self::canonicalContext(isset($a['context']) ? (string) $a['context'] : null);

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

    private function assertKnownKeys(array $payload): void
    {
        $unknown = array_diff(array_keys($payload), self::KNOWN_KEYS);
        if ($unknown === []) {
            return;
        }

        Log::channel('stack')->warning('AuditService: مفاتيحُ حمولةٍ مجهولةٌ أُسقطت', [
            'unknown_keys' => array_values($unknown),
            'action' => $payload['action'] ?? null,
        ]);
    }

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
