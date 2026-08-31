<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\KycDocumentService;
use App\Services\PiiAccessAuditService;
use App\Support\YemenGovernorates;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * AMIAL-KYC-DOCS-001 — طرفا الدائرة: العميل يرفع، والمراجع يبتّ.
 *
 * كان في لوحة الدعم زرُّ «طلب تحديث الهوية» ولا مكان يرفع إليه العميل. هذا
 * المتحكّم يغلق الدائرة من طرفيها.
 */
class KycDocumentController extends Controller
{
    public function __construct(
        private readonly KycDocumentService $kyc,
    ) {
    }

    // ==================== جانب العميل ====================

    /** POST /me/kyc/documents  {doc_type, file} */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'doc_type' => 'required|string',
            'file' => 'required|file|max:8192',
        ]);

        try {
            // AMIAL-KYC-OCR-001: تُقرأ الوثيقة فور رفعها فيجد المراجع الحقول
            // مقترحةً أمامه. والقراءة لا تُفشل الرفع مهما جرى لها.
            $doc = $this->kyc->uploadAndRead(
                $request->user(),
                (string) $request->input('doc_type'),
                $request->file('file'),
            );
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'code' => 'KYC_UPLOAD_REJECTED',
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'تمّ الرفع — سيُراجَع خلال يوم عمل',
            'data' => [
                'id' => $doc->id,
                'doc_type' => $doc->doc_type,
                'status' => $doc->status,
            ],
        ]);
    }

    /** GET /me/kyc/documents — ما رفعه العميل وحالة كلٍّ منه. */
    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();

        $docs = KycDocument::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($d) => [
                'id' => (int) $d->id,
                'doc_type' => $d->doc_type,
                'doc_label' => KycDocument::TYPE_LABELS[$d->doc_type] ?? $d->doc_type,
                'status' => $d->status,
                // سببُ الرفض يُعرَض للعميل عمداً: بدونه يرفع الصورة نفسها
                // مرّةً بعد مرّة ويتّهم النظام بالعطل.
                'rejection_reason' => $d->rejection_reason,
                'uploaded_at' => $d->created_at?->toIso8601String(),
                'reviewed_at' => $d->reviewed_at?->toIso8601String(),
            ])->all();

        return response()->json([
            'success' => true,
            'data' => [
                'documents' => $docs,
                'required_types' => KycDocument::TYPE_LABELS,
                'completeness' => $this->kyc->completenessFor($user, 2),
            ],
        ]);
    }

    // ==================== جانب المراجع ====================

    /**
     * AMIAL-KYC-PANEL-001 — الشاشة التي كانت ناقصة.
     *
     * `queue` و`file` و`approve` و`reject` بُنيت كلّها ولم تُسجَّل إلّا على
     * سطح الـAPI. فالعميل صار يرفع مستنده، والمستند يصل، ولا مراجعَ يملك
     * شاشةً يفتحه فيها — أي أنّ الدائرة التي قيل إنها أُغلقت أُغلق طرفٌ منها
     * وحده.
     */
    public function page()
    {
        return view('admin-views.amial.kyc.index', [
            'governorates' => YemenGovernorates::all(),
        ]);
    }

    /** GET /admin/kyc/queue */
    public function queue(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['queue' => $this->kyc->pendingQueue()],
        ]);
    }

    /**
     * حسابات اكتملت وثائقها لكن لم يصدر عليها قرار تفعيل نهائي بعد.
     *
     * فصل المستند عن قرار الحساب مقصود، لكن لا يجوز أن يختفي الحساب من
     * الطابور بمجرد اعتماد آخر صورة؛ لذلك لهذا القرار طابور مستقل ظاهر.
     */
    public function activationQueue(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['queue' => $this->kyc->activationQueue()],
        ]);
    }

    /**
     * اعتماد الحساب النهائي بعد اكتمال الوثائق.
     *
     * محافظة السكن إلزامية هنا حتى لا تُرسل المنصة رسالة نجاح لحساب تبقى
     * منطقته UNKNOWN، فيصبح موثَّقاً ظاهرياً وعاجزاً عن استقبال التحويلات.
     */
    public function activateAccount(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'target_tier' => ['required', 'integer', Rule::in([2])],
            'governorate' => ['required', 'string', Rule::in(YemenGovernorates::codes())],
        ]);

        $account = User::findOrFail($id);
        $governorate = (string) $request->input('governorate');

        try {
            // لا تُخمن المحافظة من الاسم أو رقم الهاتف. هذا اختيار مراجع
            // ظاهر ومراجَع في ملف الهوية، ثم ZoneAssignmentService يحوّله
            // إلى المنطقة التشغيلية ويسجل الأثر.
            $account->residence_governorate = $governorate;
            $account->save();

            $account = $this->kyc->decideAccountVerification(
                user: $account,
                reviewer: $request->user(),
                approve: true,
                targetTier: (int) $request->input('target_tier'),
            );
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'code' => 'KYC_ACCOUNT_ACTIVATION_REJECTED',
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم اعتماد الحساب وتفعيل التحويلات الداخلية بحسب حدود الفئة الثانية.',
            'data' => [
                'user_id' => (int) $account->id,
                'is_kyc_verified' => (int) $account->is_kyc_verified === 1,
                'kyc_tier' => (int) $account->kyc_tier,
                'residence_governorate' => $account->residence_governorate,
                'zone_code' => $account->zone_code,
            ],
        ]);
    }

    /** GET /admin/kyc/documents/{id}/file — الملفّ مفكوكاً، للمراجع وحده. */
    public function file(Request $request, int $id)
    {
        $doc = KycDocument::findOrFail($id);

        // فتحُ صورة هويّة أشدُّ أنواع الوصول إلى بيانات شخصية — يُسجَّل دائماً.
        app(PiiAccessAuditService::class)->logAccess(
            actorUserId: $request->user()->id,
            subjectType: 'user',
            subjectId: (int) $doc->user_id,
            fieldName: 'kyc_document:' . $doc->doc_type,
            accessType: 'view',
            accessReason: (string) $request->query('reason', 'مراجعة مستند هوية'),
        );

        return response($this->kyc->decrypt($doc), 200, [
            'Content-Type' => $doc->original_mime ?: 'application/octet-stream',
            // لا تُخزَّن صورة هويّة في وسيطٍ ولا في متصفّح.
            'Cache-Control' => 'no-store, private',
            'Content-Disposition' => 'inline',
        ]);
    }

    /**
     * GET /admin/kyc/documents/{id}/ocr — ما قرأه المحرّك.
     *
     * يُقرأ منفصلاً عن الصورة لا مدمجاً بها: الصورة تُحمَّل في إطارٍ ثنائيّ،
     * وهذه بيانات. ودمجُهما يجعل عرض الحقول ينتظر تحميل صورةٍ بحجم ميغابايت.
     */
    public function ocr(Request $request, int $id): JsonResponse
    {
        $doc = KycDocument::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => app(\App\Services\KycOcrService::class)->forReviewer($doc),
        ]);
    }

    /** POST /admin/kyc/documents/{id}/fields — إقرار المراجع للحقول. */
    public function confirmFields(Request $request, int $id): JsonResponse
    {
        $doc = KycDocument::findOrFail($id);

        try {
            $doc = app(\App\Services\KycOcrService::class)
                ->confirmFields($doc, $request->user(), (array) $request->input('fields', []));
        } catch (DomainException $e) {
            return response()->json([
                'success' => false, 'code' => 'KYC_FIELDS_REJECTED',
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'أُقرّت الحقول',
            'data' => ['id' => $doc->id],
        ]);
    }

    /** POST /admin/kyc/documents/{id}/reread — إعادة القراءة. */
    public function reread(Request $request, int $id): JsonResponse
    {
        $doc = KycDocument::findOrFail($id);
        $doc = app(\App\Services\KycOcrService::class)->process($doc);

        return response()->json([
            'success' => true,
            'message' => 'أُعيدت القراءة',
            'data' => app(\App\Services\KycOcrService::class)->forReviewer($doc),
        ]);
    }

    /** POST /admin/kyc/documents/{id}/approve  {expires_at?} */
    public function approve(Request $request, int $id): JsonResponse
    {
        $doc = KycDocument::findOrFail($id);

        try {
            $doc = $this->kyc->approve(
                $doc, $request->user(), $request->input('expires_at'),
            );
        } catch (DomainException $e) {
            return response()->json([
                'success' => false, 'code' => 'KYC_REVIEW_REJECTED',
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'اعتُمد المستند',
            'data' => [
                'id' => $doc->id,
                'completeness' => $this->kyc->completenessFor(
                    User::findOrFail($doc->user_id), 2,
                ),
            ],
        ]);
    }

    /** POST /admin/kyc/documents/{id}/reject  {reason} */
    public function reject(Request $request, int $id): JsonResponse
    {
        $request->validate(['reason' => 'required|string|min:3']);

        $doc = KycDocument::findOrFail($id);

        try {
            $doc = $this->kyc->reject($doc, $request->user(), (string) $request->input('reason'));
        } catch (DomainException $e) {
            return response()->json([
                'success' => false, 'code' => 'KYC_REVIEW_REJECTED',
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'رُفض المستند وأُبلغ العميل بالسبب',
            'data' => ['id' => $doc->id, 'reason' => $doc->rejection_reason],
        ]);
    }
}
