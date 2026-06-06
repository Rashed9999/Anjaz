<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CharityCampaign;
use App\Models\CharityOrganization;
use App\Models\CharitySettlement;
use App\Services\CharityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-DONATIONS-001 (v1.2) — admin endpoints
 */
class AdminCharityController extends Controller
{
    public function __construct(
        private readonly CharityService $service,
    ) {}

    // ============== Organizations ==============

    public function indexOrgs(Request $request): JsonResponse
    {
        $query = CharityOrganization::query();
        if ($status = $request->query('status')) {
            $query->where('verification_status', $status);
        }
        $items = $query->orderByDesc('id')->paginate(20);
        return $this->ok([
            'pagination' => $this->pag($items),
            'items' => $items->items(),
        ]);
    }

    public function showOrg(string $ulid): JsonResponse
    {
        $org = CharityOrganization::where('org_ulid', $ulid)
            ->withCount(['campaigns', 'donations'])
            ->first();
        if (!$org) return $this->error('NOT_FOUND', 'Organization not found', 404);

        // Admin يرى أيضاً بيانات بنكية
        $org->makeVisible(['bank_account_number', 'bank_swift', 'license_document_path']);
        return $this->ok(['organization' => $org]);
    }

    public function createOrg(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'name_ar' => 'required|string|max:200',
            'name_en' => 'sometimes|nullable|string|max:200',
            'license_number' => 'required|string|max:100',
            'description_ar' => 'required|string|max:5000',
            'contact_phone' => 'required|string|max:50',
            'contact_email' => 'sometimes|nullable|email|max:255',
            'address_ar' => 'sometimes|nullable|string|max:500',
            'bank_name' => 'sometimes|nullable|string|max:100',
            'bank_account_number' => 'sometimes|nullable|string|max:100',
            'bank_account_holder' => 'sometimes|nullable|string|max:200',
            'logo_url' => 'sometimes|nullable|url',
            'cover_image_url' => 'sometimes|nullable|url',
        ]);
        if ($v->fails()) return $this->validationError($v);

        try {
            $org = $this->service->createOrganization($v->validated(), $request->user());
        } catch (\RuntimeException $e) {
            return $this->error('CREATE_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(['organization' => $org], 'CREATED', 'تم إنشاء المنظمة', 201);
    }

    public function verifyOrg(Request $request, string $ulid): JsonResponse
    {
        $org = CharityOrganization::where('org_ulid', $ulid)->first();
        if (!$org) return $this->error('NOT_FOUND', 'Not found', 404);

        $org = $this->service->verifyOrganization($org, $request->user());
        return $this->ok(['organization' => $org], 'VERIFIED', 'تم التوثيق');
    }

    public function rejectOrg(Request $request, string $ulid): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'reason' => 'required|string|min:10|max:500',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $org = CharityOrganization::where('org_ulid', $ulid)->first();
        if (!$org) return $this->error('NOT_FOUND', 'Not found', 404);

        $org = $this->service->rejectOrganization($org, $request->user(), $request->input('reason'));
        return $this->ok(['organization' => $org], 'REJECTED', 'تم الرفض');
    }

    public function suspendOrg(Request $request, string $ulid): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'reason' => 'required|string|min:10|max:500',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $org = CharityOrganization::where('org_ulid', $ulid)->first();
        if (!$org) return $this->error('NOT_FOUND', 'Not found', 404);

        $org = $this->service->suspendOrganization($org, $request->user(), $request->input('reason'));
        return $this->ok(['organization' => $org], 'SUSPENDED', 'تم الإيقاف');
    }

    // ============== Campaigns ==============

    public function indexCampaigns(Request $request): JsonResponse
    {
        $query = CharityCampaign::with(['organization:id,name_ar', 'category']);
        if ($status = $request->query('status')) $query->where('status', $status);
        if ($orgId = $request->query('org_id')) $query->where('org_id', $orgId);
        $items = $query->orderByDesc('id')->paginate(20);
        return $this->ok([
            'pagination' => $this->pag($items),
            'items' => $items->items(),
        ]);
    }

    public function createCampaign(Request $request, string $orgUlid): JsonResponse
    {
        $org = CharityOrganization::where('org_ulid', $orgUlid)->first();
        if (!$org) return $this->error('ORG_NOT_FOUND', 'Org not found', 404);

        $v = Validator::make($request->all(), [
            'category_id' => 'required|integer|exists:charity_categories,id',
            'title_ar' => 'required|string|max:200',
            'description_ar' => 'required|string|max:5000',
            'story_md' => 'sometimes|nullable|string|max:50000',
            'target_amount' => 'required|numeric|min:1',
            'beneficiary_count' => 'sometimes|nullable|integer|min:1',
            'beneficiary_description_ar' => 'sometimes|nullable|string|max:500',
            'location_ar' => 'sometimes|nullable|string|max:200',
            'cover_image_url' => 'sometimes|nullable|url',
            'gallery_images' => 'sometimes|nullable|array|max:10',
            'deadline_at' => 'sometimes|nullable|date|after:tomorrow',
            'is_featured' => 'sometimes|boolean',
        ]);
        if ($v->fails()) return $this->validationError($v);

        try {
            $campaign = $this->service->createCampaign($org, $v->validated(), $request->user());
        } catch (\RuntimeException $e) {
            return $this->error('CREATE_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(['campaign' => $campaign], 'CREATED', 'تم إنشاء الحملة', 201);
    }

    public function approveCampaign(Request $request, string $ulid): JsonResponse
    {
        $campaign = CharityCampaign::where('campaign_ulid', $ulid)->first();
        if (!$campaign) return $this->error('NOT_FOUND', 'Not found', 404);

        try {
            $campaign = $this->service->approveCampaign($campaign, $request->user());
        } catch (\RuntimeException $e) {
            return $this->error('APPROVE_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(['campaign' => $campaign], 'APPROVED', 'تم اعتماد الحملة');
    }

    public function pauseCampaign(Request $request, string $ulid): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'reason' => 'required|string|min:10|max:500',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $campaign = CharityCampaign::where('campaign_ulid', $ulid)->first();
        if (!$campaign) return $this->error('NOT_FOUND', 'Not found', 404);

        try {
            $campaign = $this->service->pauseCampaign($campaign, $request->user(), $request->input('reason'));
        } catch (\RuntimeException $e) {
            return $this->error('PAUSE_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(['campaign' => $campaign], 'PAUSED', 'تم إيقاف الحملة');
    }

    // ============== Settlements ==============

    public function indexSettlements(Request $request): JsonResponse
    {
        $query = CharitySettlement::with('organization:id,name_ar');
        if ($status = $request->query('status')) $query->where('status', $status);
        $items = $query->orderByDesc('id')->paginate(20);
        return $this->ok([
            'pagination' => $this->pag($items),
            'items' => $items->items(),
        ]);
    }

    public function generateSettlement(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'org_ulid' => 'required|string|size:26',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after:period_start',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $org = CharityOrganization::where('org_ulid', $request->input('org_ulid'))->first();
        if (!$org) return $this->error('ORG_NOT_FOUND', 'Org not found', 404);

        try {
            $settlement = $this->service->generateSettlement(
                $org,
                Carbon::parse($request->input('period_start'))->startOfDay(),
                Carbon::parse($request->input('period_end'))->endOfDay(),
                $request->user(),
            );
        } catch (\RuntimeException $e) {
            return $this->error('GENERATE_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(['settlement' => $settlement], 'GENERATED', 'تم إنشاء التسوية', 201);
    }

    public function markTransferred(Request $request, string $ulid): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'bank_reference' => 'required|string|min:3|max:100',
            'notes' => 'sometimes|nullable|string|max:500',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $settlement = CharitySettlement::where('settlement_ulid', $ulid)->first();
        if (!$settlement) return $this->error('NOT_FOUND', 'Not found', 404);

        try {
            $settlement = $this->service->markSettlementTransferred(
                $settlement, $request->user(),
                $request->input('bank_reference'),
                $request->input('notes'),
            );
        } catch (\RuntimeException $e) {
            return $this->error('TRANSFER_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(['settlement' => $settlement], 'TRANSFERRED', 'تم تسجيل التحويل');
    }

    // ============================================================
    private function ok(array $meta, string $code = 'OK', string $message = 'OK', int $status = 200): JsonResponse
    {
        return new JsonResponse([
            'success' => true, 'code' => $code, 'message' => $message,
            'errors' => (object)[], 'meta' => $meta,
        ], $status);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => $code, 'message' => $message,
            'errors' => (object)[], 'meta' => (object)[],
        ], $status);
    }

    private function validationError($v): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => 'VALIDATION_FAILED',
            'message' => 'بيانات غير صحيحة', 'errors' => $v->errors(), 'meta' => (object)[],
        ], 422);
    }

    private function pag($items): array
    {
        return [
            'total' => $items->total(),
            'per_page' => $items->perPage(),
            'current_page' => $items->currentPage(),
        ];
    }
}
