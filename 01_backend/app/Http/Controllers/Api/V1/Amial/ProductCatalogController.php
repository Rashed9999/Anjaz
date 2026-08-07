<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Services\Catalog\ProductCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AMIAL-CATALOG-001 — الكتالوج من جهة التاجر.
 *
 * GET /amial/catalog/lookup?barcode=…
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وهذا غيرُ `barcode/lookup` القائم — ولا يحلّ محلّه.**
 *
 * ذاك يبحث في **منتجات التاجر نفسِه** ليضيفها للسلّة عند البيع. وهذا
 * يبحث في **الكتالوج المشترك** ليملأ الحقول عند إنشاء صنفٍ جديد.
 * فالأوّل للبيع والثاني للإدخال، ويُنادَيان في شاشتين مختلفتين.
 */
class ProductCatalogController extends Controller
{
    public function __construct(private readonly ProductCatalogService $catalog) {}

    public function lookup(Request $request): JsonResponse
    {
        $barcode = trim((string) $request->query('barcode', ''));

        if ($barcode === '') {
            return $this->err('NO_BARCODE', 'الباركود مطلوب', 422);
        }

        // **ويُقال سببُ عدم البحث، لا يُردّ «غير موجود» فحسب.**
        //
        // فمن مسح باركوداً داخليّاً يستحقّ أن يعرف أنّه لن يُوجد في أيّ
        // كتالوجٍ أبداً — لا أن يُعيد المسحَ ظانّاً أنّ الشبكة تعطّلت.
        $reason = $this->catalog->rejectionReason($barcode);

        if ($reason !== null) {
            return new JsonResponse([
                'success' => false,
                'code' => 'NOT_GLOBAL',
                'message' => $reason,
                'errors' => (object) [],
                'meta' => ['barcode' => $barcode, 'is_global' => false],
            ], 404);
        }

        $entry = $this->catalog->find($barcode);

        if (!$entry) {
            return new JsonResponse([
                'success' => false,
                'code' => 'NOT_FOUND',
                'message' => 'لا يوجد هذا الصنف في الكتالوج — أدخل اسمه وسيفيد من بعدك',
                'errors' => (object) [],
                'meta' => ['barcode' => $barcode, 'is_global' => true],
            ], 404);
        }

        return new JsonResponse([
            'success' => true,
            'code' => 'CATALOG_HIT',
            'message' => 'وُجد في الكتالوج',
            'errors' => (object) [],
            'meta' => [
                'barcode' => $entry->barcode,
                'name' => $entry->name,
                'category' => $entry->category,
                'unit' => $entry->unit,
                'image_path' => $entry->image_path,

                // **وتُقال درجةُ الثقة صراحةً.**
                // «مقترَح» غيرُ «موثّق»، ومن يرى اسماً بلا بيانِ مصدره
                // يحسبه مُراجَعاً. (القاعدة السابعة.)
                'status' => $entry->status,
                'is_verified' => $entry->status === 'verified',
                'adoption_count' => (int) $entry->adoption_count,
            ],
        ]);
    }

    private function err(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => $code, 'message' => $message,
            'errors' => (object) [], 'meta' => (object) [],
        ], $status);
    }
}
