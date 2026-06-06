<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\CharityCampaign;
use App\Models\CharityCategory;
use App\Models\CharityOrganization;
use App\Models\Donation;
use App\Services\DonationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-DONATIONS-001 (v1.2) — customer endpoints
 */
class DonationsController extends Controller
{
    public function __construct(
        private readonly DonationsService $service,
    ) {}

    /** GET /api/v1/amial/donations/categories */
    public function categories(): JsonResponse
    {
        $cats = CharityCategory::where('is_active', true)
            ->orderBy('sort_order')
            ->get();
        return $this->ok(['categories' => $cats]);
    }

    /** GET /api/v1/amial/donations/campaigns?category=...&featured=1 */
    public function campaigns(Request $request): JsonResponse
    {
        $query = CharityCampaign::with(['organization:id,name_ar,logo_url,verification_status', 'category'])
            ->acceptingDonations()
            ->orderByDesc('is_featured')
            ->orderByDesc('id');

        if ($categoryCode = $request->query('category')) {
            $cat = CharityCategory::where('code', $categoryCode)->first();
            if ($cat) $query->where('category_id', $cat->id);
        }

        if ($request->boolean('featured')) {
            $query->where('is_featured', true);
        }

        $items = $query->paginate(20);

        // أضف progress_percentage لكل campaign
        $itemsArray = collect($items->items())->map(function ($c) {
            $arr = $c->toArray();
            $arr['progress_percentage'] = $c->progress_percentage;
            return $arr;
        });

        return $this->ok([
            'pagination' => [
                'total' => $items->total(),
                'per_page' => $items->perPage(),
                'current_page' => $items->currentPage(),
            ],
            'items' => $itemsArray,
        ]);
    }

    /** GET /api/v1/amial/donations/campaigns/{ulid} */
    public function campaignShow(Request $request, string $ulid): JsonResponse
    {
        $campaign = CharityCampaign::with([
            'organization' => fn($q) => $q->select('id', 'name_ar', 'description_ar',
                'logo_url', 'cover_image_url', 'verification_status', 'total_collected', 'total_campaigns'),
            'category',
        ])->where('campaign_ulid', $ulid)->first();

        if (!$campaign) return $this->error('NOT_FOUND', 'Campaign not found', 404);

        // increment view count (atomic, no race)
        CharityCampaign::where('id', $campaign->id)->increment('view_count');

        $data = $campaign->toArray();
        $data['progress_percentage'] = $campaign->progress_percentage;

        // آخر متبرعين (anonymized)
        $recentDonors = Donation::where('campaign_id', $campaign->id)
            ->where('status', 'completed')
            ->with('donor:id,f_name,l_name')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn($d) => [
                'amount' => $d->amount,
                'donated_at' => $d->donated_at?->toIso8601String(),
                'donor_name' => $d->public_donor_name,
                'message' => $d->donor_message,
            ]);

        $data['recent_donations'] = $recentDonors;

        return $this->ok(['campaign' => $data]);
    }

    /** POST /api/v1/amial/donations/donate */
    public function donate(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'campaign_ulid' => 'required|string|size:26',
            'amount' => 'required|numeric|min:1',
            'is_anonymous' => 'sometimes|boolean',
            'message' => 'sometimes|nullable|string|max:500',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $campaign = CharityCampaign::where('campaign_ulid', $request->input('campaign_ulid'))->first();
        if (!$campaign) return $this->error('CAMPAIGN_NOT_FOUND', 'Campaign not found', 404);

        try {
            $donation = $this->service->donate(
                donor: $request->user(),
                campaign: $campaign,
                amount: (string)$request->input('amount'),
                isAnonymous: $request->boolean('is_anonymous', false),
                message: $request->input('message'),
            );
        } catch (\App\Exceptions\InsufficientBalanceException $e) {
            return new JsonResponse($e->toApiArray(), 402);
        } catch (\RuntimeException $e) {
            return $this->error('DONATION_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(
            ['donation' => $donation->fresh(['campaign:id,title_ar', 'organization:id,name_ar'])],
            'DONATION_OK',
            'تمت المساهمة بنجاح، جزاك الله خيراً',
            201,
        );
    }

    /** GET /api/v1/amial/donations/my-donations */
    public function myDonations(Request $request): JsonResponse
    {
        $items = Donation::where('donor_user_id', $request->user()->id)
            ->with([
                'campaign:id,title_ar,campaign_ulid,cover_image_url',
                'organization:id,name_ar,logo_url',
            ])
            ->orderByDesc('id')
            ->paginate(20);

        return $this->ok([
            'pagination' => [
                'total' => $items->total(),
                'per_page' => $items->perPage(),
                'current_page' => $items->currentPage(),
            ],
            'items' => $items->items(),
        ]);
    }

    /** GET /api/v1/amial/donations/organizations */
    public function organizations(): JsonResponse
    {
        $orgs = CharityOrganization::verified()
            ->select('id', 'org_ulid', 'name_ar', 'description_ar',
                'logo_url', 'cover_image_url',
                'total_collected', 'total_campaigns', 'total_donors')
            ->orderByDesc('total_collected')
            ->paginate(20);

        return $this->ok([
            'pagination' => [
                'total' => $orgs->total(),
                'per_page' => $orgs->perPage(),
                'current_page' => $orgs->currentPage(),
            ],
            'items' => $orgs->items(),
        ]);
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
}
