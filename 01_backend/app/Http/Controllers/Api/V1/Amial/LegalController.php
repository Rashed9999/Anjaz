<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Services\LegalTermsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-LEGAL-001
 *
 * APIs:
 *   GET  /api/v1/amial/legal/status        — هل المستخدم قبل آخر إصدار؟
 *   GET  /api/v1/amial/legal/current       — يعيد الإصدار الحالي (لعرضه قبل القبول)
 *   POST /api/v1/amial/legal/accept        — يسجل القبول
 *
 * مهم: هذه الـ endpoints **خارج** middleware RequireTermsAcceptance
 * (وإلا ستحدث deadlock — المستخدم لا يستطيع قبول السياسة لأنه لم يقبلها).
 */
class LegalController extends Controller
{
    public function __construct(
        private readonly LegalTermsService $service,
    ) {}

    /**
     * GET /api/v1/amial/legal/status
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return new JsonResponse([
                'success' => false,
                'code' => 'AUTH_REQUIRED',
                'message' => 'Authentication required',
                'errors' => (object)[],
                'meta' => (object)[],
            ], 401);
        }

        $locale = $this->detectLocale($request);
        $needs = $this->service->needsAcceptance($user, $locale);
        $current = $this->service->currentTerm($locale);

        return new JsonResponse([
            'success' => true,
            'code' => $needs ? 'TERMS_ACCEPTANCE_REQUIRED' : 'TERMS_ACCEPTED',
            'message' => $needs ? 'User must accept latest terms' : 'User has accepted latest terms',
            'errors' => (object)[],
            'meta' => [
                'needs_acceptance' => $needs,
                'current_version' => $current?->version,
                'current_locale' => $current?->locale,
                'title' => $current?->title,
            ],
        ]);
    }

    /**
     * GET /api/v1/amial/legal/current
     */
    public function current(Request $request): JsonResponse
    {
        $locale = $this->detectLocale($request);
        $term = $this->service->currentTerm($locale);

        if (!$term) {
            return new JsonResponse([
                'success' => false,
                'code' => 'TERMS_NOT_FOUND',
                'message' => 'No current terms published for locale ' . $locale,
                'errors' => (object)[],
                'meta' => (object)[],
            ], 404);
        }

        return new JsonResponse([
            'success' => true,
            'code' => 'TERMS_OK',
            'message' => 'Current terms',
            'errors' => (object)[],
            'meta' => [
                'id' => $term->id,
                'version' => $term->version,
                'locale' => $term->locale,
                'title' => $term->title,
                'content' => $term->content,
                'changelog' => $term->changelog,
                'effective_at' => $term->effective_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * POST /api/v1/amial/legal/accept
     *
     * Body:
     *   { "version": "1.0", "locale": "ar" (optional) }
     *
     * يفحص أن الـ version المُمررة هي الإصدار الحالي (لا نقبل قبول لإصدار قديم).
     */
    public function accept(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return new JsonResponse([
                'success' => false,
                'code' => 'AUTH_REQUIRED',
                'message' => 'Authentication required',
                'errors' => (object)[],
                'meta' => (object)[],
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'version' => 'required|string|max:32',
            'locale' => 'sometimes|string|size:2',
            'device_id' => 'sometimes|string|max:128',
        ]);

        if ($validator->fails()) {
            return new JsonResponse([
                'success' => false,
                'code' => 'VALIDATION_FAILED',
                'message' => 'Invalid input',
                'errors' => $validator->errors(),
                'meta' => (object)[],
            ], 422);
        }

        $locale = $request->input('locale') ?? $this->detectLocale($request);
        $current = $this->service->currentTerm($locale);

        if (!$current) {
            return new JsonResponse([
                'success' => false,
                'code' => 'TERMS_NOT_FOUND',
                'message' => 'No current terms',
                'errors' => (object)[],
                'meta' => (object)[],
            ], 404);
        }

        // لا نقبل قبول لإصدار قديم — يجب أن يكون الـ version المُرسلة = الإصدار الحالي
        if ($request->input('version') !== $current->version) {
            return new JsonResponse([
                'success' => false,
                'code' => 'TERMS_VERSION_OUTDATED',
                'message' => 'A newer version is available, please refresh',
                'errors' => (object)[],
                'meta' => [
                    'submitted_version' => $request->input('version'),
                    'current_version' => $current->version,
                ],
            ], 409);
        }

        $acceptance = $this->service->accept(
            user: $user,
            term: $current,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
            deviceId: $request->input('device_id'),
        );

        return new JsonResponse([
            'success' => true,
            'code' => 'TERMS_ACCEPTED',
            'message' => $acceptance ? 'Terms accepted' : 'Already accepted',
            'errors' => (object)[],
            'meta' => [
                'version' => $current->version,
                'locale' => $current->locale,
                'accepted_at' => $acceptance?->accepted_at?->toIso8601String(),
            ],
        ]);
    }

    private function detectLocale(Request $request): string
    {
        $locale = $request->header('X-Locale')
            ?? $request->header('Accept-Language')
            ?? 'ar';
        $locale = strtolower(substr($locale, 0, 2));
        return in_array($locale, ['ar', 'en']) ? $locale : 'ar';
    }
}
