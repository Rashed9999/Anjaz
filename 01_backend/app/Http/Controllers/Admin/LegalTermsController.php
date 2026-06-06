<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LegalTerm;
use App\Services\LegalTermsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-LEGAL-001 — Admin endpoints
 *
 *   GET  /api/v1/admin/legal/terms              — قائمة كل الإصدارات
 *   POST /api/v1/admin/legal/terms              — ينشر إصدار جديد
 *   GET  /api/v1/admin/legal/terms/{id}         — تفاصيل إصدار
 *   GET  /api/v1/admin/legal/terms/{id}/stats   — كم مستخدم قبل هذا الإصدار
 *
 * يجب أن يلتف بـ middleware 'admin' (موجود مسبقاً في bootstrap/app.php).
 */
class LegalTermsController extends Controller
{
    public function __construct(
        private readonly LegalTermsService $service,
    ) {}

    // ============================================================
    // AMIAL-ADMIN-001 (v0.8): Web wrappers (Blade views)
    // ============================================================

    public function webIndex(Request $request)
    {
        $locale = $request->query('locale');
        $q = LegalTerm::query()->orderByDesc('created_at');
        if ($locale) {
            $q->where('locale', $locale);
        }
        $terms = $q->paginate(20)->withQueryString();

        return view('admin-views.amial.legal.index', ['terms' => $terms]);
    }

    public function webCreate()
    {
        return view('admin-views.amial.legal.create');
    }

    public function webStore(Request $request)
    {
        $v = Validator::make($request->all(), [
            'version' => 'required|string|max:32',
            'locale' => 'required|string|size:2',
            'title' => 'required|string|max:255',
            'content' => 'required|string|min:50|max:1000000',
            'changelog' => 'sometimes|string|max:2000',
        ]);

        if ($v->fails()) {
            return back()->withErrors($v)->withInput();
        }

        try {
            $this->service->publishNewVersion(
                version: $request->input('version'),
                locale: $request->input('locale'),
                title: $request->input('title'),
                content: $request->input('content'),
                changelog: $request->input('changelog'),
                createdBy: auth('admin')->id() ?? auth()->id() ?? 1,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['version' => $e->getMessage()])->withInput();
        } catch (\Illuminate\Database\QueryException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                return back()->withErrors(['version' => translate('This version already exists for this locale')])->withInput();
            }
            throw $e;
        }

        return redirect()->route('admin.amial.legal.index')
            ->with('success', translate('New terms version published successfully'));
    }

    public function webShow(int $id)
    {
        $term = LegalTerm::find($id);
        if (!$term) {
            abort(404);
        }

        $acceptedCount = $term->acceptances()->count();
        $totalUsers = \App\Models\User::where('type', '!=', 0)->count();
        $acceptanceRate = $totalUsers > 0 ? round(($acceptedCount / $totalUsers) * 100, 2) : 0;

        return view('admin-views.amial.legal.show', [
            'term' => $term,
            'accepted_count' => $acceptedCount,
            'total_users' => $totalUsers,
            'acceptance_rate' => $acceptanceRate,
        ]);
    }

    // ============================================================
    // JSON endpoints (v0.7-A — kept for API consumers)
    // ============================================================

    public function index(Request $request): JsonResponse
    {
        $locale = $request->query('locale');
        $q = LegalTerm::query()->orderByDesc('created_at');
        if ($locale) {
            $q->where('locale', $locale);
        }

        $terms = $q->paginate(20);

        return new JsonResponse([
            'success' => true,
            'code' => 'OK',
            'message' => 'Legal terms list',
            'errors' => (object)[],
            'meta' => [
                'pagination' => [
                    'total' => $terms->total(),
                    'per_page' => $terms->perPage(),
                    'current_page' => $terms->currentPage(),
                    'last_page' => $terms->lastPage(),
                ],
                'items' => $terms->items(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'version' => 'required|string|max:32',
            'locale' => 'required|string|size:2',
            'title' => 'required|string|max:255',
            'content' => 'required|string|min:50|max:1000000',
            'changelog' => 'sometimes|string|max:2000',
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

        try {
            $term = $this->service->publishNewVersion(
                version: $request->input('version'),
                locale: $request->input('locale'),
                title: $request->input('title'),
                content: $request->input('content'),
                changelog: $request->input('changelog'),
                createdBy: $request->user()->id,
            );
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'success' => false,
                'code' => 'INVALID_VERSION',
                'message' => $e->getMessage(),
                'errors' => (object)[],
                'meta' => (object)[],
            ], 422);
        } catch (\Illuminate\Database\QueryException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                return new JsonResponse([
                    'success' => false,
                    'code' => 'VERSION_EXISTS',
                    'message' => 'This version already exists for this locale',
                    'errors' => (object)[],
                    'meta' => (object)[],
                ], 409);
            }
            throw $e;
        }

        return new JsonResponse([
            'success' => true,
            'code' => 'TERMS_PUBLISHED',
            'message' => 'New terms version published; old version superseded',
            'errors' => (object)[],
            'meta' => $term->toArray(),
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $term = LegalTerm::find($id);
        if (!$term) {
            return new JsonResponse([
                'success' => false, 'code' => 'NOT_FOUND',
                'message' => 'Term not found', 'errors' => (object)[], 'meta' => (object)[],
            ], 404);
        }
        return new JsonResponse([
            'success' => true, 'code' => 'OK',
            'message' => 'Term details', 'errors' => (object)[],
            'meta' => $term->toArray(),
        ]);
    }

    public function stats(int $id): JsonResponse
    {
        $term = LegalTerm::find($id);
        if (!$term) {
            return new JsonResponse([
                'success' => false, 'code' => 'NOT_FOUND',
                'message' => 'Term not found', 'errors' => (object)[], 'meta' => (object)[],
            ], 404);
        }

        $acceptedCount = $term->acceptances()->count();
        $totalUsers = \App\Models\User::where('type', '!=', 0)->count();

        return new JsonResponse([
            'success' => true,
            'code' => 'OK',
            'message' => 'Acceptance stats',
            'errors' => (object)[],
            'meta' => [
                'term_id' => $term->id,
                'version' => $term->version,
                'locale' => $term->locale,
                'accepted_count' => $acceptedCount,
                'total_users' => $totalUsers,
                'acceptance_rate' => $totalUsers > 0
                    ? round(($acceptedCount / $totalUsers) * 100, 2)
                    : 0,
            ],
        ]);
    }
}
