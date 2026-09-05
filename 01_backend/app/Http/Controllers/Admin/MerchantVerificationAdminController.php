<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantVerificationRequest;
use App\Models\User;
use App\Services\Admin\KycEvidenceService;
use App\Services\MerchantVerificationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-MERCHANT-VERIFY-ADMIN-001 — **التاجرُ يقدّم، ولم يكن أحدٌ يعتمد.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما قِيس، ولم يُفترَض:**
 *
 *     MerchantVerificationController::adminApprove          ← مبنيّة
 *     MerchantVerificationController::adminReject           ← مبنيّة
 *     MerchantVerificationController::adminRequestResubmission ← مبنيّة
 *
 *     grep 'MerchantVerification' routes/  →  ثلاثةُ مساراتٍ للتاجر
 *                                             **وصفرٌ للإدارة**
 *
 * فالتاجرُ يرفع سجلَّه التجاريَّ وصورةَ متجره وحسابَه البنكيَّ، ويُقال
 * له «قيد المراجعة» — **ولا شاشةَ في اللوحة تعرض طلبَه، ولا مسارَ
 * يعتمده**. فيبقى عالقاً بلا نهاية، ولا خطأ في أيّ سجلّ.
 *
 * **وهذا نمطُ العطل الأكثر تكراراً في أميال باي**: مبنيٌّ ولا يُوصَل
 * إليه — وقع هنا على **ثلاث نقاطٍ دفعةً واحدة**.
 *
 * **وأربعةُ حدودٍ تحكم هذه الشاشة:**
 *
 *   ① **الوثائقُ تُعرَض ولا تُنزَّل من الشبكة العامّة.** ملفّاتُ
 *      التوثيق على قرصٍ خاصٍّ (`local`)، ويُخدَم كلٌّ منها من مسارٍ
 *      محروسٍ بصلاحيّة — فرابطٌ مباشرٌ إلى ملفٍّ يُسرَّب.
 *
 *   ② **وتوثيقُ النشاط لا يوثّق الشخص.** والخدمةُ تحكم ذلك
 *      (`AMIAL-KYC-EVIDENCE-001`)، وهذه الشاشةُ **تعرض حالةَ الهويّة
 *      الشخصيّة بجانب طلب النشاط** — فيعرف المراجعُ أنّ اعتمادَه هنا لا
 *      يفتح سقفَ المال، ولا يظنّه فتحه.
 *
 *   ③ **والرفضُ بسببٍ مكتوب.** طلبٌ يُرفض بلا سببٍ يُنتج تذكرةَ دعمٍ لا
 *      إجراءً، والتاجرُ يعيد الرفعَ نفسَه فيُرفض ثانية.
 *
 *   ④ **وإعادةُ الرفع بابٌ ثالثٌ لا وسطَ بين قبولٍ ورفض.** ورقةٌ ناقصةٌ
 *      ليست تزويراً — والرفضُ النهائيُّ عليها يُخرج تاجراً صالحاً من
 *      المنصّة.
 *
 * يظهر في : لوحة الإدارة ← 🏪 التجّار ← «توثيق التجّار». ويُوصل إليها
 * من : مساحة العمل ← مجالُ «التجّار».
 *
 * @see \Tests\Feature\MerchantVerificationAdminGuardTest
 */
class MerchantVerificationAdminController extends Controller
{
    /** أنواعُ الوثائق وأسماؤها — **مصدرٌ واحدٌ للشاشة وللتنزيل**. */
    public const DOCUMENTS = [
        'id_card_front' => ['id_card_front_path', 'الهويّة — الوجه'],
        'id_card_back' => ['id_card_back_path', 'الهويّة — الظهر'],
        'commercial_register' => ['commercial_register_path', 'السجلّ التجاريّ'],
        'store_photo' => ['store_photo_path', 'صورة المتجر'],
        'address_proof' => ['address_proof_path', 'إثبات العنوان'],
        'profession_license' => ['profession_license_path', 'رخصة المهنة'],
        'optional_document' => ['optional_document_path', 'مستند إضافيّ'],
    ];

    public function __construct(
        private MerchantVerificationService $svc,
        private KycEvidenceService $evidence,
    ) {}

    public function page(): View
    {
        return view('admin-views.amial.merchants.verification');
    }

    /** GET .../list.json?status=pending_review|verified|rejected|resubmission_required|all */
    public function listJson(Request $request): JsonResponse
    {
        $status = (string) $request->query('status', 'pending_review');

        $q = MerchantVerificationRequest::query();

        if ($status !== 'all') {
            $q->where('status', $status);
        }

        $items = $q->orderBy('created_at')->paginate(15);

        $stores = Merchant::whereIn('user_id', collect($items->items())->pluck('merchant_user_id'))
            ->get()->keyBy('user_id');

        return response()->json([
            // **والعدّاداتُ من الجدول لا من الصفحة المعروضة** — عدُّ
            // خمسةَ عشرَ صفّاً يقول «خمسةَ عشرَ طلباً» على طابورٍ فيه مئة.
            'summary' => MerchantVerificationRequest::selectRaw('status, COUNT(*) c')
                ->groupBy('status')->pluck('c', 'status'),
            'data' => collect($items->items())->map(fn (MerchantVerificationRequest $r) => [
                'id' => $r->id,
                'ulid' => $r->request_ulid,
                'merchant_user_id' => $r->merchant_user_id,
                'business_name' => $r->business_name,
                'store_name' => $stores[$r->merchant_user_id]->store_name ?? null,
                'commercial_register_number' => $r->commercial_register_number,
                'business_category' => $r->business_category,
                'city' => $r->city,
                'contact_phone' => $r->contact_phone,
                'status' => $r->status,
                'status_label' => $this->statusLabel((string) $r->status),
                'admin_note' => $r->admin_note,
                'submitted_at' => $r->created_at?->format('Y-m-d H:i'),
                'documents' => $this->documentsOf($r),
                // ② حالةُ الهويّة الشخصيّة بجانب طلب النشاط.
                'identity' => $this->identityOf($r, $request->user()),
            ])->values(),
            'current_page' => $items->currentPage(),
            'last_page' => $items->lastPage(),
            'total' => $items->total(),
        ]);
    }

    /**
     * ① الوثيقةُ تُخدَم من مسارٍ محروسٍ بصلاحيّة — لا برابطٍ مباشر.
     */
    public function document(int $id, string $type)
    {
        $req = MerchantVerificationRequest::find($id);

        if (! $req) {
            abort(404, 'الطلب غير موجود');
        }

        $column = self::DOCUMENTS[$type][0] ?? null;

        if (! $column) {
            abort(422, 'نوع مستند غير صحيح');
        }

        $path = $req->{$column};

        if (! $path || ! Storage::disk('local')->exists($path)) {
            abort(404, 'المستند غير مرفوع');
        }

        return Storage::disk('local')->response($path);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $req = MerchantVerificationRequest::find($id);

        if (! $req) {
            return response()->json(['message' => 'الطلب غير موجود'], 404);
        }

        try {
            $updated = $this->svc->approve($req, (int) $request->user()->id, $request->input('tier'));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'وُثِّق المتجر',
            'request' => $updated,
            // ② **ويُقال إن بقيت الهويّةُ الشخصيّةُ غيرَ موثّقة** — فلا
            // يظنّ المراجعُ أنّه فتح سقفَ المال وهو لم يفتحه.
            'identity' => $this->identityOf($updated, $request->user()),
        ]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        return $this->decideWithReason($request, $id, fn ($req, $adminId, $reason) =>
            $this->svc->reject($req, $adminId, $reason), 'رُفض الطلب');
    }

    public function requestResubmission(Request $request, int $id): JsonResponse
    {
        return $this->decideWithReason($request, $id, fn ($req, $adminId, $reason) =>
            $this->svc->requestResubmission($req, $adminId, $reason), 'طُلبت إعادة الرفع');
    }

    // ── داخليّ ─────────────────────────────────────────────────────────

    private function decideWithReason(Request $request, int $id, callable $act, string $ok): JsonResponse
    {
        // ③ الرفضُ بسببٍ مكتوب — «مرفوض» وحدَها تُنتج تذكرةَ دعمٍ لا إجراءً.
        $v = Validator::make($request->all(), ['reason' => 'required|string|min:5|max:1000']);

        if ($v->fails()) {
            return response()->json([
                'message' => 'سببٌ واضحٌ مطلوب (٥ أحرف على الأقل) — يصل التاجرَ ويُسجَّل في التدقيق',
                'errors' => $v->errors(),
            ], 422);
        }

        $req = MerchantVerificationRequest::find($id);

        if (! $req) {
            return response()->json(['message' => 'الطلب غير موجود'], 404);
        }

        try {
            $updated = $act($req, (int) $request->user()->id, (string) $request->input('reason'));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => $ok, 'request' => $updated]);
    }

    /** @return array<int,array{type:string,label:string,uploaded:bool,url:?string}> */
    private function documentsOf(MerchantVerificationRequest $r): array
    {
        $out = [];

        foreach (self::DOCUMENTS as $type => [$column, $label]) {
            $uploaded = ! empty($r->{$column});

            $out[] = [
                'type' => $type,
                'label' => $label,
                // **والمرفوعُ وغيرُ المرفوعِ يُقالان** — قائمةٌ تعرض
                // المرفوعَ وحدَه تُقرأ «هذا كلُّ ما طُلب».
                'uploaded' => $uploaded,
                'url' => $uploaded
                    ? route('admin.amial.merchants.verification.document', ['id' => $r->id, 'type' => $type])
                    : null,
            ];
        }

        return $out;
    }

    private function identityOf(MerchantVerificationRequest $r, ?User $reviewer): array
    {
        $merchant = User::find($r->merchant_user_id);

        if (! $merchant) {
            return ['verified' => false, 'blockers' => ['حساب التاجر غير موجود']];
        }

        $ev = $this->evidence->for($merchant, 2, $reviewer);

        return [
            'verified' => (int) ($merchant->is_kyc_verified ?? 0) === 1,
            'complete' => $ev['complete'],
            'missing' => $ev['missing'],
            'blockers' => $ev['blockers'],
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'pending_review' => 'بانتظار المراجعة',
            'verified' => 'موثَّق',
            'rejected' => 'مرفوض',
            'resubmission_required' => 'يحتاج إعادة رفع',
            'draft' => 'مسوّدة',
            default => "حالة غير مترجمة: {$status}",
        };
    }
}
