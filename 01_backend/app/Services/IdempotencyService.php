<?php

namespace App\Services;

use App\Exceptions\DuplicateTransactionException;
use App\Models\IdempotencyKey;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-REFACTOR-CORE-001
 *
 * IdempotencyService — يضمن أن كل عملية مالية تنفّذ مرة واحدة فقط
 * حتى لو وصلت 100 مرة.
 *
 * البروتوكول المتبع (مماثل لـ Stripe API):
 *   1. العميل يولد UUIDv4 ويرسله في header: Idempotency-Key.
 *   2. الـ middleware يستدعي begin() قبل دخول الـ controller.
 *      - إن كان جديداً: نخزن row بـ status=processing ونكمل.
 *      - إن كان موجوداً وستmus=completed بنفس body: نعيد الـ response المحفوظ.
 *      - إن كان موجوداً ومع body مختلف: 409 Conflict.
 *      - إن كان موجوداً وستmus=processing: 409 (يحاول لاحقاً).
 *   3. بعد نجاح الـ controller، نستدعي complete($key, $response, $txId).
 *   4. في حالة exception، نستدعي fail($key) ليُسمح بإعادة المحاولة.
 *
 * UNIQUE constraint في DB هو الضامن الفعلي — حتى لو حدث race condition
 * بين فحص-ثم-إنشاء، الـ DB سيرفض الـ insert الثاني.
 */
class IdempotencyService
{
    /** TTL افتراضي للمفاتيح: 24 ساعة (بعدها يمكن إعادة استخدامها) */
    public const TTL_SECONDS = 86400;

    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /**
     * يبدأ معالجة idempotent. يعيد array:
     *   ['status' => 'new']    → كمل الطلب
     *   ['status' => 'replay', 'response' => [...], 'http_status' => int]
     *   ['status' => 'in_progress']
     *
     * @throws DuplicateTransactionException عند body mismatch
     */
    public function begin(string $key, ?int $userId, string $endpoint, array $requestBody): array
    {
        $this->validateKey($key);

        $hash = $this->hashBody($requestBody);

        // محاولة insert atomic — لو فشل بسبب unique constraint، نعرف أن السجل موجود
        try {
            $row = IdempotencyKey::create([
                'key' => $key,
                'user_id' => $userId,
                'endpoint' => $endpoint,
                'request_hash' => $hash,
                'status' => 'processing',
                'expires_at' => now()->addSeconds(self::TTL_SECONDS),
            ]);

            return ['status' => 'new', 'row_id' => $row->id];

        } catch (QueryException $e) {
            // SQLSTATE 23000 = integrity constraint violation (duplicate)
            if (!str_contains($e->getMessage(), 'Duplicate') && !str_contains((string)$e->getCode(), '23')) {
                throw $e;
            }
        }

        // السجل موجود — نقرأه (بدون lock، نريد قراءة سريعة)
        $existing = IdempotencyKey::where([
            'key' => $key,
            'user_id' => $userId,
            'endpoint' => $endpoint,
        ])->first();

        if (!$existing) {
            // race rare: السجل اختفى بعد فشل الـ insert (cleanup؟). نعيد المحاولة بـ recurse واحد.
            return $this->begin($key, $userId, $endpoint, $requestBody);
        }

        // فحص body match
        if ($existing->request_hash !== $hash) {
            $this->audit->record([
                'actor_type' => 'user',
                'actor_user_id' => $userId,
                'subject_type' => 'transaction',
                'action' => 'IDEMPOTENCY_BODY_MISMATCH',
                'decision_code' => 'TX_IDEMPOTENCY_CONFLICT',
                'reason' => 'Same key, different body',
                'idempotency_key' => $key,
                'severity' => 'warning',
            ]);

            throw new DuplicateTransactionException(
                idempotencyKey: $key,
                reason: 'body_mismatch',
                originalTransactionId: $existing->transaction_id,
            );
        }

        // body matches → حسب status
        return match ($existing->status) {
            'processing' => [
                'status' => 'in_progress',
                // العميل يجب أن ينتظر/يحاول لاحقاً
            ],
            'completed' => [
                'status' => 'replay',
                'response' => json_decode($existing->response_body ?? 'null', true),
                'http_status' => $existing->response_status ?? 200,
                'transaction_id' => $existing->transaction_id,
            ],
            'failed' => [
                // فشل سابق — نسمح بإعادة المحاولة بإعادة insert لـ status=processing
                // لكن في النفس key→لا. الأفضل: نطلب من العميل key جديد.
                'status' => 'failed_previously',
                'response' => json_decode($existing->response_body ?? 'null', true),
                'http_status' => $existing->response_status ?? 500,
            ],
            default => ['status' => 'in_progress'],
        };
    }

    /**
     * يكمل السجل بنجاح. يحفظ الـ response ليُعاد عند retry.
     */
    public function complete(
        string $key,
        ?int $userId,
        string $endpoint,
        int $httpStatus,
        array $responseBody,
        ?string $transactionId = null,
    ): void {
        $row = IdempotencyKey::where([
            'key' => $key,
            'user_id' => $userId,
            'endpoint' => $endpoint,
        ])->first();

        if (!$row) {
            return; // edge case: cleanup حدث بين begin و complete، نتجاهل
        }

        $row->update([
            'status' => 'completed',
            'response_status' => $httpStatus,
            'response_body' => json_encode($responseBody, JSON_UNESCAPED_UNICODE),
            'transaction_id' => $transactionId,
        ]);
    }

    /**
     * يحفظ الفشل (مع context). يسمح بإعادة المحاولة بـ key جديد فقط.
     */
    public function fail(
        string $key,
        ?int $userId,
        string $endpoint,
        int $httpStatus,
        array $responseBody,
    ): void {
        IdempotencyKey::where([
            'key' => $key,
            'user_id' => $userId,
            'endpoint' => $endpoint,
        ])->update([
            'status' => 'failed',
            'response_status' => $httpStatus,
            'response_body' => json_encode($responseBody, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * فحص شكل المفتاح. نقبل UUIDv4/ULID/random 16+ chars.
     */
    private function validateKey(string $key): void
    {
        if (strlen($key) < 16 || strlen($key) > 128) {
            throw new \InvalidArgumentException(
                'Idempotency-Key must be 16-128 chars'
            );
        }
        if (!preg_match('/^[A-Za-z0-9_\-]+$/', $key)) {
            throw new \InvalidArgumentException(
                'Idempotency-Key must contain only alphanumeric, dash, underscore'
            );
        }
    }

    /**
     * Hash الـ body باستخدام SHA-256 على JSON معيار (sorted keys).
     * نطبع المفاتيح حتى لا يختلف الـ hash بسبب ترتيب JSON.
     */
    private function hashBody(array $body): string
    {
        // نزيل headers لا تخص الـ semantic
        $filtered = $this->normalizeKeys($body);
        return hash('sha256', json_encode($filtered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * يطبع المفاتيح recursively.
     */
    private function normalizeKeys(array $arr): array
    {
        ksort($arr);
        foreach ($arr as $k => $v) {
            if (is_array($v)) {
                $arr[$k] = $this->normalizeKeys($v);
            }
        }
        return $arr;
    }
}
