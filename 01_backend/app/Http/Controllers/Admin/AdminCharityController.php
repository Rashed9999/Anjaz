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

    /**
     * AMIAL-CHARITY-META-001 — **تصنيفاتُ الحملات للوحة.**
     *
     * ══════════════════════════════════════════════════════════════════
     * وكان في الشاشة سببان لِـ«— لا تصنيفات مسجَّلة —»، لا سبب:
     *   ① الجدولُ فارغ — بذرةٌ مسجَّلةٌ في `DatabaseSeeder` لا يشغّلها
     *      النشر. (عولج بهجرة `2026_08_14_140000`.)
     *   ② **ولا نقطةَ إداريّةٌ تقرؤه أصلاً** — الشاشةُ تنادي
     *      `admin/amial/charity/categories` وهو مسارٌ لا وجود له، فكلُّ
     *      نداءٍ يسقط ويُبتلع في `catch`.
     *
     * فلو زُرع الجدولُ وحدَه لبقيت الخانةُ فارغةً — **وعطلٌ واحدٌ له
     * سببان يُصلَح أحدُهما فيبدو أنّ الإصلاح لم ينفع.**
     * ══════════════════════════════════════════════════════════════════
     */
    public function categories(): JsonResponse
    {
        return $this->ok([
            'categories' => \App\Models\CharityCategory::where('is_active', true)
                ->orderBy('sort_order')->get(['id', 'code', 'name_ar', 'icon']),
        ]);
    }

    /**
     * AMIAL-CHARITY-UPLOAD-001 — **الصورةُ تُرفع من الجهاز لا تُلصق برابط.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **الثمن الذي دُفع:** قال صاحبُ المشروع: «المفترض رفع صور من الجهاز
     * وليس رابط». وكان النموذجُ يطلب `https://…` — أي أنّ من ينشئ حملةً
     * لازمه أن يرفع الصورةَ في مكانٍ آخرَ أوّلاً ثمّ ينسخ رابطَها.
     *
     * **وهذا ليس نقصَ ميزةٍ بل بابٌ لا يُفتح**: لا أحدَ في مكتبِ جمعيّةٍ
     * يملك مستضيفَ صور. فالنتيجةُ حملةٌ بلا صورة، أو لا حملةَ أصلاً.
     * ══════════════════════════════════════════════════════════════════
     *
     * يظهر في : لوحة الإدارة ← التبرّعات ← الحملات ← خانتا الصور
     */
    public function uploadImage(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            // **النوعُ يُقيَّد صراحةً**: `image` وحدها تقبل SVG، وSVG يحمل
            // نصّاً برمجيّاً يُنفَّذ في متصفّح من يفتحه.
            'file' => 'required|file|mimes:jpg,jpeg,png,webp|max:5120',
        ], [], ['file' => 'الصورة']);
        if ($v->fails()) return $this->validationError($v);

        // **المصغِّرُ المحدود، لا `Helpers::upload`.** تلك سقفُها ٢٥٠٠ بكسل
        // و**webp تُنسخ بلا تصغيرٍ إطلاقاً** — وقد وُصفت «قاتلة» في
        // `CatalogImageService` لهذا السبب بعينه، ثمّ استُعملت هنا لحملةٍ
        // تحمل غلافاً ومعرضاً من عشر صور. تناقضٌ في الجولة نفسِها.
        try {
            $stored = app(\App\Services\Catalog\CatalogImageService::class)
                ->store($request->file('file'));
        } catch (\RuntimeException $e) {
            return $this->error('UPLOAD_FAILED', $e->getMessage(), 422);
        }

        return $this->ok([
            'path' => $stored,
            'url' => asset('storage/' . $stored),
        ], 'UPLOADED', 'رُفعت الصورة', 201);
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
            // AMIAL-CHARITY-META-001 — **بدايةٌ ونهاية، لا نهايةٌ وحدها.**
            // كان النموذجُ يسأل عن تاريخ الانتهاء فقط، وحملةٌ بلا بدايةٍ
            // تُنشر ساعةَ اعتمادها — فلا تُجهَّز حملةُ رمضانَ في شعبان.
            'start_at' => 'sometimes|nullable|date',
            'deadline_at' => 'sometimes|nullable|date|after:tomorrow|after:start_at',
            'is_featured' => 'sometimes|boolean',
            // **«عاجل» حكمٌ إداريّ**: يرفع الحملةَ في ترتيب التطبيق ويضع
            // عليها علامةً حمراء — فلا يُترك للجمعيّة تُعلنه عن نفسها.
            'is_urgent' => 'sometimes|boolean',
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

    /**
     * **متبرّعو حملةٍ بعينها.**
     *
     * ══════════════════════════════════════════════════════════════════
     * قالها صاحبُ المشروع: «يجب إظهار المتبرّعين». وكان الجدولُ `donations`
     * مكتوباً منذ بُنيت الحملات — **ولا نقطةَ تقرؤه للإدارة**، فلا سبيل
     * إلى معرفة من تبرّع لحملةٍ إلّا بفتح القاعدة.
     *
     * **والمجهوليّةُ تُحترم**: من تبرّع `is_anonymous` لا يُكشف اسمُه —
     * ولو للمدير. فذاك وعدٌ قُطع للمتبرّع في التطبيق، وكشفُه هنا يجعله
     * كذباً. ويبقى مبلغُه ظاهراً لأنّ المالَ يُحاسَب عليه.
     */
    public function campaignDonors(Request $request, string $ulid): JsonResponse
    {
        $campaign = CharityCampaign::where('campaign_ulid', $ulid)->first();

        if (! $campaign) {
            return $this->error('CAMPAIGN_NOT_FOUND', 'الحملة غير موجودة', 404);
        }

        $rows = \Illuminate\Support\Facades\DB::table('donations as d')
            ->leftJoin('users as u', 'u.id', '=', 'd.donor_user_id')
            ->where('d.campaign_id', $campaign->id)
            ->orderByDesc('d.id')
            ->paginate(50, [
                'd.donation_ulid', 'd.amount', 'd.platform_fee', 'd.net_to_charity',
                'd.is_anonymous', 'd.donor_message', 'd.status', 'd.donated_at',
                'u.id as donor_id', 'u.f_name', 'u.l_name', 'u.phone',
            ]);

        $items = collect($rows->items())->map(function ($d) {
            $anon = (bool) $d->is_anonymous;

            return [
                'ulid' => $d->donation_ulid,
                'amount' => (string) $d->amount,
                'platform_fee' => (string) $d->platform_fee,
                'net_to_charity' => (string) $d->net_to_charity,
                'status' => $d->status,
                'donated_at' => (string) $d->donated_at,
                'message' => $d->donor_message,
                'is_anonymous' => $anon,
                // **الوعدُ يُحترم**: مجهولٌ يبقى مجهولاً في اللوحة أيضاً.
                'donor' => $anon ? null : [
                    'id' => $d->donor_id,
                    'name' => trim(($d->f_name ?? '') . ' ' . ($d->l_name ?? '')) ?: 'بلا اسم',
                    'phone' => $d->phone,
                ],
            ];
        })->all();

        return $this->ok([
            'campaign' => [
                'title' => $campaign->title_ar,
                'target_amount' => (string) $campaign->target_amount,
                'current_amount' => (string) $campaign->current_amount,
                'donor_count' => (int) $campaign->donor_count,
                // نسبةُ الاكتمال تُحسب من المبلغين لا من عمودٍ مخزَّن.
                'progress' => (float) $campaign->target_amount > 0
                    ? round(((float) $campaign->current_amount / (float) $campaign->target_amount) * 100, 1)
                    : null,
            ],
            'donors' => $items,
            'pagination' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

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

    /**
     * AMIAL-CHARITY-PAYOUT-001 — صرفُ التسوية إلى محفظة أميال أو عبر وكيل
     * أو حوالةٍ بنكيّة.
     *
     * يظهر في : لوحة الإدارة ← التبرّعات ← تبويب «سحب المال» ← زرّ «صرف»
     * ويُوصل إليه من : القائمة الجانبيّة ← خدمات المنصّة ← التبرّعات
     */
    public function payoutSettlement(Request $request, string $ulid): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'method' => 'required|string|in:bank,wallet,agent',
            'reference' => 'required|string|min:3|max:100',
            // **الهاتفُ لا المعرّف**: من يصرف يعرف رقمَ من يقبض، ولا يعرف
            // رقمَه الداخليّ في قاعدتنا.
            'recipient_phone' => 'required_unless:method,bank|nullable|string|max:32',
            'notes' => 'sometimes|nullable|string|max:500',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $settlement = CharitySettlement::where('settlement_ulid', $ulid)->first();
        if (!$settlement) return $this->error('NOT_FOUND', 'Not found', 404);

        $method = (string) $request->input('method');
        $recipient = null;

        if ($method !== 'bank') {
            $phone = (string) $request->input('recipient_phone');
            $recipient = \App\Models\User::whereIn('phone', \App\Support\Phone::variants($phone))->first();
            if (!$recipient) {
                return $this->error('RECIPIENT_NOT_FOUND', 'لا حساب بهذا الرقم: ' . $phone, 422);
            }
        }

        try {
            $settlement = $this->service->payoutSettlement(
                $settlement,
                $request->user(),
                $method,
                (string) $request->input('reference'),
                $recipient,
                $request->input('notes'),
            );
        } catch (\RuntimeException $e) {
            return $this->error('PAYOUT_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(
            ['settlement' => $settlement],
            'PAID',
            'تمّ صرفُ ' . $settlement->payable_amount . ' — ' . ($recipient
                ? 'إلى ' . trim(($recipient->f_name ?? '') . ' ' . ($recipient->l_name ?? ''))
                : 'حوالةً بنكيّة'),
        );
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
