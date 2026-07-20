<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\FuelCompanyAccount;
use App\Models\FuelCompanyCard;
use App\Models\FuelProduct;
use App\Models\FuelPump;
use App\Models\FuelSale;
use App\Models\FuelShift;
use App\Models\FuelStation;
use App\Models\FuelVarianceRecord;
use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\User;
use App\Services\FuelCompanyCardService;
use App\Services\FuelReceiptPdfService;
use App\Services\FuelShiftService;
use App\Services\FuelStationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-FUEL-001 — Controller محطة الوقود.
 *
 * Endpoints:
 *   GET  /api/v1/amial/merchant/fuel/station
 *   POST /api/v1/amial/merchant/fuel/station
 *
 *   GET  /api/v1/amial/merchant/fuel/pumps
 *   POST /api/v1/amial/merchant/fuel/pumps
 *   PUT  /api/v1/amial/merchant/fuel/pumps/{id}
 *   POST /api/v1/amial/merchant/fuel/pumps/{id}/link-products
 *
 *   GET  /api/v1/amial/merchant/fuel/products
 *   POST /api/v1/amial/merchant/fuel/products
 *   PUT  /api/v1/amial/merchant/fuel/products/{id}/price
 *
 *   POST /api/v1/amial/merchant/fuel/sales              ← الجوهر
 *   GET  /api/v1/amial/merchant/fuel/sales
 *   GET  /api/v1/amial/merchant/fuel/sales/{ulid}
 *   GET  /api/v1/amial/merchant/fuel/dashboard
 *
 *   GET  /api/v1/amial/merchant/fuel/companies
 *   POST /api/v1/amial/merchant/fuel/companies
 *   POST /api/v1/amial/merchant/fuel/companies/{id}/payment
 */
class FuelStationController extends AmialApiController // AMIAL-FIX-007
{
    public function __construct(
        private readonly FuelStationService $svc,
        private readonly FuelShiftService $shifts,
        private readonly FuelCompanyCardService $cardSvc,
    ) {}

    // ============ Receipt PDF ============

    public function downloadReceipt(Request $request, string $ulid)
    {
        $ctx = $this->resolveMerchantPos($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $sale = FuelSale::where('sale_ulid', $ulid)
            ->where('merchant_user_id', $merchant->id)
            ->first();
        if (!$sale) return $this->error('NOT_FOUND', 'البيع غير موجود', 404);

        $pdfSvc = app(FuelReceiptPdfService::class);
        $pdf = $pdfSvc->generate($sale);
        $filename = $pdfSvc->suggestedFilename($sale);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    // ============ Company Cards ============

    public function listCards(Request $request, int $companyId): JsonResponse
    {
        $ctx = $this->resolveMerchantPos($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $company = FuelCompanyAccount::where('id', $companyId)
            ->where('merchant_user_id', $merchant->id)->first();
        if (!$company) return $this->error('NOT_FOUND', 'الشركة غير موجودة', 404);

        $cards = FuelCompanyCard::where('company_account_id', $company->id)
            ->orderByDesc('id')->get();
        return $this->ok(['cards' => $cards]);
    }

    public function addCard(Request $request, int $companyId): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'card_number' => 'required|string|max:40',
            'card_label' => 'sometimes|nullable|string|max:120',
            'vehicle_plate' => 'sometimes|nullable|string|max:32',
            'driver_name' => 'sometimes|nullable|string|max:120',
            'driver_phone' => 'sometimes|nullable|string|max:32',
            'daily_limit' => 'sometimes|nullable|numeric|min:0',
            'monthly_limit' => 'sometimes|nullable|numeric|min:0',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $ctx = $this->resolveMerchantPos($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $company = FuelCompanyAccount::where('id', $companyId)
            ->where('merchant_user_id', $merchant->id)->first();
        if (!$company) return $this->error('NOT_FOUND', 'الشركة غير موجودة', 404);

        try {
            $card = $this->cardSvc->addCard($company, $request->all());
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID', $e->getMessage(), 422);
        }
        return $this->ok(['card' => $card], 'ADDED', 'تم إضافة البطاقة', 201);
    }

    public function updateCard(Request $request, int $companyId, int $cardId): JsonResponse
    {
        $ctx = $this->resolveMerchantPos($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $card = FuelCompanyCard::where('id', $cardId)
            ->where('company_account_id', $companyId)->first();
        if (!$card) return $this->error('NOT_FOUND', 'البطاقة غير موجودة', 404);

        $updated = $this->cardSvc->updateCard($card, $request->all());
        return $this->ok(['card' => $updated], 'UPDATED', 'تم التحديث');
    }

    // ============ Shifts ============

    public function currentShift(Request $request): JsonResponse
    {
        $ctx = $this->resolveMerchantPos($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $station = $this->svc->getOrCreateStation($merchant);
        $shift = $this->shifts->currentOpenShift($station);
        return $this->ok(['shift' => $shift]);
    }

    public function openShift(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'opening_cash' => 'sometimes|nullable|numeric|min:0',
            'notes' => 'sometimes|nullable|string|max:500',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $ctx = $this->resolveMerchantPos($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $station = $this->svc->getOrCreateStation($merchant);
        try {
            $shift = $this->shifts->openShift(
                $station, $request->user(),
                (string)$request->input('opening_cash', '0'),
                $request->input('notes'),
            );
        } catch (\RuntimeException $e) {
            return $this->error('SHIFT_ALREADY_OPEN', $e->getMessage(), 422);
        }
        return $this->ok(['shift' => $shift], 'SHIFT_OPENED', 'تم فتح النوبة', 201);
    }

    public function closeShift(Request $request, int $shiftId): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'actual_cash' => 'required|numeric|min:0',
            'pump_closings' => 'sometimes|array',
            'pump_closings.*' => 'numeric|min:0',
            'variance_reason' => 'sometimes|nullable|string|max:1000',
            'closing_notes' => 'sometimes|nullable|string|max:500',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $ctx = $this->resolveMerchantPos($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $station = $this->svc->getOrCreateStation($merchant);
        $shift = FuelShift::where('id', $shiftId)
            ->where('station_id', $station->id)->first();
        if (!$shift) return $this->error('NOT_FOUND', 'النوبة غير موجودة', 404);

        try {
            $closed = $this->shifts->closeShift(
                $shift, $request->user(),
                (string)$request->input('actual_cash'),
                $request->input('pump_closings', []),
                $request->input('variance_reason'),
                $request->input('closing_notes'),
            );
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->error('CLOSE_FAILED', $e->getMessage(), 422);
        }
        return $this->ok(['shift' => $closed], 'SHIFT_CLOSED', 'تم إغلاق النوبة');
    }

    public function listShifts(Request $request): JsonResponse
    {
        $ctx = $this->resolveMerchantPos($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $station = $this->svc->getOrCreateStation($merchant);
        $shifts = $this->shifts->recentShifts($station, (int)$request->query('limit', 20));
        return $this->ok(['shifts' => $shifts]);
    }

    public function showShift(Request $request, int $id): JsonResponse
    {
        $ctx = $this->resolveMerchantPos($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $station = $this->svc->getOrCreateStation($merchant);
        $shift = FuelShift::where('id', $id)
            ->where('station_id', $station->id)
            ->with(['pumpSummaries.pump', 'varianceRecords'])
            ->first();
        if (!$shift) return $this->error('NOT_FOUND', 'النوبة غير موجودة', 404);

        return $this->ok(['shift' => $shift]);
    }

    // ============ Variance Records (Admin) ============

    public function listVarianceRecords(Request $request): JsonResponse
    {
        $ctx = $this->resolveMerchantPos($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $station = $this->svc->getOrCreateStation($merchant);
        $q = FuelVarianceRecord::where('station_id', $station->id);
        if ($request->filled('status')) $q->where('resolution_status', $request->query('status'));
        if ($request->filled('type')) $q->where('variance_type', $request->query('type'));

        $records = $q->orderByDesc('id')->limit(50)->get();
        return $this->ok(['variances' => $records]);
    }

    // ============ Station ============

    public function getStation(Request $request): JsonResponse
    {
        $ctx = $this->resolveMerchantPos($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $station = $this->svc->getOrCreateStation($merchant);
        return $this->ok(['station' => $station]);
    }

    public function upsertStation(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'station_name' => 'required|string|max:120',
            'license_number' => 'sometimes|nullable|string|max:64',
            'city' => 'sometimes|nullable|string|max:80',
            'address' => 'sometimes|nullable|string',
            'latitude' => 'sometimes|nullable|numeric',
            'longitude' => 'sometimes|nullable|numeric',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $ctx = $this->resolveMerchantPos($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $station = $this->svc->getOrCreateStation($merchant, $request->all());
        return $this->ok(['station' => $station], 'SAVED', 'تم حفظ بيانات المحطة');
    }

    // ============ Pumps ============

    public function listPumps(Request $request): JsonResponse
    {
        $ctx = $this->resolveMerchantPos($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $station = $this->svc->getOrCreateStation($merchant);
        $pumps = FuelPump::where('station_id', $station->id)
            ->with('products')
            ->orderBy('pump_number')
            ->get();

        return $this->ok(['pumps' => $pumps]);
    }

    public function addPump(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'pump_number' => 'required|integer|min:1|max:999',
            'pump_name' => 'sometimes|nullable|string|max:80',
            'pump_type' => 'sometimes|in:mechanical,electronic',
            'initial_meter_reading' => 'sometimes|nullable|numeric|min:0',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $ctx = $this->resolveMerchantPos($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $station = $this->svc->getOrCreateStation($merchant);

        try {
            $pump = $this->svc->addPump($station, $request->all());
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID', $e->getMessage(), 422);
        }
        return $this->ok(['pump' => $pump], 'PUMP_ADDED', 'تم إضافة المضخّة', 201);
    }

    public function updatePump(Request $request, int $id): JsonResponse
    {
        $ctx = $this->resolveMerchantPos($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $pump = FuelPump::with('station')->find($id);
        if (!$pump || $pump->station->merchant_user_id !== $merchant->id) {
            return $this->error('NOT_FOUND', 'المضخّة غير موجودة', 404);
        }

        try {
            $updated = $this->svc->updatePump($pump, $request->all());
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID', $e->getMessage(), 422);
        }
        return $this->ok(['pump' => $updated], 'UPDATED', 'تم التحديث');
    }

    public function linkPumpToProducts(Request $request, int $id): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'links' => 'required|array|min:1',
            'links.*.fuel_product_id' => 'required|integer|exists:fuel_products,id',
            'links.*.nozzle_number' => 'sometimes|nullable|integer|min:1',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $ctx = $this->resolveMerchantPos($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $pump = FuelPump::with('station')->find($id);
        if (!$pump || $pump->station->merchant_user_id !== $merchant->id) {
            return $this->error('NOT_FOUND', 'المضخّة غير موجودة', 404);
        }

        $this->svc->linkPumpToProducts($pump, $request->input('links'));
        return $this->ok(['pump' => $pump->fresh('products')], 'LINKED', 'تم الربط');
    }

    // ============ Products ============

    public function listProducts(Request $request): JsonResponse
    {
        $ctx = $this->resolveMerchantPos($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $station = $this->svc->getOrCreateStation($merchant);
        $products = FuelProduct::where('station_id', $station->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
        return $this->ok(['products' => $products]);
    }

    public function addProduct(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'name' => 'required|string|max:80',
            'product_code' => 'sometimes|nullable|string|max:32',
            'price_per_liter' => 'required|numeric|min:0.01',
            'color_hex' => 'sometimes|nullable|string|max:7',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $ctx = $this->resolveMerchantPos($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $station = $this->svc->getOrCreateStation($merchant);

        try {
            $product = $this->svc->addProduct($station, $request->all());
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID', $e->getMessage(), 422);
        }
        return $this->ok(['product' => $product], 'PRODUCT_ADDED', 'تم الإضافة', 201);
    }

    public function updateProductPrice(Request $request, int $id): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'price_per_liter' => 'required|numeric|min:0.01',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $ctx = $this->resolveMerchantPos($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $product = FuelProduct::find($id);
        if (!$product) return $this->error('NOT_FOUND', 'نوع الوقود غير موجود', 404);

        $station = $this->svc->getOrCreateStation($merchant);
        if ($product->station_id !== $station->id) {
            return $this->error('FORBIDDEN', 'لا تنتمي هذه السلعة لمحطتك', 403);
        }

        try {
            $updated = $this->svc->updateProductPrice(
                $product,
                (string)$request->input('price_per_liter'),
                $request->user(),
                $request->input('note'),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID', $e->getMessage(), 422);
        }
        return $this->ok(['product' => $updated], 'PRICE_UPDATED', 'تم تحديث السعر');
    }

    /**
     * AMIAL-FUEL-PRICE-HISTORY-001 — GET /fuel/price-history?limit=30
     * سجلّ تغيّر أسعار الوقود (من غيّر، متى، الفرق) لشاشة إعدادات التسعير.
     */
    public function priceHistory(Request $request): JsonResponse
    {
        $ctx = $this->resolveMerchantPos($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $station = $this->svc->getOrCreateStation($merchant);
        return $this->ok([
            'history' => $this->svc->priceHistory($station, (int) $request->query('limit', 30)),
        ]);
    }

    // ============ Sales (الجوهر) ============

    public function recordSale(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'pump_id' => 'required|integer',
            'fuel_product_id' => 'required|integer',
            'sale_type' => 'required|in:by_liters,by_amount',
            'liters' => 'sometimes|nullable|numeric|min:0.001',
            'amount' => 'sometimes|nullable|numeric|min:0.01',
            'payment_method' => 'required|in:cash,amial_pay,company_card',
            'paid_transaction_id' => 'sometimes|nullable|string|max:64',
            // AMIAL-FUEL-PAY-001: هاتف العميل — شحن مباشر في خطوة واحدة
            'customer_phone' => 'sometimes|nullable|string|min:6|max:20',
            'company_account_id' => 'sometimes|nullable|integer',
            'company_card_id' => 'sometimes|nullable|string|max:40',
            'vehicle_plate' => 'sometimes|nullable|string|max:32',
            'driver_name' => 'sometimes|nullable|string|max:120',
            'meter_reading_after' => 'sometimes|nullable|numeric|min:0',
            'notes' => 'sometimes|nullable|string|max:500',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $ctx = $this->resolveMerchantPos($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant, $posUserId] = $ctx;

        try {
            $sale = $this->svc->recordSale($merchant, $posUserId, $request->all());
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID', $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->error('SALE_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(['sale' => $sale], 'SALE_RECORDED', 'تم تسجيل البيع', 201);
    }

    public function listSales(Request $request): JsonResponse
    {
        $ctx = $this->resolveMerchantPos($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $q = FuelSale::where('merchant_user_id', $merchant->id);
        if ($request->filled('pump_id')) $q->where('pump_id', $request->query('pump_id'));
        if ($request->filled('payment_method')) $q->where('payment_method', $request->query('payment_method'));
        if ($request->filled('from')) $q->where('created_at', '>=', $request->query('from'));
        if ($request->filled('to')) $q->where('created_at', '<=', $request->query('to'));

        $page = $q->with(['product', 'pump'])
            ->orderByDesc('id')
            ->paginate(20);

        return $this->ok([
            'sales' => $page->items(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function showSale(Request $request, string $ulid): JsonResponse
    {
        $ctx = $this->resolveMerchantPos($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $sale = FuelSale::where('sale_ulid', $ulid)
            ->where('merchant_user_id', $merchant->id)
            ->with(['product', 'pump', 'companyAccount'])
            ->first();
        if (!$sale) return $this->error('NOT_FOUND', 'البيع غير موجود', 404);

        return $this->ok(['sale' => $sale]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $ctx = $this->resolveMerchantPos($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $today = now()->startOfDay();
        $q = FuelSale::where('merchant_user_id', $merchant->id)
            ->where('created_at', '>=', $today)
            ->where('status', 'completed');

        $totalLiters = (string) (clone $q)->sum('liters');
        $totalAmount = (string) (clone $q)->sum('total_amount');
        $countSales = (clone $q)->count();

        $byMethod = (clone $q)
            ->selectRaw('payment_method, SUM(total_amount) as total, COUNT(*) as c')
            ->groupBy('payment_method')
            ->get();

        $byProduct = (clone $q)
            ->selectRaw('fuel_product_id, SUM(liters) as total_liters, SUM(total_amount) as total_amount')
            ->groupBy('fuel_product_id')
            ->with('product:id,name')
            ->get();

        // AMIAL-REPORTS-HOURLY-001: توزيع مبيعات الوقود على 24 ساعة + ساعة الذروة.
        $byHourRaw = (clone $q)
            ->selectRaw('HOUR(created_at) as h, COUNT(*) as c, SUM(total_amount) as total, SUM(liters) as liters')
            ->groupBy('h')
            ->get()
            ->keyBy('h');

        $byHour = [];
        $peakHour = null;
        $peakTotal = 0.0;
        for ($h = 0; $h < 24; $h++) {
            $row = $byHourRaw->get($h);
            $total = $row ? (float) $row->total : 0.0;
            $byHour[] = [
                'hour' => $h,
                'count' => $row ? (int) $row->c : 0,
                'total' => (string) ($row->total ?? '0'),
                'liters' => (string) ($row->liters ?? '0'),
            ];
            if ($total > $peakTotal) {
                $peakTotal = $total;
                $peakHour = $h;
            }
        }

        return $this->ok([
            'today' => [
                'total_liters' => $totalLiters,
                'total_amount' => $totalAmount,
                'sales_count' => $countSales,
                'by_payment' => $byMethod,
                'by_product' => $byProduct,
                'by_hour' => $byHour,
                'peak_hour' => $peakHour,
                'peak_hour_total' => (string) $peakTotal,
            ],
        ]);
    }

    // ============ Company Accounts ============

    public function listCompanies(Request $request): JsonResponse
    {
        $ctx = $this->resolveMerchantPos($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $companies = FuelCompanyAccount::where('merchant_user_id', $merchant->id)
            ->where('is_active', true)
            ->orderBy('company_name')
            ->get();
        return $this->ok(['companies' => $companies]);
    }

    public function addCompany(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'company_name' => 'required|string|max:200',
            'contact_person' => 'sometimes|nullable|string|max:120',
            'contact_phone' => 'sometimes|nullable|string|max:32',
            'tax_number' => 'sometimes|nullable|string|max:64',
            'credit_limit' => 'sometimes|nullable|numeric|min:0',
            'monthly_limit' => 'sometimes|nullable|numeric|min:0',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $ctx = $this->resolveMerchantPos($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        try {
            $company = $this->svc->addCompanyAccount($merchant, $request->all());
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID', $e->getMessage(), 422);
        }
        return $this->ok(['company' => $company], 'ADDED', 'تم إضافة الشركة', 201);
    }

    public function recordCompanyPayment(Request $request, int $id): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'note' => 'sometimes|nullable|string|max:255',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $ctx = $this->resolveMerchantPos($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $company = FuelCompanyAccount::where('id', $id)
            ->where('merchant_user_id', $merchant->id)
            ->first();
        if (!$company) return $this->error('NOT_FOUND', 'الشركة غير موجودة', 404);

        try {
            $result = $this->svc->recordCompanyPayment(
                $company,
                (string)$request->input('amount'),
                $request->input('note'),
            );
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->error('FAILED', $e->getMessage(), 422);
        }
        return $this->ok($result, 'PAYMENT_OK', 'تم تسجيل السداد');
    }

    // ============ Helpers ============

    private function resolveMerchantPos(Request $request): array|JsonResponse
    {
        $authUser = $request->user();
        $pos = PosUser::where('user_id', $authUser->id)->where('is_active', true)->first();

        if ($pos) {
            $merchant = User::find($pos->merchant_user_id);
            if (!$merchant) return $this->error('MERCHANT_NOT_FOUND', 'التاجر غير موجود', 404);
            return [$merchant, $pos->id];
        }
        if (!MerchantProfile::where('user_id', $authUser->id)->exists()) {
            return $this->error('NOT_A_MERCHANT', 'متاح للتجار وموظفي المحطة فقط', 403);
        }
        return [$authUser, null];
    }
}
