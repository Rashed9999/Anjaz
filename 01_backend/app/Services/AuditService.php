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

    /**
     * بصمة السجل: SHA-256(بصمة السابق + الحقول الجوهرية بترتيب ثابت).
     * تُستخدم عند الكتابة وعند التحقق (amial:audit-verify) — يجب أن تبقى متطابقة.
     */
    /**
     * AMIAL-AUDIT-JSON-001 — **صيغةٌ قانونيّةٌ لِـ`context` قبل البصم.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **الثمن — كشفته بوّابةُ GitHub في أوّل تشغيلٍ حقيقيّ:**
     *
     *   AdminCommandCenterGuardTest → «بصمةُ قرارٍ سليمٍ لا تطابق»
     *   SafePaymentAdminAuditTest   → amial:audit-verify يخرج بـ١ بدل ٠
     *
     * يمرّان محلّيّاً ويسقطان في CI. **والفرقُ محرّكُ القاعدة:**
     *
     *   · MariaDB : `json()` مرادفٌ لـ`LONGTEXT` — يُخزَّن النصُّ حرفاً بحرف.
     *   · MySQL 8 : نوعُ JSON أصليّ — **يُعيد ترتيبَ المفاتيح ويحذف
     *               الفراغات** عند التخزين.
     *
     * والبصمةُ تُحسب **قبل** الحفظ من نصّ PHP، وتُقارَن **بعد** القراءة
     * من نصّ المحرّك. فعلى MySQL 8 لا يتطابقان أبداً.
     *
     * **وليس هذا عطلَ اختبار:** `docker-compose.prod.yml` يستعمل `mysql:8.0`.
     * أي أنّ سلسلةَ التدقيق هناك **تُبلّغ عن كلّ سجلٍّ سليمٍ أنّه معبوثٌ
     * به** — وحارسٌ يصرخ على كلّ شيءٍ لا يصدّقه أحدٌ حين يصرخ على الحقّ.
     *
     * فتُوحَّد الصيغةُ قبل البصم: تُفكَّك، وتُرتَّب مفاتيحُها ترتيباً
     * ثابتاً على كلّ عمق، ثمّ تُعاد. فتستوي القاعدتان.
     *
     * وما ليس JSON صالحاً يُترَك كما هو — فلا تُغيَّر بصمةُ نصٍّ حرّ.
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
            ksort($a);
            foreach ($a as &$v) {
                if (is_array($v)) {
                    $sort($v);
                }
            }
        };

        $sort($decoded);

        return (string) json_encode($decoded,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * **أتطابق بصمةُ الصفّ؟** — بالصيغة القانونيّة، أو بالصيغة القديمة.
     *
     * والتراجعُ مقصود: السجلّاتُ المكتوبةُ قبل هذا الإصلاح بُصمت بالنصّ
     * الخام. فرفضُها الآن يجعل كلَّ تاريخٍ سابقٍ «معبوثاً به» — وهو
     * العطلُ نفسُه مقلوباً.
     *
     * **والعبثُ يبقى مكشوفاً**: تعديلُ صفٍّ يكسر الصيغتين معاً.
     */
    public static function hashMatches(string $prevHash, array $a, string $stored): bool
    {
        if (self::computeEntryHash($prevHash, $a) === $stored) {
            return true;
        }

        return self::computeEntryHash($prevHash, $a, legacy: true) === $stored;
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
