<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\MerchantFixedAsset;
use App\Services\FeatureAccessService;
use App\Services\MerchantFixedAssetService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class WebAssetController extends Controller
{
    public function __construct(
        private readonly MerchantFixedAssetService $assets,
        private readonly FeatureAccessService $access,
    ) {}

    private function owner(Request $request): mixed
    {
        $owner = $request->user('merchant_web') ?? $request->user();
        if (! $owner || $owner->role !== A::ROLE_MERCHANT) {
            return $this->err('NOT_A_MERCHANT', 'متاح لمالك المنشأة فقط', 403);
        }
        if (! $this->access->hasFeature($owner, A::F_EXPENSES)) {
            return $this->err('FEATURE_LOCKED', 'سجل الأصول متاح في باقة الأعمال فأعلى', 402);
        }

        return $owner;
    }

    public function index(Request $request): JsonResponse
    {
        $owner = $this->owner($request);
        if ($owner instanceof JsonResponse) return $owner;

        return $this->ok($this->assets->index($owner));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $owner = $this->owner($request);
        if ($owner instanceof JsonResponse) return $owner;

        try {
            return $this->ok($this->assets->show($owner, $id));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->err('NOT_FOUND', 'الأصل غير موجود', 404);
        }
    }

    /** أصل تاريخي موجود قبل أميال؛ التسجيل لا يحرك نقداً أو محفظة. */
    public function storeOpening(Request $request): JsonResponse
    {
        $owner = $this->owner($request);
        if ($owner instanceof JsonResponse) return $owner;

        $v = Validator::make($request->all(), [
            'name' => 'required|string|max:200',
            'category' => 'sometimes|string|in:'.implode(',', MerchantFixedAsset::CATEGORIES),
            'quantity' => 'sometimes|numeric|min:0.001',
            'acquisition_cost' => 'required|numeric|min:0.01',
            'salvage_value' => 'sometimes|numeric|min:0',
            'useful_life_months' => 'required|integer|min:1|max:600',
            'acquired_on' => 'required|date|before_or_equal:today',
            'depreciation_starts_on' => 'sometimes|nullable|date',
        ]);
        if ($v->fails()) return $this->err('VALIDATION', $v->errors()->first(), 422);

        try {
            $asset = $this->assets->createOpening($owner, $v->validated());
        } catch (RuntimeException $e) {
            return $this->err('ASSET_INVALID', $e->getMessage(), 422);
        }

        return $this->ok(
            ['asset' => $this->assets->toArray($asset)],
            'ASSET_REGISTERED',
            'تم تسجيل الأصل الافتتاحي دون إنشاء حركة مالية',
            201,
        );
    }

    public function postDepreciation(Request $request): JsonResponse
    {
        $owner = $this->owner($request);
        if ($owner instanceof JsonResponse) return $owner;

        $v = Validator::make($request->all(), [
            'through' => ['required', 'regex:/^\\d{4}-\\d{2}$/'],
        ]);
        if ($v->fails()) return $this->err('VALIDATION', $v->errors()->first(), 422);

        try {
            $through = Carbon::createFromFormat('Y-m-d', $request->input('through').'-01');
            return $this->ok(
                $this->assets->postDepreciationThrough($owner, $through),
                'DEPRECIATION_POSTED',
                'تم إثبات الإهلاك حتى الشهر المحدد',
            );
        } catch (RuntimeException $e) {
            return $this->err('DEPRECIATION_FAILED', $e->getMessage(), 422);
        }
    }

    public function dispose(Request $request, int $id): JsonResponse
    {
        $owner = $this->owner($request);
        if ($owner instanceof JsonResponse) return $owner;

        $v = Validator::make($request->all(), [
            'disposed_on' => 'required|date|before_or_equal:today',
            'disposal_proceeds' => 'sometimes|nullable|numeric|min:0',
            'reason' => 'required|string|min:5|max:500',
        ]);
        if ($v->fails()) return $this->err('VALIDATION', $v->errors()->first(), 422);

        try {
            $asset = $this->assets->dispose(
                $owner,
                $id,
                Carbon::parse($request->input('disposed_on')),
                $request->filled('disposal_proceeds')
                    ? (string) $request->input('disposal_proceeds') : null,
                (string) $request->input('reason'),
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->err('NOT_FOUND', 'الأصل غير موجود', 404);
        } catch (RuntimeException $e) {
            return $this->err('ASSET_DISPOSAL_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(
            ['asset' => $this->assets->toArray($asset)],
            'ASSET_DISPOSED',
            'تم استبعاد الأصل وتجميد إهلاكه',
        );
    }

    private function ok(array $meta, string $code = 'OK', string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true, 'code' => $code, 'message' => $message,
            'errors' => (object) [], 'meta' => $meta,
        ], $status);
    }

    private function err(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false, 'code' => $code, 'message' => $message,
            'errors' => (object) [], 'meta' => (object) [],
        ], $status);
    }
}
