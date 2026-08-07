<?php

namespace App\Http\Middleware;

use App\Exceptions\DuplicateTransactionException;
use App\Services\IdempotencyService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AMIAL-REFACTOR-CORE-001
 *
 * EnforceIdempotency — middleware يطبق idempotency على endpoint المالي.
 *
 * كيف يُستخدم في routes/api.php:
 *
 *   Route::middleware(['auth:api', 'amial.idempotency'])->group(function () {
 *       Route::post('/send-money', ...);
 *       Route::post('/cash-out', ...);
 *       // ...
 *   });
 *
 * البروتوكول:
 *   - العميل يجب أن يرسل header: Idempotency-Key: <uuid-or-ulid>
 *   - بدونه: 400 IDEMPOTENCY_KEY_REQUIRED (مع رسالة شارحة)
 *   - مع key جديد: نسجل processing، نمرر للـ controller، نسجل نتيجة
 *   - مع key مكرر same body: نُعيد الـ response المحفوظ (لا نُعيد التنفيذ)
 *   - مع key مكرر different body: 409 CONFLICT
 *   - مع key في progress: 409 IN_PROGRESS (يطلب من العميل الانتظار)
 */
class EnforceIdempotency
{
    public function __construct(
        private readonly IdempotencyService $idempotency,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // GET requests لا تحتاج idempotency (idempotent بطبيعتها)
        if (!$request->isMethod('POST') && !$request->isMethod('PUT') && !$request->isMethod('PATCH')) {
            return $next($request);
        }

        $key = $request->header('Idempotency-Key');

        // AMIAL-PILOT-E2E-001 — **وحقلُ الجسد يُقرأ قبل التوليد.**
        //
        // `MerchantPaymentController::pay` يقبل `idempotency_key` في
        // قواعد التحقّق، وهذا الوسيطُ كان يقرأ الترويسةَ وحدها. فمن أرسله
        // في الجسد — والمتحكّمُ **يقبله صراحةً** — يُولَّد له مفتاحٌ
        // عشوائيٌّ بدله، فتصير كلُّ إعادةٍ مفتاحاً جديداً و**الحمايةُ صفر**.
        //
        // وحقلٌ يُقبل في التحقّق ثمّ يُهمَل في التنفيذ أسوأ من حقلٍ
        // مرفوض: المرفوضُ يُرى، والمُهمَلُ يُطمئن. فيُقرأ من حيث أُرسل.
        if (empty($key)) {
            $body = $request->input('idempotency_key');
            $key = (is_string($body) && $body !== '') ? $body : null;
        }

        if (empty($key)) {
            // ولا يُرفض الطلبُ بـ٤٠٠ حين يغيب المفتاحُ من الاثنين: ذاك
            // يكسر عملاءَ قدامى. يُولَّد مفتاحٌ — والحمايةُ حينها منعدمةٌ
            // بحقّ، ولا تُدَّعى.
            $key = (string) \Illuminate\Support\Str::ulid();
        }

        $userId = $request->user()?->id;
        $endpoint = $request->method() . ' ' . $request->path();

        try {
            $check = $this->idempotency->begin(
                key: $key,
                userId: $userId,
                endpoint: $endpoint,
                requestBody: $request->all(),
            );
        } catch (DuplicateTransactionException $e) {
            return new JsonResponse($e->toApiArray(), 409);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse(
                code: 'IDEMPOTENCY_KEY_INVALID',
                message: $e->getMessage(),
                status: 400,
            );
        }

        // حالات الـ replay
        if ($check['status'] === 'replay') {
            $body = $check['response'] ?? ['success' => true];
            return new JsonResponse($body, $check['http_status'] ?? 200, [
                'X-Idempotent-Replay' => '1',
                'X-Original-Transaction-Id' => $check['transaction_id'] ?? '',
            ]);
        }

        if ($check['status'] === 'in_progress') {
            return $this->errorResponse(
                code: 'IDEMPOTENCY_IN_PROGRESS',
                message: 'A request with this Idempotency-Key is still being processed. Try again shortly.',
                status: 409,
            );
        }

        if ($check['status'] === 'failed_previously') {
            return $this->errorResponse(
                code: 'IDEMPOTENCY_FAILED_PREVIOUSLY',
                message: 'This Idempotency-Key was used for a request that failed. Use a new key.',
                status: 409,
            );
        }

        // status === 'new' → نُكمل
        $response = $next($request);

        // بعد ما عاد الـ response، نسجل النتيجة لـ replay مستقبلي
        $status = $response->getStatusCode();
        $responseBody = $this->extractJsonBody($response);

        if ($status >= 200 && $status < 300) {
            // نجاح — نخزن للـ replay
            $transactionId = $responseBody['data']['transaction_id'] ?? $responseBody['transaction_id'] ?? null;
            $this->idempotency->complete(
                key: $key,
                userId: $userId,
                endpoint: $endpoint,
                httpStatus: $status,
                responseBody: $responseBody,
                transactionId: $transactionId,
            );
        } else {
            // فشل (4xx/5xx) — نسجل failed، نطلب key جديد للمحاولة التالية
            $this->idempotency->fail(
                key: $key,
                userId: $userId,
                endpoint: $endpoint,
                httpStatus: $status,
                responseBody: $responseBody,
            );
        }

        return $response;
    }

    private function extractJsonBody(Response $response): array
    {
        if (!$response instanceof JsonResponse) {
            return ['raw' => $response->getContent()];
        }
        $data = $response->getData(true);
        return is_array($data) ? $data : ['data' => $data];
    }

    private function errorResponse(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'message' => $message,
            'code' => $code,
            'errors' => (object)[],
            'meta' => (object)[],
        ], $status);
    }
}
