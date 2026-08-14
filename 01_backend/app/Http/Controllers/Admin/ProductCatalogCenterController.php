<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\Catalog\CatalogImageService;
use App\Services\Catalog\ProductCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * AMIAL-CATALOG-001 — مركز كتالوج المنتجات.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **«مفتوحٌ بمراجعة» — والمراجعةُ هي هذه الشاشة.**
 *
 * التجّار يقترحون، والإدارةُ توثّق أو ترفض أو تُصحّح الاسم. وبلا هذه
 * الشاشة يصير «مفتوحاً» بلا «مراجعة»: كتالوجٌ يمتلئ بأسماءٍ متضاربةٍ
 * وأخطاءِ إملاءٍ ولا أحدَ يحسمها.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولا سعرَ في هذه الشاشة ولا كميّة.**
 *
 * ليس إغفالاً: الجدولُ نفسُه لا يحمل عموداً لهما. فرؤيةُ تاجرٍ لسعر
 * منافسه تسريبٌ تجاريّ — والحدُّ بنيويٌّ لا بصريّ.
 */
class ProductCatalogCenterController extends Controller
{
    public function __construct(
        private readonly ProductCatalogService $catalog,
        private readonly AuditService $audit,
        private readonly CatalogImageService $images,
    ) {}

    /**
     * AMIAL-CATALOG-IMAGE-001 — **رفعُ صورة الصنف.**
     *
     * ══════════════════════════════════════════════════════════════
     * `image_path` عمودٌ في الجدول منذ بُني الكتالوج، **ويُقرأ في
     * موضعين ولا يُكتب في موضعٍ واحد.** فالصورةُ كانت وعداً في المخطّط
     * لا في الشاشة — مبنيٌّ ولا يُوصَل إليه.
     *
     * **وتُصغَّر إلى ٤٠٠ بكسل قبل الحفظ** — وهذا هو الفرقُ بين ٢٫٥
     * غيغابايت و٥٠ عند مئة ألف صنف. التفصيلُ في `CatalogImageService`.
     * ══════════════════════════════════════════════════════════════
     */
    public function uploadImage(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'file' => 'required|file|mimes:jpg,jpeg,png,webp|max:5120',
        ], [], ['file' => 'الصورة']);

        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }

        try {
            $path = $this->images->store($request->file('file'));
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'image_path' => $path,
            'url' => asset('storage/' . $path),
        ]);
    }

    public function page(): View
    {
        return view('admin-views.amial.catalog.index');
    }

    // ══════════════════════════════════════════════════════════════
    // المؤشّرات — تُحسب من المصدر (القاعدة السادسة)
    // ══════════════════════════════════════════════════════════════

    public function stats(): JsonResponse
    {
        $base = DB::table('product_catalog_entries')->whereNull('deleted_at');

        $total = (int) (clone $base)->count();
        $verified = (int) (clone $base)->where('status', 'verified')->count();

        // **«لا أصناف» ليست «٠٪ توثيق»** (القاعدة السابعة).
        $rate = $total > 0 ? round(($verified / $total) * 100, 1) : null;

        return response()->json(['success' => true, 'meta' => [
            'total' => $total,
            'verified' => $verified,
            'proposed' => (int) (clone $base)->where('status', 'proposed')->count(),
            'rejected' => (int) (clone $base)->where('status', 'rejected')->count(),
            'conflicts' => $this->catalog->conflictCount(),
            'verified_rate' => $rate,
            'added_this_week' => (int) (clone $base)->where('created_at', '>=', now()->subDays(7))->count(),
        ]]);
    }

    // ══════════════════════════════════════════════════════════════
    // الجدول
    // ══════════════════════════════════════════════════════════════

    public function rows(Request $request): JsonResponse
    {
        $q = DB::table('product_catalog_entries as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.proposed_by_user_id')
            ->whereNull('e.deleted_at')
            ->select('e.id', 'e.barcode', 'e.name', 'e.category', 'e.unit', 'e.status',
                     'e.adoption_count', 'e.image_path', 'e.created_at',
                     'u.f_name as proposer_f', 'u.l_name as proposer_l');

        if ($s = trim((string) $request->query('search'))) {
            $q->where(function ($w) use ($s) {
                $w->where('e.barcode', 'like', "%{$s}%")
                  ->orWhere('e.name', 'like', "%{$s}%")
                  ->orWhere('e.category', 'like', "%{$s}%");
            });
        }

        if ($st = $request->query('status')) {
            $q->where('e.status', $st);
        }

        // **فلترُ التعارض** — وهو أهمُّ ما تفتح عليه الإدارةُ الشاشة.
        if ($request->query('only_conflicts') === '1') {
            $q->whereIn('e.barcode', function ($sub) {
                $sub->select('barcode')->from('product_catalog_suggestions')
                    ->groupBy('barcode')->havingRaw('COUNT(DISTINCT name) > 1');
            });
        }

        $sort = in_array($request->query('sort'), ['adoption_count', 'created_at', 'name'], true)
            ? $request->query('sort') : 'created_at';

        $q->orderBy('e.' . $sort, $request->query('dir') === 'asc' ? 'asc' : 'desc');

        $page = $q->paginate(25)->withQueryString();

        return response()->json(['success' => true, 'meta' => [
            'rows' => collect($page->items())->map(fn ($r) => [
                'id' => $r->id,
                'barcode' => $r->barcode,
                'name' => $r->name,
                'category' => $r->category,
                'unit' => $r->unit,
                'status' => $r->status,
                'adoption_count' => (int) $r->adoption_count,
                'image_path' => $r->image_path,
                'proposer' => trim(($r->proposer_f ?? '') . ' ' . ($r->proposer_l ?? '')) ?: '—',
                'created_at' => $r->created_at,
            ])->all(),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
        ]]);
    }

    /** تفصيلُ صنفٍ — ومعه كلُّ الأسماء المتنافسة عليه. */
    public function show(int $id): JsonResponse
    {
        $e = DB::table('product_catalog_entries')->whereNull('deleted_at')->find($id);

        if (!$e) {
            return response()->json(['success' => false, 'message' => 'الصنف غير موجود'], 404);
        }

        $suggestions = $this->catalog->conflicts($e->barcode);

        return response()->json(['success' => true, 'meta' => [
            'entry' => $e,
            'suggestions' => collect($suggestions)->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'category' => $s->category,
                'unit' => $s->unit,
                'merchant' => trim(($s->f_name ?? '') . ' ' . ($s->l_name ?? '')) ?: '—',
                'phone' => $s->phone,
                'created_at' => $s->created_at,
            ])->all(),
            'distinct_names' => collect($suggestions)->pluck('name')->unique()->count(),
        ]]);
    }

    // ══════════════════════════════════════════════════════════════
    // المراجعة
    // ══════════════════════════════════════════════════════════════

    /**
     * توثيقٌ أو رفضٌ أو تصحيحُ اسم — فعلٌ واحدٌ بثلاث صور.
     *
     * **والرفضُ يمنع الاقتراحَ ثانيةً**: `find()` لا يُرجع المرفوض. ولولا
     * ذلك لعادت الإدارةُ ترفض الصنفَ نفسَه كلَّ أسبوع — **مراجعةٌ بلا أثر.**
     */
    public function review(Request $request, int $id): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'action' => 'required|in:verify,reject',
            'name' => 'sometimes|nullable|string|max:160',
            'category' => 'sometimes|nullable|string|max:80',
            'unit' => 'sometimes|nullable|string|max:24',
            'note' => 'sometimes|nullable|string|max:255',
            'image_path' => 'sometimes|nullable|string|max:255',
        ]);

        if ($v->fails()) {
            return response()->json([
                'success' => false, 'message' => $v->errors()->first(),
            ], 422);
        }

        $e = DB::table('product_catalog_entries')->whereNull('deleted_at')->find($id);

        if (!$e) {
            return response()->json(['success' => false, 'message' => 'الصنف غير موجود'], 404);
        }

        $action = $request->input('action');
        $newName = trim((string) $request->input('name', ''));

        // **والقديمةُ تُحذف حين تُستبدل.** وإلّا تراكمت صورٌ لا يشير إليها
        // صفٌّ واحد — ونموُّ قرصٍ لا يفسّره أحدٌ أسوأُ من نموٍّ مفهوم.
        $newImage = $request->input('image_path', $e->image_path);
        if ($newImage !== $e->image_path) {
            $this->images->forget($e->image_path);
        }

        DB::table('product_catalog_entries')->where('id', $id)->update([
            'image_path' => $newImage,
            'status' => $action === 'verify' ? 'verified' : 'rejected',
            'name' => $newName !== '' ? $newName : $e->name,
            'category' => $request->input('category', $e->category),
            'unit' => $request->input('unit', $e->unit),
            'reviewed_by_user_id' => $request->user()?->id,
            'reviewed_at' => now(),
            'review_note' => $request->input('note'),
            'updated_at' => now(),
        ]);

        // الاقتراحاتُ تُغلق — فلا تبقى الشاشةُ تعرض ما حُسم.
        DB::table('product_catalog_suggestions')
            ->where('barcode', $e->barcode)
            ->update(['status' => $action === 'verify' ? 'applied' : 'dismissed', 'updated_at' => now()]);

        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $request->user()?->id,
            'subject_type' => 'setting',
            'subject_id' => $id,
            'action' => 'CATALOG_ENTRY_REVIEWED',
            'decision_code' => $action === 'verify' ? 'APPROVED' : 'REJECTED',
            'severity' => 'info',
            'context' => [
                'barcode' => $e->barcode,
                'from_name' => $e->name,
                'to_name' => $newName !== '' ? $newName : $e->name,
                'note' => $request->input('note'),
            ],
        ]);

        return response()->json(['success' => true, 'message' => $action === 'verify' ? 'وُثّق الصنف' : 'رُفض الصنف']);
    }

    /** إضافةٌ يدويّةٌ من الإدارة — تُوثَّق فوراً. */
    public function store(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'barcode' => 'required|string|max:32',
            'name' => 'required|string|max:160',
            'category' => 'sometimes|nullable|string|max:80',
            'unit' => 'sometimes|nullable|string|max:24',
            'image_path' => 'sometimes|nullable|string|max:255',
        ]);

        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }

        $barcode = trim((string) $request->input('barcode'));

        // **والإدارةُ لا تتجاوز قاعدةَ الباركود الداخليّ.**
        // فلو أُدخل يدويّاً لأفسد الكتالوجَ كما يُفسده اقتراحُ تاجر —
        // والقاعدةُ لا تُخفَّف لمن يملك الصلاحيّة.
        $reason = $this->catalog->rejectionReason($barcode);

        if ($reason !== null) {
            return response()->json(['success' => false, 'message' => $reason], 422);
        }

        if (DB::table('product_catalog_entries')->where('barcode', $barcode)->exists()) {
            return response()->json([
                'success' => false, 'message' => 'هذا الباركود موجود — افتح تفصيله لتعديله',
            ], 422);
        }

        DB::table('product_catalog_entries')->insert([
            'entry_ulid' => (string) Str::ulid(),
            'barcode' => $barcode,
            'name' => trim((string) $request->input('name')),
            'category' => $request->input('category'),
            'unit' => $request->input('unit'),
            'image_path' => $request->input('image_path'),
            'status' => 'verified',
            'adoption_count' => 0,
            'reviewed_by_user_id' => $request->user()?->id,
            'reviewed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'أُضيف الصنف موثّقاً']);
    }

    // ══════════════════════════════════════════════════════════════
    // التصدير — والاستيراد الأوّليّ
    // ══════════════════════════════════════════════════════════════

    public function export(Request $request): StreamedResponse
    {
        $rows = DB::table('product_catalog_entries')
            ->whereNull('deleted_at')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderBy('barcode')->limit(20000)->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['barcode', 'name', 'category', 'unit', 'status', 'adoption_count']);

            foreach ($rows as $r) {
                fputcsv($out, [$r->barcode, $r->name, $r->category, $r->unit, $r->status, $r->adoption_count]);
            }

            fclose($out);
        }, 'product-catalog-' . Carbon::now()->format('Ymd-His') . '.csv',
           ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * استيرادٌ جماعيّ — يبدأ الكتالوجُ ممتلئاً لا فارغاً.
     *
     * **ويُقال كم قُبل وكم رُفض ولماذا** — فاستيرادٌ يردّ «تمّ» ويبتلع
     * ألفَ صفٍّ مرفوضٍ صامتاً أسوأ من استيرادٍ يفشل.
     */
    public function import(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'file' => 'required|file|mimes:csv,txt|max:10240',
        ]);

        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }

        $path = $request->file('file')->getRealPath();
        $handle = fopen($path, 'r');

        if (!$handle) {
            return response()->json(['success' => false, 'message' => 'تعذّر فتح الملفّ'], 422);
        }

        $added = 0;
        $skipped = 0;
        $reasons = [];
        $line = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $line++;

            if ($line === 1 && isset($row[0]) && !ctype_digit(trim((string) $row[0]))) {
                continue; // ترويسة
            }

            $barcode = trim((string) ($row[0] ?? ''));
            $name = trim((string) ($row[1] ?? ''));

            if ($name === '') {
                $skipped++;
                $reasons['اسم فارغ'] = ($reasons['اسم فارغ'] ?? 0) + 1;
                continue;
            }

            $reason = $this->catalog->rejectionReason($barcode);

            if ($reason !== null) {
                $skipped++;
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
                continue;
            }

            if (DB::table('product_catalog_entries')->where('barcode', $barcode)->exists()) {
                $skipped++;
                $reasons['موجود مسبقاً'] = ($reasons['موجود مسبقاً'] ?? 0) + 1;
                continue;
            }

            DB::table('product_catalog_entries')->insert([
                'entry_ulid' => (string) Str::ulid(),
                'barcode' => $barcode,
                'name' => $name,
                'category' => trim((string) ($row[2] ?? '')) ?: null,
                'unit' => trim((string) ($row[3] ?? '')) ?: null,
                'status' => 'verified',
                'adoption_count' => 0,
                'reviewed_by_user_id' => $request->user()?->id,
                'reviewed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $added++;
        }

        fclose($handle);

        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $request->user()?->id,
            'subject_type' => 'setting',
            'subject_id' => 0,
            'action' => 'CATALOG_IMPORTED',
            'decision_code' => 'APPLIED',
            'severity' => 'info',
            'context' => ['added' => $added, 'skipped' => $skipped, 'reasons' => $reasons],
        ]);

        return response()->json(['success' => true, 'message' => "أُضيف {$added} · تُخطّي {$skipped}", 'meta' => [
            'added' => $added,
            'skipped' => $skipped,
            'reasons' => $reasons,
        ]]);
    }
}
