<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * P0-LEGAL — Endpoints عامّة (بدون auth) لقراءة الوثائق القانونية markdown.
 *
 * تُستخدم في:
 *   1. صفحات Onboarding (قبل التسجيل).
 *   2. الموقع الويب الخارجي.
 *   3. عرض في الـ app في صفحة "حول".
 *
 * Endpoints:
 *   GET /api/v1/amial/legal-docs           — قائمة كل الوثائق
 *   GET /api/v1/amial/legal-docs/{slug}    — وثيقة واحدة بـ markdown
 *
 * Slugs المتاحة: terms, privacy, kyc, aml
 *
 * ملاحظة: هذه مختلفة عن LegalController الذي يدير acceptance/versions في DB.
 */
class PublicLegalController extends AmialApiController // AMIAL-FIX-007
{
    private const DOCS = [
        'terms' => [
            'title' => 'شروط الاستخدام',
            'version' => '1.0',
            'updated_at' => '2026-01-01',
        ],
        'privacy' => [
            'title' => 'سياسة الخصوصية',
            'version' => '1.0',
            'updated_at' => '2026-01-01',
        ],
        'kyc' => [
            'title' => 'سياسة اعرف عميلك (KYC)',
            'version' => '1.0',
            'updated_at' => '2026-01-01',
        ],
        'aml' => [
            'title' => 'سياسة مكافحة غسيل الأموال (AML)',
            'version' => '1.0',
            'updated_at' => '2026-01-01',
        ],
    ];

    public function index(): JsonResponse
    {
        $docs = [];
        foreach (self::DOCS as $slug => $info) {
            $docs[] = array_merge($info, [
                'slug' => $slug,
                'url' => url("/api/v1/amial/legal-docs/{$slug}"),
            ]);
        }

        return new JsonResponse([
            'success' => true, 'code' => 'OK', 'message' => '',
            'errors' => (object)[], 'meta' => ['documents' => $docs],
        ]);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        if (!array_key_exists($slug, self::DOCS)) {
            return new JsonResponse([
                'success' => false, 'code' => 'NOT_FOUND',
                'message' => 'الوثيقة غير موجودة',
                'errors' => (object)[], 'meta' => (object)[],
            ], 404);
        }

        $path = resource_path("legal/ar/{$slug}.md");
        if (!file_exists($path)) {
            return new JsonResponse([
                'success' => false, 'code' => 'FILE_MISSING',
                'message' => 'ملفّ الوثيقة غير موجود',
                'errors' => (object)[], 'meta' => (object)[],
            ], 500);
        }

        $content = file_get_contents($path);
        return new JsonResponse([
            'success' => true, 'code' => 'OK', 'message' => '',
            'errors' => (object)[],
            'meta' => array_merge(self::DOCS[$slug], [
                'slug' => $slug,
                'content' => $content,
                'format' => 'markdown',
            ]),
        ]);
    }
}
